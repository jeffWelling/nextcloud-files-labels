<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\E2E;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use PHPUnit\Framework\TestCase;

/**
 * End-to-End HTTP API tests.
 *
 * These tests make real HTTP requests to a running Nextcloud instance.
 * They test the full stack including authentication, routing, and response formatting.
 *
 * Prerequisites:
 * - Nextcloud must be running at the configured base URL
 * - Test user must exist with the configured credentials
 * - files_labels app must be enabled
 *
 * Run with: phpunit tests/E2E/HttpApiTest.php
 *
 * Environment variables:
 * - NEXTCLOUD_BASE_URL: Base URL of Nextcloud (default: http://localhost:8080)
 * - NEXTCLOUD_USER: Test username (default: admin)
 * - NEXTCLOUD_PASS: Test password (default: admin)
 *
 * @group E2E
 */
class HttpApiTest extends TestCase {
	private Client $client;
	private string $baseUrl;
	private string $username;
	private string $password;
	private array $createdFiles = [];

	protected function setUp(): void {
		parent::setUp();

		$this->baseUrl = getenv('NEXTCLOUD_BASE_URL') ?: 'http://localhost:8080';
		$this->username = getenv('NEXTCLOUD_USER') ?: 'admin';
		$this->password = getenv('NEXTCLOUD_PASS') ?: 'admin';

		$this->client = new Client([
			'base_uri' => $this->baseUrl,
			'auth' => [$this->username, $this->password],
			'headers' => [
				'OCS-APIREQUEST' => 'true',
				'Accept' => 'application/json',
			],
			'http_errors' => false,
		]);

		// Verify connectivity
		$response = $this->client->get('/status.php');
		if ($response->getStatusCode() !== 200) {
			$this->markTestSkipped('Nextcloud not available at ' . $this->baseUrl);
		}
	}

	protected function tearDown(): void {
		// Clean up created files
		foreach ($this->createdFiles as $path) {
			try {
				$this->client->delete('/remote.php/dav/files/' . $this->username . '/' . $path);
			} catch (\Exception $e) {
				// Ignore cleanup errors
			}
		}

		parent::tearDown();
	}

	/**
	 * Create a test file and return its file ID
	 */
	private function createTestFile(string $name, string $content = 'test'): int {
		$path = 'e2e_test_' . $name . '_' . time() . '.txt';
		$this->createdFiles[] = $path;

		// Upload file via WebDAV
		$this->client->put(
			'/remote.php/dav/files/' . $this->username . '/' . $path,
			['body' => $content]
		);

		// Get file ID via PROPFIND
		$response = $this->client->request('PROPFIND', '/remote.php/dav/files/' . $this->username . '/' . $path, [
			'headers' => ['Depth' => '0'],
			'body' => '<?xml version="1.0"?>
				<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
					<d:prop><oc:fileid/></d:prop>
				</d:propfind>',
		]);

		$xml = simplexml_load_string($response->getBody()->getContents());
		$xml->registerXPathNamespace('d', 'DAV:');
		$xml->registerXPathNamespace('oc', 'http://owncloud.org/ns');
		$fileId = $xml->xpath('//oc:fileid')[0];

		return (int)$fileId;
	}

	/**
	 * Get labels for a file via OCS API
	 */
	private function getLabels(int $fileId): array {
		$response = $this->client->get("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}");
		$body = json_decode($response->getBody()->getContents(), true);
		return $body['ocs']['data'] ?? [];
	}

	/**
	 * Set a label via OCS API
	 */
	private function setLabel(int $fileId, string $key, string $value): array {
		$response = $this->client->put(
			"/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}/{$key}",
			['form_params' => ['value' => $value]]
		);
		$body = json_decode($response->getBody()->getContents(), true);
		return [
			'status' => $response->getStatusCode(),
			'data' => $body['ocs']['data'] ?? null,
			'meta' => $body['ocs']['meta'] ?? null,
		];
	}

