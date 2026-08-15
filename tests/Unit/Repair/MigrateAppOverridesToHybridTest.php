<?php

/**
 * Unit tests for MigrateAppOverridesToHybrid repair step.
 *
 * Covers unify-apps-with-app-type (spec unified-app-model): no-op when no
 * legacy schema, migrates each AppOverride row to a hybrid app then deletes the
 * source row and drops the schema, and is idempotent on a second run.
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
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Repair;

use OCA\OpenBuild\Repair\MigrateAppOverridesToHybrid;
use OCA\OpenBuild\Service\AppOverrideService;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for MigrateAppOverridesToHybrid.
 */
class MigrateAppOverridesToHybridTest extends TestCase {
	/**
	 * Mock OR object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock register mapper.
	 *
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

	/**
	 * Mock schema mapper.
	 *
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * Mock unified hybrid-app store.
	 *
	 * @var AppOverrideService&MockObject
	 */
	private AppOverrideService&MockObject $appOverrideService;

	/**
	 * Repair step under test.
	 */
	private MigrateAppOverridesToHybrid $repair;

	/**
	 * Set up shared mocks + the SUT.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->appOverrideService = $this->createMock(AppOverrideService::class);

		$this->repair = new MigrateAppOverridesToHybrid(
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objectService,
			registerMapper: $this->registerMapper,
			schemaMapper: $this->schemaMapper,
			appOverrideService: $this->appOverrideService
		);

	}//end setUp()

	/**
	 * No legacy app-override schema → no-op (no migration, no deletion).
	 *
	 * @return void
	 */
	public function testNoOpWhenNoLegacySchema(): void {
		$this->schemaMapper->method('find')->willThrowException(new RuntimeException('not found'));
		$this->appOverrideService->expects(self::never())->method('upsert');
		$this->objectService->expects(self::never())->method('deleteObject');

		$this->repair->run($this->createMock(IOutput::class));

	}//end testNoOpWhenNoLegacySchema()

	/**
	 * Each legacy row is migrated (upsert), its source row deleted, and the
	 * legacy schema dropped at the end.
	 *
	 * @return void
	 */
	public function testMigratesRowDeletesSourceAndDropsSchema(): void {
		$schema = $this->getMockBuilder(Schema::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(42);
		$this->schemaMapper->method('find')->willReturn($schema);

		$register = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(7);
		$this->registerMapper->method('find')->willReturn($register);

		$this->objectService->method('findAll')->willReturn(
			[
				[
					'id' => 'row-1',
					'appId' => 'opencatalogi',
					'manifestDelta' => ['pages' => [['id' => 'home', 'title' => 'X']]],
					'baseRef' => ['kind' => 'fleet-app', 'id' => 'opencatalogi'],
					'updatedBy' => 'alice',
				],
			]
		);

		$this->appOverrideService->expects(self::once())
			->method('upsert')
			->with('opencatalogi', self::anything(), self::anything(), 'alice')
			->willReturn(['appId' => 'opencatalogi', 'applicationUuid' => 'app-1']);

		$this->objectService->expects(self::once())
			->method('deleteObject')
			->with('row-1');

		$this->schemaMapper->expects(self::once())->method('delete');

		$this->repair->run($this->createMock(IOutput::class));

	}//end testMigratesRowDeletesSourceAndDropsSchema()

	/**
	 * When a row FAILS to migrate, the legacy schema is NOT dropped — dropping it
	 * cascade-deletes the un-migrated rows, so a partial failure must retain the
	 * schema for retry (regression: the first live run dropped the schema despite
	 * a failed row and destroyed the data).
	 *
	 * @return void
	 */
	public function testDoesNotDropSchemaWhenARowFails(): void {
		$schema = $this->getMockBuilder(Schema::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(42);
		$this->schemaMapper->method('find')->willReturn($schema);

		$register = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(7);
		$this->registerMapper->method('find')->willReturn($register);

		$this->objectService->method('findAll')->willReturn(
			[['id' => 'row-1', 'appId' => 'pipelinq', 'manifestDelta' => ['pages' => []]]]
		);

		// upsert throws → migrateOne returns false → failed count > 0.
		$this->appOverrideService->method('upsert')
			->willThrowException(new RuntimeException('create denied'));

		// The source row must be preserved and the schema NOT dropped.
		$this->objectService->expects(self::never())->method('deleteObject');
		$this->schemaMapper->expects(self::never())->method('delete');

		$this->repair->run($this->createMock(IOutput::class));

	}//end testDoesNotDropSchemaWhenARowFails()

	/**
	 * A second run (no rows left) performs no migration and is a no-op for data,
	 * still attempting the (idempotent) schema drop.
	 *
	 * @return void
	 */
	public function testIdempotentWhenNoRowsRemain(): void {
		$schema = $this->getMockBuilder(Schema::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(42);
		$this->schemaMapper->method('find')->willReturn($schema);

		$register = $this->getMockBuilder(\OCA\OpenRegister\Db\Register::class)->disableOriginalConstructor()->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(7);
		$this->registerMapper->method('find')->willReturn($register);

		$this->objectService->method('findAll')->willReturn([]);
		$this->appOverrideService->expects(self::never())->method('upsert');
		$this->objectService->expects(self::never())->method('deleteObject');

		$this->repair->run($this->createMock(IOutput::class));

	}//end testIdempotentWhenNoRowsRemain()
}//end class
