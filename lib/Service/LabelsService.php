<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Service;

use OCA\FilesLabels\Db\Label;
use OCA\FilesLabels\Db\LabelMapper;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for managing file labels.
 *
 * Provides CRUD operations for user-specific file labels with:
 * - Access control validation
 * - Input validation
 * - Rate limiting
 * - Transactional bulk operations
 * - Structured logging
 */
class LabelsService {
	private const APP_ID = 'files_labels';

	// Valid label key pattern: lowercase alphanumeric, dots, dashes, underscores, colons
	private const KEY_PATTERN = '/^[a-z0-9_:.-]+$/';
	private const MAX_KEY_LENGTH = 255;
	// Value length limit imposed for UI aesthetics (sidebar display)
	private const MAX_VALUE_LENGTH = 255;

	// Rate limiting defaults
	private const DEFAULT_MAX_LABELS_PER_USER = 10000;
	private const CONFIG_KEY_MAX_LABELS = 'max_labels_per_user';

	public function __construct(
		private LabelMapper $mapper,
		private AccessChecker $accessChecker,
		private LoggerInterface $logger,
		private IConfig $config,
	) {
	}

	/**
	 * Get the maximum labels per user (configurable by admin)
	 */
	public function getMaxLabelsPerUser(): int {
		return (int)$this->config->getAppValue(
			self::APP_ID,
			self::CONFIG_KEY_MAX_LABELS,
			(string)self::DEFAULT_MAX_LABELS_PER_USER
		);
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

		$labels = $this->mapper->findByFileAndUser($fileId, $userId);

		$this->logger->debug('Retrieved labels for file', [
			'app' => self::APP_ID,
			'fileId' => $fileId,
			'userId' => $userId,
			'count' => count($labels),
		]);

		return $labels;
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

		$labelsMap = $this->mapper->findByFilesAndUser($accessibleIds, $userId);

		$this->logger->debug('Bulk retrieved labels for files', [
			'app' => self::APP_ID,
			'userId' => $userId,
			'requestedCount' => count($fileIds),
			'accessibleCount' => count($accessibleIds),
		]);

		return $labelsMap;
	}

	/**
	 * Set a label on a file
	 *
	 * @throws NotPermittedException if user cannot write to file
	 * @throws \InvalidArgumentException if key or value is invalid
	 * @throws \OverflowException if user has exceeded rate limit
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

		// Check rate limit (only for new labels)
		$existing = $this->mapper->findByFileUserAndKey($fileId, $userId, $key);
		if ($existing === null) {
			$this->checkRateLimit($userId);
		}

		$label = $this->mapper->setLabel($fileId, $userId, $key, $value);

		$this->logger->debug('Label set', [
			'app' => self::APP_ID,
			'fileId' => $fileId,
			'userId' => $userId,
			'key' => $key,
			'isNew' => $existing === null,
		]);

		return $label;
	}

	/**
	 * Set multiple labels on a file (bulk operation with transaction)
	 *
	 * @param array<string, string> $labels Map of key => value
	 * @return Label[]
	 * @throws NotPermittedException if user cannot write to file
	 * @throws \InvalidArgumentException if any key or value is invalid
	 * @throws \OverflowException if user would exceed rate limit
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

		// Count how many are truly new (not updates)
		$existingLabels = $this->mapper->findByFileAndUser($fileId, $userId);
		$existingKeys = [];
		foreach ($existingLabels as $label) {
			$existingKeys[$label->getLabelKey()] = true;
		}
		$newCount = 0;
		foreach (array_keys($labels) as $key) {
			if (!isset($existingKeys[$key])) {
				$newCount++;
			}
		}

		// Check rate limit for new labels
		if ($newCount > 0) {
			$this->checkRateLimit($userId, $newCount);
		}

		// Execute in transaction for atomicity
		$db = $this->mapper->getConnection();
		$result = [];

		$db->beginTransaction();
		try {
			foreach ($labels as $key => $value) {
				$result[] = $this->mapper->setLabel($fileId, $userId, $key, $value);
			}
			$db->commit();
		} catch (\Exception $e) {
			$db->rollBack();
			$this->logger->error('Bulk setLabels failed, rolled back', [
				'app' => self::APP_ID,
				'fileId' => $fileId,
				'userId' => $userId,
				'error' => $e->getMessage(),
			]);
			throw $e;
		}

		$this->logger->info('Bulk labels set', [
			'app' => self::APP_ID,
			'fileId' => $fileId,
			'userId' => $userId,
			'count' => count($labels),
			'newCount' => $newCount,
		]);

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

		$deleted = $this->mapper->deleteLabel($fileId, $userId, $key);

		$this->logger->debug('Label deleted', [
			'app' => self::APP_ID,
			'fileId' => $fileId,
			'userId' => $userId,
			'key' => $key,
			'success' => $deleted,
		]);

		return $deleted;
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
		$accessible = $this->accessChecker->filterAccessible($fileIds);

		$this->logger->debug('Found files by label', [
			'app' => self::APP_ID,
			'userId' => $userId,
			'key' => $key,
			'hasValue' => $value !== null,
			'foundCount' => count($fileIds),
			'accessibleCount' => count($accessible),
		]);

		return $accessible;
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
	 * Get current label count for a user
	 */
	public function getLabelCount(string $userId): int {
		return $this->mapper->countByUser($userId);
	}

	/**
	 * Check if user would exceed rate limit
	 *
	 * @throws \OverflowException if limit would be exceeded
	 */
	private function checkRateLimit(string $userId, int $newLabels = 1): void {
		$maxLabels = $this->getMaxLabelsPerUser();
		$currentCount = $this->mapper->countByUser($userId);

		if ($currentCount + $newLabels > $maxLabels) {
			$this->logger->warning('Rate limit exceeded', [
				'app' => self::APP_ID,
				'userId' => $userId,
				'currentCount' => $currentCount,
				'newLabels' => $newLabels,
				'maxLabels' => $maxLabels,
			]);
			throw new \OverflowException(
				"Label limit exceeded. You have $currentCount labels, maximum is $maxLabels."
			);
		}
	}

	/**
	 * Validate label key format
	 */
	private function validateKey(string $key): void {
		if (strlen($key) === 0) {
			throw new \InvalidArgumentException('Label key cannot be empty');
		}
		if (strlen($key) > self::MAX_KEY_LENGTH) {
			throw new \InvalidArgumentException('Label key cannot exceed ' . self::MAX_KEY_LENGTH . ' characters');
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
