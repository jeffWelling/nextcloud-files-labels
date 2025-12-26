<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Jeff <jeff@example.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FilesLabels\DAV\LabelsPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;

/**
 * Listener for SabrePluginAddEvent to register our DAV plugin.
 *
 * This uses registerEventListener() in register() instead of addListener() in boot()
 * because DAV requests don't fully load third-party apps - they only load specific
 * app types. Using registerEventListener() ensures our listener is always available.
 *
 * @template-implements IEventListener<SabrePluginAddEvent>
 */
class SabrePluginAddListener implements IEventListener {
	public function __construct(
		private ContainerInterface $container,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof SabrePluginAddEvent)) {
			return;
		}

		$server = $event->getServer();
		$plugin = $this->container->get(LabelsPlugin::class);
		$server->addPlugin($plugin);
	}
}
