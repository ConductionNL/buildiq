<?php

/**
 * Unit tests for ApplicationDeletionService.
 *
 * Covers the destructive teardown of an Application and its owned resources,
 * with particular focus on the data-purge path that only runs when the caller
 * opts in via $deleteData:
 *   - deleteData=false PRESERVES the per-version registers and their objects
 *     (no registerMapper->find, no registerService->delete)
 *   - deleteData=true drains every object of every schema BEFORE the register
 *     is deleted
 *   - the batch drain loop (purgeRegisterSchema) terminates correctly on each
 *     of its exit paths: empty batch, zero-progress batch, and the
 *     MAX_PURGE_ROUNDS safety cap (which must log a warning so the downstream
 *     register-delete orphan is diagnosable)
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

use OCA\Buildiq\Service\ApplicationDeletionService;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\RegisterStore;
use OCA\Buildiq\Service\SchemaReferenceResolver;
use OCA\Buildiq\Service\VersionSchemaLocator;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for ApplicationDeletionService.
 */
class ApplicationDeletionServiceTest extends TestCase {
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
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Warning messages captured from the logger (raw templates).
	 *
	 * @var array<int,string>
	 */
	private array $warnings = [];

	/**
	 * Service under test.
	 */
	private ApplicationDeletionService $service;

	/**
	 * Set up shared mocks + the SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Capture warnings so the cap-exhaustion assertions don't depend on
		// placeholder interpolation (the logger is passed a raw template).
		$this->warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);

		$this->service = new ApplicationDeletionService(
			store: new RegisterStore(
				objectService: $this->objectService,
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper
			),
			registerService: $this->registerService,
			// Real, not mocked: it is a thin resolver over the same two mapper
			// mocks, so every existing findAll()/findIdsBySlugs() stub below
			// still exercises the reference-matching logic it holds.
			schemaReferences: new SchemaReferenceResolver(
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper,
			),
			schemaLocator: new VersionSchemaLocator(
				registerMapper: $this->registerMapper,
				schemaMapper: $this->schemaMapper,
				logger: $this->logger,
			),
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Build a Register stub carrying the given schema ids.
	 *
	 * @param array<int,int> $schemaIds Schema id list the register owns.
	 *
	 * @return Register
	 */
	private function registerWithSchemas(array $schemaIds): Register {
		$register = new Register();
		$register->setSchemas($schemaIds);
		return $register;
	}//end registerWithSchemas()

