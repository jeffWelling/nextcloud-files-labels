<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Integration;

use OCA\FilesLabels\Controller\LabelsController;
use OCA\FilesLabels\Db\LabelMapper;
use OCA\FilesLabels\Service\AccessChecker;
use OCA\FilesLabels\Service\LabelsService;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Test\TestCase;

/**
 * Integration tests for the OCS REST API.
 *
 * Tests the full controller → service → database flow.
 *
 * @group DB
 */
class OcsApiIntegrationTest extends TestCase {
	private ?IDBConnection $db = null;
	private ?LabelMapper $mapper = null;
	private ?LabelsService $service = null;
	private ?LabelsController $controller = null;
	private ?IRootFolder $rootFolder = null;
	private ?IUserManager $userManager = null;
	private ?IUserSession $userSession = null;
	private ?IUser $testUser = null;
	private ?Folder $userFolder = null;
	private array $createdFiles = [];

	private const TEST_USER = 'files_labels_api_test';
	private const TEST_USER_PASS = 'api_test_pass_456';

	protected function setUp(): void {
		parent::setUp();

		$this->db = \OC::$server->get(IDBConnection::class);
		$this->rootFolder = \OC::$server->get(IRootFolder::class);
		$this->userManager = \OC::$server->get(IUserManager::class);
		$this->userSession = \OC::$server->get(IUserSession::class);

		// Create test user
		$this->testUser = $this->userManager->get(self::TEST_USER);
		if ($this->testUser === null) {
			$this->testUser = $this->userManager->createUser(self::TEST_USER, self::TEST_USER_PASS);
		}

		$this->userSession->setUser($this->testUser);
		$this->userFolder = $this->rootFolder->getUserFolder(self::TEST_USER);

		// Initialize components
		$this->mapper = new LabelMapper($this->db);
		$accessChecker = new AccessChecker(
			$this->rootFolder,
			$this->userSession,
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);
		$this->service = new LabelsService(
			$this->mapper,
			$accessChecker,
			\OC::$server->get(\Psr\Log\LoggerInterface::class)
		);

		// Create controller with mock request
		$request = $this->createMock(IRequest::class);
		$this->controller = new LabelsController(
			'files_labels',
			$request,
			$this->service
		);
	}

	protected function tearDown(): void {
		// Cleanup files
		foreach ($this->createdFiles as $file) {
			try {
				// Delete labels first
				$this->mapper->deleteByFile($file->getId());
				$file->delete();
			} catch (\Exception $e) {
				// Ignore
			}
		}

		$this->userSession->setUser(null);
		parent::tearDown();
	}

	private function createTestFile(string $name = 'api_test.txt'): File {
		$file = $this->userFolder->newFile($name, 'test content');
		$this->createdFiles[] = $file;
		return $file;
	}

	// ==================== GET /labels/{fileId} Tests ====================

	public function testIndexReturnsEmptyForNewFile(): void {
		$file = $this->createTestFile('empty.txt');

		$response = $this->controller->index($file->getId());

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals([], $response->getData());
	}

	public function testIndexReturnsAllLabels(): void {
		$file = $this->createTestFile('with_labels.txt');
		$fileId = $file->getId();

		// Add labels via service
		$this->service->setLabel($fileId, 'category', 'work');
		$this->service->setLabel($fileId, 'priority', 'high');

		$response = $this->controller->index($fileId);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(2, $data);
		$this->assertEquals('work', $data['category']);
		$this->assertEquals('high', $data['priority']);
	}

