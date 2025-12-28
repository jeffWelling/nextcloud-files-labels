<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\Listener;

use OCA\FilesLabels\Db\LabelMapper;
use OCA\FilesLabels\Listener\FileDeletedListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Node;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FileDeletedListenerTest extends TestCase {
	private LabelMapper|MockObject $mapper;
	private LoggerInterface|MockObject $logger;
	private FileDeletedListener $listener;

	protected function setUp(): void {
		$this->mapper = $this->createMock(LabelMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new FileDeletedListener($this->mapper, $this->logger);
	}

	public function testHandleDeletesLabelsForFile(): void {
		$fileId = 42;

		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($fileId);

		$event = $this->createMock(NodeDeletedEvent::class);
		$event->method('getNode')->willReturn($node);

		$this->mapper->expects($this->once())
			->method('deleteByFile')
			->with($fileId);

		$this->logger->expects($this->once())
			->method('debug');

		$this->listener->handle($event);
	}

	public function testHandleIgnoresNullFileId(): void {
		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn(null);

		$event = $this->createMock(NodeDeletedEvent::class);
		$event->method('getNode')->willReturn($node);

		$this->mapper->expects($this->never())
			->method('deleteByFile');

		$this->listener->handle($event);
	}

	public function testHandleLogsErrorOnException(): void {
		$fileId = 42;

		$node = $this->createMock(Node::class);
		$node->method('getId')->willReturn($fileId);

		$event = $this->createMock(NodeDeletedEvent::class);
		$event->method('getNode')->willReturn($node);

		$this->mapper->method('deleteByFile')
			->willThrowException(new \Exception('Database error'));

		$this->logger->expects($this->once())
			->method('error');

		// Should not throw - error is logged
		$this->listener->handle($event);
	}

	public function testHandleIgnoresOtherEvents(): void {
		$otherEvent = $this->createMock(\OCP\EventDispatcher\Event::class);

		$this->mapper->expects($this->never())
			->method('deleteByFile');

		$this->listener->handle($otherEvent);
	}
}
