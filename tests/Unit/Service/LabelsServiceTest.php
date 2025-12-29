<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\Service;

use OCA\FilesLabels\Db\Label;
use OCA\FilesLabels\Db\LabelMapper;
use OCA\FilesLabels\Service\AccessChecker;
use OCA\FilesLabels\Service\LabelsService;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class LabelsServiceTest extends TestCase {
	private LabelsService $service;
	private LabelMapper $mapper;
	private AccessChecker $accessChecker;
	private LoggerInterface $logger;
	private IConfig $config;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(LabelMapper::class);
		$this->accessChecker = $this->createMock(AccessChecker::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->config = $this->createMock(IConfig::class);

		// Default config: 10000 labels per user
		$this->config->method('getAppValue')
			->with('files_labels', 'max_labels_per_user', '10000')
			->willReturn('10000');

		$this->service = new LabelsService(
			$this->mapper,
			$this->accessChecker,
			$this->logger,
			$this->config
		);
	}

	/**
	 * Helper to create service with custom config
	 */
	private function createServiceWithMaxLabels(int $maxLabels): LabelsService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with('files_labels', 'max_labels_per_user', '10000')
			->willReturn((string)$maxLabels);

		return new LabelsService(
			$this->mapper,
			$this->accessChecker,
			$this->logger,
			$config
		);
	}

	public function testGetLabelsForFileSuccess(): void {
		$fileId = 123;
		$userId = 'testuser';

		$label1 = new Label();
		$label1->setLabelKey('key1');
		$label1->setLabelValue('value1');

		$label2 = new Label();
		$label2->setLabelKey('key2');
		$label2->setLabelValue('value2');

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canRead')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('findByFileAndUser')
			->with($fileId, $userId)
			->willReturn([$label1, $label2]);

		$result = $this->service->getLabelsForFile($fileId);

		$this->assertCount(2, $result);
		$this->assertEquals($label1, $result[0]);
		$this->assertEquals($label2, $result[1]);
	}

	public function testGetLabelsForFileNotAuthenticated(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn(null);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Not authenticated');

		$this->service->getLabelsForFile(123);
	}

	public function testGetLabelsForFileNoAccess(): void {
		$fileId = 123;

		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$this->accessChecker->method('canRead')
			->with($fileId)
			->willReturn(false);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Cannot access file');

		$this->service->getLabelsForFile($fileId);
	}

	public function testGetLabelsForFilesSuccess(): void {
		$fileIds = [123, 456];
		$userId = 'testuser';

		$label1 = new Label();
		$label1->setFileId(123);

		$label2 = new Label();
		$label2->setFileId(456);

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('filterAccessible')
			->with($fileIds)
			->willReturn($fileIds);

		$this->mapper->method('findByFilesAndUser')
			->with($fileIds, $userId)
			->willReturn([123 => [$label1], 456 => [$label2]]);

		$result = $this->service->getLabelsForFiles($fileIds);

		$this->assertCount(2, $result);
		$this->assertArrayHasKey(123, $result);
		$this->assertArrayHasKey(456, $result);
	}

	public function testGetLabelsForFilesNotAuthenticated(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn(null);

		$result = $this->service->getLabelsForFiles([123, 456]);

		$this->assertEquals([], $result);
	}

	public function testGetLabelsForFilesNoAccessibleFiles(): void {
		$fileIds = [123, 456];
		$userId = 'testuser';

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('filterAccessible')
			->with($fileIds)
			->willReturn([]);

		$result = $this->service->getLabelsForFiles($fileIds);

		$this->assertEquals([], $result);
	}

	public function testSetLabelSuccess(): void {
		$fileId = 123;
		$userId = 'testuser';
		$key = 'test.key';
		$value = 'test value';

		$label = new Label();
		$label->setFileId($fileId);
		$label->setUserId($userId);
		$label->setLabelKey($key);
		$label->setLabelValue($value);

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('setLabel')
			->with($fileId, $userId, $key, $value)
			->willReturn($label);

		$result = $this->service->setLabel($fileId, $key, $value);

		$this->assertEquals($label, $result);
	}

	public function testSetLabelNotAuthenticated(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn(null);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Not authenticated');

		$this->service->setLabel(123, 'key', 'value');
	}

	public function testSetLabelNoWritePermission(): void {
		$fileId = 123;

		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(false);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Cannot modify file');

		$this->service->setLabel($fileId, 'key', 'value');
	}

	public function testSetLabelInvalidKeyEmpty(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Label key cannot be empty');

		$this->service->setLabel(123, '', 'value');
	}

	public function testSetLabelInvalidKeyTooLong(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$longKey = str_repeat('a', 256);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Label key cannot exceed 255 characters');

		$this->service->setLabel(123, $longKey, 'value');
	}

	public function testSetLabelInvalidKeyPattern(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Label key must match pattern');

		$this->service->setLabel(123, 'Invalid Key!', 'value');
	}

	/**
	 * @dataProvider validKeyProvider
	 */
	public function testSetLabelValidKeys(string $key): void {
		$fileId = 123;
		$userId = 'testuser';

		$label = new Label();
		$label->setLabelKey($key);

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('setLabel')
			->willReturn($label);

		$result = $this->service->setLabel($fileId, $key, 'value');

		$this->assertEquals($key, $result->getLabelKey());
	}

	public static function validKeyProvider(): array {
		return [
			['lowercase'],
			['with-dash'],
			['with_underscore'],
			['with.dot'],
			['numbers123'],
			['mix-all_valid.chars'],
		];
	}

	/**
	 * @dataProvider invalidKeyProvider
	 */
	public function testSetLabelInvalidKeys(string $key): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->setLabel(123, $key, 'value');
	}

	public static function invalidKeyProvider(): array {
		return [
			['Has Space'],
			['UPPERCASE'],
			['special!char'],
			['has@symbol'],
			['has#hash'],
			['has/slash'],
			['has\\backslash'],
			['with:colon'],
		];
	}

	public function testSetLabelValueTooLong(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$longValue = str_repeat('a', 256);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Label value cannot exceed 255 characters');

		$this->service->setLabel(123, 'key', $longValue);
	}

	public function testSetLabelValueMaxLength(): void {
		$fileId = 123;
		$userId = 'testuser';
		$maxValue = str_repeat('a', 255);

		$label = new Label();
		$label->setLabelValue($maxValue);

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('setLabel')
			->willReturn($label);

		$result = $this->service->setLabel($fileId, 'key', $maxValue);

		$this->assertEquals($maxValue, $result->getLabelValue());
	}

	public function testSetLabelsSuccess(): void {
		$fileId = 123;
		$userId = 'testuser';
		$labels = [
			'key1' => 'value1',
			'key2' => 'value2',
		];

		$label1 = new Label();
		$label1->setLabelKey('key1');

		$label2 = new Label();
		$label2->setLabelKey('key2');

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(true);

		$this->mapper->expects($this->exactly(2))
			->method('setLabel')
			->willReturnOnConsecutiveCalls($label1, $label2);

		$result = $this->service->setLabels($fileId, $labels);

		$this->assertCount(2, $result);
	}

	public function testSetLabelsValidatesAllFirst(): void {
		$fileId = 123;
		$userId = 'testuser';
		$labels = [
			'key1' => 'value1',
			'Invalid Key' => 'value2',
		];

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(true);

		// Should throw before any setLabel calls
		$this->mapper->expects($this->never())
			->method('setLabel');

		$this->expectException(\InvalidArgumentException::class);

		$this->service->setLabels($fileId, $labels);
	}

	public function testDeleteLabelSuccess(): void {
		$fileId = 123;
		$userId = 'testuser';
		$key = 'test.key';

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('deleteLabel')
			->with($fileId, $userId, $key)
			->willReturn(true);

		$result = $this->service->deleteLabel($fileId, $key);

		$this->assertTrue($result);
	}

	public function testDeleteLabelNotFound(): void {
		$fileId = 123;
		$userId = 'testuser';
		$key = 'test.key';

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('deleteLabel')
			->with($fileId, $userId, $key)
			->willReturn(false);

		$result = $this->service->deleteLabel($fileId, $key);

		$this->assertFalse($result);
	}

	public function testDeleteLabelNotAuthenticated(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn(null);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Not authenticated');

		$this->service->deleteLabel(123, 'key');
	}

	public function testDeleteLabelNoWritePermission(): void {
		$fileId = 123;

		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$this->accessChecker->method('canWrite')
			->with($fileId)
			->willReturn(false);

		$this->expectException(NotPermittedException::class);
		$this->expectExceptionMessage('Cannot modify file');

		$this->service->deleteLabel($fileId, 'key');
	}

	public function testFindFilesByLabelSuccess(): void {
		$userId = 'testuser';
		$key = 'test.key';
		$value = 'test.value';
		$fileIds = [123, 456, 789];

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->mapper->method('findFilesByLabel')
			->with($userId, $key, $value)
			->willReturn($fileIds);

		$this->accessChecker->method('filterAccessible')
			->with($fileIds)
			->willReturn([123, 789]);

		$result = $this->service->findFilesByLabel($key, $value);

		$this->assertEquals([123, 789], $result);
	}

	public function testFindFilesByLabelNotAuthenticated(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn(null);

		$result = $this->service->findFilesByLabel('key', 'value');

		$this->assertEquals([], $result);
	}

	public function testHasLabelSuccess(): void {
		$fileId = 123;
		$userId = 'testuser';
		$key = 'test.key';
		$value = 'test.value';

		$label = new Label();
		$label->setLabelKey($key);
		$label->setLabelValue($value);

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canRead')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('findByFileUserAndKey')
			->with($fileId, $userId, $key)
			->willReturn($label);

		$result = $this->service->hasLabel($fileId, $key, $value);

		$this->assertTrue($result);
	}

	public function testHasLabelNotFound(): void {
		$fileId = 123;
		$userId = 'testuser';

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canRead')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('findByFileUserAndKey')
			->willReturn(null);

		$result = $this->service->hasLabel($fileId, 'key');

		$this->assertFalse($result);
	}

	public function testHasLabelWrongValue(): void {
		$fileId = 123;
		$userId = 'testuser';
		$key = 'test.key';

		$label = new Label();
		$label->setLabelKey($key);
		$label->setLabelValue('different value');

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canRead')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('findByFileUserAndKey')
			->with($fileId, $userId, $key)
			->willReturn($label);

		$result = $this->service->hasLabel($fileId, $key, 'expected value');

		$this->assertFalse($result);
	}

	public function testHasLabelNotAuthenticated(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn(null);

		$result = $this->service->hasLabel(123, 'key');

		$this->assertFalse($result);
	}

	public function testHasLabelNoReadAccess(): void {
		$fileId = 123;

		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$this->accessChecker->method('canRead')
			->with($fileId)
			->willReturn(false);

		$result = $this->service->hasLabel($fileId, 'key');

		$this->assertFalse($result);
	}

	public function testHasLabelWithoutValueCheck(): void {
		$fileId = 123;
		$userId = 'testuser';
		$key = 'test.key';

		$label = new Label();
		$label->setLabelKey($key);
		$label->setLabelValue('any value');

		$this->accessChecker->method('getCurrentUserId')
			->willReturn($userId);

		$this->accessChecker->method('canRead')
			->with($fileId)
			->willReturn(true);

		$this->mapper->method('findByFileUserAndKey')
			->with($fileId, $userId, $key)
			->willReturn($label);

		$result = $this->service->hasLabel($fileId, $key, null);

		$this->assertTrue($result);
	}

	// ==========================================
	// Rate Limiting Tests
	// ==========================================

	public function testGetMaxLabelsPerUserReturnsDefault(): void {
		$result = $this->service->getMaxLabelsPerUser();
		$this->assertEquals(10000, $result);
	}

	public function testGetMaxLabelsPerUserReturnsConfiguredValue(): void {
		$service = $this->createServiceWithMaxLabels(5000);
		$result = $service->getMaxLabelsPerUser();
		$this->assertEquals(5000, $result);
	}

	public function testGetMaxLabelsPerUserReturnsSmallValue(): void {
		$service = $this->createServiceWithMaxLabels(5);
		$result = $service->getMaxLabelsPerUser();
		$this->assertEquals(5, $result);
	}

	public function testGetLabelCountReturnsMapperCount(): void {
		$userId = 'testuser';

		$this->mapper->method('countByUser')
			->with($userId)
			->willReturn(42);

		$result = $this->service->getLabelCount($userId);
		$this->assertEquals(42, $result);
	}

	public function testGetLabelCountReturnsZeroForNewUser(): void {
		$userId = 'newuser';

		$this->mapper->method('countByUser')
			->with($userId)
			->willReturn(0);

		$result = $this->service->getLabelCount($userId);
		$this->assertEquals(0, $result);
	}

	// ==========================================
	// setLabel() Rate Limit Tests
	// ==========================================

	public function testSetLabelAllowedWhenUnderLimit(): void {
		$fileId = 123;
		$userId = 'testuser';

		$label = new Label();
		$label->setLabelKey('newkey');

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null); // New label
		$this->mapper->method('countByUser')->willReturn(9999); // Under 10000 limit
		$this->mapper->method('setLabel')->willReturn($label);

		// Should not throw
		$result = $this->service->setLabel($fileId, 'newkey', 'value');
		$this->assertEquals('newkey', $result->getLabelKey());
	}

	public function testSetLabelThrowsOverflowAtExactLimit(): void {
		$fileId = 123;
		$userId = 'testuser';

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null); // New label
		$this->mapper->method('countByUser')->willReturn(10000); // At limit

		$this->expectException(\OverflowException::class);
		$this->expectExceptionMessage('Label limit exceeded');

		$this->service->setLabel($fileId, 'newkey', 'value');
	}

	public function testSetLabelThrowsOverflowAboveLimit(): void {
		$fileId = 123;
		$userId = 'testuser';

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null); // New label
		$this->mapper->method('countByUser')->willReturn(15000); // Above limit

		$this->expectException(\OverflowException::class);

		$this->service->setLabel($fileId, 'newkey', 'value');
	}

	public function testSetLabelUpdatingExistingDoesNotCheckRateLimit(): void {
		$fileId = 123;
		$userId = 'testuser';

		$existingLabel = new Label();
		$existingLabel->setLabelKey('existingkey');

		$updatedLabel = new Label();
		$updatedLabel->setLabelKey('existingkey');
		$updatedLabel->setLabelValue('new value');

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn($existingLabel); // Existing!
		$this->mapper->method('setLabel')->willReturn($updatedLabel);

		// countByUser should NOT be called for updates
		$this->mapper->expects($this->never())->method('countByUser');

		$result = $this->service->setLabel($fileId, 'existingkey', 'new value');
		$this->assertEquals('existingkey', $result->getLabelKey());
	}

	public function testSetLabelWithCustomLimitOf5AllowsLabel5(): void {
		$fileId = 123;
		$userId = 'testuser';
		$service = $this->createServiceWithMaxLabels(5);

		$label = new Label();
		$label->setLabelKey('label5');

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null);
		$this->mapper->method('countByUser')->willReturn(4); // Has 4, adding 5th
		$this->mapper->method('setLabel')->willReturn($label);

		// Should succeed - 4 + 1 = 5, exactly at limit
		$result = $service->setLabel($fileId, 'label5', 'value');
		$this->assertEquals('label5', $result->getLabelKey());
	}

	public function testSetLabelWithCustomLimitOf5RejectsLabel6(): void {
		$fileId = 123;
		$userId = 'testuser';
		$service = $this->createServiceWithMaxLabels(5);

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null);
		$this->mapper->method('countByUser')->willReturn(5); // Already has 5

		$this->expectException(\OverflowException::class);
		$this->expectExceptionMessage('Label limit exceeded. You have 5 labels, maximum is 5.');

		$service->setLabel($fileId, 'label6', 'value');
	}

	// ==========================================
	// setLabels() Bulk Rate Limit Tests
	// ==========================================

	public function testSetLabelsBulkAllowedWhenUnderLimit(): void {
		$fileId = 123;
		$userId = 'testuser';

		$label1 = new Label();
		$label1->setLabelKey('key1');
		$label2 = new Label();
		$label2->setLabelKey('key2');

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileAndUser')->willReturn([]); // No existing
		$this->mapper->method('countByUser')->willReturn(9998); // Has 9998, adding 2 = 10000
		$this->mapper->method('setLabel')->willReturnOnConsecutiveCalls($label1, $label2);

		// Mock database transaction
		$db = $this->createMock(\OCP\IDBConnection::class);
		$this->mapper->method('getConnection')->willReturn($db);

		$result = $this->service->setLabels($fileId, ['key1' => 'v1', 'key2' => 'v2']);
		$this->assertCount(2, $result);
	}

	public function testSetLabelsBulkThrowsWhenExceedsLimit(): void {
		$fileId = 123;
		$userId = 'testuser';

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileAndUser')->willReturn([]); // No existing
		$this->mapper->method('countByUser')->willReturn(9999); // Has 9999, adding 2 = 10001

		$this->expectException(\OverflowException::class);
		$this->expectExceptionMessage('Label limit exceeded. You have 9999 labels, maximum is 10000.');

		$this->service->setLabels($fileId, ['key1' => 'v1', 'key2' => 'v2']);
	}

	public function testSetLabelsBulkCountsOnlyNewLabels(): void {
		$fileId = 123;
		$userId = 'testuser';

		// Existing label
		$existing = new Label();
		$existing->setLabelKey('existing');

		$label1 = new Label();
		$label1->setLabelKey('existing');
		$label2 = new Label();
		$label2->setLabelKey('newkey');

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileAndUser')->willReturn([$existing]); // Has 'existing'
		$this->mapper->method('countByUser')->willReturn(9999); // Has 9999
		$this->mapper->method('setLabel')->willReturnOnConsecutiveCalls($label1, $label2);

		// Mock database transaction
		$db = $this->createMock(\OCP\IDBConnection::class);
		$this->mapper->method('getConnection')->willReturn($db);

		// Should succeed: updating 'existing' (doesn't count) + adding 'newkey' (1 new)
		// 9999 + 1 = 10000, exactly at limit
		$result = $this->service->setLabels($fileId, [
			'existing' => 'updated',
			'newkey' => 'value',
		]);
		$this->assertCount(2, $result);
	}

	public function testSetLabelsBulkWithCustomLimitOf5(): void {
		$fileId = 123;
		$userId = 'testuser';
		$service = $this->createServiceWithMaxLabels(5);

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileAndUser')->willReturn([]);
		$this->mapper->method('countByUser')->willReturn(3); // Has 3, adding 3 = 6

		$this->expectException(\OverflowException::class);
		$this->expectExceptionMessage('Label limit exceeded. You have 3 labels, maximum is 5.');

		$service->setLabels($fileId, [
			'key1' => 'v1',
			'key2' => 'v2',
			'key3' => 'v3',
		]);
	}

	// ==========================================
	// Edge Cases & Boundary Tests
	// ==========================================

	public function testRateLimitExactlyAtBoundary10000(): void {
		$fileId = 123;
		$userId = 'testuser';

		$label = new Label();
		$label->setLabelKey('label10000');

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null);
		$this->mapper->method('countByUser')->willReturn(9999); // Adding gets to exactly 10000
		$this->mapper->method('setLabel')->willReturn($label);

		// Should succeed at exactly the limit
		$result = $this->service->setLabel($fileId, 'label10000', 'value');
		$this->assertEquals('label10000', $result->getLabelKey());
	}

	public function testRateLimitRejects10001(): void {
		$fileId = 123;
		$userId = 'testuser';

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null);
		$this->mapper->method('countByUser')->willReturn(10000); // Would be 10001

		$this->expectException(\OverflowException::class);

		$this->service->setLabel($fileId, 'label10001', 'value');
	}

	public function testRateLimitWithZeroExistingLabels(): void {
		$fileId = 123;
		$userId = 'newuser';
		$service = $this->createServiceWithMaxLabels(5);

		$label = new Label();
		$label->setLabelKey('firstlabel');

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null);
		$this->mapper->method('countByUser')->willReturn(0);
		$this->mapper->method('setLabel')->willReturn($label);

		// New user can add their first label
		$result = $service->setLabel($fileId, 'firstlabel', 'value');
		$this->assertEquals('firstlabel', $result->getLabelKey());
	}

	public function testRateLimitMessageIncludesCorrectCounts(): void {
		$fileId = 123;
		$userId = 'testuser';
		$service = $this->createServiceWithMaxLabels(100);

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null);
		$this->mapper->method('countByUser')->willReturn(100);

		try {
			$service->setLabel($fileId, 'overlimit', 'value');
			$this->fail('Expected OverflowException');
		} catch (\OverflowException $e) {
			$this->assertStringContainsString('100 labels', $e->getMessage());
			$this->assertStringContainsString('maximum is 100', $e->getMessage());
		}
	}

	/**
	 * @dataProvider rateLimitBoundaryProvider
	 */
	public function testRateLimitBoundaries(int $maxLabels, int $currentCount, bool $shouldSucceed): void {
		$fileId = 123;
		$userId = 'testuser';
		$service = $this->createServiceWithMaxLabels($maxLabels);

		$label = new Label();
		$label->setLabelKey('testkey');

		$this->accessChecker->method('getCurrentUserId')->willReturn($userId);
		$this->accessChecker->method('canWrite')->willReturn(true);
		$this->mapper->method('findByFileUserAndKey')->willReturn(null);
		$this->mapper->method('countByUser')->willReturn($currentCount);

		if ($shouldSucceed) {
			$this->mapper->method('setLabel')->willReturn($label);
			$result = $service->setLabel($fileId, 'testkey', 'value');
			$this->assertInstanceOf(Label::class, $result);
		} else {
			$this->expectException(\OverflowException::class);
			$service->setLabel($fileId, 'testkey', 'value');
		}
	}

	public static function rateLimitBoundaryProvider(): array {
		return [
			'limit 5, has 0, can add' => [5, 0, true],
			'limit 5, has 4, can add 5th' => [5, 4, true],
			'limit 5, has 5, cannot add 6th' => [5, 5, false],
			'limit 5, has 10, cannot add' => [5, 10, false],
			'limit 100, has 99, can add' => [100, 99, true],
			'limit 100, has 100, cannot add' => [100, 100, false],
			'limit 10000, has 9999, can add' => [10000, 9999, true],
			'limit 10000, has 10000, cannot add' => [10000, 10000, false],
			'limit 1, has 0, can add' => [1, 0, true],
			'limit 1, has 1, cannot add' => [1, 1, false],
		];
	}
}
