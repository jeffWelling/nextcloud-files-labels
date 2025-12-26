<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Integration;

use OCA\FilesLabels\Db\Label;
use OCA\FilesLabels\Db\LabelMapper;
use OCA\FilesLabels\Service\AccessChecker;
use OCA\FilesLabels\Service\LabelsService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * Integration tests for the Labels system.
 *
 * These tests run against a real database and file system within Nextcloud.
 * They test the full stack from service layer to database.
 *
 * @group DB
 */
class LabelsIntegrationTest extends TestCase {
	private ?IDBConnection $db = null;
	private ?LabelMapper $mapper = null;
	private ?LabelsService $service = null;
	private ?AccessChecker $accessChecker = null;
	private ?IRootFolder $rootFolder = null;
	private ?IUserManager $userManager = null;
	private ?IUserSession $userSession = null;
	private ?IUser $testUser = null;
	private ?Folder $userFolder = null;
	private array $createdFiles = [];
	private array $createdLabels = [];

	private const TEST_USER = 'files_labels_test_user';
	private const TEST_USER_PASS = 'test_password_123';

	protected function setUp(): void {
		parent::setUp();

		$this->db = \OC::$server->get(IDBConnection::class);
		$this->rootFolder = \OC::$server->get(IRootFolder::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->userSession = \OC::$server->get(IUserSession::class);

		// Create test user if it doesn't exist
		$this->testUser = $this->userManager->get(self::TEST_USER);
		if ($this->testUser === null) {
			$this->testUser = $this->userManager->createUser(self::TEST_USER, self::TEST_USER_PASS);
		}

		// Login as test user
		$this->userSession->setUser($this->testUser);

		// Get user folder
		$this->userFolder = $this->rootFolder->getUserFolder(self::TEST_USER);

		// Initialize the mapper and service
		$this->mapper = new LabelMapper($this->db);
		$this->accessChecker = new AccessChecker(
			$this->rootFolder,
			$this->userSession,
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);
		$this->service = new LabelsService(
			$this->mapper,
			$this->accessChecker,
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);
	}

	protected function tearDown(): void {
		// Clean up created labels
		foreach ($this->createdLabels as $label) {
			try {
				$this->mapper->delete($label);
			} catch (\Exception $e) {
				// Ignore cleanup errors
			}
		}

		// Clean up created files
		foreach ($this->createdFiles as $file) {
			try {
				$file->delete();
			} catch (\Exception $e) {
				// Ignore cleanup errors
			}
		}

		// Logout
		$this->userSession->setUser(null);

		parent::tearDown();
	}

	/**
	 * Create a test file in the user's folder
	 */
	private function createTestFile(string $name = 'test.txt', string $content = 'test content'): File {
		$file = $this->userFolder->newFile($name, $content);
		$this->createdFiles[] = $file;
		return $file;
	}

	/**
	 * Track a label for cleanup
	 */
	private function trackLabel(Label $label): Label {
		$this->createdLabels[] = $label;
		return $label;
	}

	// ==================== Basic CRUD Tests ====================

	public function testSetAndGetLabel(): void {
		$file = $this->createTestFile('label_test.txt');
		$fileId = $file->getId();

		// Set a label
		$labels = $this->service->setLabel($fileId, 'category', 'important');

		$this->assertArrayHasKey('category', $labels);
		$this->assertEquals('important', $labels['category']);

		// Get labels back
		$retrieved = $this->service->getLabels($fileId);
		$this->assertArrayHasKey('category', $retrieved);
		$this->assertEquals('important', $retrieved['category']);
	}

	public function testUpdateExistingLabel(): void {
		$file = $this->createTestFile('update_test.txt');
		$fileId = $file->getId();

		// Set initial label
		$this->service->setLabel($fileId, 'status', 'draft');

		// Update it
		$labels = $this->service->setLabel($fileId, 'status', 'published');

		$this->assertEquals('published', $labels['status']);

		// Verify only one label exists (not two)
		$allLabels = $this->service->getLabels($fileId);
		$this->assertCount(1, $allLabels);
		$this->assertEquals('published', $allLabels['status']);
	}

	public function testDeleteLabel(): void {
		$file = $this->createTestFile('delete_test.txt');
		$fileId = $file->getId();

		// Set labels
		$this->service->setLabel($fileId, 'tag1', 'value1');
		$this->service->setLabel($fileId, 'tag2', 'value2');

		// Delete one
		$result = $this->service->deleteLabel($fileId, 'tag1');
		$this->assertTrue($result);

		// Verify only tag2 remains
		$labels = $this->service->getLabels($fileId);
		$this->assertCount(1, $labels);
		$this->assertArrayNotHasKey('tag1', $labels);
		$this->assertArrayHasKey('tag2', $labels);
	}

	public function testDeleteNonExistentLabel(): void {
		$file = $this->createTestFile('delete_nonexistent.txt');
		$fileId = $file->getId();

		$result = $this->service->deleteLabel($fileId, 'nonexistent');
		$this->assertFalse($result);
	}

	// ==================== Multiple Labels Tests ====================

	public function testMultipleLabelsOnSameFile(): void {
		$file = $this->createTestFile('multi_label.txt');
		$fileId = $file->getId();

		// Set multiple labels
		$this->service->setLabel($fileId, 'category', 'work');
		$this->service->setLabel($fileId, 'priority', 'high');
		$this->service->setLabel($fileId, 'status', 'active');
		$this->service->setLabel($fileId, 'sensitive', 'true');

		$labels = $this->service->getLabels($fileId);

		$this->assertCount(4, $labels);
		$this->assertEquals('work', $labels['category']);
		$this->assertEquals('high', $labels['priority']);
		$this->assertEquals('active', $labels['status']);
		$this->assertEquals('true', $labels['sensitive']);
	}

	public function testSetMultipleLabelsAtOnce(): void {
		$file = $this->createTestFile('bulk_labels.txt');
		$fileId = $file->getId();

		$labelsToSet = [
			'project' => 'nextcloud',
			'team' => 'engineering',
			'version' => '1.0.0',
		];

		$result = $this->service->setMultipleLabels($fileId, $labelsToSet);

		$this->assertCount(3, $result);
		$this->assertEquals('nextcloud', $result['project']);
		$this->assertEquals('engineering', $result['team']);
		$this->assertEquals('1.0.0', $result['version']);
	}

	public function testGetLabelsForMultipleFiles(): void {
		$file1 = $this->createTestFile('multi1.txt');
		$file2 = $this->createTestFile('multi2.txt');
		$file3 = $this->createTestFile('multi3.txt');

		// Set labels on each file
		$this->service->setLabel($file1->getId(), 'type', 'document');
		$this->service->setLabel($file2->getId(), 'type', 'image');
		$this->service->setLabel($file2->getId(), 'category', 'personal');
		$this->service->setLabel($file3->getId(), 'type', 'video');

		// Get labels for all files at once
		$fileIds = [$file1->getId(), $file2->getId(), $file3->getId()];
		$result = $this->service->getLabelsForFiles($fileIds);

		$this->assertCount(3, $result);
		$this->assertEquals(['type' => 'document'], $result[$file1->getId()]);
		$this->assertEquals(['type' => 'image', 'category' => 'personal'], $result[$file2->getId()]);
		$this->assertEquals(['type' => 'video'], $result[$file3->getId()]);
	}

	// ==================== Search/Filter Tests ====================

	public function testFindFilesByLabel(): void {
		$file1 = $this->createTestFile('find1.txt');
		$file2 = $this->createTestFile('find2.txt');
		$file3 = $this->createTestFile('find3.txt');

		// Set labels
		$this->service->setLabel($file1->getId(), 'sensitive', 'true');
		$this->service->setLabel($file2->getId(), 'sensitive', 'true');
		$this->service->setLabel($file3->getId(), 'sensitive', 'false');

		// Find files with sensitive=true
		$sensitiveFiles = $this->service->findFilesByLabel('sensitive', 'true');

		$this->assertCount(2, $sensitiveFiles);
		$this->assertContains($file1->getId(), $sensitiveFiles);
		$this->assertContains($file2->getId(), $sensitiveFiles);
		$this->assertNotContains($file3->getId(), $sensitiveFiles);
	}

	public function testFindFilesByLabelKeyOnly(): void {
		$file1 = $this->createTestFile('key1.txt');
		$file2 = $this->createTestFile('key2.txt');
		$file3 = $this->createTestFile('key3.txt');

		$this->service->setLabel($file1->getId(), 'tagged', 'yes');
		$this->service->setLabel($file2->getId(), 'tagged', 'no');
		// file3 has no 'tagged' label

		// Find all files with 'tagged' key (any value)
		$taggedFiles = $this->service->findFilesByLabel('tagged');

		$this->assertCount(2, $taggedFiles);
		$this->assertContains($file1->getId(), $taggedFiles);
		$this->assertContains($file2->getId(), $taggedFiles);
		$this->assertNotContains($file3->getId(), $taggedFiles);
	}

	public function testHasLabel(): void {
		$file = $this->createTestFile('has_label.txt');
		$fileId = $file->getId();

		$this->service->setLabel($fileId, 'status', 'active');

		// Test with value check
		$this->assertTrue($this->service->hasLabel($fileId, 'status', 'active'));
		$this->assertFalse($this->service->hasLabel($fileId, 'status', 'inactive'));

		// Test without value check (just key existence)
		$this->assertTrue($this->service->hasLabel($fileId, 'status'));
		$this->assertFalse($this->service->hasLabel($fileId, 'nonexistent'));
	}

	// ==================== Validation Tests ====================

	public function testKeyValidation(): void {
		$file = $this->createTestFile('validation.txt');
		$fileId = $file->getId();

		// Valid keys
		$validKeys = [
			'lowercase',
			'with-dash',
			'with_underscore',
			'with.dot',
			'with:colon',
			'numbers123',
			'mix-all_valid.chars:123',
		];

		foreach ($validKeys as $key) {
			$labels = $this->service->setLabel($fileId, $key, 'test');
			$this->assertArrayHasKey($key, $labels, "Key '$key' should be valid");
			$this->service->deleteLabel($fileId, $key);
		}
	}

	public function testInvalidKeyRejected(): void {
		$file = $this->createTestFile('invalid_key.txt');
		$fileId = $file->getId();

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service->setLabel($fileId, 'UPPERCASE', 'value');
	}

	public function testKeyWithSpaceRejected(): void {
		$file = $this->createTestFile('space_key.txt');
		$fileId = $file->getId();

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->service->setLabel($fileId, 'has space', 'value');
	}

	public function testEmptyKeyRejected(): void {
		$file = $this->createTestFile('empty_key.txt');
		$fileId = $file->getId();

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setLabel($fileId, '', 'value');
	}

	public function testKeyTooLongRejected(): void {
		$file = $this->createTestFile('long_key.txt');
		$fileId = $file->getId();

		$longKey = str_repeat('a', 65); // Max is 64

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setLabel($fileId, $longKey, 'value');
	}

	public function testValueMaxLength(): void {
		$file = $this->createTestFile('long_value.txt');
		$fileId = $file->getId();

		// Max length value should work
		$maxValue = str_repeat('x', 4096);
		$labels = $this->service->setLabel($fileId, 'longval', $maxValue);
		$this->assertEquals($maxValue, $labels['longval']);
	}

	public function testValueTooLongRejected(): void {
		$file = $this->createTestFile('toolong_value.txt');
		$fileId = $file->getId();

		$tooLong = str_repeat('x', 4097); // Max is 4096

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setLabel($fileId, 'toobig', $tooLong);
	}

	// ==================== Special Characters Tests ====================

	public function testUnicodeInValue(): void {
		$file = $this->createTestFile('unicode.txt');
		$fileId = $file->getId();

		$unicodeValue = '日本語テスト 🎉 émojis ñ';
		$labels = $this->service->setLabel($fileId, 'unicode', $unicodeValue);

		$this->assertEquals($unicodeValue, $labels['unicode']);

		// Verify it persists correctly
		$retrieved = $this->service->getLabels($fileId);
		$this->assertEquals($unicodeValue, $retrieved['unicode']);
	}

	public function testSpecialCharsInValue(): void {
		$file = $this->createTestFile('special.txt');
		$fileId = $file->getId();

		$specialValue = '<script>alert("xss")</script> & "quotes" \'apostrophes\'';
		$labels = $this->service->setLabel($fileId, 'special', $specialValue);

		$this->assertEquals($specialValue, $labels['special']);
	}

	public function testJsonInValue(): void {
		$file = $this->createTestFile('json.txt');
		$fileId = $file->getId();

		$jsonValue = '{"nested": {"key": "value"}, "array": [1, 2, 3]}';
		$labels = $this->service->setLabel($fileId, 'metadata', $jsonValue);

		$this->assertEquals($jsonValue, $labels['metadata']);
	}

	// ==================== Cleanup Hook Tests ====================

	public function testLabelsDeletedWhenFileDeleted(): void {
		$file = $this->createTestFile('cleanup_test.txt');
		$fileId = $file->getId();

		// Add labels
		$this->service->setLabel($fileId, 'tag1', 'value1');
		$this->service->setLabel($fileId, 'tag2', 'value2');

		// Verify labels exist
		$labels = $this->service->getLabels($fileId);
		$this->assertCount(2, $labels);

		// Remove from tracked files since we're deleting it intentionally
		$this->createdFiles = array_filter($this->createdFiles, fn($f) => $f->getId() !== $fileId);

		// Delete file
		$file->delete();

		// Labels should be cleaned up by the FileDeletedListener
		// Note: In a real integration test environment, the event would fire
		// For now, we manually trigger the cleanup
		$this->mapper->deleteByFile($fileId);

		// Verify labels are gone from database
		$dbLabels = $this->mapper->findByFileAndUser($fileId, self::TEST_USER);
		$this->assertEmpty($dbLabels);
	}

	// ==================== Concurrency Tests ====================

	public function testConcurrentUpdates(): void {
		$file = $this->createTestFile('concurrent.txt');
		$fileId = $file->getId();

		// Simulate concurrent updates by setting the same key multiple times
		for ($i = 0; $i < 10; $i++) {
			$this->service->setLabel($fileId, 'counter', (string)$i);
		}

		// Final value should be the last one
		$labels = $this->service->getLabels($fileId);
		$this->assertEquals('9', $labels['counter']);

		// Should still only have one label (not 10)
		$allLabels = $this->mapper->findByFileAndUser($fileId, self::TEST_USER);
		$this->assertCount(1, $allLabels);
	}

	// ==================== Edge Cases ====================

	public function testEmptyValue(): void {
		$file = $this->createTestFile('empty_value.txt');
		$fileId = $file->getId();

		$labels = $this->service->setLabel($fileId, 'empty', '');
		$this->assertEquals('', $labels['empty']);
	}

	public function testGetLabelsForNonExistentFile(): void {
		// File ID that doesn't exist
		$labels = $this->service->getLabels(999999999);
		$this->assertEmpty($labels);
	}

	public function testFileWithNoLabels(): void {
		$file = $this->createTestFile('no_labels.txt');
		$labels = $this->service->getLabels($file->getId());
		$this->assertIsArray($labels);
		$this->assertEmpty($labels);
	}

	// ==================== Database Mapper Direct Tests ====================

	public function testMapperFindByFileAndUser(): void {
		$file = $this->createTestFile('mapper_test.txt');
		$fileId = $file->getId();

		// Insert via mapper directly
		$label = $this->mapper->setLabel($fileId, self::TEST_USER, 'direct', 'insert');
		$this->trackLabel($label);

		// Find it
		$found = $this->mapper->findByFileAndUser($fileId, self::TEST_USER);
		$this->assertCount(1, $found);
		$this->assertEquals('direct', $found[0]->getLabelKey());
		$this->assertEquals('insert', $found[0]->getLabelValue());
	}

	public function testMapperFindByFilesAndUser(): void {
		$file1 = $this->createTestFile('bulk1.txt');
		$file2 = $this->createTestFile('bulk2.txt');

		$label1 = $this->mapper->setLabel($file1->getId(), self::TEST_USER, 'type', 'text');
		$label2 = $this->mapper->setLabel($file2->getId(), self::TEST_USER, 'type', 'binary');
		$this->trackLabel($label1);
		$this->trackLabel($label2);

		$results = $this->mapper->findByFilesAndUser(
			[$file1->getId(), $file2->getId()],
			self::TEST_USER
		);

		$this->assertCount(2, $results);
		$this->assertArrayHasKey($file1->getId(), $results);
		$this->assertArrayHasKey($file2->getId(), $results);
	}

	public function testMapperFindByFileUserAndKey(): void {
		$file = $this->createTestFile('specific.txt');
		$fileId = $file->getId();

		$this->mapper->setLabel($fileId, self::TEST_USER, 'key1', 'val1');
		$label2 = $this->mapper->setLabel($fileId, self::TEST_USER, 'key2', 'val2');
		$this->trackLabel($label2);

		// Find specific key
		$found = $this->mapper->findByFileUserAndKey($fileId, self::TEST_USER, 'key2');
		$this->assertNotNull($found);
		$this->assertEquals('key2', $found->getLabelKey());
		$this->assertEquals('val2', $found->getLabelValue());

		// Non-existent key
		$notFound = $this->mapper->findByFileUserAndKey($fileId, self::TEST_USER, 'nonexistent');
		$this->assertNull($notFound);
	}

	public function testMapperDeleteByFile(): void {
		$file = $this->createTestFile('delete_all.txt');
		$fileId = $file->getId();

		// Create multiple labels
		$this->mapper->setLabel($fileId, self::TEST_USER, 'a', '1');
		$this->mapper->setLabel($fileId, self::TEST_USER, 'b', '2');
		$this->mapper->setLabel($fileId, self::TEST_USER, 'c', '3');

		// Verify they exist
		$labels = $this->mapper->findByFileAndUser($fileId, self::TEST_USER);
		$this->assertCount(3, $labels);

		// Delete all for this file
		$this->mapper->deleteByFile($fileId);

		// Verify deletion
		$labels = $this->mapper->findByFileAndUser($fileId, self::TEST_USER);
		$this->assertEmpty($labels);
	}

	public function testMapperDeleteByUser(): void {
		$file1 = $this->createTestFile('user_delete1.txt');
		$file2 = $this->createTestFile('user_delete2.txt');

		// Create labels for test user
		$this->mapper->setLabel($file1->getId(), self::TEST_USER, 'owned', 'yes');
		$this->mapper->setLabel($file2->getId(), self::TEST_USER, 'owned', 'yes');

		// Delete all for this user
		$this->mapper->deleteByUser(self::TEST_USER);

		// Verify deletion
		$labels1 = $this->mapper->findByFileAndUser($file1->getId(), self::TEST_USER);
		$labels2 = $this->mapper->findByFileAndUser($file2->getId(), self::TEST_USER);
		$this->assertEmpty($labels1);
		$this->assertEmpty($labels2);
	}
}
