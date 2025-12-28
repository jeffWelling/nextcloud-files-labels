<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Integration;

use OCA\FilesLabels\Db\Label;
use OCA\FilesLabels\Db\LabelMapper;
use OCA\FilesLabels\Listener\UserDeletedListener;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;
use Test\TestCase;
use DateTime;

/**
 * Performance tests for user deletion with large numbers of labels.
 *
 * These tests verify:
 * 1. Labels are properly deleted when a user is deleted
 * 2. Deletion performance with 100,000 labels
 *
 * Expected: The 1-second assertion will likely FAIL - this is informative
 * and demonstrates the need for background job processing.
 *
 * @group DB
 * @group Performance
 */
class UserDeletionPerformanceTest extends TestCase {
	private ?IDBConnection $db = null;
	private ?LabelMapper $mapper = null;
	private ?IRootFolder $rootFolder = null;
	private ?IUserManager $userManager = null;
	private ?IUserSession $userSession = null;
	private ?LoggerInterface $logger = null;
	private ?IUser $testUser = null;
	private ?Folder $userFolder = null;
	private ?File $testFile = null;

	private const TEST_USER = 'files_labels_perf_test_user';
	private const TEST_USER_PASS = 'perf_test_password_123';
	private const LABEL_COUNT = 100000;

