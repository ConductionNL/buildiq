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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
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
 * @package  Buildiq
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl
 * @link     https://github.com/ConductionNL/buildiq
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
	 * @var ObjectServiceInterface&MockObject
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
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
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
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper,
				auditTrailMapper: null
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
			new \OCP\AppFramework\Db\DoesNotExistException('register buildiq not found')
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

	/**
	 * An owner may write, but not a manifest the runtime cannot render.
	 *
	 * The Design tab already refuses to save one — REQ-OBPD-009 puts the
	 * guarantee in the CLIENT (Save is disabled, with a tooltip). That
	 * guarantee stopped at the browser: an API caller could PUT `{version,
	 * menu}` with no `pages` and get a 200, leaving the app with a manifest
	 * that renders nothing, and nothing on the read path repairs it. Newman's
	 * `4. PUT invalid manifest — assert 4xx` was measuring exactly this and
	 * had been red on `development`.
	 *
	 * Pinned on the AUTHORISED path deliberately: a caller who could not write
	 * anyway would be refused for the wrong reason and the assertion would pass
	 * without the shape check ever running.
	 *
	 * @return void
	 */
	public function testSaveManifestRefusesAManifestWithNoPages(): void {
		$this->authenticate(uid: 'alice');

		$register = new \OCA\OpenRegister\Db\Register();
		$register->setId(1);
		$this->registerMapper->method('find')->willReturn($register);

		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(2);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->objectService->method('searchObjects')->willReturn(
			[['id' => 'route-1', 'slug' => 'permit-tracker', 'applicationUuid' => 'app-uuid']]
		);
		$this->objectService->method('find')->willReturn(
			$this->buildEntity(
				[
					'id' => 'app-uuid',
					'slug' => 'permit-tracker',
					'permissions' => ['owners' => ['user:alice']],
				]
			)
		);

		// `menu` but no `pages` — structurally unrenderable.
		$this->request->method('getParam')->willReturn(['version' => '0.1.0', 'menu' => []]);

		// The refusal must happen BEFORE the write, not be undone after it.
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertSame('manifest_invalid', $data['error']);
		self::assertStringContainsString(
			'pages',
			$data['message'],
			'the refusal must name what is wrong — a bare 400 sends the caller back to guess'
		);

	}//end testSaveManifestRefusesAManifestWithNoPages()

	/**
	 * Wire the register, the route index and an Application owned by alice
	 * with the given production version; find() answers further ids from $byId.
	 *
	 * @param mixed                              $productionVersion The Application's productionVersion, or null for none.
	 * @param array<string,array<string,mixed>> $byId              Further objects find() returns, by id.
	 *
	 * @return void
	 */
	private function wireApplication(mixed $productionVersion, array $byId = []): void {
		$register = new \OCA\OpenRegister\Db\Register();
		$register->setId(1);
		$this->registerMapper->method('find')->willReturn($register);

		$schema = new \OCA\OpenRegister\Db\Schema();
		$schema->setId(2);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->objectService->method('searchObjects')->willReturn(
			[['id' => 'route-1', 'slug' => 'permit-tracker', 'applicationUuid' => 'app-uuid']]
		);

		$application = [
			'id' => 'app-uuid',
			'slug' => 'permit-tracker',
			'permissions' => ['owners' => ['user:alice']],
		];
		if ($productionVersion !== null) {
			$application['productionVersion'] = $productionVersion;
		}

		$byId['app-uuid'] = $application;
		$entities = array_map(fn (array $payload): ObjectEntity => $this->buildEntity($payload), $byId);
		$this->objectService->method('find')->willReturnCallback(
			static fn (string|int $id): ?ObjectEntity => ($entities[$id] ?? null)
		);

	}//end wireApplication()

	/**
	 * Capture every saveObject() call as [object, register, schema].
	 *
	 * @return \ArrayObject<int,array{0:array<string,mixed>,1:mixed,2:mixed}>
	 */
	private function captureSaves(): \ArrayObject {
		$saves = new \ArrayObject();
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null) use ($saves): ObjectEntity {
				$saves[] = [$object, $register, $schema];
				return $this->buildEntity($object);
			}
		);

		return $saves;

	}//end captureSaves()

	/**
	 * A renderable manifest carrying a permissions block the caller must not be able to write.
	 *
	 * @return array<string,mixed>
	 */
	private function manifestBody(): array {
		return [
			'version' => '1.0.0',
			'pages' => [['id' => 'home']],
			'permissions' => ['owners' => ['user:mallory']],
		];

	}//end manifestBody()

	/**
	 * A production version referenced by uuid receives the manifest, without
	 * the permissions block from the body.
	 *
	 * @return void
	 */
	public function testSaveManifestWritesOntoTheProductionVersion(): void {
		$this->authenticate(uid: 'alice');
		$this->wireApplication('ver-1', ['ver-1' => ['id' => 'ver-1', 'application' => 'app-uuid', 'manifest' => ['version' => '0.9.0']]]);
		$this->request->method('getParam')->with('manifest')->willReturn($this->manifestBody());
		$saves = $this->captureSaves();

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['status' => 'ok', 'target' => 'version'], $response->getData());
		self::assertCount(1, $saves);
		[$object, $register, $schema] = $saves[0];
		self::assertSame('buildiq', $register);
		self::assertSame('applicationVersion', $schema);
		self::assertSame('ver-1', $object['id']);
		self::assertSame(['version' => '1.0.0', 'pages' => [['id' => 'home']]], $object['manifest']);

	}//end testSaveManifestWritesOntoTheProductionVersion()

	/**
	 * An embedded production version gets the manifest nested and the Application is saved.
	 *
	 * @return void
	 */
	public function testSaveManifestWritesIntoAnEmbeddedVersion(): void {
		$this->authenticate(uid: 'alice');
		$this->wireApplication(['slug' => 'v1', 'manifest' => ['version' => '0.9.0']]);
		$this->request->method('getParam')->willReturn($this->manifestBody());
		$saves = $this->captureSaves();

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(['status' => 'ok', 'target' => 'embedded'], $response->getData());
		self::assertCount(1, $saves);
		[$object, $register, $schema] = $saves[0];
		self::assertSame(['buildiq', 'built-app'], [$register, $schema]);
		self::assertSame('app-uuid', $object['id']);
		self::assertSame('v1', $object['productionVersion']['slug']);
		self::assertSame(['version' => '1.0.0', 'pages' => [['id' => 'home']]], $object['productionVersion']['manifest']);
		self::assertSame(['owners' => ['user:alice']], $object['permissions']);
		self::assertArrayNotHasKey('manifest', $object);

	}//end testSaveManifestWritesIntoAnEmbeddedVersion()

	/**
	 * Without a usable production version — none, "draft", or a uuid that no
	 * longer resolves — the Application-level manifest is written.
	 *
	 * @return void
	 */
	public function testSaveManifestFallsBackToTheApplication(): void {
		foreach ([null, 'draft', '', 'ver-gone'] as $productionVersion) {
			$this->setUp();
			$this->authenticate(uid: 'alice');
			$this->request->method('getParam')->willReturn($this->manifestBody());
			$this->wireApplication($productionVersion);
			$saves = $this->captureSaves();

			$response = $this->controller()->saveManifest(slug: 'permit-tracker');

			self::assertSame(['status' => 'ok', 'target' => 'application'], $response->getData(), var_export($productionVersion, true));
			self::assertCount(1, $saves);
			self::assertSame(['buildiq', 'built-app'], [$saves[0][1], $saves[0][2]]);
			self::assertSame(['version' => '1.0.0', 'pages' => [['id' => 'home']]], $saves[0][0]['manifest']);
		}

	}//end testSaveManifestFallsBackToTheApplication()

	/**
	 * An admin without a role on the app may write (the bypass is recorded).
	 *
	 * @return void
	 */
	public function testSaveManifestLetsAnAdminWriteThroughTheBypass(): void {
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $uid === 'root' && $group === 'admin'
		);
		$this->authenticate(uid: 'root');
		$this->wireApplication(null);
		$this->request->method('getParam')->willReturn($this->manifestBody());
		$saves = $this->captureSaves();

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertCount(1, $saves);

	}//end testSaveManifestLetsAnAdminWriteThroughTheBypass()

	/**
	 * An anonymous caller is refused before anything is written.
	 *
	 * @return void
	 */
	public function testSaveManifestRefusesAnAnonymousCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->wireApplication(null);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'forbidden', 'code' => 'buildiq.rbac.no_role'], $response->getData());

	}//end testSaveManifestRefusesAnAnonymousCaller()

	/**
	 * A body without a manifest object is a 400.
	 *
	 * @return void
	 */
	public function testSaveManifestRefusesAMissingManifest(): void {
		$this->authenticate(uid: 'alice');
		$this->wireApplication(null);
		$this->request->method('getParam')->willReturn('not-a-manifest');
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'bad_request', 'message' => 'Missing or invalid manifest'], $response->getData());

	}//end testSaveManifestRefusesAMissingManifest()

	/**
	 * A failing write is a 500 with a correlation id.
	 *
	 * @return void
	 */
	public function testSaveManifestAnswersAFailingWriteWithA500(): void {
		$this->authenticate(uid: 'alice');
		$this->wireApplication(null);
		$this->request->method('getParam')->willReturn($this->manifestBody());
		$this->objectService->method('saveObject')->willThrowException(new \RuntimeException('disk full'));

		$response = $this->controller()->saveManifest(slug: 'permit-tracker');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		self::assertSame('internal_error', $data['error']);
		self::assertSame('Failed to save manifest', $data['message']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $data['correlationId']);

	}//end testSaveManifestAnswersAFailingWriteWithA500()

}//end class
