<?php

/**
 * OpenBuild JobOwnerImpersonator unit tests
 *
 * Covers the impersonation contract used by ExportJobService::transitionJob()
 * (#105): resolve an OR object's owner, swap the session user for the
 * duration of a callback, and ALWAYS restore the pre-impersonation session
 * user — success, failure, or thrown exception. Security-critical: a
 * regression here would either fail every background-job lifecycle
 * transition (owner never resolved) or leak an impersonated identity
 * forward into whatever the background-job process handles next (session
 * never restored).
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\JobOwnerImpersonator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Tests for {@see JobOwnerImpersonator}.
 */
final class JobOwnerImpersonatorTest extends TestCase {
	/**
	 * Container mock — resolves (or withholds) OR's ObjectService.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * User session mock — the impersonation target.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * User manager mock — resolves an owner UID to an IUser.
	 *
	 * @var IUserManager&MockObject
	 */
	private IUserManager&MockObject $userManager;

	/**
	 * Build fresh mocks for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
	}//end setUp()

	/**
	 * Build the impersonator under test with the current mocks.
	 *
	 * @return JobOwnerImpersonator
	 */
	private function buildImpersonator(): JobOwnerImpersonator {
		return new JobOwnerImpersonator(
			$this->container,
			$this->userSession,
			$this->userManager,
			new NullLogger()
		);
	}//end buildImpersonator()

	/**
	 * Wire the container to resolve ObjectService::find() to the given
	 * object for any object id.
	 *
	 * @param ObjectEntity|null $object Object `find()` should return.
	 *
	 * @return void
	 */
	private function resolvesObjectService(?ObjectEntity $object): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn($object);

