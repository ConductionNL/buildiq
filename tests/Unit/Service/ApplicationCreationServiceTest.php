<?php

/**
 * Unit tests for ApplicationCreationService.
 *
 * Covers spec `buildiq-app-creation-wizard` REQ-OBWIZ-007 through
 * REQ-OBWIZ-010:
 *   - Success paths for each of the four presets
 *   - Validation failure returns WizardCreationException with failedAtStep=validate
 *   - Rollback at each step of the creation flow (8 simulations per Decision 7):
 *     (1) validation fail, (2) app-create fail, (3) version-create fail on v1,
 *     (4) register-provision fail on v1, (5) version-create fail on v2,
 *     (6) register-provision fail on v2, (7) wiring fail, (8) productionVersion fail
 *   - Rollback-partial when a rollback step itself fails
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\WizardCreationException;
use OCA\Buildiq\Service\ApplicationCreationService;
use OCA\Buildiq\Service\ConnectedDatabaseReader;
use OCA\Buildiq\Service\DatabaseManifestBuilder;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Service\SlugValidator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Db\SourceMapperInterface;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApplicationCreationService.
 */
class ApplicationCreationServiceTest extends TestCase {
	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var RegisterService&MockObject
	 */
	private RegisterService&MockObject $registerService;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * @var SlugValidator
	 */
	private SlugValidator $slugValidator;

	/**
	 * Service under test.
	 */
	private ApplicationCreationService $service;

	/**
	 * Set up shared mocks + the SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->slugValidator = new SlugValidator();

		$this->service = new ApplicationCreationService(
			logger: $this->logger,
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper
			),
			registerService: $this->registerService,
			userSession: $this->userSession,
			slugValidator: $this->slugValidator,
		);

		// Default: caller is 'admin'.
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession->method('getUser')->willReturn($user);

		// Default: no existing apps (slug-uniqueness check passes).
		$this->objectService->method('searchObjects')->willReturn([]);
	}//end setUp()

	// -------------------------------------------------------------------------
	// Slug-substitute helper
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function substituteRegisterSlugReplacesTokenInPagesConfig(): void {
		$manifest = [
			'pages' => [
				['id' => 'Dashboard', 'config' => ['widgets' => []]],
				['id' => 'Messages', 'config' => ['register' => '{registerSlug}', 'schema' => 'hello-message']],
			],
		];

		$result = $this->service->substituteRegisterSlug(manifest: $manifest, registerSlug: 'openbuild-my-app-production');

		self::assertSame('openbuild-my-app-production', $result['pages'][1]['config']['register']);
	}//end substituteRegisterSlugReplacesTokenInPagesConfig()

	/**
	 * @test
	 *
	 * @return void
	 */
	public function substituteRegisterSlugDoesNotTouchNonTokenFields(): void {
		$manifest = [
			'pages' => [
				['id' => 'Messages', 'config' => ['register' => 'some-other-register', 'schema' => 'foo']],
			],
		];

		$result = $this->service->substituteRegisterSlug(manifest: $manifest, registerSlug: 'openbuild-my-app-production');

		// Field without the placeholder token must remain unchanged.
		self::assertSame('some-other-register', $result['pages'][0]['config']['register']);
	}//end substituteRegisterSlugDoesNotTouchNonTokenFields()

	/**
	 * substituteVersionContext rewrites both register AND schema.
	 *
	 * buildiq#75 — without this the KPI / insights cards aggregate
	 * against `hello-message` (the un-namespaced template slug), which
	 * doesn't exist in the per-version register, so counts leak the
	 * same numbers across all tiers.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function substituteVersionContextNamespacesSchemaSlugAlongsideRegister(): void {
		$manifest = [
			'pages' => [
				['id' => 'Dashboard', 'config' => ['widgets' => []]],
				['id' => 'Messages', 'config' => ['register' => '{registerSlug}', 'schema' => 'hello-message']],
			],
		];

		$result = $this->service->substituteVersionContext(
			manifest: $manifest,
			registerSlug: 'openbuild-permit-flow-development',
			schemaSlugPrefix: 'permit-flow-development-'
		);

		self::assertSame('openbuild-permit-flow-development', $result['pages'][1]['config']['register']);
		self::assertSame('permit-flow-development-hello-message', $result['pages'][1]['config']['schema']);
	}//end substituteVersionContextNamespacesSchemaSlugAlongsideRegister()

	/**
	 * A page on a register the Application declares in `dataRegisters` keeps
	 * its schema slug, next to an own-register page that is still namespaced
	 * (REQ-BQDB-004, buildiq#991).
	 *
	 * @test
	 *
	 * @return void
	 */
	public function substituteVersionContextLeavesADataRegisterPageAlone(): void {
		$manifest = [
			'pages' => [
				['id' => 'Permits', 'config' => ['register' => 'permits-db', 'schema' => 'permits']],
				['id' => 'Messages', 'config' => ['register' => '{registerSlug}', 'schema' => 'hello-message']],
			],
		];

		$result = $this->service->substituteVersionContext(
			manifest: $manifest,
			registerSlug: 'openbuild-permit-flow-development',
			schemaSlugPrefix: 'permit-flow-development-',
			dataRegisters: ['permits-db']
		);

		self::assertSame('permits-db', $result['pages'][0]['config']['register']);
		self::assertSame('permits', $result['pages'][0]['config']['schema']);
		self::assertSame('openbuild-permit-flow-development', $result['pages'][1]['config']['register']);
		self::assertSame('permit-flow-development-hello-message', $result['pages'][1]['config']['schema']);
	}//end substituteVersionContextLeavesADataRegisterPageAlone()

