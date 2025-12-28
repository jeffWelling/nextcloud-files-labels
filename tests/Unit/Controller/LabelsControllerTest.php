<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\Controller;

use OCA\FilesLabels\Controller\LabelsController;
use OCA\FilesLabels\Db\Label;
use OCA\FilesLabels\Service\LabelsService;
use OCP\AppFramework\Http;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use Test\TestCase;

class LabelsControllerTest extends TestCase {
	private LabelsController $controller;
	private LabelsService $service;
	private IRequest $request;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(LabelsService::class);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new LabelsController(
			'files_labels',
			$this->request,
			$this->service
		);
	}

	public function testIndexSuccess(): void {
		$fileId = 123;

		$label1 = new Label();
		$label1->setLabelKey('key1');
		$label1->setLabelValue('value1');

		$label2 = new Label();
		$label2->setLabelKey('key2');
		$label2->setLabelValue('value2');

		$this->service->method('getLabelsForFile')
			->with($fileId)
			->willReturn([$label1, $label2]);

		$response = $this->controller->index($fileId);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('key1', $data);
		$this->assertArrayHasKey('key2', $data);
		$this->assertEquals('value1', $data['key1']);
		$this->assertEquals('value2', $data['key2']);
	}

	public function testIndexNotPermitted(): void {
		$fileId = 123;

		$this->service->method('getLabelsForFile')
			->with($fileId)
			->willThrowException(new NotPermittedException());

		$response = $this->controller->index($fileId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('File not found', $data['error']);
	}

	public function testIndexEmptyLabels(): void {
		$fileId = 123;

		$this->service->method('getLabelsForFile')
			->with($fileId)
			->willReturn([]);

		$response = $this->controller->index($fileId);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertEmpty($data);
	}

	public function testSetSuccess(): void {
		$fileId = 123;
		$key = 'test.key';
		$value = 'test value';

		$label = new Label();
		$label->setId(1);
		$label->setFileId($fileId);
		$label->setLabelKey($key);
		$label->setLabelValue($value);

		$this->request->method('getParam')
			->with('value', '')
			->willReturn($value);

		$this->service->method('setLabel')
			->with($fileId, $key, $value)
			->willReturn($label);

		$response = $this->controller->set($fileId, $key);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertEquals(1, $data['id']);
		$this->assertEquals($fileId, $data['fileId']);
		$this->assertEquals($key, $data['key']);
		$this->assertEquals($value, $data['value']);
	}

	public function testSetWithEmptyValue(): void {
		$fileId = 123;
		$key = 'test.key';

		$label = new Label();
		$label->setLabelKey($key);
		$label->setLabelValue('');

		$this->request->method('getParam')
			->with('value', '')
			->willReturn('');

		$this->service->method('setLabel')
			->with($fileId, $key, '')
			->willReturn($label);

		$response = $this->controller->set($fileId, $key);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}

	public function testSetNotPermitted(): void {
		$fileId = 123;
		$key = 'test.key';

		$this->request->method('getParam')
			->willReturn('value');

		$this->service->method('setLabel')
			->willThrowException(new NotPermittedException());

		$response = $this->controller->set($fileId, $key);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('File not found', $data['error']);
	}

	public function testSetInvalidArgument(): void {
		$fileId = 123;
		$key = 'Invalid Key';

		$this->request->method('getParam')
			->willReturn('value');

		$this->service->method('setLabel')
			->willThrowException(new \InvalidArgumentException('Invalid key'));

		$response = $this->controller->set($fileId, $key);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('Invalid key', $data['error']);
	}

	public function testDeleteSuccess(): void {
		$fileId = 123;
		$key = 'test.key';

		$this->service->method('deleteLabel')
			->with($fileId, $key)
			->willReturn(true);

		$response = $this->controller->delete($fileId, $key);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('success', $data);
		$this->assertTrue($data['success']);
	}

	public function testDeleteNotFound(): void {
		$fileId = 123;
		$key = 'test.key';

		$this->service->method('deleteLabel')
			->with($fileId, $key)
			->willReturn(false);

		$response = $this->controller->delete($fileId, $key);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('Label not found', $data['error']);
	}

	public function testDeleteNotPermitted(): void {
		$fileId = 123;
		$key = 'test.key';

		$this->service->method('deleteLabel')
			->willThrowException(new NotPermittedException());

		$response = $this->controller->delete($fileId, $key);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('File not found', $data['error']);
	}

	public function testBulkSetSuccess(): void {
		$fileId = 123;
		$labels = [
			'key1' => 'value1',
			'key2' => 'value2',
		];

		$label1 = new Label();
		$label1->setLabelKey('key1');
		$label1->setLabelValue('value1');

		$label2 = new Label();
		$label2->setLabelKey('key2');
		$label2->setLabelValue('value2');

		$this->request->method('getParam')
			->with('labels', [])
			->willReturn($labels);

		$this->service->method('setLabels')
			->with($fileId, $labels)
			->willReturn([$label1, $label2]);

		$response = $this->controller->bulkSet($fileId);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('key1', $data);
		$this->assertArrayHasKey('key2', $data);
		$this->assertEquals('value1', $data['key1']);
		$this->assertEquals('value2', $data['key2']);
	}

	public function testBulkSetEmptyLabels(): void {
		$fileId = 123;

		$this->request->method('getParam')
			->with('labels', [])
			->willReturn([]);

		$this->service->method('setLabels')
			->with($fileId, [])
			->willReturn([]);

		$response = $this->controller->bulkSet($fileId);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertEmpty($data);
	}

	public function testBulkSetInvalidLabelsType(): void {
		$fileId = 123;

		$this->request->method('getParam')
			->with('labels', [])
			->willReturn('not an array');

		$response = $this->controller->bulkSet($fileId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('Labels must be an object', $data['error']);
	}

	public function testBulkSetNotPermitted(): void {
		$fileId = 123;
		$labels = ['key' => 'value'];

		$this->request->method('getParam')
			->willReturn($labels);

		$this->service->method('setLabels')
			->willThrowException(new NotPermittedException());

		$response = $this->controller->bulkSet($fileId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('File not found', $data['error']);
	}

	public function testBulkSetInvalidArgument(): void {
		$fileId = 123;
		$labels = ['Invalid Key' => 'value'];

		$this->request->method('getParam')
			->willReturn($labels);

		$this->service->method('setLabels')
			->willThrowException(new \InvalidArgumentException('Invalid key'));

		$response = $this->controller->bulkSet($fileId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('Invalid key', $data['error']);
	}

	// ==================== bulk (GET multiple files) Tests ====================

	public function testBulkSuccess(): void {
		$fileIds = [123, 456, 789];

		$label1 = new Label();
		$label1->setLabelKey('key1');
		$label1->setLabelValue('value1');

		$label2 = new Label();
		$label2->setLabelKey('key2');
		$label2->setLabelValue('value2');

		$this->request->method('getParam')
			->with('fileIds', [])
			->willReturn($fileIds);

		$this->service->method('getLabelsForFiles')
			->with($fileIds)
			->willReturn([
				123 => [$label1],
				456 => [$label2],
				789 => [],
			]);

		$response = $this->controller->bulk();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey(123, $data);
		$this->assertArrayHasKey(456, $data);
		$this->assertArrayHasKey(789, $data);
		$this->assertEquals(['key1' => 'value1'], $data[123]);
		$this->assertEquals(['key2' => 'value2'], $data[456]);
		$this->assertEquals([], $data[789]);
	}

	public function testBulkEmptyFileIds(): void {
		$this->request->method('getParam')
			->with('fileIds', [])
			->willReturn([]);

		$response = $this->controller->bulk();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertEmpty($data);
	}

	public function testBulkInvalidFileIdsType(): void {
		$this->request->method('getParam')
			->with('fileIds', [])
			->willReturn('not an array');

		$response = $this->controller->bulk();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('fileIds must be an array', $data['error']);
	}

	public function testBulkTooManyFileIds(): void {
		$fileIds = range(1, 1001); // 1001 IDs, exceeds limit

		$this->request->method('getParam')
			->with('fileIds', [])
			->willReturn($fileIds);

		$response = $this->controller->bulk();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());

		$data = $response->getData();
		$this->assertArrayHasKey('error', $data);
		$this->assertEquals('Too many file IDs (max 1000)', $data['error']);
	}

	public function testBulkConvertsToIntegers(): void {
		$fileIds = ['123', '456'];

		$this->request->method('getParam')
			->with('fileIds', [])
			->willReturn($fileIds);

		$this->service->expects($this->once())
			->method('getLabelsForFiles')
			->with([123, 456]) // Should be integers
			->willReturn([]);

		$response = $this->controller->bulk();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
	}
}
