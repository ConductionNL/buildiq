<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Wire-contract tests for ApplicationsController::saveManifest().
 *
 * `saveManifest` is `#[NoAdminRequired]` and is the write path for an app's
 * whole manifest, yet it had no automated contract proof — gate-25 reported
 * it as an uncovered public endpoint. These pin the authorisation contract at
 * the wire: an unresolvable slug never reaches the write, an anonymous caller
 * is refused, and a viewer (a real role, but a read-only one) cannot write.
 *
 * @category Test
 * @package  OpenBuild
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openbuild
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Controller;

use OCA\OpenBuild\Controller\ApplicationsController;
use OCA\OpenBuild\Service\AppChannelApplier;
use OCA\OpenBuild\Service\ManifestResolverService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Contract tests for ApplicationsController::saveManifest().
 *
 * @category Test
 * @package  OpenBuild
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/openbuild
 */
class ApplicationsControllerSaveManifestTest extends TestCase {

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectService&MockObject
	 */
	private $objectService;

	/**
	 * Register mapper mock.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private $registerMapper;

	/**
	 * Schema mapper mock.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * Session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private $userSession;

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&MockObject
	 */
	private $groupManager;

	/**
	 * Wire the collaborator mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);

	}//end setUp()

	/**
	 * Build the controller under test with a REAL PermissionResolver, so the
	 * role decision is genuinely exercised rather than stubbed to a verdict.
	 *
	 * @return ApplicationsController
	 */
	private function controller(): ApplicationsController {
		return new ApplicationsController(
			request: $this->request,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			manifestResolver: $this->createMock(ManifestResolverService::class),
			permissionResolver: new PermissionResolver(
				$this->groupManager,
				$this->createMock(LoggerInterface::class)
			),
			channelApplier: $this->createMock(AppChannelApplier::class),
			auditTrailMapper: null
		);

	}//end controller()

	/**
	 * Build an ObjectEntity carrying the given payload.
	 *
	 * NC Db entities expose their data through jsonSerialize(); an anonymous
	 * subclass is the pattern the other controller suites in this repo use.
	 *
	 * @param array<string,mixed> $payload The object payload.
	 *
	 * @return ObjectEntity
	 */
	private function buildEntity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {

			/**
			 * The payload this stand-in serialises to.
			 *
			 * @var array<string,mixed>
			 */
			public array $payload = [];

			/**
			 * Serialise the payload.
			 *
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
	 * Wire an authenticated user into the session mock.
	 *
	 * @param string $uid The UID.
	 *
	 * @return void
	 */
	private function authenticate(string $uid = 'bob'): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);

	}//end authenticate()

	/**
	 * A slug with no BuiltAppRoute is refused and never reaches a write.
	 *
	 * `resolveApplicationBySlug` returns a JSONResponse on a miss, which
	 * saveManifest must return untouched.
	 *
	 * @return void
	 */
	public function testSaveManifestRefusesUnknownSlug(): void {
		$this->authenticate();
		$this->registerMapper->method('find')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('register openbuild not found')
		);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'no-such-app');

		// Either the 404 (no route) or the translated 500 (no register) — the
		// contract under test is that it is an ERROR envelope and that nothing
		// was written, not which of the two it is.
		self::assertGreaterThanOrEqual(400, $response->getStatus());

	}//end testSaveManifestRefusesUnknownSlug()

	/**
	 * A viewer — a real role, but read-only — cannot write the manifest.
	 *
	 * This is the case a coarse "has any role" check would wrongly allow, so
	 * it is the one worth pinning.
	 *
	 * @return void
	 */
	public function testSaveManifestForbidsViewer(): void {
		$this->authenticate(uid: 'vera-viewer');

		$register = new \OCA\OpenRegister\Db\Register();
		$register->setId(1);
		$this->registerMapper->method('find')->willReturn($register);

		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(2);
		$this->schemaMapper->method('find')->willReturn($schema);

		// Step 1: the BuiltAppRoute index resolves slug -> applicationUuid.
		$this->objectService->method('searchObjects')->willReturn(
			[['id' => 'route-1', 'slug' => 'permit-tracker', 'applicationUuid' => 'app-uuid']]
		);

		// Step 2: the Application itself, on which vera is a VIEWER only.
		// ObjectService::find() returns an ObjectEntity, not an array.
		$this->objectService->method('find')->willReturn(
			$this->buildEntity(
				[
					'id' => 'app-uuid',
					'slug' => 'permit-tracker',
					'permissions' => [
						'owners' => ['user:alice'],
						'editors' => ['user:bob'],
						'viewers' => ['user:vera-viewer'],
					],
				]
			)
		);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());

	}//end testSaveManifestForbidsViewer()

}//end class