	/**
	 * Delete a label via OCS API
	 */
	private function deleteLabel(int $fileId, string $key): array {
		$response = $this->client->delete("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}/{$key}");
		$body = json_decode($response->getBody()->getContents(), true);
		return [
			'status' => $response->getStatusCode(),
			'data' => $body['ocs']['data'] ?? null,
			'meta' => $body['ocs']['meta'] ?? null,
		];
	}

	/**
	 * Get labels via WebDAV PROPFIND
	 */
	private function getLabelsViaDav(string $path): ?array {
		$response = $this->client->request('PROPFIND', '/remote.php/dav/files/' . $this->username . '/' . $path, [
			'headers' => ['Depth' => '0'],
			'body' => '<?xml version="1.0"?>
				<d:propfind xmlns:d="DAV:" xmlns:nc="http://nextcloud.org/ns">
					<d:prop><nc:labels/></d:prop>
				</d:propfind>',
		]);

		$xml = simplexml_load_string($response->getBody()->getContents());
		$xml->registerXPathNamespace('d', 'DAV:');
		$xml->registerXPathNamespace('nc', 'http://nextcloud.org/ns');
		$labels = $xml->xpath('//nc:labels');

		if (empty($labels)) {
			return null;
		}

		return json_decode((string)$labels[0], true);
	}

	// ==================== OCS API Tests ====================

	public function testOcsGetEmptyLabels(): void {
		$fileId = $this->createTestFile('empty');
		$labels = $this->getLabels($fileId);

		$this->assertIsArray($labels);
		$this->assertEmpty($labels);
	}

	public function testOcsSetAndGetLabel(): void {
		$fileId = $this->createTestFile('set_get');

		// Set label
		$result = $this->setLabel($fileId, 'category', 'work');
		$this->assertEquals(200, $result['status']);
		$this->assertEquals('category', $result['data']['key']);
		$this->assertEquals('work', $result['data']['value']);

		// Get labels
		$labels = $this->getLabels($fileId);
		$this->assertEquals(['category' => 'work'], $labels);
	}

	public function testOcsUpdateLabel(): void {
		$fileId = $this->createTestFile('update');

		$this->setLabel($fileId, 'status', 'draft');
		$result = $this->setLabel($fileId, 'status', 'published');

		$this->assertEquals('published', $result['data']['value']);

		$labels = $this->getLabels($fileId);
		$this->assertCount(1, $labels);
		$this->assertEquals('published', $labels['status']);
	}

	public function testOcsDeleteLabel(): void {
		$fileId = $this->createTestFile('delete');

		$this->setLabel($fileId, 'tag1', 'val1');
		$this->setLabel($fileId, 'tag2', 'val2');

		$result = $this->deleteLabel($fileId, 'tag1');
		$this->assertEquals(200, $result['status']);

		$labels = $this->getLabels($fileId);
		$this->assertArrayNotHasKey('tag1', $labels);
		$this->assertArrayHasKey('tag2', $labels);
	}

	public function testOcsDeleteNonExistentLabel(): void {
		$fileId = $this->createTestFile('no_label');

		$response = $this->client->delete("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}/nonexistent");
		$this->assertEquals(404, $response->getStatusCode());
	}

	public function testOcsInvalidFileId(): void {
		$response = $this->client->get('/ocs/v2.php/apps/files_labels/api/v1/labels/999999999');
		// Should return 404 (not found) or empty labels
		$this->assertContains($response->getStatusCode(), [200, 404]);
	}

	public function testOcsMultipleLabels(): void {
		$fileId = $this->createTestFile('multi');

		$this->setLabel($fileId, 'project', 'nextcloud');
		$this->setLabel($fileId, 'team', 'core');
		$this->setLabel($fileId, 'priority', 'high');

		$labels = $this->getLabels($fileId);
		$this->assertCount(3, $labels);
		$this->assertEquals('nextcloud', $labels['project']);
		$this->assertEquals('core', $labels['team']);
		$this->assertEquals('high', $labels['priority']);
	}

