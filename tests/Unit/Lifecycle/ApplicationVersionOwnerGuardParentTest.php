<?php

/**
 * Unit tests for how ApplicationVersionOwnerGuard loads the parent
 * Application and what it answers when that fails: the exact lookup, a
 * throwing lookup, a non-string relation, the user-scope flag
 * compared strictly, and the refusal messages naming the action.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Lifecycle;

use OCA\Buildiq\Lifecycle\ApplicationVersionOwnerGuard;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Parent loading of ApplicationVersionOwnerGuard.
 *
 * @covers \OCA\Buildiq\Lifecycle\ApplicationVersionOwnerGuard
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 */
final class ApplicationVersionOwnerGuardParentTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the guard for a caller.
	 *
	 * @param bool $isAdmin Whether the caller is a Nextcloud admin.
	 *
	 * @return ApplicationVersionOwnerGuard The guard.
	 */
	private function guard(bool $isAdmin = false): ApplicationVersionOwnerGuard {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->logger  = $this->createMock(LoggerInterface::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);
		$groups->method('isAdmin')->willReturn($isAdmin);

		return new ApplicationVersionOwnerGuard(
			objectService: $this->objects,
			permissionResolver: new PermissionResolver(groupManager: $groups, logger: $this->logger),
			userManager: $users,
			logger: $this->logger
		);
	}//end guard()

	/**
	 * A parent stub serialising to the given value.
	 *
	 * @param mixed $data What jsonSerialize() returns.
	 *
	 * @return ObjectEntity&MockObject The entity.
	 */
	private function parent(mixed $data): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end parent()

	/**
	 * The parent is looked up by its uuid in the buildiq register's built-app schema.
	 *
	 * @return void
	 */
	public function testTheParentIsLookedUpInTheApplicationSchema(): void {
		$guard = $this->guard();
		$this->objects->expects($this->once())->method('find')
			->with('app-A', [], false, 'buildiq', 'built-app')
			->willReturn($this->parent(['permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => []]]));

		self::assertTrue($guard->check(['application' => 'app-A'], 'publish', 'alice')->isAllowed());
	}//end testTheParentIsLookedUpInTheApplicationSchema()

	/**
	 * A throwing lookup denies with the unresolved-parent message and logs why.
	 *
	 * @return void
	 */
	public function testAThrowingLookupDeniesAndLogs(): void {
		$guard = $this->guard();
		$this->objects->method('find')->willThrowException(new RuntimeException('database gone'));
		$this->logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			static function (string $message, array $context): void {
				static $calls = 0;
				$calls++;
				if ($calls === 1) {
					self::assertSame('Buildiq ApplicationVersionOwnerGuard: failed to load parent Application: database gone', $message);
					self::assertSame(['application' => 'app-A'], $context);
					return;
				}

				self::assertSame('Buildiq ApplicationVersionOwnerGuard: parent Application unresolved; denying transition', $message);
				self::assertSame(['action' => 'archive', 'application' => 'app-A'], $context);
			}
		);

		$result = $guard->check(['application' => 'app-A'], 'archive', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame('De bovenliggende applicatie kon niet worden bepaald; transitie geweigerd.', $result->getMessage());
	}//end testAThrowingLookupDeniesAndLogs()


	/**
	 * A relation that is not a string is never looked up.
	 *
	 * @return void
	 */
	public function testANonStringRelationIsNotLookedUp(): void {
		$guard = $this->guard(isAdmin: true);
		$this->objects->expects($this->never())->method('find');

		self::assertFalse($guard->check(['application' => 42], 'publish', 'alice')->isAllowed());
	}//end testANonStringRelationIsNotLookedUp()

	/**
	 * A non-owner of the parent is refused with a message that names the action.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheAction(): void {
		$guard = $this->guard();
		$this->objects->method('find')->willReturn($this->parent(['permissions' => ['owners' => ['user:bob'], 'editors' => [], 'viewers' => []]]));

		self::assertSame(
			'U mag deze versie niet heropenen. Alleen een eigenaar van de bovenliggende applicatie (of een beheerder) '
			.'is gemachtigd voor publiceren, archiveren of heropenen.',
			$guard->check(['application' => 'app-A'], 'heropenen', 'alice')->getMessage()
		);
	}//end testTheRefusalNamesTheAction()

	/**
	 * The user-scope flag must be literally true: a truthy string does not permit overrides.
	 *
	 * @return void
	 */
	public function testTheUserOverrideFlagIsComparedStrictly(): void {
		$guard = $this->guard();
		$this->objects->method('find')->willReturn($this->parent(['allowUserOverrides' => '1']));

		$result = $guard->check(['scope' => 'user', 'owner' => 'alice', 'application' => 'app-A'], 'publish', 'alice');

		self::assertFalse($result->isAllowed());
		self::assertSame('Deze app staat geen persoonlijke aanpassingen (meer) toe; transitie geweigerd.', $result->getMessage());
	}//end testTheUserOverrideFlagIsComparedStrictly()

	/**
	 * A user delta whose parent cannot be loaded is refused for its owner, but allowed for an admin.
	 *
	 * @return void
	 */
	public function testAUserDeltaWithoutParentNeedsAnAdmin(): void {
		$delta = ['scope' => 'user', 'owner' => 'alice', 'application' => 'app-gone'];

		$owner = $this->guard();
		$this->objects->method('find')->willReturn(null);
		self::assertFalse($owner->check($delta, 'publish', 'alice')->isAllowed());

		$admin = $this->guard(isAdmin: true);
		$this->objects->method('find')->willReturn(null);
		self::assertTrue($admin->check($delta, 'publish', 'alice')->isAllowed());
	}//end testAUserDeltaWithoutParentNeedsAnAdmin()

	/**
	 * A foreign user's delta is refused with a message naming the action.
	 *
	 * @return void
	 */
	public function testAForeignDeltaRefusalNamesTheAction(): void {
		$guard = $this->guard();
		$this->objects->expects($this->never())->method('find');

		self::assertSame(
			'U mag deze persoonlijke aanpassing niet archiveren; alleen de eigenaar is gemachtigd.',
			$guard->check(['scope' => 'user', 'owner' => 'bob'], 'archiveren', 'alice')->getMessage()
		);
	}//end testAForeignDeltaRefusalNamesTheAction()
}//end class
