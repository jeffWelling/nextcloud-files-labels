<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Controller;

use OCA\FilesLabels\Service\LabelsService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

class LabelsController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private LabelsService $labelsService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Get all labels for a file
	 *
	 * @param int $fileId The file ID
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function index(int $fileId): DataResponse {
		try {
			$labels = $this->labelsService->getLabelsForFile($fileId);

			// Convert to key-value map for easier client consumption
			$result = [];
			foreach ($labels as $label) {
				$result[$label->getLabelKey()] = $label->getLabelValue();
			}

			return new DataResponse($result);
		} catch (NotPermittedException $e) {
			// Return 404 to avoid leaking file existence
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Set a single label on a file
	 *
	 * @param int $fileId The file ID
	 * @param string $key The label key
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function set(int $fileId, string $key): DataResponse {
		$value = $this->request->getParam('value', '');

		try {
			$label = $this->labelsService->setLabel($fileId, $key, $value);
			return new DataResponse($label->toArray());
		} catch (NotPermittedException $e) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Delete a label from a file
	 *
	 * @param int $fileId The file ID
	 * @param string $key The label key
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function delete(int $fileId, string $key): DataResponse {
		try {
			$deleted = $this->labelsService->deleteLabel($fileId, $key);
			if (!$deleted) {
				return new DataResponse(['error' => 'Label not found'], Http::STATUS_NOT_FOUND);
			}
			return new DataResponse(['success' => true]);
		} catch (NotPermittedException $e) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Get labels for multiple files (bulk GET)
	 *
	 * @return DataResponse Map of fileId => labels
	 */
	#[NoAdminRequired]
	public function bulk(): DataResponse {
		$fileIds = $this->request->getParam('fileIds', []);

		if (!is_array($fileIds)) {
			return new DataResponse(['error' => 'fileIds must be an array'], Http::STATUS_BAD_REQUEST);
		}

		if (empty($fileIds)) {
			return new DataResponse([]);
		}

		// Limit to prevent abuse
		if (count($fileIds) > 1000) {
			return new DataResponse(['error' => 'Too many file IDs (max 1000)'], Http::STATUS_BAD_REQUEST);
		}

		// Convert to integers
		$fileIds = array_map('intval', $fileIds);

		$labelsMap = $this->labelsService->getLabelsForFiles($fileIds);

		// Convert Label objects to key-value maps
		$result = [];
		foreach ($labelsMap as $fileId => $labels) {
			$result[$fileId] = [];
			foreach ($labels as $label) {
				$result[$fileId][$label->getLabelKey()] = $label->getLabelValue();
			}
		}

		return new DataResponse($result);
	}

	/**
	 * Bulk set labels on a file
	 *
	 * @param int $fileId The file ID
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function bulkSet(int $fileId): DataResponse {
		$labels = $this->request->getParam('labels', []);

		if (!is_array($labels)) {
			return new DataResponse(['error' => 'Labels must be an object'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->labelsService->setLabels($fileId, $labels);

			// Convert to key-value map
			$response = [];
			foreach ($result as $label) {
				$response[$label->getLabelKey()] = $label->getLabelValue();
			}

			return new DataResponse($response);
		} catch (NotPermittedException $e) {
			return new DataResponse(['error' => 'File not found'], Http::STATUS_NOT_FOUND);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