	public function testOcsBulkSet(): void {
		$fileId = $this->createTestFile('bulk');

		$response = $this->client->put("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}", [
			'json' => [
				'labels' => [
					'bulk1' => 'value1',
					'bulk2' => 'value2',
					'bulk3' => 'value3',
				]
			],
		]);

		$this->assertEquals(200, $response->getStatusCode());

		$labels = $this->getLabels($fileId);
		$this->assertEquals('value1', $labels['bulk1']);
		$this->assertEquals('value2', $labels['bulk2']);
		$this->assertEquals('value3', $labels['bulk3']);
	}

	// ==================== Validation Tests ====================

	public function testOcsRejectsInvalidKey(): void {
		$fileId = $this->createTestFile('invalid_key');

		$response = $this->client->put("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}/INVALID KEY!", [
			'form_params' => ['value' => 'test'],
		]);

		$this->assertEquals(400, $response->getStatusCode());
	}

	public function testOcsAcceptsValidKeys(): void {
		$fileId = $this->createTestFile('valid_keys');

		$validKeys = ['lowercase', 'with-dash', 'with_underscore', 'with.dot', 'with:colon', 'num123'];

		foreach ($validKeys as $key) {
			$result = $this->setLabel($fileId, $key, 'test');
			$this->assertEquals(200, $result['status'], "Key '$key' should be accepted");
		}
	}

	public function testOcsRejectsTooLongKey(): void {
		$fileId = $this->createTestFile('long_key');
		$longKey = str_repeat('a', 65);

		$response = $this->client->put("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}/{$longKey}", [
			'form_params' => ['value' => 'test'],
		]);

		$this->assertEquals(400, $response->getStatusCode());
	}

	public function testOcsRejectsTooLongValue(): void {
		$fileId = $this->createTestFile('long_value');
		$longValue = str_repeat('x', 4097);

		$response = $this->client->put("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}/test", [
			'form_params' => ['value' => $longValue],
		]);

		$this->assertEquals(400, $response->getStatusCode());
	}

	// ==================== WebDAV Tests ====================

	public function testDavLabelsProperty(): void {
		$fileId = $this->createTestFile('dav');
		$path = $this->createdFiles[array_key_last($this->createdFiles)];

		$this->setLabel($fileId, 'davtest', 'value');

		$labels = $this->getLabelsViaDav($path);
		$this->assertIsArray($labels);
		$this->assertEquals('value', $labels['davtest']);
	}

	public function testDavLabelsPropertyEmptyFile(): void {
		$this->createTestFile('dav_empty');
		$path = $this->createdFiles[array_key_last($this->createdFiles)];

		$labels = $this->getLabelsViaDav($path);
		$this->assertIsArray($labels);
		$this->assertEmpty($labels);
	}

	public function testDavLabelsMultiple(): void {
		$fileId = $this->createTestFile('dav_multi');
		$path = $this->createdFiles[array_key_last($this->createdFiles)];

		$this->setLabel($fileId, 'key1', 'val1');
		$this->setLabel($fileId, 'key2', 'val2');

		$labels = $this->getLabelsViaDav($path);
		$this->assertCount(2, $labels);
		$this->assertEquals('val1', $labels['key1']);
		$this->assertEquals('val2', $labels['key2']);
	}

	// ==================== Unicode and Special Characters ====================

	public function testOcsUnicodeValue(): void {
		$fileId = $this->createTestFile('unicode');
		$unicodeValue = '日本語テスト 🎉 émojis';

		$result = $this->setLabel($fileId, 'unicode', $unicodeValue);
		$this->assertEquals(200, $result['status']);

		$labels = $this->getLabels($fileId);
		$this->assertEquals($unicodeValue, $labels['unicode']);
	}

