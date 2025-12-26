<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\Service;

use OCA\FilesLabels\Db\Label;
use OCA\FilesLabels\Db\LabelMapper;
use OCA\FilesLabels\Service\AccessChecker;
use OCA\FilesLabels\Service\LabelsService;
use OCP\Files\NotPermittedException;
use Test\TestCase;

class LabelsServiceTest extends TestCase {
	private LabelsService $service;
	private LabelMapper $mapper;
	private AccessChecker $accessChecker;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(LabelMapper::class);
		$this->accessChecker = $this->createMock(AccessChecker::class);

		$this->service = new LabelsService($this->mapper, $this->accessChecker);
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

		$longKey = str_repeat('a', 65);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Label key cannot exceed 64 characters');

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
			['with:colon'],
			['numbers123'],
			['mix-all_valid.chars:together'],
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
		];
	}

	public function testSetLabelValueTooLong(): void {
		$this->accessChecker->method('getCurrentUserId')
			->willReturn('testuser');

		$longValue = str_repeat('a', 4097);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Label value cannot exceed 4096 characters');

		$this->service->setLabel(123, 'key', $longValue);
	}

	public function testSetLabelValueMaxLength(): void {
		$fileId = 123;
		$userId = 'testuser';
		$maxValue = str_repeat('a', 4096);

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
}