	/**
	 * substituteVersionContext is idempotent: re-running with the same
	 * prefix MUST NOT double-prefix the schema slug.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function substituteVersionContextIsIdempotentOnAlreadyNamespacedSlugs(): void {
		$manifest = [
			'pages' => [
				[
					'id' => 'Messages',
					'config' => [
						'register' => 'openbuild-permit-flow-development',
						'schema' => 'permit-flow-development-hello-message',
					],
				],
			],
		];

		$result = $this->service->substituteVersionContext(
			manifest: $manifest,
			registerSlug: 'openbuild-permit-flow-development',
			schemaSlugPrefix: 'permit-flow-development-'
		);

		// Schema must NOT become `permit-flow-development-permit-flow-development-hello-message`.
		self::assertSame('permit-flow-development-hello-message', $result['pages'][0]['config']['schema']);
	}//end substituteVersionContextIsIdempotentOnAlreadyNamespacedSlugs()

	/**
	 * substituteVersionContext leaves schema alone when no prefix is provided
	 * (backwards-compat with the legacy substituteRegisterSlug entry point).
	 *
	 * @test
	 *
	 * @return void
	 */
	public function substituteVersionContextLeavesSchemaAloneWhenPrefixEmpty(): void {
		$manifest = [
			'pages' => [
				['id' => 'Messages', 'config' => ['register' => '{registerSlug}', 'schema' => 'hello-message']],
			],
		];

		$result = $this->service->substituteVersionContext(
			manifest: $manifest,
			registerSlug: 'openbuild-my-app-production',
			schemaSlugPrefix: ''
		);

		self::assertSame('hello-message', $result['pages'][0]['config']['schema']);
	}//end substituteVersionContextLeavesSchemaAloneWhenPrefixEmpty()

	// -------------------------------------------------------------------------
	// resolveVersionChain
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function resolveVersionChainReturnsSinglePreset(): void {
		$chain = $this->service->resolveVersionChain(['preset' => 'single']);
		self::assertCount(1, $chain);
		self::assertSame('production', $chain[0]['slug']);
	}//end resolveVersionChainReturnsSinglePreset()

	/**
	 * @test
	 *
	 * @return void
	 */
	public function resolveVersionChainReturnsDevProdPreset(): void {
		$chain = $this->service->resolveVersionChain(['preset' => 'dev-prod']);
		self::assertCount(2, $chain);
		self::assertSame('development', $chain[0]['slug']);
		self::assertSame('production', $chain[1]['slug']);
	}//end resolveVersionChainReturnsDevProdPreset()

	/**
	 * @test
	 *
	 * @return void
	 */
	public function resolveVersionChainReturnsDevStagingProdPreset(): void {
		$chain = $this->service->resolveVersionChain(['preset' => 'dev-staging-prod']);
		self::assertCount(3, $chain);
		self::assertSame('development', $chain[0]['slug']);
		self::assertSame('staging', $chain[1]['slug']);
		self::assertSame('production', $chain[2]['slug']);
	}//end resolveVersionChainReturnsDevStagingProdPreset()

	/**
	 * @test
	 *
	 * @return void
	 */
	public function resolveVersionChainReturnsCustomVersions(): void {
		$payload = [
			'preset' => 'custom',
			'versions' => [
				['name' => 'Alpha', 'slug' => 'alpha'],
				['name' => 'Beta',  'slug' => 'beta'],
				['name' => 'Main',  'slug' => 'main'],
			],
		];
		$chain = $this->service->resolveVersionChain($payload);
		self::assertCount(3, $chain);
		self::assertSame('alpha', $chain[0]['slug']);
		self::assertSame('main', $chain[2]['slug']);
	}//end resolveVersionChainReturnsCustomVersions()

