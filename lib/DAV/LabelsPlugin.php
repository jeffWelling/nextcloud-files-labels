<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\DAV;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File;
use OCA\DAV\Connector\Sabre\Node;
use OCA\FilesLabels\Service\LabelsService;
use OCP\Cache\CappedMemoryCache;
use Psr\Log\LoggerInterface;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * DAV plugin to expose file labels as WebDAV properties.
 *
 * Supports both PROPFIND (read) and PROPPATCH (write) operations.
 * Follows the same pattern as comments and systemtags plugins.
 */
class LabelsPlugin extends ServerPlugin {
	public const NS_NEXTCLOUD = 'http://nextcloud.org/ns';
	public const PROPERTY_LABELS = '{http://nextcloud.org/ns}labels';

	private Server $server;
	private CappedMemoryCache $cachedLabels;
	private array $cachedDirectories = [];

	public function __construct(
		private LabelsService $labelsService,
		private LoggerInterface $logger,
	) {
		$this->cachedLabels = new CappedMemoryCache();
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		$server->xml->namespaceMap[self::NS_NEXTCLOUD] = 'nc';

		$server->on('propFind', [$this, 'handleGetProperties']);
		$server->on('propPatch', [$this, 'handleSetProperties']);
		$server->on('preloadCollection', [$this, 'preloadCollection']);
	}

	/**
	 * Preload labels for all files in a directory to avoid N+1 queries
	 */
	public function preloadCollection(PropFind $propFind, ICollection $collection): void {
		if (!($collection instanceof Directory)) {
			return;
		}

		$path = $collection->getPath();
		if (isset($this->cachedDirectories[$path])) {
			return;
		}

		// Only preload if labels property is requested
		if ($propFind->getStatus(self::PROPERTY_LABELS) === null) {
			return;
		}

		// Collect file IDs
		$fileIds = [(int)$collection->getId()];
		foreach ($collection->getChildren() as $child) {
			if ($child instanceof File || $child instanceof Directory) {
				$fileIds[] = (int)$child->getId();
			}
		}

		// Bulk fetch labels
		$labelsMap = $this->labelsService->getLabelsForFiles($fileIds);

		// Cache results
		foreach ($labelsMap as $fileId => $labels) {
			$labelData = [];
			foreach ($labels as $label) {
				$labelData[$label->getLabelKey()] = $label->getLabelValue();
			}
			$this->cachedLabels[$fileId] = $labelData;
		}

		// Mark empty results too
		foreach ($fileIds as $fileId) {
			if (!isset($this->cachedLabels[$fileId])) {
				$this->cachedLabels[$fileId] = [];
			}
		}

		$this->cachedDirectories[$path] = true;
	}

	/**
	 * Handle propFind to return labels
	 */
	public function handleGetProperties(PropFind $propFind, INode $node): void {
		if (!($node instanceof Node)) {
			return;
		}

		$propFind->handle(self::PROPERTY_LABELS, function () use ($node) {
			$fileId = (int)$node->getId();

			// Check cache first
			if (isset($this->cachedLabels[$fileId])) {
				// Use explicit flags to escape HTML entities for XML safety
				return json_encode($this->cachedLabels[$fileId], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
			}

			// Fallback for single-file requests
			try {
				$labels = $this->labelsService->getLabelsForFile($fileId);
				$result = [];
				foreach ($labels as $label) {
					$result[$label->getLabelKey()] = $label->getLabelValue();
				}
				// Use explicit flags to escape HTML entities for XML safety
				return json_encode($result, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
			} catch (\Exception $e) {
				return '{}';
			}
		});
	}

	/**
	 * Handle propPatch to set/update/remove labels
	 *
	 * Expects JSON format: {"key": "value", ...}
	 * To remove a label, set its value to null: {"key": null}
	 * To remove all labels, set to empty object: {}
	 */
	public function handleSetProperties(string $path, PropPatch $propPatch): void {
		$node = $this->server->tree->getNodeForPath($path);

		if (!($node instanceof Node)) {
			return;
		}

		$propPatch->handle(self::PROPERTY_LABELS, function ($value) use ($node) {
			$fileId = (int)$node->getId();

			// Parse the JSON value
			$newLabels = json_decode($value, true);
			if (!is_array($newLabels)) {
				$this->logger->warning('Invalid labels JSON received via WebDAV', [
					'app' => 'files_labels',
					'fileId' => $fileId,
					'value' => $value,
				]);
				return false;
			}

			try {
				// Get current labels
				$currentLabels = [];
				foreach ($this->labelsService->getLabelsForFile($fileId) as $label) {
					$currentLabels[$label->getLabelKey()] = $label->getLabelValue();
				}

				// Determine what to add/update and what to delete
				$toSet = [];
				$toDelete = [];

				// Check new labels for additions/updates
				foreach ($newLabels as $key => $value) {
					if ($value === null) {
						// Explicit null means delete
						if (isset($currentLabels[$key])) {
							$toDelete[] = $key;
						}
					} else {
						// Add or update
						if (!isset($currentLabels[$key]) || $currentLabels[$key] !== $value) {
							$toSet[$key] = (string)$value;
						}
					}
				}

				// Find labels to delete (in current but not in new, unless we're doing partial update)
				// Note: We only delete labels explicitly set to null, not missing keys
				// This allows partial updates

				// Perform deletions
				foreach ($toDelete as $key) {
					$this->labelsService->deleteLabel($fileId, $key);
				}

				// Perform additions/updates
				if (!empty($toSet)) {
					$this->labelsService->setLabels($fileId, $toSet);
				}

				// Invalidate cache
				unset($this->cachedLabels[$fileId]);

				$this->logger->debug('Labels updated via WebDAV', [
					'app' => 'files_labels',
					'fileId' => $fileId,
					'setCount' => count($toSet),
					'deleteCount' => count($toDelete),
				]);

				return true;
			} catch (\OCP\Files\NotPermittedException $e) {
				$this->logger->warning('Permission denied for WebDAV label update', [
					'app' => 'files_labels',
					'fileId' => $fileId,
					'error' => $e->getMessage(),
				]);
				return false;
			} catch (\InvalidArgumentException $e) {
				$this->logger->warning('Invalid label data via WebDAV', [
					'app' => 'files_labels',
					'fileId' => $fileId,
					'error' => $e->getMessage(),
				]);
				return false;
			} catch (\OverflowException $e) {
				$this->logger->warning('Rate limit exceeded via WebDAV', [
					'app' => 'files_labels',
					'fileId' => $fileId,
					'error' => $e->getMessage(),
				]);
				return false;
			} catch (\Exception $e) {
				$this->logger->error('Failed to update labels via WebDAV', [
					'app' => 'files_labels',
					'fileId' => $fileId,
					'error' => $e->getMessage(),
				]);
				return false;
			}
		});
	}
}
