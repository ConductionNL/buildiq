<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Write-target tests for ApplicationsController::saveManifest().
 *
 * The contract suite pins who may write; these pin where an accepted manifest
 * lands — the production ApplicationVersion, an embedded version on the
 * Application, or the legacy application-level manifest — that a body can
 * never overwrite the permissions roster, and that an admin's write on an app
 * they hold no role on is audited.
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\RegisterStore;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
 * Write-target tests for ApplicationsController::saveManifest().
 *
 * @covers \OCA\Buildiq\Controller\ApplicationsController
 * @uses   \OCA\Buildiq\Service\RegisterStore
 * @uses   \OCA\Buildiq\Service\CallerContext
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 *
 * @category Test
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
 */
class ApplicationsControllerSaveManifestWritesTest extends TestCase {

	/**
	 * A manifest the runtime can render.
	 *
	 * @var array<string,mixed>
	 */
	private const MANIFEST = ['version' => '1.2.0', 'pages' => [['id' => 'home']]];

	/**
	 * Request mock.
	 *
	 * @var IRequest&MockObject
	 */
	private $request;

	/**
	 * OpenRegister object service mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private $objectService;

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
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

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
	 * Whether the caller is in the admin group.
	 *
	 * @var bool
	 */
	private bool $isAdmin = false;

	/**
	 * Objects find() returns, by schema then id.
	 *
	 * @var array<string,array<string,ObjectEntity|null>>
	 */
	private array $objects = [];

