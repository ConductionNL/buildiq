<?php

/**
 * Edge-case tests for the MigrateAppOverridesToHybrid repair step.
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

use OCA\Buildiq\Repair\MigrateAppOverridesToHybrid;
use OCA\Buildiq\Service\AppOverrideService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Repair\MigrateAppOverridesToHybrid
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class MigrateAppOverridesToHybridEdgeCasesTest extends TestCase {
	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var AppOverrideService&MockObject
	 */
	private AppOverrideService&MockObject $appOverrideService;

	/**
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Output lines, as "level: message".
	 *
	 * @var list<string>
	 */
	private array $said = [];

	/**
	 * The legacy schema double (id 42).
	 *
	 * @var Schema&MockObject
	 */
	private Schema&MockObject $schema;

	/**
	 * Set up mocks with a resolvable legacy schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->appOverrideService = $this->createMock(AppOverrideService::class);
		$this->output = $this->createMock(IOutput::class);
		$this->said = [];

		$this->output->method('info')->willReturnCallback(
			function (string $message): void {
				$this->said[] = 'info: ' . $message;
			}
		);
		$this->output->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->said[] = 'warning: ' . $message;
			}
		);

		$this->schema = $this->getMockBuilder(Schema::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$this->schema->method('getId')->willReturn(42);
		$this->schemaMapper->method('find')->willReturn($this->schema);

	}//end setUp()

	/**
	 * Resolve the buildiq register to id 7 and return the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Legacy rows.
	 *
	 * @return void
	 */
	private function rows(array $rows): void {
		$register = $this->getMockBuilder(Register::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(7);
		$this->registerMapper->method('find')->willReturn($register);
		$this->objectService->method('findAll')
			->with(['filters' => ['register' => 7, 'schema' => 42]])
			->willReturn($rows);

	}//end rows()

	/**
	 * Build the SUT.
	 *
	 * @return MigrateAppOverridesToHybrid
	 */
	private function step(): MigrateAppOverridesToHybrid {
		return new MigrateAppOverridesToHybrid(
			logger: $this->logger,
			objectService: $this->objectService,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			appOverrideService: $this->appOverrideService
		);

	}//end step()

	/**
	 * The step names the migration.
	 *
	 * @return void
	 */
	public function testTheNameDescribesTheMigration(): void {
		$this->assertSame('Migrate Buildiq AppOverride records to hybrid Applications', $this->step()->getName());

	}//end testTheNameDescribesTheMigration()

	/**
	 * Without the legacy schema the step reports nothing to migrate.
	 *
	 * @return void
	 */
	public function testNoLegacySchemaIsReported(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('missing'));
		$this->schemaMapper->expects($this->never())->method('delete');

		$this->step()->run($this->output);

		$this->assertSame(
			['info: Migrate-app-overrides-to-hybrid: no app-override schema present, nothing to migrate.'],
			$this->said
		);

	}//end testNoLegacySchemaIsReported()

	/**
	 * Without the buildiq register no rows are read and the empty schema is dropped.
	 *
	 * @return void
	 */
	public function testAMissingRegisterMigratesNothingAndDropsTheSchema(): void {
		$this->registerMapper->method('find')->willThrowException(new RuntimeException('no register'));
		$this->objectService->expects($this->never())->method('findAll');
		$this->schemaMapper->expects($this->once())->method('delete')->with($this->schema);

		$this->step()->run($this->output);

		$this->assertSame(
			[
				'info: Migrate-app-overrides-to-hybrid: migrated 0 override(s) to hybrid apps.',
				'info: Migrate-app-overrides-to-hybrid: dropped the retired app-override schema.',
			],
			$this->said
		);

	}//end testAMissingRegisterMigratesNothingAndDropsTheSchema()

	/**
	 * A row without appId is skipped and the schema is retained for retry.
	 *
	 * @return void
	 */
	public function testARowWithoutAppIdRetainsTheSchema(): void {
		$this->rows(rows: [['id' => 'row-x', 'manifestDelta' => []]]);
		$this->appOverrideService->expects($this->never())->method('upsert');
		$this->schemaMapper->expects($this->never())->method('delete');

		$warnings = [];
		$this->logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);

		$this->step()->run($this->output);

		$this->assertSame(
			[
				['Buildiq: MigrateAppOverridesToHybrid skipped a row without appId', ['row' => ['id' => 'row-x', 'manifestDelta' => []]]],
				['Buildiq: MigrateAppOverridesToHybrid: schema retained — 1 override(s) un-migrated', []],
			],
			$warnings
		);
		$this->assertSame(
			[
				'info: Migrate-app-overrides-to-hybrid: migrated 0 override(s) to hybrid apps.',
				'warning: Migrate-app-overrides-to-hybrid: 1 override(s) failed to migrate; '
				. 'retaining the app-override schema and its rows for retry (schema NOT dropped).',
			],
			$this->said
		);

	}//end testARowWithoutAppIdRetainsTheSchema()

	/**
	 * A non-array delta becomes empty, a non-array baseRef becomes null, and
	 * the source row is found by its @self id.
	 *
	 * @return void
	 */
	public function testMalformedFieldsAreNormalisedAndSelfIdIsUsed(): void {
		$this->rows(
			rows: [
				[
					'appId' => 'docudesk',
					'manifestDelta' => 'not-an-array',
					'baseRef' => 'fleet-app:docudesk',
					'@self' => ['id' => 'self-uuid'],
				],
			]
		);
		$this->appOverrideService->expects($this->once())->method('upsert')
			->with('docudesk', [], null, '', true)
			->willReturn([]);
		$this->objectService->expects($this->once())->method('deleteObject')
			->with('self-uuid', false, false);

		$this->step()->run($this->output);

		$this->assertSame(
			[
				"info: Migrate-app-overrides-to-hybrid: migrated override 'docudesk' to a hybrid app.",
				'info: Migrate-app-overrides-to-hybrid: migrated 1 override(s) to hybrid apps.',
				'info: Migrate-app-overrides-to-hybrid: dropped the retired app-override schema.',
			],
			$this->said
		);

	}//end testMalformedFieldsAreNormalisedAndSelfIdIsUsed()

	/**
	 * A row with no identifier is migrated without a delete attempt.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdIsNotDeleted(): void {
		$this->rows(rows: [['appId' => 'larpingapp', 'uuid' => '']]);
		$this->appOverrideService->method('upsert')->willReturn([]);
		$this->objectService->expects($this->never())->method('deleteObject');

		$this->step()->run($this->output);

		$this->assertContains("info: Migrate-app-overrides-to-hybrid: migrated override 'larpingapp' to a hybrid app.", $this->said);

	}//end testARowWithoutAnIdIsNotDeleted()

	/**
	 * A failing source delete is logged but the row still counts as migrated.
	 *
	 * @return void
	 */
	public function testAFailedSourceDeleteStillCountsAsMigrated(): void {
		$this->rows(rows: [['uuid' => 'u-1', 'appId' => 'pipelinq']]);
		$this->appOverrideService->method('upsert')->willReturn([]);
		$this->objectService->method('deleteObject')->willThrowException(new RuntimeException('locked'));
		$this->schemaMapper->expects($this->once())->method('delete');

		$this->logger->expects($this->once())->method('error')
			->with(
				'Buildiq: MigrateAppOverridesToHybrid: migrated but failed to delete source row',
				['appId' => 'pipelinq', 'exception' => 'locked']
			);

		$this->step()->run($this->output);

		$this->assertContains('info: Migrate-app-overrides-to-hybrid: migrated 1 override(s) to hybrid apps.', $this->said);

	}//end testAFailedSourceDeleteStillCountsAsMigrated()

	/**
	 * A failing schema drop is reported as harmless and logged.
	 *
	 * @return void
	 */
	public function testAFailedSchemaDropIsReported(): void {
		$this->rows(rows: []);
		$this->schemaMapper->method('delete')->willThrowException(new RuntimeException('in use'));

		$this->logger->expects($this->once())->method('warning')
			->with('Buildiq: MigrateAppOverridesToHybrid: schema drop failed', ['exception' => 'in use']);

		$this->step()->run($this->output);

		$this->assertSame(
			[
				'info: Migrate-app-overrides-to-hybrid: migrated 0 override(s) to hybrid apps.',
				'warning: Migrate-app-overrides-to-hybrid: could not drop the app-override schema (in use); it is empty and harmless.',
			],
			$this->said
		);

	}//end testAFailedSchemaDropIsReported()
}//end class
