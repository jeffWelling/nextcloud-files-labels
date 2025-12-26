<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Listener;

use OCA\FilesLabels\Db\LabelMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Listener to clean up labels when a user is deleted.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private LabelMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			return;
		}

		$user = $event->getUser();
		$userId = $user->getUID();

		try {
			$this->mapper->deleteByUser($userId);
			$this->logger->info('Deleted all labels for user {userId}', [
				'app' => 'files_labels',
				'userId' => $userId,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to delete labels for user {userId}: {error}', [
				'app' => 'files_labels',
				'userId' => $userId,
				'error' => $e->getMessage(),
			]);
		}
	}
}
