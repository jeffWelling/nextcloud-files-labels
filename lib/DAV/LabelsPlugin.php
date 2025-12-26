<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\DAV;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File;
use OCA\DAV\Connector\Sabre\Node;
use OCA\FilesLabels\Service\LabelsService;
use OCP\Cache\CappedMemoryCache;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * DAV plugin to expose file labels as WebDAV properties.
 *
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
	) {
		$this->cachedLabels = new CappedMemoryCache();
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		$server->xml->namespaceMap[self::NS_NEXTCLOUD] = 'nc';

		$server->on('propFind', [$this, 'handleGetProperties']);
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
				return json_encode($this->cachedLabels[$fileId]);
			}

			// Fallback for single-file requests
			try {
				$labels = $this->labelsService->getLabelsForFile($fileId);
				$result = [];
				foreach ($labels as $label) {
					$result[$label->getLabelKey()] = $label->getLabelValue();
				}
				return json_encode($result);
			} catch (\Exception $e) {
				return '{}';
			}
		});
	}
}
