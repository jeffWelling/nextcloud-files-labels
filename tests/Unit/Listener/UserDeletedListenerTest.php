<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\Listener;

use OCA\FilesLabels\Db\LabelMapper;
use OCA\FilesLabels\Listener\UserDeletedListener;
use OCP\IUser;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserDeletedListenerTest extends TestCase {
	private LabelMapper|MockObject $mapper;
	private LoggerInterface|MockObject $logger;
	private UserDeletedListener $listener;

	protected function setUp(): void {
		$this->mapper = $this->createMock(LabelMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new UserDeletedListener($this->mapper, $this->logger);
	}

	public function testHandleDeletesLabelsForUser(): void {
		$userId = 'testuser';

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($user);

		$this->mapper->expects($this->once())
			->method('deleteByUser')
			->with($userId);

		$this->logger->expects($this->once())
			->method('info');

		$this->listener->handle($event);
	}

	public function testHandleLogsErrorOnException(): void {
		$userId = 'testuser';

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$event = $this->createMock(UserDeletedEvent::class);
		$event->method('getUser')->willReturn($user);

		$this->mapper->method('deleteByUser')
			->willThrowException(new \Exception('Database error'));

		$this->logger->expects($this->once())
			->method('error');

		// Should not throw - error is logged
		$this->listener->handle($event);
	}

	public function testHandleIgnoresOtherEvents(): void {
		$otherEvent = $this->createMock(\OCP\EventDispatcher\Event::class);

		$this->mapper->expects($this->never())
			->method('deleteByUser');

		$this->listener->handle($otherEvent);
	}
}
