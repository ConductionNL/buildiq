<?php

/**
 * Golden tests for how the versioning repair steps enumerate their rows.
 *
 * MigrateAppOverridesToHybrid and MigrateToVersionedModel each read every row of
 * one schema in buildiq's register before migrating them. These pin the
 * findAll() filter each sends, that every row comes back normalised and in
 * order, and that a missing register (or schema) yields no rows and one debug
 * line instead of an exception.
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
use OCA\Buildiq\Repair\MigrateToVersionedModel;
use OCA\Buildiq\Service\AppOverrideService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\RegisterService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Pins the row enumeration of the two versioning repairs.
 */
class RepairEnumerationTest extends TestCase {
	/**
	 * Object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Register mapper double.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Schema mapper double.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The legacy override rows of schema 42, normalised and in order.
	 *
	 * @return void
	 */
	public function testLegacyOverridesAreReadFromTheRegister(): void {
		$this->register(id: 7);
		$this->objectService->expects(self::once())->method('findAll')
			->with(['filters' => ['register' => 7, 'schema' => 42]])
			->willReturn($this->rows());

		self::assertSame([['id' => 'a'], ['id' => 'b']], $this->enumerate(repair: $this->overrides(), method: 'enumerateLegacyOverrides', args: [42]));
	}//end testLegacyOverridesAreReadFromTheRegister()

	/**
	 * Without the register there are no legacy overrides, and one debug line says so.
	 *
	 * @return void
	 */
	public function testLegacyOverridesWithoutTheRegisterAreNone(): void {
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->objectService->expects(self::never())->method('findAll');
		$this->logger->expects(self::once())->method('debug')->with('Buildiq: MigrateAppOverridesToHybrid: buildiq register not found (gone).');

		self::assertSame([], $this->enumerate(repair: $this->overrides(), method: 'enumerateLegacyOverrides', args: [42]));
	}//end testLegacyOverridesWithoutTheRegisterAreNone()

	/**
	 * The Application rows, normalised and in order.
	 *
	 * @return void
	 */
	public function testApplicationsAreReadFromTheRegister(): void {
		$this->register(id: 7);
		$schema = new Schema();
		$schema->setId(9);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->objectService->expects(self::once())->method('findAll')
			->with(['filters' => ['register' => 7, 'schema' => 9]])
			->willReturn($this->rows());

		self::assertSame([['id' => 'a'], ['id' => 'b']], $this->enumerate(repair: $this->versioned(), method: 'enumerateApplications', args: []));
	}//end testApplicationsAreReadFromTheRegister()

	/**
	 * Without the Application schema there are no rows, and one debug line says so.
	 *
	 * @return void
	 */
	public function testApplicationsWithoutTheSchemaAreNone(): void {
		$this->register(id: 7);
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('no schema'));
		$this->objectService->expects(self::never())->method('findAll');
		$this->logger->expects(self::once())->method('debug')->with('Buildiq: MigrateToVersionedModel enumeration found no register/schema: no schema');

		self::assertSame([], $this->enumerate(repair: $this->versioned(), method: 'enumerateApplications', args: []));
	}//end testApplicationsWithoutTheSchemaAreNone()

	/**
	 * Two stored rows: one already an array, one an entity.
	 *
	 * @return array<int, mixed>
	 */
	private function rows(): array {
		$entity = $this->createMock(ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn(['id' => 'b']);
		return [['id' => 'a'], $entity];
	}//end rows()

	/**
	 * Resolve buildiq's register to the given id.
	 *
	 * @param int $id The register id.
	 *
	 * @return void
	 */
	private function register(int $id): void {
		$register = new Register();
		$register->setId($id);
		$this->registerMapper->method('find')->willReturn($register);
	}//end register()

	/**
	 * Call a repair's private enumeration.
	 *
	 * @param object            $repair The repair step.
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   Its arguments.
	 *
	 * @return mixed
	 */
	private function enumerate(object $repair, string $method, array $args): mixed {
		return (new ReflectionMethod($repair, $method))->invokeArgs($repair, $args);
	}//end enumerate()

	/**
	 * The override migration under test.
	 *
	 * @return MigrateAppOverridesToHybrid
	 */
	private function overrides(): MigrateAppOverridesToHybrid {
		return new MigrateAppOverridesToHybrid(
			logger: $this->logger,
			objectService: $this->objectService,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			appOverrideService: $this->createMock(AppOverrideService::class),
		);
	}//end overrides()

	/**
	 * The versioned-model migration under test.
	 *
	 * @return MigrateToVersionedModel
	 */
	private function versioned(): MigrateToVersionedModel {
		return new MigrateToVersionedModel(
			logger: $this->logger,
			objectService: $this->objectService,
			registerService: $this->createMock(RegisterService::class),
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			appConfig: $this->createMock(IAppConfig::class),
		);
	}//end versioned()
}//end class
