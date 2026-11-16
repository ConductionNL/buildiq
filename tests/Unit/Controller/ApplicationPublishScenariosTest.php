<?php

/**
 * The publish and unpublish scenarios of REQ-BQCV-002 against ApplicationPublishController.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-runtime/spec.md#requirement-an-owner-publishes-and-unpublishes-an-app-req-bqcv-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationPublishController;
use OCA\Buildiq\Service\ApplicationDeletionService;
use OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\Buildiq\Service\PermissionResolver;
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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * An owner publishes and unpublishes; a viewer is refused and nothing is written.
 */
class ApplicationPublishScenariosTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The controller under test.
	 *
	 * @var ApplicationPublishController
	 */
	private ApplicationPublishController $controller;

	/**
	 * Build the controller with a real PermissionResolver over a group manager without groups.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession   = $this->createMock(IUserSession::class);
		$groupManager        = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$groupManager->method('getUserGroupIds')->willReturn([]);
		$groupManager->method('isAdmin')->willReturn(false);

		$this->controller = new ApplicationPublishController(
			request: $this->createMock(IRequest::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			userSession: $this->userSession,
			permissionResolver: new PermissionResolver($groupManager, $this->createMock(LoggerInterface::class)),
			deletionService: $this->createMock(ApplicationDeletionService::class),
			credentialRegistrar: $this->createMock(VirtualAppCredentialRegistrar::class),
		);
	}//end setUp()

	/**
	 * A stored Application owned by alice, with bob as viewer.
	 *
	 * @param string $status The stored status.
	 *
	 * @return ObjectEntity
	 */
	private function application(string $status): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * The payload.
			 *
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * The payload as JSON.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}

			/**
			 * The payload.
			 *
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->payload;
			}
		};

		$entity->payload = [
			'id'          => 'u-app',
			'slug'        => 'demo',
			'name'        => 'Demo',
			'status'      => $status,
			'versions'    => ['v-1', 'v-2'],
			'pages'       => [['id' => 'list']],
			'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => ['user:bob']],
		];
		return $entity;
	}//end application()

	/**
	 * Sign in as the given user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return void
	 */
	private function signInAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signInAs()

	/**
	 * Scenario: an owner unpublishes; only the status of the Application changes, nothing is deleted.
	 *
	 * @return void
	 */
	public function testUnpublishWritesOnlyTheStatusAndDeletesNothing(): void {
		$this->signInAs(uid: 'alice');
		$this->objectService->method('find')->willReturn($this->application(status: 'published'));

		$saved = [];
		$this->objectService->expects($this->once())->method('saveObject')->willReturnCallback(
			function (array $object, ObjectSaveOptions $options = new ObjectSaveOptions()) use (&$saved): ObjectEntity {
				$extend = $options->extend ?? [];
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$saved = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				return $this->application(status: $object['status']);
			}
		);
		$this->objectService->expects($this->never())->method('deleteObject');

		$response = $this->controller->unpublish('u-app');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('draft', $response->getData()['status']);
		self::assertSame('built-app', $saved['schema']);
		self::assertSame('u-app', $saved['uuid']);
		self::assertSame('draft', $saved['object']['status']);
		self::assertSame(['v-1', 'v-2'], $saved['object']['versions']);
		self::assertSame([['id' => 'list']], $saved['object']['pages']);
		self::assertSame(['user:alice'], $saved['object']['permissions']['owners']);
	}//end testUnpublishWritesOnlyTheStatusAndDeletesNothing()

	/**
	 * Scenario: a viewer cannot publish; the call is refused with 403 and nothing is written.
	 *
	 * @return void
	 */
	public function testAViewerIsRefusedAndTheStatusStaysDraft(): void {
		$this->signInAs(uid: 'bob');
		$this->objectService->method('find')->willReturn($this->application(status: 'draft'));
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->publish('u-app');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertArrayNotHasKey('status', $response->getData());
	}//end testAViewerIsRefusedAndTheStatusStaysDraft()

	/**
	 * A viewer cannot unpublish either.
	 *
	 * @return void
	 */
	public function testAViewerCannotUnpublish(): void {
		$this->signInAs(uid: 'bob');
		$this->objectService->method('find')->willReturn($this->application(status: 'published'));
		$this->objectService->expects($this->never())->method('saveObject');

		$response = $this->controller->unpublish('u-app');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAViewerCannotUnpublish()
}//end class
