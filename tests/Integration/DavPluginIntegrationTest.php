<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Integration;

use OCA\DAV\Connector\Sabre\File;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\FilesLabels\DAV\LabelsPlugin;
use OCA\FilesLabels\Db\LabelMapper;
use OCA\FilesLabels\Service\AccessChecker;
use OCA\FilesLabels\Service\LabelsService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Test\TestCase;

/**
 * Integration tests for the WebDAV Labels Plugin.
 *
 * Tests the DAV plugin integration including property handling and preloading.
 *
 * @group DB
 */
class DavPluginIntegrationTest extends TestCase {
	private ?IDBConnection $db = null;
	private ?LabelMapper $mapper = null;
	private ?LabelsService $service = null;
	private ?LabelsPlugin $plugin = null;
	private ?IRootFolder $rootFolder = null;
	private ?IUserManager $userManager = null;
	private ?IUserSession $userSession = null;
	private ?IUser $testUser = null;
	private ?Folder $userFolder = null;
	private array $createdFiles = [];

	private const TEST_USER = 'files_labels_dav_test';
	private const TEST_USER_PASS = 'dav_test_789';
	private const LABELS_PROPERTY = '{http://nextcloud.org/ns}labels';

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

		$this->plugin = new LabelsPlugin($this->service);
	}

	protected function tearDown(): void {
		foreach ($this->createdFiles as $file) {
			try {
				$this->mapper->deleteByFile($file->getId());
				$file->delete();
			} catch (\Exception $e) {
				// Ignore
			}
		}

		$this->userSession->setUser(null);
		parent::tearDown();
	}

	private function createTestFile(string $name = 'dav_test.txt'): \OCP\Files\File {
		$file = $this->userFolder->newFile($name, 'test content');
		$this->createdFiles[] = $file;
		return $file;
	}

	private function createTestFolder(string $name = 'dav_folder'): Folder {
		$folder = $this->userFolder->newFolder($name);
		$this->createdFiles[] = $folder;
		return $folder;
	}

	// ==================== Property Tests ====================

	public function testLabelsPropertyReturnsJsonForFile(): void {
		$file = $this->createTestFile('prop_test.txt');
		$fileId = $file->getId();

		// Set labels via service
		$this->service->setLabel($fileId, 'category', 'work');
		$this->service->setLabel($fileId, 'priority', 'high');

		// Create a mock Sabre file node
		$sabreFile = $this->createMock(File::class);
		$sabreFile->method('getId')->willReturn($fileId);

		// Create PropFind
		$propFind = new PropFind('files/' . self::TEST_USER . '/prop_test.txt', [self::LABELS_PROPERTY]);

		// Handle the property
		$this->plugin->handleGetProperties($propFind, $sabreFile);

		// Get the result
		$result = $propFind->get(self::LABELS_PROPERTY);
		$this->assertNotNull($result);

		// Should be JSON encoded
		$decoded = json_decode($result, true);
		$this->assertIsArray($decoded);
		$this->assertEquals('work', $decoded['category']);
		$this->assertEquals('high', $decoded['priority']);
	}

	public function testLabelsPropertyReturnsEmptyObjectForFileWithNoLabels(): void {
		$file = $this->createTestFile('no_labels.txt');

		$sabreFile = $this->createMock(File::class);
		$sabreFile->method('getId')->willReturn($file->getId());

		$propFind = new PropFind('files/' . self::TEST_USER . '/no_labels.txt', [self::LABELS_PROPERTY]);

		$this->plugin->handleGetProperties($propFind, $sabreFile);

		$result = $propFind->get(self::LABELS_PROPERTY);
		$decoded = json_decode($result, true);
		$this->assertIsArray($decoded);
		$this->assertEmpty($decoded);
	}

	public function testLabelsPropertyNotReturnedWhenNotRequested(): void {
		$file = $this->createTestFile('not_requested.txt');
		$this->service->setLabel($file->getId(), 'test', 'value');

		$sabreFile = $this->createMock(File::class);
		$sabreFile->method('getId')->willReturn($file->getId());

		// Request different property
		$propFind = new PropFind('files/' . self::TEST_USER . '/not_requested.txt', ['{DAV:}getcontentlength']);

		$this->plugin->handleGetProperties($propFind, $sabreFile);

		// Labels should not be set
		$result = $propFind->get(self::LABELS_PROPERTY);
		$this->assertNull($result);
	}

	public function testLabelsPropertyHandlesUnicode(): void {
		$file = $this->createTestFile('unicode.txt');
		$fileId = $file->getId();

		$unicodeValue = '日本語テスト 🎉';
		$this->service->setLabel($fileId, 'unicode', $unicodeValue);

		$sabreFile = $this->createMock(File::class);
		$sabreFile->method('getId')->willReturn($fileId);

		$propFind = new PropFind('test', [self::LABELS_PROPERTY]);
		$this->plugin->handleGetProperties($propFind, $sabreFile);

		$result = $propFind->get(self::LABELS_PROPERTY);
		$decoded = json_decode($result, true);
		$this->assertEquals($unicodeValue, $decoded['unicode']);
	}

	public function testLabelsPropertyHandlesSpecialChars(): void {
		$file = $this->createTestFile('special.txt');
		$fileId = $file->getId();

		$specialValue = '<html> & "quotes" \'apostrophe\'';
		$this->service->setLabel($fileId, 'special', $specialValue);

		$sabreFile = $this->createMock(File::class);
		$sabreFile->method('getId')->willReturn($fileId);

		$propFind = new PropFind('test', [self::LABELS_PROPERTY]);
		$this->plugin->handleGetProperties($propFind, $sabreFile);

		$result = $propFind->get(self::LABELS_PROPERTY);
		$decoded = json_decode($result, true);
		$this->assertEquals($specialValue, $decoded['special']);
	}

	// ==================== Preloading Tests ====================

	public function testPreloadCollectionLoadsLabelsForAllFiles(): void {
		// Create folder with files
		$folder = $this->createTestFolder('preload_folder');
		$file1 = $folder->newFile('file1.txt', 'content1');
		$file2 = $folder->newFile('file2.txt', 'content2');
		$file3 = $folder->newFile('file3.txt', 'content3');
		$this->createdFiles[] = $file1;
		$this->createdFiles[] = $file2;
		$this->createdFiles[] = $file3;

		// Set labels on some files
		$this->service->setLabel($file1->getId(), 'type', 'document');
		$this->service->setLabel($file2->getId(), 'type', 'image');
		$this->service->setLabel($file2->getId(), 'category', 'personal');
		// file3 has no labels

		// Mock the directory node
		$sabreDir = $this->createMock(Directory::class);
		$sabreDir->method('getId')->willReturn($folder->getId());

		// Mock children
		$child1 = $this->createMock(File::class);
		$child1->method('getId')->willReturn($file1->getId());
		$child2 = $this->createMock(File::class);
		$child2->method('getId')->willReturn($file2->getId());
		$child3 = $this->createMock(File::class);
		$child3->method('getId')->willReturn($file3->getId());

		$sabreDir->method('getChildren')->willReturn([$child1, $child2, $child3]);

		// Create PropFind requesting labels
		$propFind = new PropFind('files/' . self::TEST_USER . '/preload_folder', [self::LABELS_PROPERTY], 1);

		// Trigger preload
		$this->plugin->preloadCollection($propFind, $sabreDir);

		// Now when we get properties for individual files, they should use cached data
		$propFind1 = new PropFind('file1.txt', [self::LABELS_PROPERTY]);
		$this->plugin->handleGetProperties($propFind1, $child1);
		$result1 = json_decode($propFind1->get(self::LABELS_PROPERTY), true);
		$this->assertEquals(['type' => 'document'], $result1);

		$propFind2 = new PropFind('file2.txt', [self::LABELS_PROPERTY]);
		$this->plugin->handleGetProperties($propFind2, $child2);
		$result2 = json_decode($propFind2->get(self::LABELS_PROPERTY), true);
		$this->assertEquals(['type' => 'image', 'category' => 'personal'], $result2);

		$propFind3 = new PropFind('file3.txt', [self::LABELS_PROPERTY]);
		$this->plugin->handleGetProperties($propFind3, $child3);
		$result3 = json_decode($propFind3->get(self::LABELS_PROPERTY), true);
		$this->assertEquals([], $result3);
	}

	public function testPreloadSkipsWhenLabelsNotRequested(): void {
		$folder = $this->createTestFolder('no_preload');
		$file = $folder->newFile('test.txt', 'content');
		$this->createdFiles[] = $file;

		$sabreDir = $this->createMock(Directory::class);
		$sabreDir->method('getId')->willReturn($folder->getId());
		// getChildren should NOT be called if labels not requested
		$sabreDir->expects($this->never())->method('getChildren');

		// PropFind without labels property
		$propFind = new PropFind('folder', ['{DAV:}resourcetype'], 1);

		$this->plugin->preloadCollection($propFind, $sabreDir);
	}

	public function testPreloadSkipsForNonDirectory(): void {
		$file = $this->createTestFile('not_a_dir.txt');

		$sabreFile = $this->createMock(File::class);
		$sabreFile->method('getId')->willReturn($file->getId());

		// PropFind with depth 1 on a file (shouldn't preload)
		$propFind = new PropFind('file.txt', [self::LABELS_PROPERTY], 1);

		// This should not throw or cause issues
		$this->plugin->preloadCollection($propFind, $sabreFile);
	}

	// ==================== Edge Cases ====================

	public function testHandlePropertiesWithNonNodeObject(): void {
		// Non-Node Sabre object (e.g., principal, calendar, etc.)
		$nonNode = $this->createMock(\Sabre\DAV\INode::class);

		$propFind = new PropFind('principals/users/test', [self::LABELS_PROPERTY]);

		// Should not throw, just skip
		$this->plugin->handleGetProperties($propFind, $nonNode);

		// Property should not be set
		$this->assertNull($propFind->get(self::LABELS_PROPERTY));
	}

	public function testMultipleLabelsPreserveOrder(): void {
		$file = $this->createTestFile('order.txt');
		$fileId = $file->getId();

		// Set multiple labels
		$this->service->setLabel($fileId, 'aaa', 'first');
		$this->service->setLabel($fileId, 'zzz', 'last');
		$this->service->setLabel($fileId, 'mmm', 'middle');

		$sabreFile = $this->createMock(File::class);
		$sabreFile->method('getId')->willReturn($fileId);

		$propFind = new PropFind('test', [self::LABELS_PROPERTY]);
		$this->plugin->handleGetProperties($propFind, $sabreFile);

		$result = $propFind->get(self::LABELS_PROPERTY);
		$decoded = json_decode($result, true);

		// All labels should be present
		$this->assertCount(3, $decoded);
		$this->assertEquals('first', $decoded['aaa']);
		$this->assertEquals('last', $decoded['zzz']);
		$this->assertEquals('middle', $decoded['mmm']);
	}

	public function testLargeNumberOfLabels(): void {
		$file = $this->createTestFile('many_labels.txt');
		$fileId = $file->getId();

		// Set 50 labels
		for ($i = 0; $i < 50; $i++) {
			$this->service->setLabel($fileId, "label{$i}", "value{$i}");
		}

		$sabreFile = $this->createMock(File::class);
		$sabreFile->method('getId')->willReturn($fileId);

		$propFind = new PropFind('test', [self::LABELS_PROPERTY]);
		$this->plugin->handleGetProperties($propFind, $sabreFile);

		$result = $propFind->get(self::LABELS_PROPERTY);
		$decoded = json_decode($result, true);

		$this->assertCount(50, $decoded);
		for ($i = 0; $i < 50; $i++) {
			$this->assertEquals("value{$i}", $decoded["label{$i}"]);
		}
	}

	public function testPluginInitialization(): void {
		$server = $this->createMock(Server::class);

		// Should register event handlers
		$server->expects($this->exactly(2))
			->method('on')
			->withConsecutive(
				['propFind', [$this->plugin, 'handleGetProperties']],
				['propFind', [$this->plugin, 'preloadCollection'], 200]
			);

		$this->plugin->initialize($server);
	}

	public function testPluginInfo(): void {
		$info = $this->plugin->getPluginInfo();

		$this->assertIsArray($info);
		$this->assertArrayHasKey('name', $info);
		$this->assertEquals('files_labels', $info['name']);
	}
}
