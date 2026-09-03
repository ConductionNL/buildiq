<?php

/**
 * Unit tests for AutomationWriteService.
 *
 * Covers spec REQ-AUTD-008 for the automation WRITE path extracted out of
 * AutomationsController (Conduction/buildiq#173):
 *
 *   - create() 403s a caller who holds no role on the parent Application;
 *   - create() 400s when `applicationSlug` or `versionUuid` is missing — they
 *     ARE the authorization scope, so an unscoped create must never be a silent
 *     allow;
 *   - create() 201s an authorised caller and writes with `_rbac: false`;
 *   - update() PINS `applicationSlug`/`versionUuid` to the STORED values, so a
 *     caller cannot re-parent an automation into an application they do hold a
 *     role on;
 *   - destroy() removes the compiled artifacts BEFORE deleting the object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\Buildiq\Service\AutomationWriteService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for {@see AutomationWriteService}.
 */
final class AutomationWriteServiceTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var AutomationCompilerService&MockObject
	 */
	private AutomationCompilerService&MockObject $compiler;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * The service under test.
	 *
	 * @var AutomationWriteService
	 */
	private AutomationWriteService $service;

	/**
	 * The Application fixture every test authorises against.
	 *
	 * `alice` owns it, `bob` edits it, `eve-outsider` holds nothing.
	 *
	 * @var array<string,mixed>
	 */
	private const APPLICATION = [
		'id' => 'app-1',
		'slug' => 'permit-tracker',
		'permissions' => [
			'owners' => ['user:alice'],
			'editors' => ['user:bob'],
		],
	];

	/**
	 * Wire the service with mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->compiler = $this->createMock(AutomationCompilerService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn(false);

		$this->service = new AutomationWriteService(
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			compiler: $this->compiler,
			permissionResolver: new PermissionResolver($this->groupManager, $this->createMock(LoggerInterface::class)),
			userSession: $this->userSession
		);

	}//end setUp()

	/**
	 * Wire a Nextcloud user with the given UID into the session mock.
	 *
	 * @param string $uid The UID.
	 *
	 * @return void
	 */
	private function wireUser(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end wireUser()

	/**
	 * Build an ObjectEntity carrying a JSON payload (mirrors
	 * AutomationsControllerTest's fixture builder).
	 *
	 * @param array<string,mixed> $payload The inner payload.
	 *
	 * @return ObjectEntity
	 */
	private function buildEntity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string,mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;

		return $entity;
	}//end buildEntity()

	/**
	 * Zero-based position of a named parameter on an ObjectService method.
	 *
	 * PHPUnit records mock invocations POSITIONALLY even when the production
	 * code passes named arguments, so an assertion on `_rbac` has to know where
	 * `_rbac` sits. Resolving that by NAME via reflection keeps the assertion
	 * honest if OpenRegister ever reorders the signature — the alternative, a
	 * hardcoded index, would silently start asserting on a different argument.
	 *
	 * @param string $method The ObjectService method name.
	 * @param string $name The parameter name.
	 *
	 * @return int
	 */
	private function argumentIndex(string $method, string $name): int {
		$parameters = (new ReflectionMethod(ObjectService::class, $method))->getParameters();
		foreach ($parameters as $index => $parameter) {
			if ($parameter->getName() === $name) {
				return $index;
			}
		}

		$this->fail(sprintf('ObjectService::%s() has no $%s parameter', $method, $name));

	}//end argumentIndex()

	/**
	 * REQ-AUTD-008: a caller with no role on the parent Application is
	 * forbidden, and nothing is written.
	 *
	 * @return void
	 */
	public function testCreateReturns403ForNonMember(): void {
		$this->wireUser(uid: 'eve-outsider');
		$this->objectService->method('find')->willReturn($this->buildEntity(self::APPLICATION));
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->service->create(
			payload: ['applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'slug' => 'nag'],
			roles: ['owners', 'editors']
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('insufficient_permission', $response->getData()['error']);

	}//end testCreateReturns403ForNonMember()

	/**
	 * REQ-AUTD-008: `applicationSlug` is the authorization scope — without it
	 * there is no `permissions` block to check, so the call is a 400 and never
	 * a silent allow.
	 *
	 * @return void
	 */
	public function testCreateReturns400WithoutApplicationSlug(): void {
		$this->wireUser(uid: 'alice');
		$this->objectService->expects($this->never())->method('find');
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->service->create(
			payload: ['versionUuid' => 'draft-version', 'slug' => 'nag'],
			roles: ['owners', 'editors']
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);

	}//end testCreateReturns400WithoutApplicationSlug()

	/**
	 * REQ-AUTD-008: `versionUuid` is equally part of the scope — a create
	 * without it is a 400, not an unscoped write.
	 *
	 * @return void
	 */
	public function testCreateReturns400WithoutVersionUuid(): void {
		$this->wireUser(uid: 'alice');
		$this->objectService->expects($this->never())->method('find');
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->service->create(
			payload: ['applicationSlug' => 'permit-tracker', 'slug' => 'nag'],
			roles: ['owners', 'editors']
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('invalid_request', $response->getData()['error']);

	}//end testCreateReturns400WithoutVersionUuid()

	/**
	 * REQ-AUTD-008: an editor of the parent Application gets a 201, and the
	 * write goes out in SYSTEM CONTEXT (`_rbac: false`) — buildiq has already
	 * made the finer-grained decision OR's coarse schema ACL cannot express.
	 *
	 * @return void
	 */
	public function testCreateReturns201AndSavesWithRbacDisabled(): void {
		$this->wireUser(uid: 'bob');
		$this->objectService->method('find')->willReturn($this->buildEntity(self::APPLICATION));

		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (...$arguments) use (&$captured): ObjectEntity {
				$captured = $arguments;
				return $this->buildEntity(['id' => 'a-new', 'slug' => 'nag']);
			});

		$response = $this->service->create(
			payload: ['applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version', 'slug' => 'nag'],
			roles: ['owners', 'editors']
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'a-new', 'slug' => 'nag'], $response->getData());

		$this->assertIsArray($captured);
		$this->assertFalse(
			$captured[$this->argumentIndex(method: 'saveObject', name: '_rbac')],
			'create() must save with _rbac:false — the per-Application check above IS the authorization decision'
		);
		// No uuid on create: OpenRegister mints it.
		$this->assertNull($captured[$this->argumentIndex(method: 'saveObject', name: 'uuid')]);

	}//end testCreateReturns201AndSavesWithRbacDisabled()

	/**
	 * REQ-AUTD-008 (the security-relevant one): update() PINS the ownership
	 * fields to the STORED record. A caller who legitimately edits application
	 * B cannot re-parent that automation into application A by naming A in the
	 * body — the assertion is on the array actually handed to saveObject().
	 *
	 * @return void
	 */
	public function testUpdatePinsApplicationScopeToStoredValues(): void {
		$captured = null;
		$this->objectService->expects($this->once())
			->method('saveObject')
			->willReturnCallback(function (...$arguments) use (&$captured): ObjectEntity {
				$captured = $arguments;
				return $this->buildEntity(['id' => 'a-1']);
			});

		$response = $this->service->update(
			uuid: 'a-1',
			payload: [
				// The caller ATTEMPTS to re-parent into an application they own.
				'applicationSlug' => 'attacker-owned-app',
				'versionUuid' => 'attacker-version',
				'slug' => 'renamed',
			],
			automation: [
				'id' => 'a-1',
				'applicationSlug' => 'permit-tracker',
				'versionUuid' => 'draft-version',
			]
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$this->assertIsArray($captured);
		$written = $captured[$this->argumentIndex(method: 'saveObject', name: 'object')];
		$this->assertSame('permit-tracker', $written['applicationSlug']);
		$this->assertSame('draft-version', $written['versionUuid']);
		// The rest of the definition IS the caller's to rewrite.
		$this->assertSame('renamed', $written['slug']);
		$this->assertSame('a-1', $captured[$this->argumentIndex(method: 'saveObject', name: 'uuid')]);
		$this->assertFalse($captured[$this->argumentIndex(method: 'saveObject', name: '_rbac')]);

	}//end testUpdatePinsApplicationScopeToStoredValues()

	/**
	 * REQ-AUTD-008: destroy() removes the compiled artifacts BEFORE deleting
	 * the definition. The reverse order leaves the instance acting on a rule
	 * nobody can see or edit any more, so the ORDER is the assertion.
	 *
	 * @return void
	 */
	public function testDestroyRemovesArtifactsBeforeDeletingTheObject(): void {
		$order = [];

		$this->compiler->expects($this->once())
			->method('remove')
			->willReturnCallback(function (array $automation, array $provenance) use (&$order): void {
				$order[] = 'remove';
				$this->assertSame(['notificationKeys' => ['k-1']], $provenance);
				$this->assertSame('a-1', $automation['id']);
			});

		$captured = null;
		$this->objectService->expects($this->once())
			->method('deleteObject')
			->willReturnCallback(function (...$arguments) use (&$order, &$captured): bool {
				$order[] = 'deleteObject';
				$captured = $arguments;
				return true;
			});

		$response = $this->service->destroy(
			uuid: 'a-1',
			automation: [
				'id' => 'a-1',
				'applicationSlug' => 'permit-tracker',
				'versionUuid' => 'draft-version',
				'provenance' => ['notificationKeys' => ['k-1']],
			]
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['deleted' => 'a-1'], $response->getData());
		$this->assertSame(
			['remove', 'deleteObject'],
			$order,
			'compiled artifacts must be removed BEFORE the definition is deleted'
		);

		$this->assertIsArray($captured);
		$this->assertFalse($captured[$this->argumentIndex(method: 'deleteObject', name: '_rbac')]);

	}//end testDestroyRemovesArtifactsBeforeDeletingTheObject()

	/**
	 * A missing parent Application is a 404, not an unchecked write.
	 *
	 * @return void
	 */
	public function testCreateReturns404WhenApplicationMissing(): void {
		$this->wireUser(uid: 'alice');
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->service->create(
			payload: ['applicationSlug' => 'ghost-app', 'versionUuid' => 'draft-version'],
			roles: ['owners', 'editors']
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testCreateReturns404WhenApplicationMissing()

	/**
	 * No session user at all is a 401 — checked before the scope validation, so
	 * an anonymous caller never learns whether a slug exists.
	 *
	 * @return void
	 */
	public function testCreateReturns401WhenUnauthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->service->create(
			payload: ['applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
			roles: ['owners', 'editors']
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());

	}//end testCreateReturns401WhenUnauthenticated()

	/**
	 * An OpenRegister failure inside the authorised write maps to the uniform
	 * 500 envelope rather than escaping as a raw exception.
	 *
	 * @return void
	 */
	public function testCreateMapsStorageFailureToInternalError(): void {
		$this->wireUser(uid: 'alice');
		$this->objectService->method('find')->willReturn($this->buildEntity(self::APPLICATION));
		$this->objectService->method('saveObject')->willThrowException(new \RuntimeException('OR exploded'));

		$response = $this->service->create(
			payload: ['applicationSlug' => 'permit-tracker', 'versionUuid' => 'draft-version'],
			roles: ['owners', 'editors']
		);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('internal_error', $response->getData()['error']);

	}//end testCreateMapsStorageFailureToInternalError()

	/**
	 * requestBody() strips the route placeholders NC merges into getParams(),
	 * so `_route`/`uuid` can never be written onto the stored object as if they
	 * were automation properties.
	 *
	 * @return void
	 */
	public function testRequestBodyStripsRoutePlaceholders(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(
			[
				'_route' => 'buildiq.automations.update',
				'uuid' => 'a-1',
				'applicationSlug' => 'permit-tracker',
				'slug' => 'nag',
			]
		);

		$this->assertSame(
			['applicationSlug' => 'permit-tracker', 'slug' => 'nag'],
			$this->service->requestBody(request: $request)
		);

	}//end testRequestBodyStripsRoutePlaceholders()

	/**
	 * 🔴 requestBody() also strips the keys that ADDRESS an object.
	 *
	 * `saveObject()` resolves its target from the payload —
	 * `extractUuidAndNormalizeObject()` reads `@self.id` first, then `id` — and
	 * the write is PUT-semantic, so every omitted field is NULLED.
	 * `saveAuthorised()` passes `_rbac: false`, so nothing downstream refuses
	 * it, and the create route is `#[NoAdminRequired]`.
	 *
	 * The authorization guard does not cover this: `withApplication()`
	 * authorises the `applicationSlug` the CALLER CLAIMS, while the object
	 * written is whatever the payload's identity points at. Stripping `uuid`
	 * alone left the two keys that actually do the addressing.
	 *
	 * @return void
	 */
	public function testRequestBodyStripsCallerSuppliedIdentity(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(
			[
				'_route' => 'buildiq.automations.create',
				'uuid' => 'a-1',
				'id' => 'someone-elses-automation',
				'@self' => ['id' => 'someone-elses-automation'],
				'applicationSlug' => 'permit-tracker',
				'slug' => 'nag',
			]
		);

		$body = $this->service->requestBody(request: $request);

		$this->assertArrayNotHasKey('id', $body);
		$this->assertArrayNotHasKey('uuid', $body);
		$this->assertArrayNotHasKey('@self', $body, '@self.id is the key saveObject reads FIRST');
		$this->assertSame('permit-tracker', $body['applicationSlug'], 'the real payload survives');
		$this->assertSame('nag', $body['slug']);

	}//end testRequestBodyStripsCallerSuppliedIdentity()
}//end class