		$this->container->method('has')->willReturn(true);
		$this->container->method('get')->willReturn($objectService);
	}//end resolvesObjectService()

	/**
	 * The happy path: the object's owner resolves to a real IUser,
	 * `runAsOwner()` swaps the session to that user for the duration of
	 * `$work`, returns `$work`'s result, and restores the prior session
	 * user afterwards.
	 *
	 * @return void
	 */
	public function testRunAsOwnerImpersonatesOwnerAndRestoresSessionOnSuccess(): void {
		$ownerUid = 'alice';
		$object = new ObjectEntity();
		$object->setOwner($ownerUid);
		$this->resolvesObjectService($object);

		$ownerUser = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($ownerUid)->willReturn($ownerUser);

		$priorUser = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($priorUser);

		$setUserCalls = [];
		$this->userSession
			->expects(self::exactly(2))
			->method('setUser')
			->willReturnCallback(function ($user) use (&$setUserCalls): void {
				$setUserCalls[] = $user;
			});

		$workRan = false;
		$result = $this->buildImpersonator()->runAsOwner('object-uuid', function () use (&$workRan): string {
			$workRan = true;
			return 'work-result';
		});

		self::assertTrue($workRan, 'the work callback must actually run');
		self::assertSame('work-result', $result, 'runAsOwner() must return whatever $work returns');
		self::assertCount(2, $setUserCalls, 'setUser() must be called exactly twice: impersonate, then restore');
		self::assertSame($ownerUser, $setUserCalls[0], 'first setUser() call must impersonate the object owner');
		self::assertSame($priorUser, $setUserCalls[1], 'second setUser() call must restore the pre-impersonation session user');
	}//end testRunAsOwnerImpersonatesOwnerAndRestoresSessionOnSuccess()

	/**
	 * The finally-block restore MUST fire even when `$work` throws —
	 * otherwise a failed transition would leave the impersonated identity
	 * bleeding into whatever the background-job process handles next
	 * (hermiq ScheduleService::runAgentAsOwner precedent: restore is
	 * unconditional). The exception must also propagate to the caller
	 * (runAsOwner does not swallow it).
	 *
	 * @return void
	 */
	public function testRunAsOwnerRestoresSessionUserWhenWorkThrows(): void {
		$ownerUid = 'bob';
		$object = new ObjectEntity();
		$object->setOwner($ownerUid);
		$this->resolvesObjectService($object);

		$ownerUser = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($ownerUid)->willReturn($ownerUser);

		$priorUser = $this->createMock(IUser::class);
		$this->userSession->method('getUser')->willReturn($priorUser);

		$setUserCalls = [];
		$this->userSession
			->expects(self::exactly(2))
			->method('setUser')
			->willReturnCallback(function ($user) use (&$setUserCalls): void {
				$setUserCalls[] = $user;
			});

		$thrown = new \RuntimeException('boom');

		$this->expectExceptionObject($thrown);

		try {
			$this->buildImpersonator()->runAsOwner('object-uuid', function () use ($thrown): never {
				throw $thrown;
			});
		} finally {
			self::assertCount(2, $setUserCalls, 'setUser() must still be called twice (impersonate, restore) despite the exception');
			self::assertSame($ownerUser, $setUserCalls[0]);
			self::assertSame($priorUser, $setUserCalls[1], 'the finally block must restore the session user even on failure');
		}
	}//end testRunAsOwnerRestoresSessionUserWhenWorkThrows()

	/**
	 * When the object has no recorded owner (empty/null), runAsOwner()
	 * must NOT call setUser() at all — there is nothing safe to
	 * impersonate. `$work` still runs under whatever identity (or lack of
	 * one) already exists.
	 *
	 * @return void
	 */
	public function testRunAsOwnerDoesNotImpersonateWhenObjectHasNoOwner(): void {
		$object = new ObjectEntity();
		// Owner deliberately left null.
		$this->resolvesObjectService($object);

		$this->userSession->expects(self::never())->method('setUser');
		$this->userManager->expects(self::never())->method('get');

		$workRan = false;
		$result = $this->buildImpersonator()->runAsOwner('object-uuid', function () use (&$workRan): string {
			$workRan = true;
			return 'ran-anyway';
		});

		self::assertTrue($workRan, 'work must still run even without an impersonatable owner');
		self::assertSame('ran-anyway', $result);
	}//end testRunAsOwnerDoesNotImpersonateWhenObjectHasNoOwner()

	/**
	 * When the recorded owner UID no longer resolves to a Nextcloud user
	 * (deleted account), runAsOwner() must NOT call setUser() — there is
	 * no IUser to impersonate. Must degrade gracefully rather than throw.
	 *
	 * @return void
	 */
	public function testRunAsOwnerDoesNotImpersonateWhenOwnerUserNoLongerExists(): void {
		$object = new ObjectEntity();
		$object->setOwner('deleted-user');
		$this->resolvesObjectService($object);

		$this->userManager->method('get')->with('deleted-user')->willReturn(null);
		$this->userSession->expects(self::never())->method('setUser');

		$workRan = false;
		$this->buildImpersonator()->runAsOwner('object-uuid', function () use (&$workRan): void {
			$workRan = true;
		});

		self::assertTrue($workRan, 'work must still run even when the owner cannot be resolved');
	}//end testRunAsOwnerDoesNotImpersonateWhenOwnerUserNoLongerExists()

	/**
	 * When OR's ObjectService isn't available in the container at all
	 * (older OR builds, or a unit test that never wires it), runAsOwner()
	 * must degrade to "no impersonation" rather than throw.
	 *
	 * @return void
	 */
	public function testRunAsOwnerDoesNotImpersonateWhenObjectServiceUnavailable(): void {
		$this->container->method('has')->willReturn(false);

		$this->userSession->expects(self::never())->method('setUser');

		$workRan = false;
		$this->buildImpersonator()->runAsOwner('object-uuid', function () use (&$workRan): void {
			$workRan = true;
		});

		self::assertTrue($workRan, 'work must still run even when ObjectService is unavailable');
	}//end testRunAsOwnerDoesNotImpersonateWhenObjectServiceUnavailable()

	/**
	 * When the object cannot be found at all (`find()` returns null),
	 * runAsOwner() must degrade to "no impersonation" rather than throw.
	 *
	 * @return void
	 */
	public function testRunAsOwnerDoesNotImpersonateWhenObjectNotFound(): void {
		$this->resolvesObjectService(null);

		$this->userSession->expects(self::never())->method('setUser');

		$workRan = false;
		$this->buildImpersonator()->runAsOwner('object-uuid', function () use (&$workRan): void {
			$workRan = true;
		});

		self::assertTrue($workRan, 'work must still run even when the object cannot be found');
	}//end testRunAsOwnerDoesNotImpersonateWhenObjectNotFound()

	/**
	 * The owner lookup MUST bypass RBAC and multitenancy.
	 *
	 * It runs before the impersonation it exists to set up, so the caller is
	 * still the background job's session — nobody. An RBAC-checked read is
	 * therefore evaluated as `Anonymous` and refused by any schema that does
	 * not grant anonymous `read`, which is a chicken-and-egg rather than a
	 * permission decision: you cannot read the object to learn its owner
	 * without already being someone.
	 *
	 * Observed on a live instance before this was fixed — every export sat at
	 * `status: queued` because the `start` transition could not fire:
	 *
	 *   OpenBuild: owner impersonation lookup failed for object <uuid>:
	 *   User 'Anonymous' does not have permission to 'read' objects in schema
	 *   'Export Job'
	 *
	 * Pinned as an explicit assertion because the failure it prevents is
	 * SILENT at the unit layer: with RBAC on, the mock still returns an
	 * object and every other test here keeps passing.
	 *
	 * @return void
	 */
	public function testOwnerLookupBypassesRbacAndMultitenancy(): void {
		$object = new ObjectEntity();
		$object->setOwner('alice');

		// ⚠️ A HAND-WRITTEN FAKE, NOT A PHPUnit MOCK. The production call uses
		// NAMED arguments (`_rbac: false`), and a mock cannot observe those:
		// when named arguments skip intermediate positions, PHP hands the
		// generated mock only the positional ones, so a `willReturnCallback`
		// sees its OWN defaults — `_rbac => true` — whether or not the fix is
		// present. The test then passes on broken code and fails on fixed
		// code, which is worse than no test.
		//
		// A real method binds named arguments correctly. The impersonator
		// resolves the service duck-typed (`method_exists($service, 'find')`)
		// rather than by type, so a two-method fake is a faithful stand-in.
		$objectService = new class($object) {

			/** @var array<string,bool> */
			public array $captured = [];

			public function __construct(private object $object) {
			}

			public function find(
				int|string $id,
				?array $_extend=[],
				bool $files=false,
				string|int|null $register=null,
				string|int|null $schema=null,
				bool $_rbac=true,
				bool $_multitenancy=true,
				bool $_render=true,
				bool $_audit=true
			): object {
				$this->captured = ['_rbac' => $_rbac, '_multitenancy' => $_multitenancy];
				return $this->object;
			}
		};

		$this->container->method('has')->willReturn(true);
		$this->container->method('get')->willReturn($objectService);

		$this->userManager->method('get')->willReturn($this->createMock(IUser::class));
		$this->userSession->method('getUser')->willReturn(null);

		$this->buildImpersonator()->runAsOwner('object-uuid', static function (): void {
		});

		self::assertSame(
			['_rbac' => false, '_multitenancy' => false],
			$objectService->captured,
			'the owner lookup must opt out of RBAC and multitenancy — checked, '
			. 'it runs as Anonymous here and is refused before impersonation starts'
		);
	}//end testOwnerLookupBypassesRbacAndMultitenancy()
}//end class
