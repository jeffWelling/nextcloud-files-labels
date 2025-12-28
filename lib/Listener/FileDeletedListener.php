<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Listener;

use OCA\FilesLabels\Db\LabelMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Listener to clean up labels when a file is deleted.
 *
 * Note: The database schema also has ON DELETE CASCADE via foreign key,
 * but Nextcloud's filecache doesn't always trigger cascades reliably,
 * so we handle cleanup explicitly via events.
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class FileDeletedListener implements IEventListener {
	public function __construct(
		private LabelMapper $mapper,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeDeletedEvent)) {
			return;
		}

		$node = $event->getNode();
		$fileId = $node->getId();

		if ($fileId === null) {
			return;
		}

		try {
			$this->mapper->deleteByFile($fileId);
			$this->logger->debug('Deleted labels for file {fileId}', [
				'app' => 'files_labels',
				'fileId' => $fileId,
			]);
		} catch (\Exception $e) {
			$this->logger->error('Failed to delete labels for file {fileId}: {error}', [
				'app' => 'files_labels',
				'fileId' => $fileId,
				'error' => $e->getMessage(),
			]);
		}
	}
}
