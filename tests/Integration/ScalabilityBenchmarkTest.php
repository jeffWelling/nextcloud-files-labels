<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Integration;

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
 * Scalability benchmarks for the Labels system.
 *
 * These tests measure performance at various scales to identify bottlenecks
 * and ensure the system can handle large numbers of labels.
 *
 * @group DB
 * @group scalability
 */
class ScalabilityBenchmarkTest extends TestCase {
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

	private const TEST_USER = 'files_labels_scale_test';
	private const TEST_USER_PASS = 'scale_test_123';

	// Test scales to benchmark
	private const SCALES = [10, 100, 1000, 10000];

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
			$this->accessChecker
		);

		echo "\n\n";
		echo "========================================\n";
		echo "  SCALABILITY BENCHMARK TEST RESULTS   \n";
		echo "========================================\n\n";
		echo str_pad("Scale", 12) . " | ";
		echo str_pad("Operation", 15) . " | ";
		echo str_pad("Time (ms)", 12) . " | ";
		echo str_pad("Memory (MB)", 12) . " | ";
		echo str_pad("Labels/sec", 15) . "\n";
		echo str_repeat("-", 80) . "\n";
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

		echo "\n========================================\n\n";

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
	private function trackLabel($label): void {
		if (is_array($label)) {
			$this->createdLabels = array_merge($this->createdLabels, $label);
		} else {
			$this->createdLabels[] = $label;
		}
	}

	/**
	 * Format memory usage in MB
	 */
	private function formatMemory(int $bytes): string {
		return number_format($bytes / 1024 / 1024, 2);
	}

	/**
	 * Format time in milliseconds
	 */
	private function formatTime(float $seconds): string {
		return number_format($seconds * 1000, 2);
	}

	/**
	 * Format throughput in labels per second
	 */
	private function formatThroughput(int $count, float $seconds): string {
		if ($seconds == 0) {
			return 'N/A';
		}
		return number_format($count / $seconds, 0);
	}

	/**
	 * Print benchmark result row
	 */
	private function printResult(int $scale, string $operation, float $timeSec, int $memoryBytes, ?int $count = null): void {
		echo str_pad(number_format($scale), 12) . " | ";
		echo str_pad($operation, 15) . " | ";
		echo str_pad($this->formatTime($timeSec), 12) . " | ";
		echo str_pad($this->formatMemory($memoryBytes), 12) . " | ";
		echo str_pad($this->formatThroughput($count ?? $scale, $timeSec), 15) . "\n";
	}

	/**
	 * Benchmark adding labels to a file at various scales
	 */
	public function testBenchmarkAddLabels(): void {
		foreach (self::SCALES as $scale) {
			$file = $this->createTestFile("add_labels_$scale.txt");
			$fileId = $file->getId();

			// Measure time and memory
			$memBefore = memory_get_usage(true);
			$timeBefore = microtime(true);

			// Add labels
			$labels = [];
			for ($i = 0; $i < $scale; $i++) {
				$label = $this->service->setLabel($fileId, "key$i", "value$i");
				$this->trackLabel($label);
			}

			$timeAfter = microtime(true);
			$memAfter = memory_get_usage(true);

			$elapsed = $timeAfter - $timeBefore;
			$memUsed = $memAfter - $memBefore;

			$this->printResult($scale, 'Add', $elapsed, $memUsed);

			// Verify all labels were added
			$retrieved = $this->service->getLabelsForFile($fileId);
			$this->assertCount($scale, $retrieved);
		}
	}

	/**
	 * Benchmark fetching labels for a file at various scales
	 */
	public function testBenchmarkFetchLabels(): void {
		foreach (self::SCALES as $scale) {
			$file = $this->createTestFile("fetch_labels_$scale.txt");
			$fileId = $file->getId();

			// Pre-populate labels
			for ($i = 0; $i < $scale; $i++) {
				$label = $this->service->setLabel($fileId, "key$i", "value$i");
				$this->trackLabel($label);
			}

			// Clear any caches by getting labels once
			$this->service->getLabelsForFile($fileId);

			// Measure time and memory for fetching
			$memBefore = memory_get_usage(true);
			$timeBefore = microtime(true);

			$labels = $this->service->getLabelsForFile($fileId);

			$timeAfter = microtime(true);
			$memAfter = memory_get_usage(true);

			$elapsed = $timeAfter - $timeBefore;
			$memUsed = $memAfter - $memBefore;

			$this->printResult($scale, 'Fetch', $elapsed, $memUsed);

			// Verify correct count
			$this->assertCount($scale, $labels);
		}
	}

	/**
	 * Benchmark bulk fetching labels for multiple files
	 */
	public function testBenchmarkBulkFetch(): void {
		// For bulk fetch, we test with 100 files having varying label counts
		$fileScales = [
			10 => 100,    // 100 files with 10 labels each = 1,000 total labels
			100 => 100,   // 100 files with 100 labels each = 10,000 total labels
		];

		foreach ($fileScales as $labelsPerFile => $fileCount) {
			$fileIds = [];

			// Create files with labels
			for ($f = 0; $f < $fileCount; $f++) {
				$file = $this->createTestFile("bulk_fetch_{$labelsPerFile}_{$f}.txt");
				$fileIds[] = $file->getId();

				for ($i = 0; $i < $labelsPerFile; $i++) {
					$label = $this->service->setLabel($file->getId(), "key$i", "value$i");
					$this->trackLabel($label);
				}
			}

			$totalLabels = $labelsPerFile * $fileCount;

			// Clear any caches
			$this->service->getLabelsForFiles($fileIds);

			// Measure bulk fetch
			$memBefore = memory_get_usage(true);
			$timeBefore = microtime(true);

			$results = $this->service->getLabelsForFiles($fileIds);

			$timeAfter = microtime(true);
			$memAfter = memory_get_usage(true);

			$elapsed = $timeAfter - $timeBefore;
			$memUsed = $memAfter - $memBefore;

			$this->printResult($totalLabels, "Bulk ($fileCount f)", $elapsed, $memUsed, $totalLabels);

			// Verify correct data
			$this->assertCount($fileCount, $results);
			foreach ($results as $fileId => $labels) {
				$this->assertCount($labelsPerFile, $labels);
			}
		}
	}

	/**
	 * Benchmark deleting labels at various scales
	 */
	public function testBenchmarkDeleteLabels(): void {
		foreach (self::SCALES as $scale) {
			$file = $this->createTestFile("delete_labels_$scale.txt");
			$fileId = $file->getId();

			// Pre-populate labels
			for ($i = 0; $i < $scale; $i++) {
				$label = $this->service->setLabel($fileId, "key$i", "value$i");
				$this->trackLabel($label);
			}

			// Measure time and memory for deletion
			$memBefore = memory_get_usage(true);
			$timeBefore = microtime(true);

			// Delete all labels
			for ($i = 0; $i < $scale; $i++) {
				$this->service->deleteLabel($fileId, "key$i");
			}

			$timeAfter = microtime(true);
			$memAfter = memory_get_usage(true);

			$elapsed = $timeAfter - $timeBefore;
			$memUsed = $memAfter - $memBefore;

			$this->printResult($scale, 'Delete', $elapsed, $memUsed);

			// Verify all deleted
			$remaining = $this->service->getLabelsForFile($fileId);
			$this->assertCount(0, $remaining);
		}
	}

	/**
	 * Benchmark bulk set operation at various scales
	 */
	public function testBenchmarkBulkSet(): void {
		foreach (self::SCALES as $scale) {
			$file = $this->createTestFile("bulk_set_$scale.txt");
			$fileId = $file->getId();

			// Prepare labels array
			$labels = [];
			for ($i = 0; $i < $scale; $i++) {
				$labels["key$i"] = "value$i";
			}

			// Measure bulk set
			$memBefore = memory_get_usage(true);
			$timeBefore = microtime(true);

			$result = $this->service->setLabels($fileId, $labels);
			$this->trackLabel($result);

			$timeAfter = microtime(true);
			$memAfter = memory_get_usage(true);

			$elapsed = $timeAfter - $timeBefore;
			$memUsed = $memAfter - $memBefore;

			$this->printResult($scale, 'Bulk Set', $elapsed, $memUsed);

			// Verify all set
			$this->assertCount($scale, $result);
		}
	}

	/**
	 * Benchmark updating existing labels at various scales
	 */
	public function testBenchmarkUpdateLabels(): void {
		foreach (self::SCALES as $scale) {
			$file = $this->createTestFile("update_labels_$scale.txt");
			$fileId = $file->getId();

			// Pre-populate labels
			for ($i = 0; $i < $scale; $i++) {
				$label = $this->service->setLabel($fileId, "key$i", "initial_value");
				$this->trackLabel($label);
			}

			// Measure time and memory for updates
			$memBefore = memory_get_usage(true);
			$timeBefore = microtime(true);

			// Update all labels
			for ($i = 0; $i < $scale; $i++) {
				$this->service->setLabel($fileId, "key$i", "updated_value");
			}

			$timeAfter = microtime(true);
			$memAfter = memory_get_usage(true);

			$elapsed = $timeAfter - $timeBefore;
			$memUsed = $memAfter - $memBefore;

			$this->printResult($scale, 'Update', $elapsed, $memUsed);

			// Verify updates
			$labels = $this->service->getLabelsForFile($fileId);
			$this->assertCount($scale, $labels);
			foreach ($labels as $label) {
				$this->assertEquals('updated_value', $label->getLabelValue());
			}
		}
	}

	/**
	 * Benchmark finding files by label at various scales
	 */
	public function testBenchmarkFindByLabel(): void {
		// Create multiple files with the same label at different scales
		$scales = [10, 100, 1000];

		foreach ($scales as $scale) {
			$fileIds = [];

			// Create files with label
			for ($i = 0; $i < $scale; $i++) {
				$file = $this->createTestFile("find_by_label_{$scale}_{$i}.txt");
				$fileIds[] = $file->getId();
				$label = $this->service->setLabel($file->getId(), 'category', 'work');
				$this->trackLabel($label);
			}

			// Measure finding files by label
			$memBefore = memory_get_usage(true);
			$timeBefore = microtime(true);

			$found = $this->service->findFilesByLabel('category', 'work');

			$timeAfter = microtime(true);
			$memAfter = memory_get_usage(true);

			$elapsed = $timeAfter - $timeBefore;
			$memUsed = $memAfter - $memBefore;

			$this->printResult($scale, 'Find by Label', $elapsed, $memUsed);

			// Verify all found
			$this->assertCount($scale, $found);
			foreach ($fileIds as $fileId) {
				$this->assertContains($fileId, $found);
			}
		}
	}

	/**
	 * Stress test: Single file with maximum number of labels
	 */
	public function testStressSingleFileMaxLabels(): void {
		$file = $this->createTestFile('stress_max_labels.txt');
		$fileId = $file->getId();

		// Test with a very large number of labels on a single file
		$scale = 50000;

		echo "\n--- STRESS TEST: Single file with " . number_format($scale) . " labels ---\n";

		// Measure adding labels
		$memBefore = memory_get_usage(true);
		$timeBefore = microtime(true);

		for ($i = 0; $i < $scale; $i++) {
			$label = $this->service->setLabel($fileId, "stress-key$i", "stress-value$i");
			$this->trackLabel($label);

			// Progress indicator every 10,000 labels
			if (($i + 1) % 10000 === 0) {
				$elapsed = microtime(true) - $timeBefore;
				$throughput = ($i + 1) / $elapsed;
				echo "  Progress: " . number_format($i + 1) . " labels added (" .
					 number_format($throughput, 0) . " labels/sec)\n";
			}
		}

		$timeAfter = microtime(true);
		$memAfter = memory_get_usage(true);

		$elapsed = $timeAfter - $timeBefore;
		$memUsed = $memAfter - $memBefore;

		echo "  Total time: " . $this->formatTime($elapsed) . " ms\n";
		echo "  Memory used: " . $this->formatMemory($memUsed) . " MB\n";
		echo "  Throughput: " . $this->formatThroughput($scale, $elapsed) . " labels/sec\n";

		// Verify count
		$labels = $this->service->getLabelsForFile($fileId);
		$this->assertCount($scale, $labels);

		echo "  Verification: All " . number_format($scale) . " labels retrieved successfully\n";
		echo str_repeat("-", 80) . "\n";
	}
}
