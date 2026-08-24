<?php

/**
 * Unit tests for ApplicationsController::createFromTemplate.
 *
 * Covers the six branch-coverage cases mandated by tasks.md 1.3 / 4.1:
 *   - 404 unknown templateSlug
 *   - 4xx slug-collision (global: any existing Application with same slug, regardless of owner)
 *   - success → 201 + Application + per-app register + companion schemas
 *   - manifest schema-refs rewritten with new-slug prefix
 *   - owner field tagged with authenticated UID
 *   - cross-user slug collision: second user is ALSO rejected (WF1 fix — global uniqueness)
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Service\AppChannelApplier;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Tests for ApplicationsController::createFromTemplate.
 */
class CreateFromTemplateTest extends TestCase {
	/**
	 * Controller under test.
	 *
	 * @var ApplicationsController
	 */
	private ApplicationsController $controller;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock OR ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock RegisterMapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Mock SchemaMapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Mock IRequest.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Mock IGroupManager (unused by createFromTemplate but required by the ctor).
	 *
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Mock ManifestResolverService (unused by createFromTemplate but required by the ctor).
	 *
	 * @var ManifestResolverService&MockObject
	 */
	private ManifestResolverService&MockObject $manifestResolver;

	/**
	 * The v2 channel applier (apply-v2-channels).
	 *
	 * @var AppChannelApplier&MockObject
	 */
	private AppChannelApplier&MockObject $channelApplier;

	/**
	 * Per-app Register entity stub.
	 *
	 * @var Register&MockObject
	 */
	private Register&MockObject $perAppRegister;

	/**
	 * The slug of the template under test in fixtures.
	 *
	 * @var string
	 */
	private const TEMPLATE_SLUG = 'permit-tracker';

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->manifestResolver = $this->createMock(ManifestResolverService::class);
		$this->channelApplier = $this->createMock(AppChannelApplier::class);

		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		// RegisterMapper mock chain: find()->getId(), create + update.
		$registerEntity = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$registerEntity->method('getId')->willReturn(926);

		$this->perAppRegister = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemas', 'setSchemas', 'getId', 'getSlug'])
			->getMock();
		$this->perAppRegister->method('getId')->willReturn(2001);
		$this->perAppRegister->method('getSlug')->willReturn('openbuild-my-permits');
		$this->perAppRegister->method('getSchemas')->willReturn([]);
		$this->perAppRegister->method('setSchemas')->willReturn($this->perAppRegister);

		$this->registerMapper = $this->createMock(RegisterMapper::class);
		// Default: shared register find succeeds, per-app register find throws (not yet provisioned).
		$this->registerMapper->method('find')->willReturnCallback(
			function (...$args) use ($registerEntity): Register {
				$slug = (string)($args['id'] ?? $args[0]);
				// Matched against the CONSTANT, not a literal. Pinning the slug
				// here is what turned a rename into eight red PHPUnit cells
				// reporting 503: the controller asked for the new slug, this
				// mock answered only to the old one and threw, and the
				// controller's "register unavailable" branch did exactly what it
				// is supposed to. Tracking the constant means the next rename
				// moves the mock with the code.
				if ($slug === ApplicationVersionService::REGISTER_SLUG) {
					return $registerEntity;
				}
				throw new \RuntimeException('register not found: ' . $slug);
			}
		);
		$this->registerMapper->method('createFromArray')->willReturn($this->perAppRegister);
		$this->registerMapper->method('update')->willReturn($this->perAppRegister);

		// SchemaMapper mock chain: find()->getId() for shared schemas; createFromArray for clones.
		$applicationTemplateSchema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$applicationTemplateSchema->method('getId')->willReturn(1635);
		$applicationSchema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$applicationSchema->method('getId')->willReturn(1636);

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willReturnCallback(
			function (...$args) use ($applicationTemplateSchema, $applicationSchema): Schema {
				$slug = (string)($args['id'] ?? $args[0]);
				if ($slug === 'application-template') {
					return $applicationTemplateSchema;
				}
				if ($slug === 'application') {
					return $applicationSchema;
				}
				throw new \RuntimeException('schema not found: ' . $slug);
			}
		);

		$permissionResolver = new PermissionResolver($this->groupManager, $this->logger);

