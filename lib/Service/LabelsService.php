<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Service;

use OCA\FilesLabels\Db\Label;
use OCA\FilesLabels\Db\LabelMapper;
use OCP\Files\NotPermittedException;

class LabelsService {
	// Valid label key pattern: lowercase alphanumeric, dots, dashes, underscores, colons
	private const KEY_PATTERN = '/^[a-z0-9_:.-]+$/';
	private const MAX_VALUE_LENGTH = 4096;

	public function __construct(
		private LabelMapper $mapper,
		private AccessChecker $accessChecker,
	) {
	}

	/**
	 * Get all labels for a file
	 *
	 * @return Label[]
	 * @throws NotPermittedException if user cannot access file
	 */
	public function getLabelsForFile(int $fileId): array {
		$userId = $this->accessChecker->getCurrentUserId();
		if ($userId === null) {
			throw new NotPermittedException('Not authenticated');
		}

		if (!$this->accessChecker->canRead($fileId)) {
			throw new NotPermittedException('Cannot access file');
		}

		return $this->mapper->findByFileAndUser($fileId, $userId);
	}

	/**
	 * Get labels for multiple files (bulk operation)
	 *
	 * @param int[] $fileIds
	 * @return array<int, Label[]> Map of fileId => labels (only accessible files)
	 */
	public function getLabelsForFiles(array $fileIds): array {
		$userId = $this->accessChecker->getCurrentUserId();
		if ($userId === null) {
			return [];
		}

		// Filter to accessible files first
		$accessibleIds = $this->accessChecker->filterAccessible($fileIds);
		if (empty($accessibleIds)) {
			return [];
		}

		return $this->mapper->findByFilesAndUser($accessibleIds, $userId);
	}

	/**
	 * Set a label on a file
	 *
	 * @throws NotPermittedException if user cannot write to file
	 * @throws \InvalidArgumentException if key or value is invalid
	 */
	public function setLabel(int $fileId, string $key, string $value): Label {
		$this->validateKey($key);
		$this->validateValue($value);

		$userId = $this->accessChecker->getCurrentUserId();
		if ($userId === null) {
			throw new NotPermittedException('Not authenticated');
		}

		if (!$this->accessChecker->canWrite($fileId)) {
			throw new NotPermittedException('Cannot modify file');
		}

		return $this->mapper->setLabel($fileId, $userId, $key, $value);
	}

	/**
	 * Set multiple labels on a file (bulk operation)
	 *
	 * @param array<string, string> $labels Map of key => value
	 * @return Label[]
	 * @throws NotPermittedException if user cannot write to file
	 * @throws \InvalidArgumentException if any key or value is invalid
	 */
	public function setLabels(int $fileId, array $labels): array {
		// Validate all first
		foreach ($labels as $key => $value) {
			$this->validateKey($key);
			$this->validateValue($value);
		}

		$userId = $this->accessChecker->getCurrentUserId();
		if ($userId === null) {
			throw new NotPermittedException('Not authenticated');
		}

		if (!$this->accessChecker->canWrite($fileId)) {
			throw new NotPermittedException('Cannot modify file');
		}

		$result = [];
		foreach ($labels as $key => $value) {
			$result[] = $this->mapper->setLabel($fileId, $userId, $key, $value);
		}

		return $result;
	}

	/**
	 * Delete a label from a file
	 *
	 * @throws NotPermittedException if user cannot write to file
	 */
	public function deleteLabel(int $fileId, string $key): bool {
		$userId = $this->accessChecker->getCurrentUserId();
		if ($userId === null) {
			throw new NotPermittedException('Not authenticated');
		}

		if (!$this->accessChecker->canWrite($fileId)) {
			throw new NotPermittedException('Cannot modify file');
		}

		return $this->mapper->deleteLabel($fileId, $userId, $key);
	}

	/**
	 * Find all files with a specific label
	 *
	 * @return int[] File IDs (only accessible files)
	 */
	public function findFilesByLabel(string $key, ?string $value = null): array {
		$userId = $this->accessChecker->getCurrentUserId();
		if ($userId === null) {
			return [];
		}

		$fileIds = $this->mapper->findFilesByLabel($userId, $key, $value);

		// Filter to accessible files
		return $this->accessChecker->filterAccessible($fileIds);
	}

	/**
	 * Check if a file has a specific label with a specific value
	 */
	public function hasLabel(int $fileId, string $key, ?string $value = null): bool {
		$userId = $this->accessChecker->getCurrentUserId();
		if ($userId === null) {
			return false;
		}

		if (!$this->accessChecker->canRead($fileId)) {
			return false;
		}

		$label = $this->mapper->findByFileUserAndKey($fileId, $userId, $key);
		if ($label === null) {
			return false;
		}

		if ($value !== null && $label->getLabelValue() !== $value) {
			return false;
		}

		return true;
	}

	/**
	 * Validate label key format
	 */
	private function validateKey(string $key): void {
		if (strlen($key) === 0) {
			throw new \InvalidArgumentException('Label key cannot be empty');
		}
		if (strlen($key) > 64) {
			throw new \InvalidArgumentException('Label key cannot exceed 64 characters');
		}
		if (!preg_match(self::KEY_PATTERN, $key)) {
			throw new \InvalidArgumentException('Label key must match pattern [a-z0-9_:.-]+');
		}
	}

	/**
	 * Validate label value
	 */
	private function validateValue(string $value): void {
		if (strlen($value) > self::MAX_VALUE_LENGTH) {
			throw new \InvalidArgumentException('Label value cannot exceed ' . self::MAX_VALUE_LENGTH . ' characters');
		}
	}
}