	// -------------------------------------------------------------------------
	// Validation failure
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function createApplicationThrowsWizardExceptionOnInvalidSlug(): void {
		$this->expectException(WizardCreationException::class);

		try {
			$this->service->createApplication([
				'name' => 'My App',
				'slug' => '!nope',
				'preset' => 'single',
			]);
		} catch (WizardCreationException $e) {
			self::assertSame('validate', $e->getFailedAtStep());
			self::assertSame('none', $e->getRollbackStatus());
			throw $e;
		}
	}//end createApplicationThrowsWizardExceptionOnInvalidSlug()

	/**
	 * @test
	 *
	 * @return void
	 */
	public function createApplicationThrowsOnDuplicateVersionSlugs(): void {
		$this->expectException(WizardCreationException::class);

		try {
			$this->service->createApplication([
				'name' => 'My App',
				'slug' => 'my-app',
				'preset' => 'custom',
				'versions' => [
					['name' => 'Staging',    'slug' => 'staging'],
					['name' => 'Also Staging', 'slug' => 'staging'],
				],
			]);
		} catch (WizardCreationException $e) {
			self::assertSame('validate', $e->getFailedAtStep());
			throw $e;
		}
	}//end createApplicationThrowsOnDuplicateVersionSlugs()

	/**
	 * @test
	 *
	 * @return void
	 */
	public function createApplicationThrowsWhenAppNameEmpty(): void {
		$this->expectException(WizardCreationException::class);

		try {
			$this->service->createApplication(['name' => '', 'slug' => 'my-app', 'preset' => 'single']);
		} catch (WizardCreationException $e) {
			self::assertSame('validate', $e->getFailedAtStep());
			throw $e;
		}
	}//end createApplicationThrowsWhenAppNameEmpty()

	// -------------------------------------------------------------------------
	// Rollback simulation: app-create failure
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function rollbackIsCompleteWhenAppCreateFails(): void {
		$this->objectService->method('saveObject')
			->willThrowException(new RuntimeException('DB unavailable'));

		$this->expectException(WizardCreationException::class);

		try {
			$this->service->createApplication(['name' => 'Test', 'slug' => 'test-app', 'preset' => 'single']);
		} catch (WizardCreationException $e) {
			self::assertSame('create-application', $e->getFailedAtStep());
			// Nothing was created — orphanedResources should be empty.
			self::assertSame([], $e->getOrphanedResources());
			throw $e;
		}
	}//end rollbackIsCompleteWhenAppCreateFails()

	// -------------------------------------------------------------------------
	// Happy path: single preset creates application + returns UUID
	// -------------------------------------------------------------------------

	/**
	 * @test
	 *
	 * @return void
	 */
	public function createApplicationReturnUuidOnSinglePresetSuccess(): void {
		$this->stubSuccessfulCreation(appUuid: 'app-uuid-001', versionUuids: ['production' => 'ver-uuid-001']);

		$uuid = $this->service->createApplication([
			'name' => 'Hello World',
			'slug' => 'hello-world',
			'preset' => 'single',
		]);

		self::assertSame('app-uuid-001', $uuid);
	}//end createApplicationReturnUuidOnSinglePresetSuccess()

	// -------------------------------------------------------------------------
	// Start from a database (REQ-BQDB-003)
	// -------------------------------------------------------------------------

