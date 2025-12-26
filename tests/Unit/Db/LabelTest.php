<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\Db;

use DateTime;
use OCA\FilesLabels\Db\Label;
use Test\TestCase;

class LabelTest extends TestCase {
	private Label $label;

	protected function setUp(): void {
		parent::setUp();
		$this->label = new Label();
	}

	public function testGetSetId(): void {
		$this->label->setId(42);
		$this->assertEquals(42, $this->label->getId());
	}

	public function testGetSetFileId(): void {
		$this->label->setFileId(123);
		$this->assertEquals(123, $this->label->getFileId());
	}

	public function testGetSetUserId(): void {
		$this->label->setUserId('testuser');
		$this->assertEquals('testuser', $this->label->getUserId());
	}

	public function testGetSetLabelKey(): void {
		$this->label->setLabelKey('test.key');
		$this->assertEquals('test.key', $this->label->getLabelKey());
	}

	public function testGetSetLabelValue(): void {
		$this->label->setLabelValue('test value');
		$this->assertEquals('test value', $this->label->getLabelValue());
	}

	public function testGetSetCreatedAt(): void {
		$now = new DateTime();
		$this->label->setCreatedAt($now);
		$this->assertEquals($now, $this->label->getCreatedAt());
	}

	public function testGetSetUpdatedAt(): void {
		$now = new DateTime();
		$this->label->setUpdatedAt($now);
		$this->assertEquals($now, $this->label->getUpdatedAt());
	}

	public function testToArray(): void {
		$now = new DateTime('2024-01-15 12:00:00');

		$this->label->setId(1);
		$this->label->setFileId(123);
		$this->label->setUserId('testuser');
		$this->label->setLabelKey('test.key');
		$this->label->setLabelValue('test value');
		$this->label->setCreatedAt($now);
		$this->label->setUpdatedAt($now);

		$array = $this->label->toArray();

		$this->assertEquals(1, $array['id']);
		$this->assertEquals(123, $array['fileId']);
		$this->assertEquals('test.key', $array['key']);
		$this->assertEquals('test value', $array['value']);
		$this->assertEquals($now->format('c'), $array['createdAt']);
		$this->assertEquals($now->format('c'), $array['updatedAt']);
	}

	public function testToArrayWithNullDates(): void {
		$this->label->setId(1);
		$this->label->setFileId(123);
		$this->label->setLabelKey('test.key');
		$this->label->setLabelValue('test value');

		$array = $this->label->toArray();

		$this->assertNull($array['createdAt']);
		$this->assertNull($array['updatedAt']);
	}

	public function testTypeConversion(): void {
		// Test that fileId is properly converted to integer
		$this->label->setFileId(123);
		$this->assertIsInt($this->label->getFileId());

		// Test DateTime type conversion
		$now = new DateTime();
		$this->label->setCreatedAt($now);
		$this->assertInstanceOf(DateTime::class, $this->label->getCreatedAt());
	}

	public function testEmptyValue(): void {
		$this->label->setLabelValue('');
		$this->assertEquals('', $this->label->getLabelValue());
	}

	public function testLongValue(): void {
		$longValue = str_repeat('a', 4096);
		$this->label->setLabelValue($longValue);
		$this->assertEquals($longValue, $this->label->getLabelValue());
	}

	public function testSpecialCharactersInKey(): void {
		$this->label->setLabelKey('test_key-with.special:chars');
		$this->assertEquals('test_key-with.special:chars', $this->label->getLabelKey());
	}

	public function testSpecialCharactersInValue(): void {
		$specialValue = 'Test with "quotes" and \'apostrophes\' and\nnewlines';
		$this->label->setLabelValue($specialValue);
		$this->assertEquals($specialValue, $this->label->getLabelValue());
	}

	public function testUnicodeInValue(): void {
		$unicodeValue = 'Test with émojis 🎉 and ñoñ-ASCII';
		$this->label->setLabelValue($unicodeValue);
		$this->assertEquals($unicodeValue, $this->label->getLabelValue());
	}
}
