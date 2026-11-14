<?php

/**
 * Edge-case tests for the MigrateToVersionedModel repair step.
 *
 * Pins the paths the main test leaves open: an unreadable OpenRegister counts
 * as a fresh install, rows without a slug or UUID fail the run, a missing
 * per-app register still lets the row go, and a row delete that fails after
 * its register was dropped is reported and marks the run failed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Repair
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

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\MigrateToVersionedModel;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Repair\MigrateToVersionedModel
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class MigrateToVersionedModelEdgeCasesTest extends TestCase {
	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock OR object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock OR register service.
	 *
	 * @var RegisterService&MockObject
	 */
	private RegisterService&MockObject $registerService;

	/**
	 * Mock OR register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Mock OR schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Mock repair output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * State values written via setValueString(), in order.
	 *
	 * @var array<int, string>
	 */
	private array $persisted = [];

	/**
	 * Set up mocks.
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
		$this->output = $this->createMock(IOutput::class);
		$this->appConfig = $this->createMock(IAppConfig::class);

		$this->appConfig->method('getValueString')->willReturn('');
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->persisted[] = $app . '/' . $key . '=' . $value;
				return true;
			}
		);

		$this->objectService->method('runAsSystem')->willReturnCallback(
			static fn (callable $operation) => $operation()
		);
	}//end setUp()

	/**
	 * Build the SUT.
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
	 * A register double with the given id.
	 *
	 * @param int $id Register id.
	 *
	 * @return Register&MockObject
	 */
	private function register(int $id): Register&MockObject {
		$register = $this->getMockBuilder(Register::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$register->method('getId')->willReturn($id);
		return $register;
	}//end register()

	/**
	 * Resolve the buildiq register and application schema; per-app registers
	 * are answered by $perApp (slug => register, or null for "not found").
	 *
	 * @param array<string, Register|null> $perApp Per-app register answers.
	 *
	 * @return void
	 */
	private function mappers(array $perApp = []): void {
		$this->registerMapper->method('find')->willReturnCallback(
			function (string|int $slug) use ($perApp): Register {
				if ($slug === 'buildiq') {
					return $this->register(id: 5);
				}

				if (array_key_exists((string)$slug, $perApp) === true && $perApp[$slug] !== null) {
					return $perApp[$slug];
				}

				throw new RuntimeException(message: 'no register ' . $slug);
			}
		);

		$schema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getId'])
			->getMock();
		$schema->method('getId')->willReturn(9);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end mappers()

	/**
	 * An entity double serialising to the payload.
	 *
	 * @param array<string, mixed> $payload Serialised payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $payload): ObjectEntity&MockObject {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($payload);
		return $entity;
	}//end entity()

	/**
	 * The step's name flags it as destructive.
	 *
	 * @return void
	 */
	public function testTheNameFlagsTheStepAsDestructive(): void {
		$this->assertSame('Migrate Buildiq to versioned app model (DESTRUCTIVE)', $this->step()->getName());
	}//end testTheNameFlagsTheStepAsDestructive()

	/**
	 * Without the buildiq register the install is fresh: nothing is deleted and
	 * the run is persisted as done.
	 *
	 * @return void
	 */
	public function testAMissingRegisterCountsAsAFreshInstall(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException(message: 'no register'));
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('deleteObject');
		$this->registerService->expects($this->never())->method('delete');

		$this->output->expects($this->once())->method('info')
			->with('Migrated-to-versioned-model: schema already in versioned shape, skipping');

		$this->step()->run($this->output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=done'], $this->persisted);
	}//end testAMissingRegisterCountsAsAFreshInstall()

	/**
	 * The enumeration filters on the resolved register and schema ids.
	 *
	 * @return void
	 */
	public function testTheEnumerationFiltersOnTheResolvedIds(): void {
		$this->mappers();
		$this->objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 5, 'schema' => 9]])
			->willReturn([]);

		$this->step()->run($this->output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=done'], $this->persisted);
	}//end testTheEnumerationFiltersOnTheResolvedIds()

	/**
	 * A failing object lookup is treated as nothing to migrate.
	 *
	 * @return void
	 */
	public function testAFailingObjectLookupIsTreatedAsVersioned(): void {
		$this->mappers();
		$this->objectService->method('findAll')->willThrowException(new RuntimeException(message: 'db down'));
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->output->expects($this->once())->method('info')
			->with('Migrated-to-versioned-model: schema already in versioned shape, skipping');

		$this->step()->run($this->output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=done'], $this->persisted);
	}//end testAFailingObjectLookupIsTreatedAsVersioned()

	/**
	 * Each legacy top-level key on its own marks the row as pre-migration.
	 *
	 * @return void
	 */
	public function testEveryLegacyKeyTriggersTheMigration(): void {
		$this->mappers();
		$this->objectService->method('findAll')->willReturn(
			[
				$this->entity(['id' => 'u-m', 'slug' => 'm', 'manifest' => []]),
				$this->entity(['id' => 'u-v', 'slug' => 'v', 'version' => '1']),
				$this->entity(['id' => 'u-s', 'slug' => 's', 'status' => 'x']),
			]
		);

		$deleted = [];
		$this->objectService->method('deleteObject')->willReturnCallback(
			static function (string $uuid) use (&$deleted): bool {
				$deleted[] = $uuid;
				return true;
			}
		);

		$this->step()->run($this->output);

		$this->assertSame(['u-m', 'u-v', 'u-s'], $deleted);
		$this->assertSame(['buildiq/repair_migrate_versioned_model=done'], $this->persisted);
	}//end testEveryLegacyKeyTriggersTheMigration()

	/**
	 * A row without a slug is skipped, logged, and marks the run failed.
	 *
	 * @return void
	 */
	public function testARowWithoutASlugFailsTheRun(): void {
		$this->mappers();
		$this->objectService->method('findAll')->willReturn(
			[$this->entity(['id' => 'u-1', 'currentVersion' => 'cv'])]
		);
		$this->objectService->expects($this->never())->method('deleteObject');
		$this->registerService->expects($this->never())->method('delete');

		$this->logger->expects($this->once())->method('warning')
			->with(
				'Buildiq: MigrateToVersionedModel skipped Application without slug',
				['application' => ['id' => 'u-1', 'currentVersion' => 'cv']]
			);

		$this->step()->run($this->output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=failed'], $this->persisted);
	}//end testARowWithoutASlugFailsTheRun()

	/**
	 * Without a per-app register the row is still deleted, by its uuid fallback.
	 *
	 * @return void
	 */
	public function testAMissingPerAppRegisterStillDeletesTheRow(): void {
		$this->mappers(perApp: ['openbuild-alpha' => null]);
		$this->objectService->method('findAll')->willReturn(
			[$this->entity(['uuid' => 'uuid-alpha', 'slug' => 'alpha', 'currentVersion' => 'cv'])]
		);
		$this->registerService->expects($this->never())->method('delete');
		$this->objectService->expects($this->once())->method('deleteObject')
			->with('uuid-alpha')
			->willReturn(true);

		$this->output->expects($this->once())->method('info')
			->with("Migrated-to-versioned-model: dropped Application 'alpha' and register 'buildiq-alpha'");

		$this->step()->run($this->output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=done'], $this->persisted);
	}//end testAMissingPerAppRegisterStillDeletesTheRow()

	/**
	 * The per-app register dropped is the versioned prefix plus the slug.
	 *
	 * @return void
	 */
	public function testThePerAppRegisterUsesTheVersionPrefix(): void {
		$perApp = $this->register(id: 42);
		$this->mappers(perApp: ['openbuild-beta' => $perApp]);
		$this->objectService->method('findAll')->willReturn(
			[$this->entity(['id' => 'u-b', 'slug' => 'beta', 'currentVersion' => 'cv'])]
		);
		$this->registerService->expects($this->once())->method('delete')->with($perApp);
		$this->objectService->expects($this->once())->method('deleteObject')->with('u-b')->willReturn(true);

		$this->step()->run($this->output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=done'], $this->persisted);
	}//end testThePerAppRegisterUsesTheVersionPrefix()

	/**
	 * A row with a slug but no id or uuid cannot be deleted and fails the run.
	 *
	 * @return void
	 */
	public function testARowWithoutAUuidFailsTheRun(): void {
		$this->mappers();
		$this->objectService->method('findAll')->willReturn(
			[$this->entity(['slug' => 'gamma', 'currentVersion' => 'cv'])]
		);
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->logger->expects($this->once())->method('warning')
			->with("Buildiq: MigrateToVersionedModel: Application 'gamma' has no UUID; cannot delete row.");

		$this->step()->run($this->output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=failed'], $this->persisted);
	}//end testARowWithoutAUuidFailsTheRun()

	/**
	 * A row delete failing after the register drop is reported and fails the run.
	 *
	 * @return void
	 */
	public function testAFailedRowDeleteIsReportedAndFailsTheRun(): void {
		$this->mappers(perApp: ['openbuild-delta' => $this->register(id: 7)]);
		$this->objectService->method('findAll')->willReturn(
			[$this->entity(['id' => 'u-d', 'slug' => 'delta', 'currentVersion' => 'cv'])]
		);
		$this->registerService->expects($this->once())->method('delete');
		$this->objectService->method('deleteObject')->willThrowException(new RuntimeException(message: 'locked'));

		$this->output->expects($this->once())->method('warning')
			->with(
				"Migrated-to-versioned-model: dropped register 'openbuild-delta'"
				. " but FAILED to delete Application row 'delta' (locked)."
			);
		$this->output->expects($this->never())->method('info');
		$this->logger->expects($this->once())->method('error')
			->with(
				'Buildiq: MigrateToVersionedModel: row-delete failed after register dropped',
				['slug' => 'delta', 'exception' => 'locked']
			);

		$this->step()->run($this->output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=failed'], $this->persisted);
	}//end testAFailedRowDeleteIsReportedAndFailsTheRun()
}//end class
