<?php

/**
 * Unit tests for MigrateToVersionedModel repair step.
 *
 * Covers spec REQ-OBGFM-001 (destructive deletion), REQ-OBGFM-002 (idempotent
 * short-circuit), REQ-OBGFM-003 (one log line per deletion), and
 * REQ-OBGFM-004 (per-app register-delete failure isolation).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Repair
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

namespace OCA\OpenBuild\Tests\Unit\Repair;

use OCA\OpenBuild\Repair\MigrateToVersionedModel;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateToVersionedModel.
 */
class MigrateToVersionedModelTest extends TestCase {
	/**
	 * Terminal state: every pre-migration row was migrated (or none existed).
	 * Mirrors the SUT's private `STATE_DONE` constant.
	 */
	private const STATE_DONE = 'done';

	/**
	 * Terminal state: a row still failed to migrate while running with
	 * system-context elevation active. Mirrors the SUT's private
	 * `STATE_FAILED` constant.
	 */
	private const STATE_FAILED = 'failed';

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock OR ObjectService.
	 *
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

	/**
	 * Mock OR RegisterService.
	 *
	 * @var RegisterService&MockObject
	 */
	private RegisterService&MockObject $registerService;

	/**
	 * Mock OR RegisterMapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Mock OR SchemaMapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Mock NC IOutput.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Mock NC IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectService::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->output = $this->createMock(IOutput::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		// No prior run persisted a state marker, by default.
		$this->appConfig->method('getValueString')->willReturn('');

		// `ObjectService::runAsSystem()` really invokes the given callable and
		// returns its result — mirrors the real elevation, which is transparent
		// to the caller. Individual tests may override this to simulate an
		// OpenRegister release that predates the elevation API.
		$this->objectService->method('runAsSystem')->willReturnCallback(
			static fn (callable $operation) => $operation()
		);
	}//end setUp()

	/**
	 * Build the SUT with the shared mocks.
	 *
	 * @return MigrateToVersionedModel
	 */
	private function step(): MigrateToVersionedModel {
		return new MigrateToVersionedModel(
			logger: $this->logger,
			objectService: $this->objectService,
			registerService: $this->registerService,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			appConfig: $this->appConfig,
		);
	}//end step()

	/**
	 * Short-circuit: no Application rows carry pre-spec-C shape → no deletions.
	 *
	 * The previous check probed for the `applicationVersion` schema's
	 * existence, but `InitializeSettings` imports that schema BEFORE
	 * this step runs, so the probe always fired true and the migration
	 * was skipped (openbuild#69). The new check inspects Application
	 * row shape and only proceeds when at least one row still carries
	 * a legacy `manifest` / `version` / `status` / `currentVersion`
	 * top-level field.
	 *
	 * @return void
	 */
	public function testShortCircuitsWhenVersionedSchemaPresent(): void {
		// Register + schema lookups succeed.
		$register = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$register->method('getId')->willReturn(1);
		$this->registerMapper->method('find')->willReturn($register);

		$this->schemaMapper->method('find')->willReturn(
			$this->getMockBuilder(Schema::class)
				->disableOriginalConstructor()
				->onlyMethods(['getId'])
				->getMock()
		);

		// Application rows already match the post-C shape (no legacy keys).
		$postCRows = [
			$this->mockEntity(['id' => 'uuid-a', 'slug' => 'app-a', 'name' => 'App A']),
		];
		$this->objectService->method('findAll')->willReturn($postCRows);

		// No deletions should fire: rows look post-C so the short-circuit
		// returns true and `run()` exits before touching either service.
		$this->objectService->expects(self::never())->method('deleteObject');
		$this->registerService->expects(self::never())->method('delete');

		$this->output->expects(self::once())
			->method('info')
			->with(self::stringContains('schema already in versioned shape'));

		$this->step()->run($this->output);
	}//end testShortCircuitsWhenVersionedSchemaPresent()

