<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\DAV;

use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\File;
use OCA\FilesLabels\DAV\LabelsPlugin;
use OCA\FilesLabels\Db\Label;
use OCA\FilesLabels\Service\LabelsService;
use OCP\Files\NotPermittedException;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\Xml\Service as XmlService;
use Test\TestCase;

class LabelsPluginTest extends TestCase {
	private LabelsPlugin $plugin;
	private LabelsService $service;
	private Server $server;
	private XmlService $xmlService;

	protected function setUp(): void {
		parent::setUp();

		$this->service = $this->createMock(LabelsService::class);
		$this->server = $this->createMock(Server::class);
		$this->xmlService = $this->createMock(XmlService::class);
		$this->xmlService->namespaceMap = [];

		$this->server->xml = $this->xmlService;

		$this->plugin = new LabelsPlugin($this->service);
	}

	public function testInitialize(): void {
		$this->server->expects($this->exactly(2))
			->method('on')
			->withConsecutive(
				['propFind', [$this->plugin, 'handleGetProperties']],
				['preloadCollection', [$this->plugin, 'preloadCollection']]
			);

		$this->plugin->initialize($this->server);

		$this->assertEquals('nc', $this->xmlService->namespaceMap[LabelsPlugin::NS_NEXTCLOUD]);
	}

	public function testHandleGetPropertiesWithFile(): void {
		$fileId = 123;
		$file = $this->createMock(File::class);
		$file->method('getId')
			->willReturn($fileId);

		$label1 = new Label();
		$label1->setLabelKey('key1');
		$label1->setLabelValue('value1');

		$label2 = new Label();
		$label2->setLabelKey('key2');
		$label2->setLabelValue('value2');

		$this->service->method('getLabelsForFile')
			->with($fileId)
			->willReturn([$label1, $label2]);

		$propFind = $this->createMock(PropFind::class);
		$propFind->expects($this->once())
			->method('handle')
			->willReturnCallback(function ($property, $callback) use ($file) {
				$this->assertEquals(LabelsPlugin::PROPERTY_LABELS, $property);
				$result = $callback($file);
				$this->assertIsString($result);
				$decoded = json_decode($result, true);
				$this->assertEquals(['key1' => 'value1', 'key2' => 'value2'], $decoded);
			});

		$this->plugin->handleGetProperties($propFind, $file);
	}

	public function testHandleGetPropertiesWithDirectory(): void {
		$fileId = 456;
		$directory = $this->createMock(Directory::class);
		$directory->method('getId')
			->willReturn($fileId);

		$this->service->method('getLabelsForFile')
			->with($fileId)
			->willReturn([]);

		$propFind = $this->createMock(PropFind::class);
		$propFind->expects($this->once())
			->method('handle')
			->willReturnCallback(function ($property, $callback) use ($directory) {
				$result = $callback($directory);
				$this->assertEquals('{}', $result);
			});

		$this->plugin->handleGetProperties($propFind, $directory);
	}

	public function testHandleGetPropertiesNotPermitted(): void {
		$fileId = 123;
		$file = $this->createMock(File::class);
		$file->method('getId')
			->willReturn($fileId);

		$this->service->method('getLabelsForFile')
			->with($fileId)
			->willThrowException(new NotPermittedException());

		$propFind = $this->createMock(PropFind::class);
		$propFind->expects($this->once())
			->method('handle')
			->willReturnCallback(function ($property, $callback) use ($file) {
				$result = $callback($file);
				$this->assertEquals('{}', $result);
			});

		$this->plugin->handleGetProperties($propFind, $file);
	}

	public function testHandleGetPropertiesNotANode(): void {
		$notANode = $this->createMock(\Sabre\DAV\INode::class);

		$propFind = $this->createMock(PropFind::class);
		$propFind->expects($this->never())
			->method('handle');

		$this->plugin->handleGetProperties($propFind, $notANode);
	}

