<?php

/**
 * FlowBindingsControllerTest
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-binding-runs-the-flow-on-the-apps-records-req-bqsf-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\FlowBindingsController;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\FlowBindingWrapperService;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests who may wire an application's flow bindings, and with which register.
 *
 * @covers \OCA\Buildiq\Controller\FlowBindingsController
 */
class FlowBindingsControllerTest extends TestCase {

	/**
	 * The Application's uuid.
	 *
	 * @var string
	 */
	private const APP = 'a1b2c3d4-0000-4000-8000-000000000001';

	/**
	 * The production version's uuid.
	 *
	 * @var string
	 */
	private const VERSION = 'b1b2c3d4-0000-4000-8000-000000000002';

	/**
	 * The wrapper service.
	 *
	 * @var FlowBindingWrapperService&MockObject
	 */
	private FlowBindingWrapperService&MockObject $wrappers;

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The permission resolver.
	 *
	 * @var PermissionResolverInterface&MockObject
	 */
	private PermissionResolverInterface&MockObject $permissions;

	/**
	 * The session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The stored objects, by uuid.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $stored = [];

	/**
	 * Wire app `permits` owned by `sanne`, whose production version uses register `permits-register`.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->stored = [
			self::APP => ['slug' => 'permits', 'productionVersion' => self::VERSION, 'permissions' => ['owners' => ['user:sanne']], 'flows' => []],
			self::VERSION => ['register' => 'permits-register'],
		];
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				if (isset($this->stored[$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($this->stored[$id]);
				return $entity;
			}
		);

		$this->wrappers = $this->createMock(FlowBindingWrapperService::class);
		$this->permissions = $this->createMock(PermissionResolverInterface::class);
		$this->permissions->method('resolveUserGroups')->willReturn([]);
		$this->permissions->method('matchesCaller')->willReturnCallback(
			static fn (array $permissions, IUser $caller, array $userGroups, ?bool $allowAdminBypass, array $roles): bool => $roles === ['owners']
				&& in_array('user:' . $caller->getUID(), (array)($permissions['owners'] ?? []), true)
		);
		$this->session = $this->createMock(IUserSession::class);

	}//end setUp()

	/**
	 * The controller, signed in as `$uid` (or nobody).
	 *
	 * @param string|null $uid The caller.
	 *
	 * @return FlowBindingsController The controller.
	 */
	private function controller(?string $uid): FlowBindingsController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$this->session->method('getUser')->willReturn($user);

		return new FlowBindingsController(
			$this->createMock(IRequest::class),
			$this->wrappers,
			$this->objects,
			$this->session,
			$this->permissions,
			$this->createMock(LoggerInterface::class)
		);

	}//end controller()

	/**
	 * An owner's request syncs with the production version's register and answers the result.
	 *
	 * @return void
	 */
	public function testAnOwnerSyncsWithTheProductionRegister(): void {
		$answer = ['flows' => [['flow' => 'x']], 'created' => 1, 'removed' => 0, 'failed' => []];
		$this->wrappers->expects($this->once())->method('sync')
			->with(self::APP, $this->stored[self::APP], 'permits-register')
			->willReturn($answer);

		$response = $this->controller('sanne')->sync(self::APP);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: $answer, actual: $response->getData());

	}//end testAnOwnerSyncsWithTheProductionRegister()

	/**
	 * An embedded production version gives its register directly.
	 *
	 * @return void
	 */
	public function testAnEmbeddedProductionVersionGivesItsRegister(): void {
		$this->stored[self::APP]['productionVersion'] = ['register' => 'embedded-register'];
		$this->wrappers->expects($this->once())->method('sync')
			->with(self::APP, $this->anything(), 'embedded-register')
			->willReturn(['flows' => [], 'created' => 0, 'removed' => 0, 'failed' => []]);

		$this->controller('sanne')->sync(self::APP);

	}//end testAnEmbeddedProductionVersionGivesItsRegister()

	/**
	 * An editor, an unknown app and an anonymous caller are refused before anything is synced.
	 *
	 * @return void
	 */
	public function testOnlyAnOwnerOfAKnownAppMaySync(): void {
		$this->wrappers->expects($this->never())->method('sync');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $this->controller('colleague')->sync(self::APP)->getStatus());

		$this->setUp();
		$this->wrappers->expects($this->never())->method('sync');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $this->controller('sanne')->sync('ffffffff-0000-4000-8000-000000000000')->getStatus());

		$this->setUp();
		$this->wrappers->expects($this->never())->method('sync');
		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $this->controller(null)->sync(self::APP)->getStatus());

	}//end testOnlyAnOwnerOfAKnownAppMaySync()

	/**
	 * A failing sync answers 500 without its message.
	 *
	 * @return void
	 */
	public function testAFailingSyncAnswersAnInternalError(): void {
		$this->wrappers->method('sync')->willThrowException(new RuntimeException('database at db.internal is gone'));

		$response = $this->controller('sanne')->sync(self::APP);

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'internal_error'], actual: $response->getData());

	}//end testAFailingSyncAnswersAnInternalError()
}//end class