	/**
	 * Three pre-migration Applications → three deletions + three log lines.
	 *
	 * @return void
	 */
	public function testDeletesEveryPreMigrationApplication(): void {
		// Versioned schema is absent.
		$this->schemaMapper->method('find')
			->willReturnCallback(function (string|int $slug) {
				if ($slug === 'applicationVersion') {
					throw new RuntimeException(message: 'not found');
				}

				return $this->getMockBuilder(Schema::class)
					->disableOriginalConstructor()
					->onlyMethods(['getId'])
					->getMock();
			});

		$register = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$register->method('getId')->willReturn(1);
		$this->registerMapper->method('find')->willReturn($register);

		$appRows = [
			$this->mockEntity(['id' => 'uuid-a', 'slug' => 'app-a', 'currentVersion' => 'old-cv-a']),
			$this->mockEntity(['id' => 'uuid-b', 'slug' => 'app-b', 'currentVersion' => 'old-cv-b']),
			$this->mockEntity(['id' => 'uuid-c', 'slug' => 'app-c', 'currentVersion' => 'old-cv-c']),
		];
		$this->objectService->method('findAll')->willReturn($appRows);

		$this->registerService->expects(self::exactly(3))->method('delete');
		$this->objectService->expects(self::exactly(3))->method('deleteObject');

		$infoMessages = [];
		$this->output->method('info')->willReturnCallback(static function (string $msg) use (&$infoMessages): void {
			$infoMessages[] = $msg;
		});

		$this->step()->run($this->output);

		$matching = array_filter(
			$infoMessages,
			static fn (string $line): bool => str_contains($line, 'Migrated-to-versioned-model: dropped Application')
		);

		self::assertCount(3, $matching);
		self::assertNotEmpty(array_filter($matching, static fn (string $line): bool => str_contains($line, "'app-a'")));
		self::assertNotEmpty(array_filter($matching, static fn (string $line): bool => str_contains($line, "'app-b'")));
		self::assertNotEmpty(array_filter($matching, static fn (string $line): bool => str_contains($line, "'app-c'")));
	}//end testDeletesEveryPreMigrationApplication()

	/**
	 * Partial-failure: register-delete fails for slug B → A and C deleted,
	 * B's row is preserved, B failure is logged via $output->warning.
	 *
	 * @return void
	 */
	public function testPartialFailurePreservesRowOnFailedRegisterDelete(): void {
		$this->schemaMapper->method('find')
			->willReturnCallback(function (string|int $slug) {
				if ($slug === 'applicationVersion') {
					throw new RuntimeException(message: 'not found');
				}

				return $this->getMockBuilder(Schema::class)
					->disableOriginalConstructor()
					->onlyMethods(['getId'])
					->getMock();
			});

		$register = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$register->method('getId')->willReturn(1);

		// registerMapper->find returns a mock for every slug.
		$this->registerMapper->method('find')->willReturn($register);

		$appRows = [
			$this->mockEntity(['id' => 'uuid-a', 'slug' => 'app-a', 'currentVersion' => 'cv-a']),
			$this->mockEntity(['id' => 'uuid-b', 'slug' => 'app-b', 'currentVersion' => 'cv-b']),
			$this->mockEntity(['id' => 'uuid-c', 'slug' => 'app-c', 'currentVersion' => 'cv-c']),
		];
		$this->objectService->method('findAll')->willReturn($appRows);

		$this->registerService->method('delete')->willReturnCallback(
			function (Register $r) use ($register): Register {
				// First call OK; second call fails; third OK.
				static $callCount = 0;
				$callCount++;
				if ($callCount === 2) {
					throw new RuntimeException(message: 'simulated OR failure');
				}

				return $register;
			}
		);

		$deletedUuids = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			static function (string $uuid) use (&$deletedUuids): bool {
				$deletedUuids[] = $uuid;
				return true;
			}
		);

		$warningCalls = 0;
		$this->output->method('warning')->willReturnCallback(static function () use (&$warningCalls): void {
			$warningCalls++;
		});