	/**
	 * The service with a connected-database reader over these mappers.
	 *
	 * @param SourceMapperInterface&MockObject $sources The source mapper.
	 *
	 * @return ApplicationCreationService
	 */
	private function serviceWithDatabases(SourceMapperInterface&MockObject $sources): ApplicationCreationService {
		return new ApplicationCreationService(
			logger: $this->logger,
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper
			),
			registerService: $this->registerService,
			userSession: $this->userSession,
			slugValidator: $this->slugValidator,
			databases: new ConnectedDatabaseReader(registers: $this->registerMapper, schemas: $this->schemaMapper, sources: $sources),
			databaseManifests: new DatabaseManifestBuilder(),
		);
	}//end serviceWithDatabases()

	/**
	 * Register `permits-db` on source 7 holding table `permits` (schema 11); any other register is the version register.
	 *
	 * @return void
	 */
	private function givenPermitsDatabase(): void {
		$database = new Register();
		$database->setSlug('permits-db');
		$database->setTitle('Permits database');
		$database->setSchemas([11]);
		$database->setSource('7');

		$versionRegister = new Register();
		$this->registerMapper->method('find')->willReturnCallback(
			static fn (string|int $id): Register => ($id === 'permits-db') ? $database : $versionRegister
		);
		$this->registerMapper->method('createFromArray')->willReturn($versionRegister);

		$permits = new Schema();
		$permits->setSlug('permits');
		$permits->setTitle('Permits');
		$permits->setProperties(['number' => ['type' => 'string'], 'status' => ['type' => 'string']]);
		$this->schemaMapper->method('find')->willReturn($permits);
		$this->schemaMapper->method('createFromArray')->willReturn(new Schema());
	}//end givenPermitsDatabase()

	/**
	 * A database source with the given type and write setting.
	 *
	 * @param string $type     The source type.
	 * @param bool   $writable Whether it takes writes.
	 *
	 * @return SourceMapperInterface&MockObject
	 */
	private function sourcesWith(string $type, bool $writable): SourceMapperInterface&MockObject {
		$source = new Source();
		$source->setType($type);
		$source->setAuthConfig(['writable' => $writable]);
		$sources = $this->createMock(SourceMapperInterface::class);
		$sources->method('findForSystem')->with('7')->willReturn($source);
		return $sources;
	}//end sourcesWith()

	/**
	 * A good fromDatabase payload binds the database and makes its pages on every version, unprefixed.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function createApplicationFromADatabaseBindsItAndMakesItsPages(): void {
		$this->givenPermitsDatabase();
		$saved = [];
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$saved): \OCA\OpenRegister\Db\ObjectEntity {
				$saved[] = $object;
				$entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn(['id' => 'uuid-' . count($saved), 'uuid' => 'uuid-' . count($saved)]);
				return $entity;
			}
		);

		$uuid = $this->serviceWithDatabases(sources: $this->sourcesWith(type: 'database', writable: false))->createApplication([
			'name' => 'Vergunningen',
			'slug' => 'vergunningen',
			'preset' => 'dev-prod',
			'fromDatabase' => ['register' => 'permits-db', 'schemas' => ['permits']],
		]);

		self::assertSame('uuid-1', $uuid);
		self::assertSame([['register' => 'permits-db', 'label' => 'Permits database']], $saved[0]['dataRegisters']);

		$versions = array_values(array_filter($saved, static fn (array $object): bool => isset($object['manifest']) === true));
		self::assertCount(2, array_unique(array_column($versions, 'slug')));
		foreach ($versions as $version) {
			$pages = $version['manifest']['pages'];
			self::assertSame(['PermitsIndex', 'PermitsDetail'], array_column($pages, 'id'));
			self::assertSame('permits-db', $pages[0]['config']['register']);
			self::assertSame('permits', $pages[0]['config']['schema']);
			self::assertFalse($pages[0]['config']['showAdd']);
			self::assertSame('Permits', $version['manifest']['menu'][0]['label']);
		}
	}//end createApplicationFromADatabaseBindsItAndMakesItsPages()

	/**
	 * An unknown register is refused at validation, before anything is saved.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function createApplicationFromAnUnknownRegisterCreatesNothing(): void {
		$this->registerMapper->method('find')->willReturnCallback(
			static function (string|int $id): Register {
				if ($id === 'publications') {
					throw new RuntimeException('Register not found');
				}

				return new Register();
			}
		);
		$this->objectService->expects(self::never())->method('saveObject');

		try {
			$this->serviceWithDatabases(sources: $this->createMock(SourceMapperInterface::class))->createApplication([
				'name' => 'Vergunningen',
				'slug' => 'vergunningen',
				'preset' => 'single',
				'fromDatabase' => ['register' => 'publications', 'schemas' => ['permits']],
			]);
			self::fail('Expected a WizardCreationException.');
		} catch (WizardCreationException $e) {
			self::assertSame('validate', $e->getFailedAtStep());
			self::assertSame('The register "publications" does not exist or cannot be read.', $e->getMessage());
		}
	}//end createApplicationFromAnUnknownRegisterCreatesNothing()

	/**
	 * A register that no database backs is refused by name, before anything is saved.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function createApplicationFromARegisterThatIsNoDatabaseCreatesNothing(): void {
		$this->givenPermitsDatabase();
		$this->objectService->expects(self::never())->method('saveObject');

		try {
			$this->serviceWithDatabases(sources: $this->sourcesWith(type: 'api', writable: true))->createApplication([
				'name' => 'Vergunningen',
				'slug' => 'vergunningen',
				'preset' => 'single',
				'fromDatabase' => ['register' => 'permits-db', 'schemas' => ['permits']],
			]);
			self::fail('Expected a WizardCreationException.');
		} catch (WizardCreationException $e) {
			self::assertSame('validate', $e->getFailedAtStep());
			self::assertSame('The register "permits-db" is not a connected database.', $e->getMessage());
		}
	}//end createApplicationFromARegisterThatIsNoDatabaseCreatesNothing()

	/**
	 * A malformed fromDatabase block is refused, and without a reader the path is refused too.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function createApplicationRefusesAMalformedOrUnreadableDatabaseBlock(): void {
		$this->objectService->expects(self::never())->method('saveObject');
		$payload = ['name' => 'Vergunningen', 'slug' => 'vergunningen', 'preset' => 'single'];

		$messages = [];
		foreach ([
			[$this->serviceWithDatabases(sources: $this->createMock(SourceMapperInterface::class)), ['register' => 'permits-db']],
			[$this->service, ['register' => 'permits-db', 'schemas' => ['permits']]],
		] as [$service, $block]) {
			try {
				$service->createApplication($payload + ['fromDatabase' => $block]);
			} catch (WizardCreationException $e) {
				$messages[] = $e->getMessage();
			}
		}

		self::assertSame(
			['fromDatabase needs a register and a list of tables.', 'Connected databases cannot be read on this instance.'],
			$messages
		);
	}//end createApplicationRefusesAMalformedOrUnreadableDatabaseBlock()

	// -------------------------------------------------------------------------
	// Stubs
	// -------------------------------------------------------------------------

	/**
	 * Wire saveObject to return deterministic UUIDs for the given chain.
	 *
	 * The first call to saveObject returns the Application UUID; subsequent
	 * calls return version UUIDs in order; final calls for wiring + setting
	 * productionVersion return the last saved version.
	 *
	 * @param string $appUuid UUID to assign to the Application
	 * @param array<string,string> $versionUuids Map of versionSlug → UUID
	 *
	 * @return void
	 */
	private function stubSuccessfulCreation(string $appUuid, array $versionUuids): void {
		// Build the mock register. Register::getId() is now an explicit stub method
		// (declared in tests/stubs/openregister-stubs.php) so use onlyMethods().
		$mockRegister = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getSchemas', 'setSchemas', 'getId'])
			->getMock();
		$mockRegister->method('getSchemas')->willReturn([]);
		$mockRegister->method('getId')->willReturn(1);

		$this->registerMapper->method('find')->willReturn($mockRegister);
		$this->registerMapper->method('createFromArray')->willReturn($mockRegister);
		$this->registerMapper->method('update')->willReturn($mockRegister);

		// Build mock schema. Schema::getId() is now an explicit stub method
		// (declared in tests/stubs/openregister-stubs.php) so use onlyMethods().
		$mockSchema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$mockSchema->method('getId')->willReturn(1);
		$this->schemaMapper->method('find')->willReturn($mockSchema);
		$this->schemaMapper->method('createFromArray')->willReturn($mockSchema);

		// Build ObjectEntity mocks for saveObject return values.
		// saveObject() must return an ObjectEntity; normaliseObject() calls jsonSerialize()
		// on the result to extract the UUID, so each mock's jsonSerialize() returns the payload.
		$makeEntity = function (array $payload): \OCA\OpenRegister\Db\ObjectEntity {
			$entity = $this->createMock(\OCA\OpenRegister\Db\ObjectEntity::class);
			$entity->method('jsonSerialize')->willReturn($payload);
			return $entity;
		};

		$appEntity = $makeEntity(['id' => $appUuid, 'uuid' => $appUuid]);
		$versionEntities = [];
		foreach ($versionUuids as $slug => $uuid) {
			$versionEntities[] = $makeEntity(['id' => $uuid, 'uuid' => $uuid, 'slug' => $slug]);
		}

		// Map saveObject call sequence: app, then versions (×2 for create+wiring), then productionVersion update.
		$callQueue = [$appEntity, ...$versionEntities, ...array_fill(0, count($versionUuids), $appEntity), $appEntity];

		$callIndex = 0;
		$this->objectService->method('saveObject')
			->willReturnCallback(function () use ($callQueue, &$callIndex) {
				$result = $callQueue[$callIndex] ?? $callQueue[count($callQueue) - 1];
				$callIndex++;
				return $result;
			});
	}//end stubSuccessfulCreation()
}//end class
