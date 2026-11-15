<?php

/**
 * Unit tests for the failure arms of AgentsController::runs().
 *
 * The loaders behind `runs()` swallow a throwing object store and answer
 * `null`, which the endpoint then reports as "not found". That is the right
 * answer for a missing row and a misleading one for a broken store, so both
 * paths are pinned here: a parent application that is genuinely gone is a 404
 * naming the slug, and a store that throws AFTER the gate has passed surfaces
 * as a 500 rather than as an empty run list.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @covers \OCA\Buildiq\Controller\AgentsController
 *
 * @uses \OCA\Buildiq\Service\CallerContext
 * @uses \OCA\Buildiq\Service\PermissionResolver
 * @uses \OCA\Buildiq\Service\RegisterStore
 * @uses \OCA\Buildiq\Support\ObjectRecord
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\AgentsController;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the not-found and internal-error arms of runs().
 */
class AgentsControllerRunsFailuresTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 */
	private AgentsController $controller;

	/**
	 * Wire the controller over mocked boundaries, signed in as alice.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$register = new Register();
		$register->setId(1);
		$registerMapper->method('find')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schema = new Schema();
		$schema->setId(2);
		$schemaMapper->method('find')->willReturn($schema);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('getUserGroups')->willReturn([]);

		$this->controller = new AgentsController(
			request: $this->createMock(IRequest::class),
			logger: $this->createMock(LoggerInterface::class),
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $registerMapper,
				schemaMapper: $schemaMapper
			),
			caller: new CallerContext(
				userSession: $this->userSession,
				groupManager: $groupManager,
				permissionResolver: new PermissionResolver(
					groupManager: $groupManager,
					logger: $this->createMock(LoggerInterface::class)
				)
			)
		);
	}//end setUp()

	/**
	 * An agent whose parent application is gone is a 404 naming the slug.
	 *
	 * @return void
	 */
	public function testAnAgentWhoseApplicationIsGoneIsANotFound(): void {
		// The agent is there; the parent application row is no longer.
		$this->wireFind(
			agent: ['id' => 'agent-1', 'applicationSlug' => 'permit-tracker'],
			application: null
		);

		$response = $this->controller->runs(uuid: 'agent-1');
		$body = $response->getData();

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('not_found', $body['error']);
		self::assertStringContainsString('permit-tracker', $body['detail']);
	}//end testAnAgentWhoseApplicationIsGoneIsANotFound()

	/**
	 * An agent naming no application at all cannot be gated, so it is a 404
	 * too — the empty slug is never looked up.
	 *
	 * @return void
	 */
	public function testAnAgentNamingNoApplicationIsANotFound(): void {
		$schemasAsked = [];
		$agentEntity = $this->objectEntity(['id' => 'agent-1']);
		$this->objectService->method('find')->willReturnCallback(
			static function (
				string|int $id,
				mixed $extend,
				mixed $files,
				mixed $register,
				mixed $schema,
				mixed ...$rest
			) use ($agentEntity, &$schemasAsked) {
				$schemasAsked[] = $schema;
				return ($schema === 'buildAgent' ? $agentEntity : null);
			}
		);

		$response = $this->controller->runs(uuid: 'agent-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('not_found', $response->getData()['error']);
		self::assertNotContains(
			'built-app',
			$schemasAsked,
			'An empty slug is never looked up: the guard returns before the store is asked.'
		);
	}//end testAnAgentNamingNoApplicationIsANotFound()

	/**
	 * A store that throws while loading the agent reads as "not found", not
	 * as a crash — the loader contains it so a transient read cannot 500 the
	 * page.
	 *
	 * @return void
	 */
	public function testAThrowingStoreDuringTheAgentLoadReadsAsNotFound(): void {
		$this->objectService->method('find')
			->willThrowException(new RuntimeException('object store down'));

		$response = $this->controller->runs(uuid: 'agent-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame('not_found', $response->getData()['error']);
	}//end testAThrowingStoreDuringTheAgentLoadReadsAsNotFound()

	/**
	 * A failure while reading the runs is an internal error, by design: the
	 * loader deliberately does not swallow it, because an empty list would
	 * misreport a broken read as "this agent has never run" on the very
	 * endpoint whose purpose is that runs are reviewable.
	 *
	 * @return void
	 */
	public function testAFailureAfterTheGateIsAnInternalError(): void {
		$this->wireFind(
			agent: ['id' => 'agent-1', 'applicationSlug' => 'permit-tracker'],
			application: ['slug' => 'permit-tracker', 'permissions' => ['owners' => ['user:alice']]]
		);
		$this->objectService->method('searchObjects')
			->willThrowException(new RuntimeException('run index unavailable'));

		$response = $this->controller->runs(uuid: 'agent-1');
		$body = $response->getData();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $body['error']);
	}//end testAFailureAfterTheGateIsAnInternalError()

	/**
	 * A permissions field that is not an array grants nothing, so a caller
	 * who would otherwise be an owner is refused rather than let through.
	 *
	 * @return void
	 */
	public function testMalformedPermissionsGrantNothing(): void {
		$this->wireFind(
			agent: ['id' => 'agent-1', 'applicationSlug' => 'permit-tracker'],
			application: ['slug' => 'permit-tracker', 'permissions' => 'owners:alice']
		);

		$response = $this->controller->runs(uuid: 'agent-1');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame('insufficient_permission', $response->getData()['error']);
	}//end testMalformedPermissionsGrantNothing()

	/**
	 * Build a mocked `ObjectEntity` whose `jsonSerialize()` returns the payload.
	 *
	 * @param array<string, mixed> $payload The payload to serialise.
	 *
	 * @return ObjectEntity&MockObject The entity double.
	 */
	private function objectEntity(array $payload): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end objectEntity()

	/**
	 * Answer `find()` per schema.
	 *
	 * ObjectService::find()'s real positional signature is
	 * (id, extend, files, register, schema, rbac, multitenancy). The
	 * controller calls it with named arguments, but PHP resolves those to the
	 * full positional list before the mock sees them, so the callback has to
	 * destructure by POSITION.
	 *
	 * @param array<string, mixed>|null $agent The agent record, or null.
	 * @param array<string, mixed>|null $application The application record, or null.
	 *
	 * @return void
	 */
	private function wireFind(?array $agent, ?array $application): void {
		$agentEntity = ($agent === null ? null : $this->objectEntity($agent));
		$applicationEntity = ($application === null ? null : $this->objectEntity($application));

		$this->objectService->method('find')->willReturnCallback(
			static function (
				string|int $id,
				mixed $extend,
				mixed $files,
				mixed $register,
				mixed $schema,
				mixed ...$rest
			) use ($agentEntity, $applicationEntity) {
				if ($schema === 'buildAgent') {
					return $agentEntity;
				}

				if ($schema === 'built-app') {
					return $applicationEntity;
				}

				return null;
			}
		);
	}//end wireFind()
}//end class