	public function testPreloadCollectionWithDirectory(): void {
		$directoryId = 1;
		$fileId1 = 123;
		$fileId2 = 456;

		$directory = $this->createMock(Directory::class);
		$directory->method('getId')
			->willReturn($directoryId);
		$directory->method('getPath')
			->willReturn('/test/path');

		$file1 = $this->createMock(File::class);
		$file1->method('getId')
			->willReturn($fileId1);

		$file2 = $this->createMock(File::class);
		$file2->method('getId')
			->willReturn($fileId2);

		$directory->method('getChildren')
			->willReturn([$file1, $file2]);

		$propFind = $this->createMock(PropFind::class);
		$propFind->method('getStatus')
			->with(LabelsPlugin::PROPERTY_LABELS)
			->willReturn(404); // Property was requested

		$label1 = new Label();
		$label1->setFileId($fileId1);
		$label1->setLabelKey('key1');
		$label1->setLabelValue('value1');

		$this->service->method('getLabelsForFiles')
			->with([$directoryId, $fileId1, $fileId2])
			->willReturn([
				$fileId1 => [$label1],
				$fileId2 => [],
			]);

		$this->plugin->preloadCollection($propFind, $directory);

		// Now test that the cache is used
		$propFind2 = $this->createMock(PropFind::class);
		$propFind2->expects($this->once())
			->method('handle')
			->willReturnCallback(function ($property, $callback) use ($file1) {
				$result = $callback($file1);
				$decoded = json_decode($result, true);
				$this->assertEquals(['key1' => 'value1'], $decoded);
			});

		$this->plugin->handleGetProperties($propFind2, $file1);
	}

	public function testPreloadCollectionNotRequested(): void {
		$directory = $this->createMock(Directory::class);

		$propFind = $this->createMock(PropFind::class);
		$propFind->method('getStatus')
			->with(LabelsPlugin::PROPERTY_LABELS)
			->willReturn(null); // Property not requested

		$this->service->expects($this->never())
			->method('getLabelsForFiles');

		$this->plugin->preloadCollection($propFind, $directory);
	}

	public function testPreloadCollectionNotADirectory(): void {
		$file = $this->createMock(File::class);

		$propFind = $this->createMock(PropFind::class);

		$this->service->expects($this->never())
			->method('getLabelsForFiles');

		$this->plugin->preloadCollection($propFind, $file);
	}

	public function testPreloadCollectionCachedDirectory(): void {
		$directory = $this->createMock(Directory::class);
		$directory->method('getPath')
			->willReturn('/test/path');
		$directory->method('getId')
			->willReturn(1);

		$propFind = $this->createMock(PropFind::class);
		$propFind->method('getStatus')
			->with(LabelsPlugin::PROPERTY_LABELS)
			->willReturn(404);

		$directory->method('getChildren')
			->willReturn([]);

		$this->service->expects($this->once())
			->method('getLabelsForFiles')
			->willReturn([]);

		// First call should preload
		$this->plugin->preloadCollection($propFind, $directory);

		// Second call should skip (already cached)
		$this->plugin->preloadCollection($propFind, $directory);
	}

	public function testHandleGetPropertiesUsesCache(): void {
		$fileId = 123;
		$file = $this->createMock(File::class);
		$file->method('getId')
			->willReturn($fileId);

		// First populate cache via preload
		$directory = $this->createMock(Directory::class);
		$directory->method('getId')
			->willReturn(1);
		$directory->method('getPath')
			->willReturn('/test');
		$directory->method('getChildren')
			->willReturn([$file]);

		$propFind = $this->createMock(PropFind::class);
		$propFind->method('getStatus')
			->willReturn(404);

		$label = new Label();
		$label->setFileId($fileId);
		$label->setLabelKey('cached.key');
		$label->setLabelValue('cached value');

		$this->service->expects($this->once())
			->method('getLabelsForFiles')
			->willReturn([$fileId => [$label]]);

		$this->plugin->preloadCollection($propFind, $directory);

		// Now handleGetProperties should use cache, not call service again
		$this->service->expects($this->never())
			->method('getLabelsForFile');

		$propFind2 = $this->createMock(PropFind::class);
		$propFind2->expects($this->once())
			->method('handle')
			->willReturnCallback(function ($property, $callback) use ($file) {
				$result = $callback($file);
				$decoded = json_decode($result, true);
				$this->assertEquals(['cached.key' => 'cached value'], $decoded);
			});

		$this->plugin->handleGetProperties($propFind2, $file);
	}

	public function testHandleGetPropertiesEmptyLabels(): void {
		$fileId = 123;
		$file = $this->createMock(File::class);
		$file->method('getId')
			->willReturn($fileId);

		$this->service->method('getLabelsForFile')
			->with($fileId)
			->willReturn([]);

		$propFind = $this->createMock(PropFind::class);
		$propFind->expects($this->once())
			->method('handle')
			->willReturnCallback(function ($property, $callback) use ($file) {
				$result = $callback($file);
				$this->assertEquals('{}', $result);
			});

		$this->plugin->handleGetProperties($propFind, $file);
	}
}