	public function testOcsSpecialCharsValue(): void {
		$fileId = $this->createTestFile('special');
		$specialValue = '<script>alert("xss")</script> & "quotes" \'apos\'';

		$result = $this->setLabel($fileId, 'special', $specialValue);
		$this->assertEquals(200, $result['status']);

		$labels = $this->getLabels($fileId);
		$this->assertEquals($specialValue, $labels['special']);
	}

	public function testDavUnicodeValue(): void {
		$fileId = $this->createTestFile('dav_unicode');
		$path = $this->createdFiles[array_key_last($this->createdFiles)];
		$unicodeValue = '日本語 🎉';

		$this->setLabel($fileId, 'test', $unicodeValue);

		$labels = $this->getLabelsViaDav($path);
		$this->assertEquals($unicodeValue, $labels['test']);
	}

	// ==================== Full Workflow Tests ====================

	public function testCompleteWorkflow(): void {
		// Create file
		$fileId = $this->createTestFile('workflow');
		$path = $this->createdFiles[array_key_last($this->createdFiles)];

		// Verify empty
		$labels = $this->getLabels($fileId);
		$this->assertEmpty($labels);

		// Add labels
		$this->setLabel($fileId, 'status', 'draft');
		$this->setLabel($fileId, 'owner', 'admin');

		// Verify via OCS
		$labels = $this->getLabels($fileId);
		$this->assertCount(2, $labels);

		// Verify via WebDAV
		$davLabels = $this->getLabelsViaDav($path);
		$this->assertEquals($labels, $davLabels);

		// Update label
		$this->setLabel($fileId, 'status', 'published');
		$labels = $this->getLabels($fileId);
		$this->assertEquals('published', $labels['status']);

		// Delete label
		$this->deleteLabel($fileId, 'owner');
		$labels = $this->getLabels($fileId);
		$this->assertCount(1, $labels);
		$this->assertArrayNotHasKey('owner', $labels);

		// Verify WebDAV still in sync
		$davLabels = $this->getLabelsViaDav($path);
		$this->assertEquals($labels, $davLabels);
	}

	public function testRapidOperations(): void {
		$fileId = $this->createTestFile('rapid');

		// Rapid creates
		for ($i = 0; $i < 10; $i++) {
			$this->setLabel($fileId, "rapid{$i}", "value{$i}");
		}

		$labels = $this->getLabels($fileId);
		$this->assertCount(10, $labels);

		// Rapid updates
		for ($i = 0; $i < 10; $i++) {
			$this->setLabel($fileId, "rapid{$i}", "updated{$i}");
		}

		$labels = $this->getLabels($fileId);
		$this->assertEquals('updated5', $labels['rapid5']);

		// Rapid deletes
		for ($i = 0; $i < 5; $i++) {
			$this->deleteLabel($fileId, "rapid{$i}");
		}

		$labels = $this->getLabels($fileId);
		$this->assertCount(5, $labels);
	}

	// ==================== Authentication Tests ====================

	public function testUnauthenticatedRequestFails(): void {
		$fileId = $this->createTestFile('auth_test');

		$unauthClient = new Client([
			'base_uri' => $this->baseUrl,
			'headers' => [
				'OCS-APIREQUEST' => 'true',
				'Accept' => 'application/json',
			],
			'http_errors' => false,
		]);

		$response = $unauthClient->get("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}");
		$this->assertEquals(401, $response->getStatusCode());
	}

	public function testWrongPasswordFails(): void {
		$fileId = $this->createTestFile('wrong_pass');

		$wrongClient = new Client([
			'base_uri' => $this->baseUrl,
			'auth' => [$this->username, 'wrong_password'],
			'headers' => [
				'OCS-APIREQUEST' => 'true',
				'Accept' => 'application/json',
			],
			'http_errors' => false,
		]);

		$response = $wrongClient->get("/ocs/v2.php/apps/files_labels/api/v1/labels/{$fileId}");
		$this->assertEquals(401, $response->getStatusCode());
	}
}