	/**
	 * Route objectService->findAll by the schema filter: the versions query,
	 * the routes query, and the per-schema purge query are distinguished so a
	 * single callback can serve the whole teardown. The purge branch delegates
	 * to $purge($round) so each test controls the drain sequence.
	 *
	 * @param array<int,array<string,mixed>> $versions Version rows to return.
	 * @param callable $purge fn(int $round):array batch.
	 *
	 * @return void
	 */
	private function routeFindAll(array $versions, callable $purge): void {
		$round = 0;
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($versions, $purge, &$round): array {
				$schema = $config['filters']['schema'] ?? null;
				if ($schema === ApplicationVersionService::APPLICATION_VERSION_SCHEMA) {
					return $versions;
				}

				if ($schema === 'built-app-route') {
					return [];
				}

				// Per-schema purge query (register + schema, limit 500).
				$round++;
				return $purge($round);
			}
		);
	}//end routeFindAll()

	/**
	 * deleteData=false removes the app wrapper but PRESERVES the per-version
	 * register and everything inside it: no register lookup, no register
	 * delete, and only the version + application objects are deleted.
	 *
	 * @return void
	 */
	public function testDeleteDataFalsePreservesRegisterAndObjects(): void {
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: fn (int $round): array => [],
		);

		$this->registerMapper->expects($this->never())->method('find');
		$this->registerService->expects($this->never())->method('delete');

		$deleted = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid) use (&$deleted): bool {
				$deleted[] = $uuid;
				return true;
			}
		);

		$orphaned = $this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: false);

		self::assertSame([], $orphaned);
		self::assertSame(['v1', 'u-app'], $deleted, 'only the version and the app are deleted; register data is untouched');
	}//end testDeleteDataFalsePreservesRegisterAndObjects()

	/**
	 * deleteData=true drains the register's objects BEFORE the register itself
	 * is deleted (draining after delete would hit the "objects still attached"
	 * guard and orphan the register).
	 *
	 * @return void
	 */
	public function testDeleteDataTruePurgesObjectsBeforeDeletingRegister(): void {
		$register = $this->registerWithSchemas([10]);
		$this->registerMapper->method('find')->willReturn($register);

		// One non-empty batch, then drained.
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: fn (int $round): array => ($round === 1 ? [['id' => 'data-1']] : []),
		);

		$order = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid) use (&$order): bool {
				$order[] = 'obj:' . $uuid;
				return true;
			}
		);
		$this->registerService->expects($this->once())
			->method('delete')
			->with($register)
			->willReturnCallback(
				function (Register $r) use (&$order): Register {
					$order[] = 'register-delete';
					return $r;
				}
			);

		$orphaned = $this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame([], $orphaned);
		$dataIdx = array_search('obj:data-1', $order, true);
		$registerIdx = array_search('register-delete', $order, true);
		self::assertNotFalse($dataIdx, 'the register object must be purged');
		self::assertNotFalse($registerIdx, 'the register must be deleted');
		self::assertLessThan($registerIdx, $dataIdx, 'objects must be purged before the register is deleted');
	}//end testDeleteDataTruePurgesObjectsBeforeDeletingRegister()

	/**
	 * Draining a register removes the rows; deleting it removes the namespace.
	 * The schema DEFINITIONS the wizard cloned into that namespace used to
	 * survive both, so every "Also permanently delete all data" left a set of
	 * unowned schemas behind — and the schema designer, which lists by slug
	 * prefix, kept showing them alongside the live app's.
	 *
	 * @return void
	 */
	public function testDeleteDataTrueDeletesTheRegistersSchemaDefinitions(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWithSchemas([10, 11]));
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: fn (int $round): array => [],
		);
		$this->objectService->method('deleteObject')->willReturn(true);
		$this->registerService->method('delete')->willReturnArgument(0);

		$deleted = [];
		$this->captureSchemaDeletes($deleted);

		$orphaned = $this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame([], $orphaned);
		self::assertSame([10, 11], $deleted, 'every schema the register owned must be deleted with it');
	}//end testDeleteDataTrueDeletesTheRegistersSchemaDefinitions()

	/**
	 * `register.schemas` is a REFERENCE list — the same definition may be held
	 * by more than one register. Deleting one that another register still holds
	 * would break that register, so a shared schema is left alone.
	 *
	 * @return void
	 */
	public function testSchemaHeldByAnotherRegisterSurvives(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWithSchemas([10, 11]));
		// A surviving register elsewhere still holds schema 10.
		$this->registerMapper->method('findAll')->willReturn([$this->registerWithSchemas([10])]);
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: fn (int $round): array => [],
		);
		$this->objectService->method('deleteObject')->willReturn(true);
		$this->registerService->method('delete')->willReturnArgument(0);

		$deleted = [];
		$this->captureSchemaDeletes($deleted);

		$this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame([11], $deleted, 'only the schema no other register holds is deleted');
	}//end testSchemaHeldByAnotherRegisterSurvives()

	/**
	 * A schema referenced by a numeric id in one register and by its slug in
	 * another must still be recognised as shared. Casting a slug to (int)
	 * collapses it to 0, which would make `deleteUnreferencedSchemas()` treat
	 * schema 10 as unclaimed and delete it out from under the surviving
	 * register.
	 *
	 * @return void
	 */
	public function testSchemaHeldByAnotherRegisterViaSlugSurvives(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWithSchemas([10, 11]));
		// A surviving register elsewhere holds schema 10 by slug, not by id.
		$this->registerMapper->method('findAll')->willReturn([$this->registerWithSchemas(['invoice-schema'])]);
		$this->schemaMapper->method('findIdsBySlugs')->willReturnCallback(
			function (array $slugs): array {
				return in_array('invoice-schema', $slugs, true) === true
					? ['invoice-schema' => ['10']]
					: [];
			}
		);
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: fn (int $round): array => [],
		);
		$this->objectService->method('deleteObject')->willReturn(true);
		$this->registerService->method('delete')->willReturnArgument(0);

		$deleted = [];
		$this->captureSchemaDeletes($deleted);

		$this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame([11], $deleted, 'the schema shared by slug must survive, matching the by-id case');
	}//end testSchemaHeldByAnotherRegisterViaSlugSurvives()

	/**
	 * A promotion bug handed production's register development's schema ids,
	 * which left production's own schema attached to no register. "Delete all
	 * data" walked the register lists only, so that schema survived. It is now
	 * found by its version-namespaced slug, drained and deleted with the rest.
	 *
	 * @return void
	 */
	public function testDeleteDataTrueDeletesAVersionSchemaNoRegisterLists(): void {
		// Both registers list only the development schema (11).
		$this->registerMapper->method('find')->willReturnCallback(
			fn (): Register => $this->registerWithSchemas([11])
		);
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->schemaMapper->method('findIdsBySlugs')->willReturnCallback(
			static function (array $slugs): array {
				sort($slugs);
				self::assertSame(['demo-development-msg', 'demo-production-msg'], $slugs);
				return ['demo-development-msg' => ['11'], 'demo-production-msg' => ['21']];
			}
		);

		$purged = [];
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config) use (&$purged): array {
				$schema = $config['filters']['schema'] ?? null;
				if ($schema === ApplicationVersionService::APPLICATION_VERSION_SCHEMA) {
					return [
						['id' => 'v-dev', 'slug' => 'development', 'register' => 'openbuild-demo-development'],
						['id' => 'v-prod', 'slug' => 'production', 'register' => 'openbuild-demo-production'],
					];
				}

				if ($schema === 'built-app-route') {
					return [];
				}

				$purged[] = $config['filters']['register'] . '#' . (string)$schema;
				return [];
			}
		);
		$this->objectService->method('deleteObject')->willReturn(true);
		$this->registerService->method('delete')->willReturnArgument(0);

		$this->schemaMapper->method('find')->willReturnCallback(
			function (string|int $id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				$schema->setSlug((string)$id === '11' ? 'demo-development-msg' : 'demo-production-msg');
				return $schema;
			}
		);
		$deleted = [];
		$this->schemaMapper->method('delete')->willReturnCallback(
			function (Schema $schema) use (&$deleted): Schema {
				$deleted[] = $schema->getId();
				return $schema;
			}
		);

		$orphaned = $this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame([], $orphaned);
		self::assertContains('openbuild-demo-production#21', $purged, 'the detached schema is drained from its version register');
		sort($deleted);
		self::assertSame([11, 21], $deleted, 'the detached production schema is deleted too');
	}//end testDeleteDataTrueDeletesAVersionSchemaNoRegisterLists()

	/**
	 * Without the data opt-in the register is preserved, so its schemas are
	 * preserved with it — they still describe live data.
	 *
	 * @return void
	 */
	public function testDeleteDataFalseKeepsSchemaDefinitions(): void {
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: fn (int $round): array => [],
		);
		$this->objectService->method('deleteObject')->willReturn(true);
		$this->schemaMapper->expects($this->never())->method('delete');

		$this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: false);
	}//end testDeleteDataFalseKeepsSchemaDefinitions()

	/**
	 * A register scan we cannot trust must not authorise a delete: the schemas
	 * are reported as orphaned instead of being removed on a guess.
	 *
	 * @return void
	 */
	public function testUnreadableRegisterScanOrphansSchemasRatherThanDeleting(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWithSchemas([10]));
		$this->registerMapper->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: fn (int $round): array => [],
		);
		$this->objectService->method('deleteObject')->willReturn(true);
		$this->registerService->method('delete')->willReturnArgument(0);
		$this->schemaMapper->expects($this->never())->method('delete');

		$orphaned = $this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame(['schema:10'], $orphaned);
	}//end testUnreadableRegisterScanOrphansSchemasRatherThanDeleting()

	/**
	 * Wire the schema mapper so every delete appends its schema id to $deleted.
	 *
	 * @param array<int,int> $deleted Collector for the deleted schema ids.
	 *
	 * @return void
	 */
	private function captureSchemaDeletes(array &$deleted): void {
		$this->schemaMapper->method('find')->willReturnCallback(
			function (string|int $id): Schema {
				$schema = new Schema();
				$schema->setId((int)$id);
				return $schema;
			}
		);
		$this->schemaMapper->method('delete')->willReturnCallback(
			function (Schema $schema) use (&$deleted): Schema {
				$deleted[] = $schema->getId();
				return $schema;
			}
		);
	}//end captureSchemaDeletes()

	/**
	 * An empty first batch exits the drain loop after exactly one findAll —
	 * it does not spin for a second round.
	 *
	 * @return void
	 */
	public function testPurgeEmptyBatchExitsAfterSingleRound(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWithSchemas([10]));

		$purgeCalls = 0;
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: function (int $round) use (&$purgeCalls): array {
				$purgeCalls = $round;
				return [];
			},
		);
		$this->objectService->method('deleteObject')->willReturn(true);
		$this->registerService->method('delete')->willReturnArgument(0);

		$this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame(1, $purgeCalls, 'an empty first batch must exit after exactly one findAll');
		self::assertSame([], $this->warnings, 'a clean drain logs no warning');
	}//end testPurgeEmptyBatchExitsAfterSingleRound()

	/**
	 * A batch where every deleteObject throws makes no progress, so the loop
	 * returns before a second round instead of spinning. The undeletable
	 * object is recorded as orphaned, and the cap warning does NOT fire.
	 *
	 * @return void
	 */
	public function testPurgeZeroProgressExitsBeforeSecondRound(): void {
		$this->registerMapper->method('find')->willReturn($this->registerWithSchemas([10]));

		$purgeCalls = 0;
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: function (int $round) use (&$purgeCalls): array {
				$purgeCalls = $round;
				return [['id' => 'stuck-1']];
			},
		);

		// The register object can never be deleted; version + app succeed.
		$this->objectService->method('deleteObject')->willReturnCallback(
			function (string $uuid): bool {
				if ($uuid === 'stuck-1') {
					throw new RuntimeException('object is locked');
				}
				return true;
			}
		);
		$this->registerService->method('delete')->willReturnArgument(0);

		$orphaned = $this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame(1, $purgeCalls, 'a zero-progress round must return before a second findAll');
		self::assertContains('object:stuck-1', $orphaned);
		self::assertEmpty(
			array_filter($this->warnings, static fn (string $m): bool => str_contains($m, 'MAX_PURGE_ROUNDS')),
			'the cap warning must not fire when the loop exits on zero progress',
		);
	}//end testPurgeZeroProgressExitsBeforeSecondRound()

	/**
	 * A register that never drains (every round returns a full, deletable
	 * batch) runs the full MAX_PURGE_ROUNDS cap and then logs a single warning
	 * so the downstream register-delete orphan is diagnosable.
	 *
	 * @return void
	 */
	public function testPurgeCapExhaustionLogsWarningOnce(): void {
		$maxRounds = (new ReflectionClass(ApplicationDeletionService::class))->getConstant('MAX_PURGE_ROUNDS');

		$this->registerMapper->method('find')->willReturn($this->registerWithSchemas([10]));

		$purgeCalls = 0;
		$this->routeFindAll(
			versions: [['id' => 'v1', 'register' => 'reg-demo']],
			purge: function (int $round) use (&$purgeCalls): array {
				$purgeCalls = $round;
				// Always a non-empty batch with a fresh, deletable uuid so the
				// loop makes progress every round yet never empties.
				return [['id' => 'never-drains-' . $round]];
			},
		);
		$this->objectService->method('deleteObject')->willReturn(true);
		$this->registerService->method('delete')->willReturnArgument(0);

		$this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: true);

		self::assertSame($maxRounds, $purgeCalls, 'the loop must run exactly MAX_PURGE_ROUNDS rounds');
		$capWarnings = array_filter(
			$this->warnings,
			static fn (string $m): bool => str_contains($m, 'hit MAX_PURGE_ROUNDS cap'),
		);
		self::assertCount(1, $capWarnings, 'the cap-exhaustion warning must fire exactly once');
	}//end testPurgeCapExhaustionLogsWarningOnce()

	/**
	 * The whole teardown, call by call, with and without deleteData: every
	 * lookup, purge query, delete and log line in order, and the orphan list,
	 * compared with the recorded fixture. Set GOLDEN_RECORD=1 to write a
	 * missing fixture.
	 *
	 * @return void
	 */
	public function testTeardownTraceGolden(): void {
		$fixture = __DIR__ . '/fixtures/application-deletion-trace.golden.json';
		$cases = [];
		foreach ([true, false] as $deleteData) {
			$cases[] = $this->teardownTrace(deleteData: $deleteData);
		}

		$actual = json_encode($cases, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . "\n";
		if (file_exists($fixture) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents($fixture, $actual);
		}

		self::assertFileExists($fixture);
		self::assertSame(file_get_contents($fixture), $actual);
	}//end testTeardownTraceGolden()

	/**
	 * Run one teardown over five versions and two routes and record every call.
	 *
	 * Versions: one whose register drains and deletes, one whose register is
	 * gone, one whose register refuses to delete, one keyed only by `@self.id`
	 * and one with no id. One route refuses to delete; one schema refuses to
	 * load.
	 *
	 * @param bool $deleteData Whether the data goes too.
	 *
	 * @return array<string,mixed> The trace and the orphan list.
	 */
	private function teardownTrace(bool $deleteData): array {
		$this->setUp();
		$trace = [];
		$record = static function (string $call, array $args = []) use (&$trace): void {
			$trace[] = $call . ' ' . json_encode($args, JSON_UNESCAPED_SLASHES);
		};
		foreach (['info', 'error', 'warning'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				static function (string $message, array $context = []) use ($record, $level): void {
					$record($level, [$message, $context]);
				}
			);
		}

		$versions = [
			['id' => 'v1', 'slug' => 'development', 'register' => 'reg-a'],
			['id' => 'v2', 'register' => 'reg-b'],
			['id' => 'v3', 'register' => 'reg-c'],
			['@self' => ['id' => 'v4']],
			['slug' => 'no-id'],
		];
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($record, $versions): array {
				$record('findAll', $config);
				$schema = ($config['filters']['schema'] ?? null);
				if ($schema === ApplicationVersionService::APPLICATION_VERSION_SCHEMA) {
					return $versions;
				}

				if ($schema === 'built-app-route') {
					return [['id' => 'r1'], ['@self' => ['id' => 'r2']]];
				}

				return [];
			}
		);
		$this->objectService->method('deleteObject')->willReturnCallback(
			static function (...$args) use ($record): bool {
				// A uuid string, or a deletion value object carrying one.
				$uuid = '?';
				if (is_string($args[0] ?? null) === true) {
					$uuid = $args[0];
				} else if (is_object($args[0] ?? null) === true && isset($args[0]->uuid) === true) {
					$uuid = (string)$args[0]->uuid;
				}

				$record('deleteObject', [$uuid]);
				if ($uuid === 'r1') {
					throw new RuntimeException('route locked');
				}

				return true;
			}
		);
		$registers = ['reg-a' => [10, 11], 'reg-c' => [12]];
		$this->registerMapper->method('find')->willReturnCallback(
			function (...$args) use ($record, $registers): Register {
				$record('findRegister', [$args[0]]);
				if (isset($registers[$args[0]]) === false) {
					throw new RuntimeException('no register ' . $args[0]);
				}

				$register = $this->registerWithSchemas($registers[$args[0]]);
				$register->setSlug($args[0]);
				return $register;
			}
		);
		$this->registerMapper->method('findAll')->willReturn([]);
		$this->registerService->method('delete')->willReturnCallback(
			static function (Register $register) use ($record): Register {
				$record('deleteRegister', [$register->getSlug()]);
				if ($register->getSlug() === 'reg-c') {
					throw new RuntimeException('register busy');
				}

				return $register;
			}
		);
		$this->schemaMapper->method('find')->willReturnCallback(
			static function (string|int $id) use ($record): Schema {
				$record('findSchema', [$id]);
				if ($id === 11) {
					throw new RuntimeException('schema unreadable');
				}

				$schema = new Schema();
				$schema->setId($id);
				return $schema;
			}
		);
		$this->schemaMapper->method('delete')->willReturnCallback(
			static function (Schema $schema) use ($record): Schema {
				$record('deleteSchema', [$schema->getId()]);
				return $schema;
			}
		);

		$orphaned = $this->service->deleteApplication(appUuid: 'u-app', appSlug: 'demo', deleteData: $deleteData);

		return ['deleteData' => $deleteData, 'trace' => $trace, 'orphaned' => $orphaned];
	}//end teardownTrace()
}//end class