		// A row failed while running WITH system-context elevation active
		// (the mocked ObjectService always exposes runAsSystem()) — this is
		// operator-facing, so the terminal `failed` state is persisted and
		// the next invocation will short-circuit instead of retrying app-b
		// forever. Must be configured BEFORE run() executes — a mock
		// expectation only counts invocations that happen after it is set up.
		$this->assertPersistedState(self::STATE_FAILED);

		$this->step()->run($this->output);

		self::assertSame(['uuid-a', 'uuid-c'], $deletedUuids, 'Only the rows whose register-delete succeeded are removed.');
		self::assertGreaterThanOrEqual(1, $warningCalls, 'Failure for app-b is surfaced via $output->warning.');
	}//end testPartialFailurePreservesRowOnFailedRegisterDelete()

	/**
	 * A previous run already completed (`done` persisted) → `run()`
	 * short-circuits before touching either OpenRegister service, and does
	 * not re-persist the state (spec: converge — stop re-attempting).
	 *
	 * @return void
	 */
	public function testSkipsWhenPreviouslyCompleted(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn(self::STATE_DONE);

		$this->registerMapper->expects(self::never())->method('find');
		$this->objectService->expects(self::never())->method('findAll');
		$this->registerService->expects(self::never())->method('delete');
		$this->appConfig->expects(self::never())->method('setValueString');

		$this->output->expects(self::once())
			->method('info')
			->with(self::stringContains('previously completed'));

		$this->step()->run($this->output);
	}//end testSkipsWhenPreviouslyCompleted()

	/**
	 * A previous run persisted the terminal `failed` state → `run()`
	 * short-circuits and tells the operator how to re-arm it, instead of
	 * re-attempting the same doomed delete on every invocation (the bug
	 * behind the 306x/2h error-log flood).
	 *
	 * @return void
	 */
	public function testSkipsWhenPreviouslyFailed(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn(self::STATE_FAILED);

		$this->registerMapper->expects(self::never())->method('find');
		$this->objectService->expects(self::never())->method('findAll');
		$this->appConfig->expects(self::never())->method('setValueString');

		$this->output->expects(self::once())
			->method('info')
			->with(self::stringContains('previously failed'));

		$this->step()->run($this->output);
	}//end testSkipsWhenPreviouslyFailed()

	/**
	 * Every row migrates cleanly → the aggregate outcome persisted is
	 * `done`, so the next invocation (whatever re-triggers it) short-circuits
	 * at the top of `run()` instead of re-running the destructive migration.
	 *
	 * @return void
	 */
	public function testPersistsDoneStateAfterFullSuccess(): void {
		$this->schemaMapper->method('find')
			->willReturnCallback(function (string|int $slug) {
				if ($slug === 'applicationVersion') {
					throw new RuntimeException(message: 'not found');
				}

				return $this->getMockBuilder(Schema::class)
					->disableOriginalConstructor()
					->onlyMethods(['getId'])
					->getMock();
			});

		$register = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$register->method('getId')->willReturn(1);
		$this->registerMapper->method('find')->willReturn($register);

		$this->objectService->method('findAll')->willReturn(
			[$this->mockEntity(['id' => 'uuid-a', 'slug' => 'app-a', 'currentVersion' => 'old-cv-a'])]
		);

		$this->assertPersistedState(self::STATE_DONE);

		$this->step()->run($this->output);
	}//end testPersistsDoneStateAfterFullSuccess()

	/**
	 * Configure `$this->appConfig` to expect exactly one `setValueString`
	 * call persisting the given migration state, scoped to the app's own
	 * config key.
	 *
	 * @param string $expectedState The state value expected to be persisted
	 *
	 * @return void
	 */
	private function assertPersistedState(string $expectedState): void {
		$this->appConfig->expects(self::once())
			->method('setValueString')
			->with(self::anything(), self::anything(), $expectedState);
	}//end assertPersistedState()

	/**
	 * Build a stand-in ObjectEntity mock that exposes `jsonSerialize` returning the given payload.
	 *
	 * @param array<string,mixed> $payload The serialised payload
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function mockEntity(array $payload): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end mockEntity()
}//end class
