<?php

/**
 * Unit tests for the failure paths of two repair steps that their own tests
 * leave out: MigrateSchemaApplicationId when only the read of the old
 * application id fails, and MigrateToVersionedModel when recording "done"
 * fails or the legacy rows are gone by the time the migration enumerates.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\MigrateSchemaApplicationId;
use OCA\Buildiq\Repair\MigrateToVersionedModel;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure paths of MigrateSchemaApplicationId and MigrateToVersionedModel.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RepairStepFailurePathsTest extends TestCase {

	/**
	 * A failed read of the old application id is logged and treated as nothing to move.
	 *
	 * @return void
	 */
	public function testAFailedReadOfTheOldAppIdMovesNothing(): void {
		$taken = $this->createMock(IResult::class);
		$taken->method('fetchAll')->willReturn([['slug' => 'Case']]);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->exactly(2))
			->method('executeQuery')
			->willReturnCallback(
				static function (string $sql, array $params) use ($taken): IResult {
					if ($params === ['openbuild']) {
						throw new DbException('table locked');
					}

					return $taken;
				}
			);
		$db->expects($this->never())->method('executeStatement');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'MigrateSchemaApplicationId: could not read schemas on the old application id; skipping.',
				['exception' => 'table locked']
			);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with('MigrateSchemaApplicationId: no schemas on the old application id; nothing to do.');

		(new MigrateSchemaApplicationId($db, $logger))->run($output);
	}//end testAFailedReadOfTheOldAppIdMovesNothing()

	/**
	 * Rows on the old application id without a slug are not candidates.
	 *
	 * @return void
	 */
	public function testRowsWithoutASlugAreNotCandidates(): void {
		$none = $this->createMock(IResult::class);
		$none->method('fetchAll')->willReturn([]);
		$old = $this->createMock(IResult::class);
		$old->method('fetchAll')->willReturn([['slug' => ''], ['other' => 'x']]);

		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnOnConsecutiveCalls($none, $old);
		$db->expects($this->never())->method('executeStatement');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with('MigrateSchemaApplicationId: no schemas on the old application id; nothing to do.');

		(new MigrateSchemaApplicationId($db, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testRowsWithoutASlugAreNotCandidates()

	/**
	 * When recording "done" fails, the run warns, logs an error and does not record anything.
	 *
	 * @return void
	 */
	public function testAFailureToRecordDoneIsASafeSkip(): void {
		$failure = new RuntimeException('config table read-only');
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->expects($this->once())->method('setValueString')->willThrowException($failure);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willThrowException(new RuntimeException('no register'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Buildiq: MigrateToVersionedModel short-circuit detection failed', ['exception' => $failure]);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Migrated-to-versioned-model: schema already in versioned shape, skipping');
		$output->expects($this->once())
			->method('warning')
			->with('Migrated-to-versioned-model: could not determine schema state (config table read-only); skipping for safety.');

		$this->migration(appConfig: $appConfig, registerMapper: $registerMapper, logger: $logger)->run($output);
	}//end testAFailureToRecordDoneIsASafeSkip()

	/**
	 * Legacy rows seen by the check but gone when the migration enumerates: nothing to migrate, recorded as done.
	 *
	 * @return void
	 */
	public function testLegacyRowsGoneByEnumerationAreDone(): void {
		$persisted = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) use (&$persisted): bool {
				$persisted[] = $app . '/' . $key . '=' . $value;
				return true;
			}
		);

		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(5);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('runAsSystem')->willReturnCallback(static fn (callable $operation) => $operation());
		$objects->expects($this->exactly(2))
			->method('findAll')
			->willReturnOnConsecutiveCalls([['slug' => 'permits', 'manifest' => []]], []);
		$objects->expects($this->never())->method('deleteObject');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Migrated-to-versioned-model: no pre-migration Application rows found.');

		$this->migration(appConfig: $appConfig, registerMapper: $registerMapper, objects: $objects)->run($output);

		$this->assertSame(['buildiq/repair_migrate_versioned_model=done'], $persisted);
	}//end testLegacyRowsGoneByEnumerationAreDone()

	/**
	 * The migration step over the given doubles; the application schema resolves to id 9.
	 *
	 * @param IAppConfig                  $appConfig      The app config.
	 * @param RegisterMapper              $registerMapper The register mapper.
	 * @param LoggerInterface|null        $logger         The logger.
	 * @param ObjectServiceInterface|null $objects        The object service.
	 *
	 * @return MigrateToVersionedModel The step.
	 */
	private function migration(
		IAppConfig $appConfig,
		RegisterMapper $registerMapper,
		?LoggerInterface $logger = null,
		?ObjectServiceInterface $objects = null
	): MigrateToVersionedModel {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(9);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		return new MigrateToVersionedModel(
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
			objectService: ($objects ?? $this->createMock(ObjectServiceInterface::class)),
			registerService: $this->createMock(RegisterService::class),
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			appConfig: $appConfig,
		);
	}//end migration()
}//end class
