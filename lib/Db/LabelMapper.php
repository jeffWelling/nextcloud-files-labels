<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use DateTime;

/**
 * @extends QBMapper<Label>
 */
class LabelMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'file_labels', Label::class);
	}

	/**
	 * Get all labels for a file for a specific user
	 *
	 * @return Label[]
	 */
	public function findByFileAndUser(int $fileId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntities($qb);
	}

	/**
	 * Get labels for multiple files for a specific user (bulk fetch)
	 *
	 * @param int[] $fileIds
	 * @return array<int, Label[]> Map of fileId => labels
	 */
	public function findByFilesAndUser(array $fileIds, string $userId): array {
		if (empty($fileIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('file_id', $qb->createNamedParameter($fileIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$labels = $this->findEntities($qb);

		// Group by file ID
		$result = [];
		foreach ($fileIds as $fileId) {
			$result[$fileId] = [];
		}
		foreach ($labels as $label) {
			$result[$label->getFileId()][] = $label;
		}

		return $result;
	}

	/**
	 * Get a specific label by file, user, and key
	 */
	public function findByFileUserAndKey(int $fileId, string $userId, string $key): ?Label {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('label_key', $qb->createNamedParameter($key)));

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find all files with a specific label key/value for a user
	 *
	 * @return int[] Array of file IDs
	 */
	public function findFilesByLabel(string $userId, string $key, ?string $value = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('label_key', $qb->createNamedParameter($key)));

		if ($value !== null) {
			$qb->andWhere($qb->expr()->eq('label_value', $qb->createNamedParameter($value)));
		}

		$result = $qb->executeQuery();
		$fileIds = [];
		while ($row = $result->fetch()) {
			$fileIds[] = (int)$row['file_id'];
		}
		$result->closeCursor();

		return $fileIds;
	}

	/**
	 * Set a label (insert or update)
	 */
	public function setLabel(int $fileId, string $userId, string $key, string $value): Label {
		$existing = $this->findByFileUserAndKey($fileId, $userId, $key);
		$now = new DateTime();

		if ($existing !== null) {
			$existing->setLabelValue($value);
			$existing->setUpdatedAt($now);
			return $this->update($existing);
		}

		$label = new Label();
		$label->setFileId($fileId);
		$label->setUserId($userId);
		$label->setLabelKey($key);
		$label->setLabelValue($value);
		$label->setCreatedAt($now);
		$label->setUpdatedAt($now);

		return $this->insert($label);
	}

	/**
	 * Delete a specific label
	 */
	public function deleteLabel(int $fileId, string $userId, string $key): bool {
		$label = $this->findByFileUserAndKey($fileId, $userId, $key);
		if ($label === null) {
			return false;
		}
		$this->delete($label);
		return true;
	}

	/**
	 * Delete all labels for a file (called when file is deleted)
	 */
	public function deleteByFile(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Delete all labels for a user (called when user is deleted)
	 */
	public function deleteByUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Count total labels for a user (for rate limiting)
	 */
	public function countByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Get the database connection for transactions
	 */
	public function getConnection(): IDBConnection {
		return $this->db;
	}
}