		$this->controller = new ApplicationsController(
			request: $this->request,
			logger: $this->logger,
			objectService: $this->objectService,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			manifestResolver: $this->manifestResolver,
			permissionResolver: $permissionResolver,
			channelApplier: $this->channelApplier,
			auditTrailMapper: null,
		);
	}//end setUp()

	/**
	 * Build a Schema test double that reports the given numeric id.
	 *
	 * SchemaMapper::createFromArray() returns a Schema; the controller only
	 * calls getId() on the result.
	 *
	 * @param int $id The schema id to report.
	 *
	 * @return Schema&MockObject
	 */
	private function schemaWithId(int $id): Schema&MockObject {
		$schema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$schema->method('getId')->willReturn($id);
		return $schema;
	}//end schemaWithId()

	/**
	 * Build an ObjectEntity test double whose jsonSerialize() returns $payload.
	 *
	 * ObjectService::saveObject() returns an ObjectEntity; the controller
	 * normalises it via jsonSerialize().
	 *
	 * @param array<string, mixed> $payload Serialised object payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function savedEntity(array $payload): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end savedEntity()

	/**
	 * Record every saveObject() call and answer each with a usable entity.
	 *
	 * A successful template install writes THREE objects, in this order:
	 *   1. the Application            (shared register, numeric ctx ids)
	 *   2. its production ApplicationVersion — where the manifest actually lives
	 *   3. the Application again, patched with `productionVersion`
	 * Step 3 exists because OpenRegister resolves a manifest through
	 * `productionVersion`, never through a `manifest` key on the Application.
	 *
	 * PHPUnit resolves the controller's NAMED arguments against
	 * `ObjectServiceInterface::saveObject()`'s OWN signature and then invokes
	 * this callback POSITIONALLY, so the parameters below must mirror that
	 * signature's order exactly: (object, extend, register, schema, uuid, …).
	 * Verified against the live interface with ReflectionMethod.
	 *
	 * The expected call count is configured on THIS matcher, never as a second
	 * `expects()` on the same method: PHPUnit answers a call from the first
	 * matching matcher only, so a separate `expects(...)->method('saveObject')`
	 * would shadow this one and return null for every save.
	 *
	 * @param array<int,array<string,mixed>> $calls OUT: one entry per call.
	 * @param InvocationOrder|null $matcher Expected invocation count, or null for no count assertion.
	 *
	 * @return void
	 */
	private function recordSaves(array &$calls, ?InvocationOrder $matcher = null): void {
		$stub = ($matcher === null)
			? $this->objectService->method('saveObject')
			: $this->objectService->expects($matcher)->method('saveObject');

		$stub->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$calls): ObjectEntity {
				$calls[] = [
					'object' => $object,
					'register' => $register,
					'schema' => $schema,
					'uuid' => $uuid,
				];

				// Echo the persisted object back, the way OpenRegister does —
				// saveObject() answers with the STORED entity, not a bare id.
				// A double that returned only `['uuid' => …]` would make the
				// controller's read-modify-write look lossless no matter what
				// it actually sent.
				$isVersion = ($schema === 'applicationVersion');
				return $this->savedEntity(
					array_merge(
						$object,
						['uuid' => ($isVersion === true ? 'new-version-1' : 'new-uuid-1')]
					)
				);
			}
		);
	}//end recordSaves()

	/**
	 * Register an authenticated user for the test.
	 *
	 * Cloning a template provisions an OpenRegister register (admin-only,
	 * issue #157), so createFromTemplate is admin-gated. The default caller is
	 * an admin (the legitimate authoring flow); pass $admin=false to exercise
	 * the 403 gate.
	 *
	 * @param string $uid The UID to return from getUID.
	 * @param bool $admin Whether the caller is in the `admin` group.
	 *
	 * @return void
	 */
	private function authenticateAs(string $uid, bool $admin = true): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isInGroup')->willReturnCallback(
			static function (string $u, string $g) use ($uid, $admin): bool {
				return ($admin === true && $u === $uid && $g === 'admin');
			}
		);
	}//end authenticateAs()

	/**
	 * Set the request body params (name + slug).
	 *
	 * @param array<string,mixed> $params The body params.
	 *
	 * @return void
	 */
	private function withRequestParams(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}//end withRequestParams()

	/**
	 * Build a representative template record.
	 *
	 * @param string $slug The template slug.
	 *
	 * @return array<string,mixed>
	 */
	private function templateRecord(string $slug): array {
		return [
			'slug' => $slug,
			'version' => '1.0.0',
			'manifest' => [
				'pages' => [
					['name' => 'Index', 'type' => 'index', 'config' => ['schema' => 'permit-application']],
					['name' => 'Form', 'type' => 'form', 'config' => ['schema' => 'permit-application']],
				],
			],
			'companionSchemas' => [
				[
					'slug' => 'permit-application',
					'title' => 'Permit application',
					'type' => 'object',
					'version' => '0.1.0',
				],
			],
		];
	}//end templateRecord()

	/**
	 * Test 1 — Unknown templateSlug → 404 + template_not_found error envelope.
	 *
	 * @return void
	 */
	public function testReturns404WhenTemplateSlugUnknown(): void {
		$this->authenticateAs('alice');
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		// Template lookup returns no hits (any number of times — controller may also
		// perform a slug-collision lookup after the missing template would normally
		// be detected; both return empty here).
		$this->objectService->method('searchObjects')->willReturn([]);

		$result = $this->controller->createFromTemplate(templateSlug: 'no-such-template');

		self::assertInstanceOf(JSONResponse::class, $result);
		self::assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
		$body = $result->getData();
		self::assertSame('template_not_found', $body['error']);
	}//end testReturns404WhenTemplateSlugUnknown()

	/**
	 * Test 2 — Slug collision (any owner) → 4xx + slug_collision error envelope.
	 *
	 * WF1 fix: the collision check is now org-wide (no owner filter). Any existing
	 * Application with the same slug, regardless of who owns it, blocks the clone.
	 *
	 * The lookup sequence for createFromTemplate is:
	 *   1. lookupOne(templateSchema, slug=templateSlug) — template exists
	 *   2. lookupOne(applicationSchema, slug=newSlug)   — existing app collides (global)
	 *
	 * @return void
	 */
	public function testReturns4xxOnSlugCollision(): void {
		$this->authenticateAs('alice');
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			// 1) template found
			[$this->templateRecord(self::TEMPLATE_SLUG)],
			// 2) existing application with the same slug (owned by anyone)
			[['slug' => 'my-permits', 'owner' => 'alice']]
		);

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);

		self::assertGreaterThanOrEqual(400, $result->getStatus());
		self::assertLessThan(500, $result->getStatus());
		$body = $result->getData();
		self::assertSame('slug_collision', $body['error']);
	}//end testReturns4xxOnSlugCollision()

	/**
	 * Test 3 — Success: 201 + Application + per-app register + companion schema with prefix.
	 *
	 * @return void
	 */
	public function testSuccessCreatesApplicationAndPerAppArtifacts(): void {
		$this->authenticateAs('alice');
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		// Lookup sequence: 1) template found, 2) no slug collision.
		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->templateRecord(self::TEMPLATE_SLUG)],
			[]
		);

		// Expect a schema clone CALL with the prefixed slug `my-permits-permit-application`.
		$this->schemaMapper->expects(self::once())
			->method('createFromArray')
			->with(self::callback(
				static function (array $payload): bool {
					return ($payload['slug'] ?? null) === 'my-permits-permit-application';
				}
			))
			->willReturn($this->schemaWithId(7777));

		$calls = [];
		$this->recordSaves($calls, self::exactly(3));

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);

		self::assertSame(Http::STATUS_CREATED, $result->getStatus());
		$body = $result->getData();
		self::assertSame('new-uuid-1', $body['uuid']);
		self::assertSame('my-permits', $body['slug']);
		self::assertSame('openbuild-my-permits', $body['register']);
		self::assertSame([7777], $body['companionSchemas']);

		// The three writes, in order, with the arguments that make each of them
		// the write it claims to be — a count on its own would pass for any
		// three saves at all.
		self::assertCount(3, $calls);

		// 1) the Application, into the shared register by numeric ctx id, no uuid
		//    (a create).
		self::assertSame(1636, $calls[0]['schema']);
		self::assertSame(926, $calls[0]['register']);
		self::assertNull($calls[0]['uuid']);
		self::assertSame('my-permits', $calls[0]['object']['slug']);

		// 2) the production ApplicationVersion, carrying the manifest.
		self::assertSame('applicationVersion', $calls[1]['schema']);
		self::assertNull($calls[1]['uuid']);
		self::assertSame('new-uuid-1', $calls[1]['object']['application']);
		self::assertArrayHasKey('manifest', $calls[1]['object']);

		// 3) the Application again, now pointing at that version.
		self::assertSame('application', $calls[2]['schema']);
		self::assertSame('new-uuid-1', $calls[2]['uuid']);
		self::assertSame('new-version-1', $calls[2]['object']['productionVersion']);
	}//end testSuccessCreatesApplicationAndPerAppArtifacts()

	/**
	 * Regression: the productionVersion re-save must PRESERVE the Application.
	 *
	 * OpenRegister's `saveObject()` is PUT, not PATCH — on the update path it
	 * NULLs every schema property absent from the payload. The third write must
	 * therefore carry the whole stored object with one field patched in, not a
	 * hand-built subset; an earlier revision sent only slug/name/permissions/
	 * productionVersion and so wiped `owner`, `status`, `version` and
	 * `templateOrigin` off the record created one statement earlier.
	 *
	 * @return void
	 */
	public function testProductionVersionLinkPreservesTheStoredApplication(): void {
		$this->authenticateAs('alice');
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->templateRecord(self::TEMPLATE_SLUG)],
			[]
		);
		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(7777));

		$calls = [];
		$this->recordSaves($calls);

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);
		self::assertSame(Http::STATUS_CREATED, $result->getStatus());

		self::assertCount(3, $calls);
		$created = $calls[0]['object'];
		$relinked = $calls[2]['object'];

		self::assertSame('new-version-1', $relinked['productionVersion']);
		foreach (['owner', 'status', 'version', 'templateOrigin', 'permissions'] as $key) {
			self::assertArrayHasKey(
				$key,
				$relinked,
				'the productionVersion re-save must not drop "' . $key . '" — saveObject() is a full replace'
			);
			self::assertSame($created[$key], $relinked[$key], 'field "' . $key . '" changed across the re-save');
		}
	}//end testProductionVersionLinkPreservesTheStoredApplication()

	/**
	 * Test 4 — Manifest schema-refs are rewritten with the new-slug prefix.
	 *
	 * @return void
	 */
	public function testManifestSchemaRefsRewrittenWithNewSlugPrefix(): void {
		$this->authenticateAs('alice');
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->templateRecord(self::TEMPLATE_SLUG)],
			[]
		);

		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(7777));

		$calls = [];
		$this->recordSaves($calls);

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);
		self::assertSame(Http::STATUS_CREATED, $result->getStatus());

		// The FIRST write is the Application create. Asserting on "the last
		// payload seen" would silently retarget onto the productionVersion
		// re-save, which legitimately carries no rewritten manifest.
		self::assertNotEmpty($calls, 'saveObject should have been invoked with the cloned Application payload');
		$savedPayload = $calls[0]['object'];
		$manifest = $savedPayload['manifest'] ?? [];
		$pages = $manifest['pages'] ?? [];
		self::assertCount(2, $pages);
		foreach ($pages as $page) {
			self::assertSame(
				'my-permits-permit-application',
				$page['config']['schema'] ?? null,
				'every manifest page-config schema must be rewritten with the new-slug prefix'
			);
		}
	}//end testManifestSchemaRefsRewrittenWithNewSlugPrefix()

	/**
	 * Test 5 — Owner field on the persisted Application matches the authenticated UID.
	 *
	 * @return void
	 */
	public function testOwnerFieldSetToAuthenticatedUid(): void {
		$this->authenticateAs('bob');
		$this->withRequestParams(['name' => 'Bob app', 'slug' => 'bob-app']);

		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->templateRecord(self::TEMPLATE_SLUG)],
			[]
		);

		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(8888));

		$calls = [];
		$this->recordSaves($calls);

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);
		self::assertSame(Http::STATUS_CREATED, $result->getStatus());

		// Asserted on the Application create specifically — and again on the
		// final write, because a re-save that drops `owner` un-owns the app just
		// as effectively as never setting it.
		self::assertNotEmpty($calls);
		self::assertSame('bob', $calls[0]['object']['owner'] ?? null);
		self::assertSame('bob', $calls[count($calls) - 1]['object']['owner'] ?? null);
	}//end testOwnerFieldSetToAuthenticatedUid()

	/**
	 * The productionVersion link is best-effort: a failure there must not fail
	 * the install.
	 *
	 * `linkProductionVersion()` documents itself as never-throwing — the app is
	 * installed and published either way, it is only unreachable by slug until
	 * a retry succeeds. Losing the whole install (and the register and schemas
	 * already provisioned for it) over a manifest-resolution step would be far
	 * worse than the 404 it guards against.
	 *
	 * @return void
	 */
	public function testInstallSucceedsWhenTheProductionVersionLinkFails(): void {
		$this->authenticateAs('alice');
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->templateRecord(self::TEMPLATE_SLUG)],
			[]
		);
		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(7777));

		$seen = 0;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$seen): ObjectEntity {
				$seen++;
				if ($schema === 'applicationVersion') {
					throw new \RuntimeException('OR unavailable');
				}

				return $this->savedEntity(array_merge($object, ['uuid' => 'new-uuid-1']));
			}
		);

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);

		self::assertSame(Http::STATUS_CREATED, $result->getStatus());
		self::assertSame('new-uuid-1', $result->getData()['uuid']);
		// The Application create, then the version attempt that threw. The
		// re-save must NOT run: there is no version to point at.
		self::assertSame(2, $seen);
	}//end testInstallSucceedsWhenTheProductionVersionLinkFails()

	/**
	 * A version save that yields no UUID must not trigger the Application re-save.
	 *
	 * Re-saving the app with an empty `productionVersion` would overwrite a
	 * working pointer with nothing, so the link step returns early instead.
	 *
	 * @return void
	 */
	public function testNoApplicationRelinkWhenTheVersionSaveYieldsNoUuid(): void {
		$this->authenticateAs('alice');
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->templateRecord(self::TEMPLATE_SLUG)],
			[]
		);
		$this->schemaMapper->method('createFromArray')->willReturn($this->schemaWithId(7777));

		$calls = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (
				array $object,
				?array $extend = [],
				string|int|null $register = null,
				string|int|null $schema = null,
				?string $uuid = null,
			) use (&$calls): ObjectEntity {
				$calls[] = $schema;
				if ($schema === 'applicationVersion') {
					// An entity carrying neither `uuid` nor `id`.
					return $this->savedEntity(['name' => 'Production']);
				}

				return $this->savedEntity(array_merge($object, ['uuid' => 'new-uuid-1']));
			}
		);

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);

		self::assertSame(Http::STATUS_CREATED, $result->getStatus());
		self::assertSame([1636, 'applicationVersion'], $calls, 'the Application re-save must be skipped');
	}//end testNoApplicationRelinkWhenTheVersionSaveYieldsNoUuid()

	/**
	 * Test 6 — Cross-user slug collision is now REJECTED (WF1 fix).
	 *
	 * With the global slug uniqueness check in place, a second user attempting
	 * to clone a template with a slug already taken by another user receives
	 * slug_collision, preventing slug-squatting and routing conflicts.
	 *
	 * @return void
	 */
	public function testCrossUserSlugCollisionIsRejected(): void {
		$this->authenticateAs('carol');
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		// Sequence: template found, then the global collision lookup finds bob's app.
		$this->objectService->method('searchObjects')->willReturnOnConsecutiveCalls(
			[$this->templateRecord(self::TEMPLATE_SLUG)],
			// Global check: 'my-permits' is already taken by bob.
			[['slug' => 'my-permits', 'owner' => 'bob']]
		);

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);

		self::assertGreaterThanOrEqual(400, $result->getStatus());
		self::assertLessThan(500, $result->getStatus());
		$body = $result->getData();
		self::assertSame('slug_collision', $body['error'], 'Cross-user slug squatting must be rejected');
	}//end testCrossUserSlugCollisionIsRejected()

	/**
	 * DoS/authz hardening (harden-xss-dos-csrf): a non-admin caller is rejected
	 * with 403 before any clone/provisioning work, mirroring the creation
	 * wizard's admin gate.
	 *
	 * @return void
	 */
	public function testNonAdminIsForbidden(): void {
		$this->authenticateAs(uid: 'bob', admin: false);
		$this->withRequestParams(['name' => 'My permits', 'slug' => 'my-permits']);

		// No provisioning may happen for a rejected caller.
		$this->objectService->expects(self::never())->method('saveObject');

		$result = $this->controller->createFromTemplate(templateSlug: self::TEMPLATE_SLUG);
		self::assertSame(Http::STATUS_FORBIDDEN, $result->getStatus());
	}//end testNonAdminIsForbidden()

	/**
	 * The endpoint carries a UserRateLimit attribute (amplification guard).
	 *
	 * @return void
	 */
	public function testCreateFromTemplateHasRateLimit(): void {
		$method = new ReflectionMethod(ApplicationsController::class, 'createFromTemplate');
		$attributes = $method->getAttributes(UserRateLimit::class);
		self::assertCount(1, $attributes);
	}//end testCreateFromTemplateHasRateLimit()
}//end class
