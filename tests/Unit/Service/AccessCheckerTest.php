<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Jeff Welling <real.jeff.welling@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLabels\Tests\Unit\Service;

use OCA\FilesLabels\Service\AccessChecker;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use Test\TestCase;

class AccessCheckerTest extends TestCase {
	private AccessChecker $accessChecker;
	private IRootFolder $rootFolder;
	private IUserSession $userSession;
	private IUser $user;
	private Folder $userFolder;

	protected function setUp(): void {
		parent::setUp();

		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->user = $this->createMock(IUser::class);
		$this->userFolder = $this->createMock(Folder::class);

		$this->accessChecker = new AccessChecker(
			$this->rootFolder,
			$this->userSession
		);
	}

	public function testGetCurrentUserIdWithUser(): void {
		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$result = $this->accessChecker->getCurrentUserId();

		$this->assertEquals('testuser', $result);
	}

	public function testGetCurrentUserIdWithoutUser(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$result = $this->accessChecker->getCurrentUserId();

		$this->assertNull($result);
	}

	public function testCanReadSuccess(): void {
		$fileId = 123;
		$node = $this->createMock(File::class);

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($this->userFolder);

		$this->userFolder->method('getFirstNodeById')
			->with($fileId)
			->willReturn($node);

		$result = $this->accessChecker->canRead($fileId);

		$this->assertTrue($result);
	}

	public function testCanReadNotAuthenticated(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$result = $this->accessChecker->canRead(123);

		$this->assertFalse($result);
	}

	public function testCanReadFileNotFound(): void {
		$fileId = 123;

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($this->userFolder);

		$this->userFolder->method('getFirstNodeById')
			->with($fileId)
			->willReturn(null);

		$result = $this->accessChecker->canRead($fileId);

		$this->assertFalse($result);
	}

	public function testCanReadException(): void {
		$fileId = 123;

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willThrowException(new NotFoundException());

		$result = $this->accessChecker->canRead($fileId);

		$this->assertFalse($result);
	}

	public function testCanWriteSuccess(): void {
		$fileId = 123;
		$node = $this->createMock(File::class);
		$node->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($this->userFolder);

		$this->userFolder->method('getById')
			->with($fileId)
			->willReturn([$node]);

		$result = $this->accessChecker->canWrite($fileId);

		$this->assertTrue($result);
	}

	public function testCanWriteNotAuthenticated(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$result = $this->accessChecker->canWrite(123);

		$this->assertFalse($result);
	}

	public function testCanWriteNoUpdatePermission(): void {
		$fileId = 123;
		$node = $this->createMock(File::class);
		$node->method('getPermissions')
			->willReturn(Constants::PERMISSION_READ);

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($this->userFolder);

		$this->userFolder->method('getById')
			->with($fileId)
			->willReturn([$node]);

		$result = $this->accessChecker->canWrite($fileId);

		$this->assertFalse($result);
	}

	public function testCanWriteNoNodes(): void {
		$fileId = 123;

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($this->userFolder);

		$this->userFolder->method('getById')
			->with($fileId)
			->willReturn([]);

		$result = $this->accessChecker->canWrite($fileId);

		$this->assertFalse($result);
	}

	public function testCanWriteMultipleNodesFirstHasPermission(): void {
		$fileId = 123;
		$node1 = $this->createMock(File::class);
		$node1->method('getPermissions')
			->willReturn(Constants::PERMISSION_ALL);

		$node2 = $this->createMock(File::class);
		$node2->method('getPermissions')
			->willReturn(Constants::PERMISSION_READ);

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($this->userFolder);

		$this->userFolder->method('getById')
			->with($fileId)
			->willReturn([$node1, $node2]);

		$result = $this->accessChecker->canWrite($fileId);

		$this->assertTrue($result);
	}

	public function testCanWriteException(): void {
		$fileId = 123;

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willThrowException(new NotFoundException());

		$result = $this->accessChecker->canWrite($fileId);

		$this->assertFalse($result);
	}

	public function testFilterAccessibleSuccess(): void {
		$fileIds = [123, 456, 789];
		$node1 = $this->createMock(File::class);
		$node2 = $this->createMock(File::class);

		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($this->userFolder);

		$this->userFolder->method('getFirstNodeById')
			->willReturnMap([
				[123, $node1],
				[456, null],
				[789, $node2],
			]);

		$result = $this->accessChecker->filterAccessible($fileIds);

		$this->assertEquals([123, 789], $result);
	}

	public function testFilterAccessibleNotAuthenticated(): void {
		$this->userSession->method('getUser')
			->willReturn(null);

		$result = $this->accessChecker->filterAccessible([123, 456]);

		$this->assertEquals([], $result);
	}

	public function testFilterAccessibleException(): void {
		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willThrowException(new NotFoundException());

		$result = $this->accessChecker->filterAccessible([123, 456]);

		$this->assertEquals([], $result);
	}

	public function testFilterAccessibleEmptyArray(): void {
		$this->user->method('getUID')
			->willReturn('testuser');

		$this->userSession->method('getUser')
			->willReturn($this->user);

		$this->rootFolder->method('getUserFolder')
			->with('testuser')
			->willReturn($this->userFolder);

		$result = $this->accessChecker->filterAccessible([]);

		$this->assertEquals([], $result);
	}
}