	/**
	 * Wire the collaborator mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);

		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturnCallback(fn (): bool => $this->isAdmin);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => $gid === 'admin' && $this->isAdmin
		);

		$register = new Register();
		$register->setId(1);
		$this->registerMapper->method('find')->willReturn($register);
		$schema = new Schema();
		$schema->setId(2);
		$this->schemaMapper->method('find')->willReturn($schema);

		// The BuiltAppRoute index resolves the slug to the Application.
		$this->objectService->method('searchObjects')->willReturn(
			[['id' => 'route-1', 'slug' => 'permit-tracker', 'applicationUuid' => 'app-uuid']]
		);
		$this->objectService->method('find')->willReturnCallback(
			fn (string|int $id, mixed $_extend = [], mixed $files = false, mixed $register = null, mixed $schema = null): ?ObjectEntity
				=> ($this->objects[(string)$schema][$id] ?? null)
		);

	}//end setUp()

	/**
	 * Build the controller under test.
	 *
	 * @param AuditTrailMapper|null $auditTrailMapper The audit trail mapper, if any.
	 *
	 * @return ApplicationsController
	 */
	private function controller(?AuditTrailMapper $auditTrailMapper = null): ApplicationsController {
		return new ApplicationsController(
			request: $this->request,
			logger: $this->logger,
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper,
				auditTrailMapper: $auditTrailMapper
			),
			caller: new CallerContext(
				userSession: $this->userSession,
				groupManager: $this->groupManager,
				permissionResolver: new PermissionResolver(
					$this->groupManager,
					$this->createMock(LoggerInterface::class)
				)
			),
			manifestResolver: $this->createMock(ManifestResolverService::class),
			channelApplier: $this->createMock(AppChannelApplier::class)
		);

	}//end controller()

	/**
	 * Build an ObjectEntity carrying the given payload.
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
	 * Store the Application find() resolves for the route.
	 *
	 * @param array<string,mixed> $application The Application payload.
	 *
	 * @return ObjectEntity The stored entity.
	 */
	private function givenApplication(array $application): ObjectEntity {
		$entity = $this->buildEntity(array_merge(['id' => 'app-uuid', 'slug' => 'permit-tracker'], $application));
		$this->objects['built-app']['app-uuid'] = $entity;

		return $entity;
	}//end givenApplication()

	/**
	 * Wire an authenticated user and the request's manifest body.
	 *
	 * @param string $uid      The UID.
	 * @param mixed  $manifest The `manifest` request parameter.
	 *
	 * @return void
	 */
	private function asUserSending(string $uid, mixed $manifest): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturnMap([['manifest', null, $manifest]]);

	}//end asUserSending()

	/**
	 * Expect exactly one saveObject call and capture its arguments.
	 *
	 * @param array<string,mixed> $saved Receives the saved object, register and schema.
	 *
	 * @return void
	 */
	private function captureSave(array &$saved): void {
		$this->objectService->expects(self::once())->method('saveObject')->willReturnCallback(
			function (mixed $object, mixed $extend = null, mixed $register = null, mixed $schema = null) use (&$saved) {
				$saved = ['object' => $object, 'register' => $register, 'schema' => $schema];
				return $this->buildEntity([]);
			}
		);

	}//end captureSave()

	/**
	 * A UUID production version receives the manifest, minus any permissions
	 * key the body tried to smuggle in.
	 *
	 * @return void
	 */
	public function testWritesOntoTheProductionVersion(): void {
		$this->givenApplication(['productionVersion' => 'v-1', 'permissions' => ['owners' => ['user:alice']]]);
		$this->objects['applicationVersion']['v-1'] = $this->buildEntity(['id' => 'v-1', 'semver' => '1.0.0', 'manifest' => ['old' => true]]);
		$this->asUserSending(uid: 'alice', manifest: self::MANIFEST + ['permissions' => ['owners' => ['user:mallory']]]);
		$saved = [];
		$this->captureSave($saved);

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'ok', 'target' => 'version'], $response->getData());
		self::assertSame(['id' => 'v-1', 'semver' => '1.0.0', 'manifest' => self::MANIFEST], $saved['object']);
		self::assertSame('buildiq', $saved['register']);
		self::assertSame('applicationVersion', $saved['schema']);

	}//end testWritesOntoTheProductionVersion()

	/**
	 * A production version uuid that no longer resolves falls back to the
	 * Application-level manifest.
	 *
	 * @return void
	 */
	public function testAnUnresolvableProductionVersionFallsBackToTheApplication(): void {
		$this->givenApplication(['productionVersion' => 'v-gone', 'permissions' => ['editors' => ['user:ed']]]);
		$this->asUserSending(uid: 'ed', manifest: self::MANIFEST);
		$saved = [];
		$this->captureSave($saved);

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(['status' => 'ok', 'target' => 'application'], $response->getData());
		self::assertSame(self::MANIFEST, $saved['object']['manifest']);
		self::assertSame('v-gone', $saved['object']['productionVersion']);
		self::assertSame('built-app', $saved['schema']);

	}//end testAnUnresolvableProductionVersionFallsBackToTheApplication()

	/**
	 * An embedded production version gets the manifest nested in it, and the
	 * Application carrying it is saved.
	 *
	 * @return void
	 */
	public function testWritesIntoAnEmbeddedProductionVersion(): void {
		$this->givenApplication(
			[
				'productionVersion' => ['semver' => '0.3.0', 'manifest' => ['old' => true]],
				'permissions' => ['owners' => ['user:alice']],
			]
		);
		$this->asUserSending(uid: 'alice', manifest: self::MANIFEST);
		$saved = [];
		$this->captureSave($saved);

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(['status' => 'ok', 'target' => 'embedded'], $response->getData());
		self::assertSame(['semver' => '0.3.0', 'manifest' => self::MANIFEST], $saved['object']['productionVersion']);
		self::assertArrayNotHasKey('manifest', $saved['object']);
		self::assertSame('built-app', $saved['schema']);

	}//end testWritesIntoAnEmbeddedProductionVersion()

	/**
	 * A legacy app (draft, no versions) keeps its manifest on the Application.
	 *
	 * @return void
	 */
	public function testWritesTheLegacyApplicationManifest(): void {
		$this->givenApplication(['productionVersion' => 'draft', 'permissions' => ['owners' => ['user:alice']]]);
		$this->asUserSending(uid: 'alice', manifest: self::MANIFEST);
		$saved = [];
		$this->captureSave($saved);

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(['status' => 'ok', 'target' => 'application'], $response->getData());
		self::assertSame(
			[
				'id' => 'app-uuid',
				'slug' => 'permit-tracker',
				'productionVersion' => 'draft',
				'permissions' => ['owners' => ['user:alice']],
				'manifest' => self::MANIFEST,
			],
			$saved['object']
		);

	}//end testWritesTheLegacyApplicationManifest()

	/**
	 * A body without a manifest object is a 400 and nothing is written.
	 *
	 * @return void
	 */
	public function testAMissingManifestIsABadRequest(): void {
		$this->givenApplication(['permissions' => ['owners' => ['user:alice']]]);
		$this->asUserSending(uid: 'alice', manifest: 'not-a-manifest');
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'bad_request', 'message' => 'Missing or invalid manifest'], $response->getData());

	}//end testAMissingManifestIsABadRequest()

	/**
	 * An anonymous caller is refused once the app resolves.
	 *
	 * @return void
	 */
	public function testAnAnonymousCallerIsForbidden(): void {
		$this->givenApplication(['permissions' => ['owners' => ['user:alice']]]);
		$this->userSession->method('getUser')->willReturn(null);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'], $response->getData());

	}//end testAnAnonymousCallerIsForbidden()

	/**
	 * An admin without a role may write, and the bypass lands in the audit
	 * trail against the Application, mirrored to the log.
	 *
	 * @return void
	 */
	public function testAnAdminBypassIsRecordedInTheAuditTrail(): void {
		$this->isAdmin = true;
		$application = $this->givenApplication(['permissions' => ['owners' => ['user:alice']]]);
		$this->asUserSending(uid: 'root', manifest: self::MANIFEST);
		$saved = [];
		$this->captureSave($saved);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->expects(self::once())->method('recordObjectEvent')->with(
			self::callback(
				static fn (ObjectAuditEvent $event): bool => $event->object === $application
					&& $event->action === 'rbac.admin_bypass'
					&& $event->context['actor'] === 'root'
					&& $event->context['slug'] === 'permit-tracker'
			)
		);
		$this->logger->expects(self::once())->method('info')->with(
			'Buildiq: rbac.admin_bypass exercised',
			self::callback(static fn (array $context): bool => $context['actor'] === 'root')
		);
		$this->logger->expects(self::never())->method('critical');

		$response = $this->controller(auditTrailMapper: $audit)->saveManifest(slug: 'permit-tracker');

		self::assertSame(['status' => 'ok', 'target' => 'application'], $response->getData());

	}//end testAnAdminBypassIsRecordedInTheAuditTrail()

	/**
	 * When the audit trail write fails the bypass is reported as critical and
	 * still logged, and the write goes ahead.
	 *
	 * @return void
	 */
	public function testAFailedAuditWriteIsCriticalAndStillLogged(): void {
		$this->isAdmin = true;
		$this->givenApplication(['permissions' => ['owners' => ['user:alice']]]);
		$this->asUserSending(uid: 'root', manifest: self::MANIFEST);
		$saved = [];
		$this->captureSave($saved);

		$audit = $this->createMock(AuditTrailMapper::class);
		$audit->method('recordObjectEvent')->willThrowException(new RuntimeException('audit down'));
		$this->logger->expects(self::once())->method('critical')->with(
			self::stringContains('COMPLIANCE GAP'),
			self::callback(static fn (array $context): bool => $context['exception'] === 'audit down')
		);
		$this->logger->expects(self::once())->method('info')->with('Buildiq: rbac.admin_bypass exercised');

		$response = $this->controller(auditTrailMapper: $audit)->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAFailedAuditWriteIsCriticalAndStillLogged()

	/**
	 * Without an audit trail the bypass is still logged.
	 *
	 * @return void
	 */
	public function testAnAdminBypassWithoutAuditTrailIsLogged(): void {
		$this->isAdmin = true;
		$this->givenApplication(['permissions' => []]);
		$this->asUserSending(uid: 'root', manifest: self::MANIFEST);
		$saved = [];
		$this->captureSave($saved);
		$this->logger->expects(self::once())->method('info')->with(
			'Buildiq: rbac.admin_bypass exercised',
			self::callback(static fn (array $context): bool => $context['event'] === 'rbac.admin_bypass')
		);

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_OK, $response->getStatus());

	}//end testAnAdminBypassWithoutAuditTrailIsLogged()

	/**
	 * A failing write is a 500 carrying a correlation id that is also logged.
	 *
	 * @return void
	 */
	public function testAFailingWriteIsAnInternalErrorWithACorrelationId(): void {
		$this->givenApplication(['permissions' => ['owners' => ['user:alice']]]);
		$this->asUserSending(uid: 'alice', manifest: self::MANIFEST);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('disk full'));
		$logged = [];
		$this->logger->expects(self::once())->method('error')->willReturnCallback(
			static function (string $message, array $context) use (&$logged): void {
				$logged = ['message' => $message, 'context' => $context];
			}
		);

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');
		$data = $response->getData();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame('internal_error', $data['error']);
		self::assertSame('Failed to save manifest', $data['message']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $data['correlationId']);
		self::assertSame('Buildiq: saveManifest failed for slug permit-tracker: disk full', $logged['message']);
		self::assertSame($data['correlationId'], $logged['context']['correlationId']);

	}//end testAFailingWriteIsAnInternalErrorWithACorrelationId()
}//end class
