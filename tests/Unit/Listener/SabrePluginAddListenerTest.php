<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\FilesLabels\DAV\LabelsPlugin;
use OCA\FilesLabels\Listener\SabrePluginAddListener;
use OCP\EventDispatcher\Event;
use Psr\Container\ContainerInterface;
use Sabre\DAV\Server;
use Test\TestCase;

class SabrePluginAddListenerTest extends TestCase {
	private SabrePluginAddListener $listener;
	private ContainerInterface $container;
	private Server $server;
	private LabelsPlugin $plugin;

	protected function setUp(): void {
		parent::setUp();

		$this->container = $this->createMock(ContainerInterface::class);
		$this->server = $this->createMock(Server::class);
		$this->plugin = $this->createMock(LabelsPlugin::class);

		$this->listener = new SabrePluginAddListener($this->container);
	}

	public function testHandleSabrePluginAddEvent(): void {
		$event = $this->createMock(SabrePluginAddEvent::class);
		$event->method('getServer')
			->willReturn($this->server);

		$this->container->expects($this->once())
			->method('get')
			->with(LabelsPlugin::class)
			->willReturn($this->plugin);

		$this->server->expects($this->once())
			->method('addPlugin')
			->with($this->plugin);

		$this->listener->handle($event);
	}

	public function testHandleWrongEventType(): void {
		$event = $this->createMock(Event::class);

		$this->container->expects($this->never())
			->method('get');

		$this->listener->handle($event);
	}

	public function testHandleRetrievesPluginFromContainer(): void {
		$event = $this->createMock(SabrePluginAddEvent::class);
		$event->method('getServer')
			->willReturn($this->server);

		$this->container->expects($this->once())
			->method('get')
			->with(LabelsPlugin::class)
			->willReturn($this->plugin);

		$this->listener->handle($event);
	}

	public function testConstructor(): void {
		$listener = new SabrePluginAddListener($this->container);
		$this->assertInstanceOf(SabrePluginAddListener::class, $listener);
	}
}