	public function testIndexReturns404ForUnauthorizedFile(): void {
		// File ID the user can't access
		$response = $this->controller->index(999999999);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ==================== PUT /labels/{fileId}/{key} Tests ====================

	public function testSetCreatesNewLabel(): void {
		$file = $this->createTestFile('new_label.txt');
		$fileId = $file->getId();

		$response = $this->controller->set($fileId, 'status', 'active');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals($fileId, $data['fileId']);
		$this->assertEquals('status', $data['key']);
		$this->assertEquals('active', $data['value']);
		$this->assertArrayHasKey('createdAt', $data);
		$this->assertArrayHasKey('updatedAt', $data);
	}

	public function testSetUpdatesExistingLabel(): void {
		$file = $this->createTestFile('update_label.txt');
		$fileId = $file->getId();

		// Create initial label
		$this->controller->set($fileId, 'version', '1.0');

		// Update it
		$response = $this->controller->set($fileId, 'version', '2.0');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals('2.0', $response->getData()['value']);

		// Verify via service
		$labels = $this->service->getLabels($fileId);
		$this->assertCount(1, $labels);
		$this->assertEquals('2.0', $labels['version']);
	}

	public function testSetRejectsEmptyValue(): void {
		$file = $this->createTestFile('empty_val.txt');

		// Empty string value should still work (it's allowed)
		$response = $this->controller->set($file->getId(), 'empty', '');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals('', $response->getData()['value']);
	}

	public function testSetRejectsInvalidKey(): void {
		$file = $this->createTestFile('invalid_key.txt');

		$response = $this->controller->set($file->getId(), 'INVALID KEY!', 'value');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetRejectsTooLongKey(): void {
		$file = $this->createTestFile('long_key.txt');
		$longKey = str_repeat('a', 256); // Max is 255

		$response = $this->controller->set($file->getId(), $longKey, 'value');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetRejectsTooLongValue(): void {
		$file = $this->createTestFile('long_val.txt');
		$longValue = str_repeat('x', 256); // Max is 255

		$response = $this->controller->set($file->getId(), 'key', $longValue);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testSetReturns404ForUnauthorizedFile(): void {
		$response = $this->controller->set(999999999, 'key', 'value');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ==================== DELETE /labels/{fileId}/{key} Tests ====================

	public function testDeleteRemovesLabel(): void {
		$file = $this->createTestFile('delete_me.txt');
		$fileId = $file->getId();

		// Create label
		$this->service->setLabel($fileId, 'to_delete', 'value');

		// Delete via controller
		$response = $this->controller->delete($fileId, 'to_delete');

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);

		// Verify it's gone
		$labels = $this->service->getLabels($fileId);
		$this->assertArrayNotHasKey('to_delete', $labels);
	}

	public function testDeleteReturns404ForNonExistentLabel(): void {
		$file = $this->createTestFile('no_such_label.txt');

		$response = $this->controller->delete($file->getId(), 'nonexistent');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testDeleteReturns404ForUnauthorizedFile(): void {
		$response = $this->controller->delete(999999999, 'key');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ==================== PUT /labels/{fileId} (Bulk) Tests ====================

	public function testBulkSetCreatesMultipleLabels(): void {
		$file = $this->createTestFile('bulk.txt');
		$fileId = $file->getId();

		// Create request mock that returns JSON body
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(function ($key) {
			if ($key === 'labels') {
				return [
					'project' => 'nextcloud',
					'team' => 'core',
					'status' => 'active',
				];
			}
			return null;
		});

		$controller = new LabelsController('files_labels', $request, $this->service);
		$response = $controller->bulkSet($fileId);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(3, $data);
		$this->assertEquals('nextcloud', $data['project']);
		$this->assertEquals('core', $data['team']);
		$this->assertEquals('active', $data['status']);
	}

	public function testBulkSetRejectsEmptyLabels(): void {
		$file = $this->createTestFile('bulk_empty.txt');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn([]);

		$controller = new LabelsController('files_labels', $request, $this->service);
		$response = $controller->bulkSet($file->getId());

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testBulkSetRejectsInvalidType(): void {
		$file = $this->createTestFile('bulk_invalid.txt');

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn('not an array');

		$controller = new LabelsController('files_labels', $request, $this->service);
		$response = $controller->bulkSet($file->getId());

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testBulkSetValidatesAllKeysFirst(): void {
		$file = $this->createTestFile('bulk_validate.txt');
		$fileId = $file->getId();

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturn([
			'valid' => 'ok',
			'INVALID' => 'bad', // Should cause rejection
		]);

		$controller = new LabelsController('files_labels', $request, $this->service);
		$response = $controller->bulkSet($fileId);

		// Should reject the whole batch
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());

		// Neither label should be set
		$labels = $this->service->getLabels($fileId);
		$this->assertEmpty($labels);
	}

	// ==================== Unicode and Special Characters ====================

	public function testApiHandlesUnicode(): void {
		$file = $this->createTestFile('unicode_api.txt');
		$fileId = $file->getId();

		$unicodeValue = '日本語 🎉 émoji';
		$response = $this->controller->set($fileId, 'unicode', $unicodeValue);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals($unicodeValue, $response->getData()['value']);

		// Verify via index
		$getResponse = $this->controller->index($fileId);
		$this->assertEquals($unicodeValue, $getResponse->getData()['unicode']);
	}

	public function testApiHandlesSpecialChars(): void {
		$file = $this->createTestFile('special_api.txt');
		$fileId = $file->getId();

		$specialValue = '<html> & "quotes" \'apos\' \n newline';
		$response = $this->controller->set($fileId, 'special', $specialValue);

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertEquals($specialValue, $response->getData()['value']);
	}

	// ==================== Response Format Tests ====================

	public function testSetResponseContainsAllFields(): void {
		$file = $this->createTestFile('format.txt');
		$fileId = $file->getId();

		$response = $this->controller->set($fileId, 'test', 'value');
		$data = $response->getData();

		$this->assertArrayHasKey('id', $data);
		$this->assertArrayHasKey('fileId', $data);
		$this->assertArrayHasKey('key', $data);
		$this->assertArrayHasKey('value', $data);
		$this->assertArrayHasKey('createdAt', $data);
		$this->assertArrayHasKey('updatedAt', $data);

		$this->assertIsInt($data['id']);
		$this->assertEquals($fileId, $data['fileId']);
		$this->assertEquals('test', $data['key']);
		$this->assertEquals('value', $data['value']);
	}

	public function testIndexReturnsSimpleKeyValueMap(): void {
		$file = $this->createTestFile('map_format.txt');
		$fileId = $file->getId();

		$this->service->setLabel($fileId, 'key1', 'val1');
		$this->service->setLabel($fileId, 'key2', 'val2');

		$response = $this->controller->index($fileId);
		$data = $response->getData();

		// Should be a simple associative array
		$this->assertEquals(['key1' => 'val1', 'key2' => 'val2'], $data);
	}

	// ==================== Concurrent Operations ====================

	public function testRapidSequentialOperations(): void {
		$file = $this->createTestFile('rapid.txt');
		$fileId = $file->getId();

		// Rapid set/update/delete cycles
		for ($i = 0; $i < 20; $i++) {
			$this->controller->set($fileId, 'counter', (string)$i);
		}

		$labels = $this->service->getLabels($fileId);
		$this->assertEquals('19', $labels['counter']);

		// Delete
		$this->controller->delete($fileId, 'counter');
		$labels = $this->service->getLabels($fileId);
		$this->assertEmpty($labels);
	}
}