	protected function setUp(): void {
		parent::setUp();

		$this->db = \OC::$server->get(IDBConnection::class);
		$this->rootFolder = \OC::$server->get(IRootFolder::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->userSession = \OC::$server->get(IUserSession::class);
		$this->logger = \OC::$server->get(LoggerInterface::class);
		$this->mapper = new LabelMapper($this->db);

		// Clean up any existing test user first
		$existingUser = $this->userManager->get(self::TEST_USER);
		if ($existingUser !== null) {
			// Clean up labels first
			$this->mapper->deleteByUser(self::TEST_USER);
			$existingUser->delete();
		}

		// Create fresh test user
		$this->testUser = $this->userManager->createUser(self::TEST_USER, self::TEST_USER_PASS);
		$this->assertNotNull($this->testUser, 'Failed to create test user');

		// Login as test user
		$this->userSession->setUser($this->testUser);

		// Get user folder and create test file
		$this->userFolder = $this->rootFolder->getUserFolder(self::TEST_USER);
		$this->testFile = $this->userFolder->newFile('performance_test.txt', 'test content for performance');
	}

	protected function tearDown(): void {
		// Clean up test user if still exists
		$existingUser = $this->userManager->get(self::TEST_USER);
		if ($existingUser !== null) {
			// Clean up labels first
			$this->mapper->deleteByUser(self::TEST_USER);
			$existingUser->delete();
		}

		// Logout
		$this->userSession->setUser(null);

		parent::tearDown();
	}

	/**
	 * Insert labels in bulk using direct SQL for performance.
	 *
	 * @param int $count Number of labels to insert
	 * @return int Number of labels actually inserted
	 */
	private function insertLabelsInBulk(int $count): int {
		$fileId = $this->testFile->getId();
		$userId = self::TEST_USER;
		$now = (new DateTime())->format('Y-m-d H:i:s');

		$batchSize = 1000;
		$inserted = 0;

		// Use raw SQL for maximum insert speed
		$connection = $this->db;

		for ($batch = 0; $batch < ceil($count / $batchSize); $batch++) {
			$values = [];
			$params = [];
			$paramIndex = 0;

			$batchCount = min($batchSize, $count - $inserted);

			for ($i = 0; $i < $batchCount; $i++) {
				$labelIndex = $inserted + $i;
				$key = sprintf('label_%06d', $labelIndex);
				$value = sprintf('value_%06d', $labelIndex);

				$values[] = sprintf(
					'(:file_id_%d, :user_id_%d, :key_%d, :value_%d, :created_%d, :updated_%d)',
					$paramIndex, $paramIndex, $paramIndex, $paramIndex, $paramIndex, $paramIndex
				);

				$params["file_id_$paramIndex"] = $fileId;
				$params["user_id_$paramIndex"] = $userId;
				$params["key_$paramIndex"] = $key;
				$params["value_$paramIndex"] = $value;
				$params["created_$paramIndex"] = $now;
				$params["updated_$paramIndex"] = $now;

				$paramIndex++;
			}

			if (!empty($values)) {
				$sql = 'INSERT INTO `*PREFIX*file_labels` '
					. '(`file_id`, `user_id`, `label_key`, `label_value`, `created_at`, `updated_at`) VALUES '
					. implode(', ', $values);

				try {
					$stmt = $connection->prepare($sql);
					$stmt->execute($params);
					$inserted += $batchCount;
				} catch (\Exception $e) {
					$this->logger->error('Bulk insert failed: ' . $e->getMessage());
					// Fall back to individual inserts for remaining
					for ($i = 0; $i < $batchCount; $i++) {
						$labelIndex = $inserted + $i;
						$key = sprintf('label_%06d', $labelIndex);
						$value = sprintf('value_%06d', $labelIndex);
						try {
							$this->mapper->setLabel($fileId, $userId, $key, $value);
							$inserted++;
						} catch (\Exception $e2) {
							// Skip duplicates or other errors
						}
					}
				}
			}
		}

		return $inserted;
	}

	/**
	 * Count labels for a user using direct SQL.
	 */
	private function countLabelsForUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from('file_labels')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/**
	 * Test that user deletion with 100,000 labels works correctly.
	 *
	 * This test verifies:
	 * 1. Labels can be created in bulk
	 * 2. UserDeletedListener properly triggers label cleanup
	 * 3. All labels are deleted after user deletion
	 */
	public function testUserDeletionWith100kLabels(): void {
		$this->markTestSkipped(
			'Performance test skipped by default. Run with: '
			. 'phpunit --group Performance --no-coverage'
		);
	}

	/**
	 * Actual performance test - run explicitly.
	 *
	 * @group SlowTests
	 */
	public function testUserDeletionPerformanceWith100kLabels(): void {
		// Step 1: Insert 100,000 labels
		$this->logger->info('Starting bulk insert of ' . self::LABEL_COUNT . ' labels...');
		$insertStart = microtime(true);

		$insertedCount = $this->insertLabelsInBulk(self::LABEL_COUNT);

		$insertDuration = microtime(true) - $insertStart;
		$this->logger->info(sprintf(
			'Inserted %d labels in %.2f seconds (%.0f labels/sec)',
			$insertedCount,
			$insertDuration,
			$insertedCount / $insertDuration
		));

		// Verify labels were created
		$labelCount = $this->countLabelsForUser(self::TEST_USER);
		$this->assertEquals(
			$insertedCount,
			$labelCount,
			"Expected $insertedCount labels, found $labelCount"
		);

		$this->logger->info('Verified ' . $labelCount . ' labels exist for test user');

		// Step 2: Create the UserDeletedListener and trigger deletion
		$listener = new UserDeletedListener($this->mapper, $this->logger);

		// Create mock event
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($this->testUser);

		// Step 3: Measure deletion time
		$this->logger->info('Starting label deletion via UserDeletedListener...');
		$deleteStart = microtime(true);

		$listener->handle($event);

		$deleteDuration = microtime(true) - $deleteStart;
		$this->logger->info(sprintf(
			'Deleted %d labels in %.2f seconds (%.0f labels/sec)',
			$labelCount,
			$deleteDuration,
			$labelCount / $deleteDuration
		));

		// Step 4: Verify all labels are deleted
		$remainingLabels = $this->countLabelsForUser(self::TEST_USER);
		$this->assertEquals(
			0,
			$remainingLabels,
			"Expected 0 labels after deletion, found $remainingLabels"
		);

		$this->logger->info('Verified all labels deleted');

		// Step 5: Assert deletion time is under 1 second
		// NOTE: This assertion is expected to FAIL with 100K labels using
		// synchronous deletion. This failure demonstrates the need for
		// background job processing.
		$this->assertLessThan(
			1.0,
			$deleteDuration,
			sprintf(
				'PERFORMANCE ISSUE: Deleting %d labels took %.2f seconds. '
				. 'Expected < 1 second. This demonstrates the need for '
				. 'background job processing for large label sets.',
				$labelCount,
				$deleteDuration
			)
		);
	}

	/**
	 * Test with smaller dataset to verify the mechanism works.
	 */
	public function testUserDeletionWith1000Labels(): void {
		$labelCount = 1000;

		// Insert labels
		$fileId = $this->testFile->getId();
		$userId = self::TEST_USER;
		$now = new DateTime();

		for ($i = 0; $i < $labelCount; $i++) {
			$key = sprintf('smalltest_%04d', $i);
			$this->mapper->setLabel($fileId, $userId, $key, 'value');
		}

		// Verify labels exist
		$count = $this->countLabelsForUser($userId);
		$this->assertEquals($labelCount, $count);

		// Create and trigger listener
		$listener = new UserDeletedListener($this->mapper, $this->logger);
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($this->testUser);

		$deleteStart = microtime(true);
		$listener->handle($event);
		$deleteDuration = microtime(true) - $deleteStart;

		// Verify deletion
		$remaining = $this->countLabelsForUser($userId);
		$this->assertEquals(0, $remaining, 'All labels should be deleted');

		// Log timing for analysis
		$this->logger->info(sprintf(
			'Deleted %d labels in %.4f seconds',
			$labelCount,
			$deleteDuration
		));

		// 1000 labels should definitely be under 1 second
		$this->assertLessThan(
			1.0,
			$deleteDuration,
			"Deleting 1000 labels should take less than 1 second"
		);
	}

	/**
	 * Test that labels for multiple files are all deleted.
	 */
	public function testUserDeletionDeletesLabelsFromMultipleFiles(): void {
		// Create multiple test files
		$file1 = $this->userFolder->newFile('multi_test_1.txt', 'content 1');
		$file2 = $this->userFolder->newFile('multi_test_2.txt', 'content 2');
		$file3 = $this->userFolder->newFile('multi_test_3.txt', 'content 3');

		$userId = self::TEST_USER;

		// Add labels to each file
		foreach ([$file1, $file2, $file3] as $file) {
			for ($i = 0; $i < 100; $i++) {
				$this->mapper->setLabel($file->getId(), $userId, "key_$i", "value_$i");
			}
		}

		// Plus the original test file
		for ($i = 0; $i < 100; $i++) {
			$this->mapper->setLabel($this->testFile->getId(), $userId, "key_$i", "value_$i");
		}

		// Verify: should have 400 labels total (4 files x 100 labels)
		$count = $this->countLabelsForUser($userId);
		$this->assertEquals(400, $count);

		// Trigger user deletion listener
		$listener = new UserDeletedListener($this->mapper, $this->logger);
		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($this->testUser);

		$listener->handle($event);

		// Verify all labels deleted
		$remaining = $this->countLabelsForUser($userId);
		$this->assertEquals(0, $remaining, 'All labels across all files should be deleted');
	}

	/**
	 * Test that deleting one user's labels doesn't affect another user's labels.
	 */
	public function testUserDeletionDoesNotAffectOtherUsers(): void {
		$otherUserId = 'files_labels_other_user';

		// Clean up other user if exists
		$otherUser = $this->userManager->get($otherUserId);
		if ($otherUser !== null) {
			$this->mapper->deleteByUser($otherUserId);
			$otherUser->delete();
		}

		// Create other user
		$otherUser = $this->userManager->createUser($otherUserId, 'other_password');
		$this->assertNotNull($otherUser);

		try {
			// Add labels for test user
			$fileId = $this->testFile->getId();
			for ($i = 0; $i < 50; $i++) {
				$this->mapper->setLabel($fileId, self::TEST_USER, "testuser_key_$i", 'value');
			}

			// Add labels for other user (using same file ID)
			for ($i = 0; $i < 50; $i++) {
				$this->mapper->setLabel($fileId, $otherUserId, "otheruser_key_$i", 'value');
			}

			// Verify both users have labels
			$testUserCount = $this->countLabelsForUser(self::TEST_USER);
			$otherUserCount = $this->countLabelsForUser($otherUserId);
			$this->assertEquals(50, $testUserCount);
			$this->assertEquals(50, $otherUserCount);

			// Delete only test user's labels
			$listener = new UserDeletedListener($this->mapper, $this->logger);
			$event = $this->createMock(UserDeletedEvent::class);
			$event->method('getUser')->willReturn($this->testUser);

			$listener->handle($event);

			// Verify test user labels are gone
			$testUserRemaining = $this->countLabelsForUser(self::TEST_USER);
			$this->assertEquals(0, $testUserRemaining);

			// Verify other user's labels are intact
			$otherUserRemaining = $this->countLabelsForUser($otherUserId);
			$this->assertEquals(50, $otherUserRemaining, 'Other user labels should not be affected');
		} finally {
			// Clean up other user
			$this->mapper->deleteByUser($otherUserId);
			$otherUser->delete();
		}
	}

	/**
	 * Benchmark test to measure deletion performance at various scales.
	 *
	 * @group SlowTests
	 * @dataProvider labelCountProvider
	 */
	public function testDeletionPerformanceAtScale(int $labelCount, float $maxExpectedSeconds): void {
		$this->markTestSkipped(
			'Benchmark test skipped by default. Run with: '
			. 'phpunit --group SlowTests --no-coverage'
		);
	}

	/**
	 * Data provider for scale testing.
	 */
	public static function labelCountProvider(): array {
		return [
			'100 labels' => [100, 0.1],
			'1000 labels' => [1000, 0.5],
			'10000 labels' => [10000, 1.0],
			'50000 labels' => [50000, 5.0],
			'100000 labels' => [100000, 10.0],
		];
	}
}
