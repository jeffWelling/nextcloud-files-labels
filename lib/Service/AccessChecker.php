<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Service;

use OCP\Constants;
use OCP\Files\IRootFolder;
use OCP\IUserSession;

/**
 * Service to check if the current user can access files for label operations.
 *
 * Follows the same pattern as comments and systemtags apps.
 */
class AccessChecker {
	public function __construct(
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Get the current user ID or null if not logged in
	 */
	public function getCurrentUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user?->getUID();
	}

	/**
	 * Check if current user can read labels for a file.
	 *
	 * Uses getFirstNodeById which is cached and fast (O(1) with cache hit).
	 */
	public function canRead(int $fileId): bool {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			return false;
		}

		try {
			$node = $this->rootFolder
				->getUserFolder($userId)
				->getFirstNodeById($fileId);
			return $node !== null;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Check if current user can write labels for a file.
	 *
	 * Must check PERMISSION_UPDATE on at least one access path.
	 */
	public function canWrite(int $fileId): bool {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			return false;
		}

		try {
			$nodes = $this->rootFolder
				->getUserFolder($userId)
				->getById($fileId);

			foreach ($nodes as $node) {
				if (($node->getPermissions() & Constants::PERMISSION_UPDATE) === Constants::PERMISSION_UPDATE) {
					return true;
				}
			}
			return false;
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Filter file IDs to only those accessible by current user.
	 *
	 * Use for bulk operations to avoid N+1 permission checks.
	 *
	 * @param int[] $fileIds
	 * @return int[] Accessible file IDs
	 */
	public function filterAccessible(array $fileIds): array {
		$userId = $this->getCurrentUserId();
		if ($userId === null) {
			return [];
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$accessible = [];

			foreach ($fileIds as $fileId) {
				if ($userFolder->getFirstNodeById($fileId) !== null) {
					$accessible[] = $fileId;
				}
			}

			return $accessible;
		} catch (\Exception $e) {
			return [];
		}
	}
}
