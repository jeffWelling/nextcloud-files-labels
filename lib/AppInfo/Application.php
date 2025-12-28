<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FilesLabels\Listener\FileDeletedListener;
use OCA\FilesLabels\Listener\SabrePluginAddListener;
use OCA\FilesLabels\Listener\UserDeletedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_labels';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		// Register the DAV plugin listener using the modern typed event pattern.
		// This ensures the listener is registered even when the app is not fully
		// loaded (critical for DAV requests where only the 'dav' app is loaded).
		$context->registerEventListener(SabrePluginAddEvent::class, SabrePluginAddListener::class);

		// Cleanup listeners for file and user deletion
		$context->registerEventListener(NodeDeletedEvent::class, FileDeletedListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	public function boot(IBootContext $context): void {
		// Load JavaScript for the Files app sidebar
		Util::addScript(self::APP_ID, 'files_labels-main');
	}
}
