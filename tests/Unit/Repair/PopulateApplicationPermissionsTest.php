<?php

/**
 * Unit tests for the repair step that gives pre-existing Applications an owner.
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

use OCA\Buildiq\Repair\PopulateApplicationPermissions;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PopulateApplicationPermissions.
 *
 * @covers \OCA\Buildiq\Repair\PopulateApplicationPermissions
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class PopulateApplicationPermissionsTest extends TestCase {

	/**
	 * Mock OR object service; runAsSystem() runs the operation it is given.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock repair output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Step under test.
	 *
	 * @var PopulateApplicationPermissions
	 */
	private PopulateApplicationPermissions $step;

	/**
	 * Wire mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->objectService->method('runAsSystem')->willReturnCallback(fn (callable $operation): mixed => $operation());
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->output = $this->createMock(originalClassName: IOutput::class);
		$this->step = new PopulateApplicationPermissions(logger: $this->logger, objectService: $this->objectService);
	}//end setUp()

	/**
	 * The Applications findAll() returns.
	 *
	 * @param array<int, mixed> $applications The entries.
	 *
	 * @return void
	 */
	private function applications(array $applications): void {
		$this->objectService->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'buildiq', 'schema' => 'built-app'], 'limit' => 1000])
			->willReturn($applications);
	}//end applications()

	/**
	 * Collect the info lines the step writes.
	 *
	 * @return \ArrayObject<int, string>
	 */
	private function collectInfo(): \ArrayObject {
		$lines = new \ArrayObject();
		$this->output->method('info')->willReturnCallback(
			function (string $message) use ($lines): void {
				$lines[] = $message;
			}
		);

		return $lines;
	}//end collectInfo()

	/**
	 * With no Applications nothing is saved.
	 *
	 * @return void
	 */
	public function testNoApplicationsMeansNothingToMigrate(): void {
		$this->applications(applications: []);
		$this->objectService->expects($this->never())->method('saveObject');
		$info = $this->collectInfo();

		$this->step->run(output: $this->output);

		$this->assertSame(
			expected: ['Populating permissions on pre-existing Applications...', 'No Applications found; nothing to migrate.'],
			actual: $info->getArrayCopy()
		);
	}//end testNoApplicationsMeansNothingToMigrate()

	/**
	 * Applications without owners get the admin group as owner; those with owners are left alone.
	 *
	 * @return void
	 */
	public function testApplicationsWithoutOwnersGetTheAdminGroup(): void {
		$entity = $this->createMock(originalClassName: ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'self-id'], 'slug' => 'from-entity']);
		$this->applications(
			applications: [
				['uuid' => 'keep', 'permissions' => ['owners' => ['group:sales']]],
				['uuid' => 'none', 'slug' => 'a'],
				['uuid' => 'empty', 'permissions' => ['owners' => []]],
				['uuid' => 'scalar', 'permissions' => ['owners' => 'admin']],
				$entity,
			]
		);
		$saved = [];
		$this->objectService->expects($this->exactly(count: 4))->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$saved): ObjectEntityInterface {
				$saved[] = $object;
				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);
		$this->logger->expects($this->once())->method('info')
			->with('Buildiq: PopulateApplicationPermissions completed', ['patched' => 4]);
		$info = $this->collectInfo();

		$this->step->run(output: $this->output);

		$owner = ['owners' => ['admin'], 'editors' => [], 'viewers' => []];
		$this->assertSame(
			expected: [
				['uuid' => 'none', 'slug' => 'a', 'permissions' => $owner],
				['uuid' => 'empty', 'permissions' => $owner],
				['uuid' => 'scalar', 'permissions' => $owner],
				['@self' => ['id' => 'self-id'], 'slug' => 'from-entity', 'permissions' => $owner],
			],
			actual: $saved
		);
		$this->assertSame(expected: 'Permissions populated on 4 Application(s).', actual: $info[1]);
	}//end testApplicationsWithoutOwnersGetTheAdminGroup()

	/**
	 * Saves bypass RBAC and multitenancy, into the buildiq built-app schema.
	 *
	 * @return void
	 */
	public function testTheSaveBypassesRbacAndMultitenancy(): void {
		$this->applications(applications: [['uuid' => 'u1']]);
		$this->objectService->expects($this->once())->method('saveObject')->with(
			['uuid' => 'u1', 'permissions' => ['owners' => ['admin'], 'editors' => [], 'viewers' => []]],
			$this->anything(),
			'buildiq',
			'built-app',
			null,
			false,
			false
		)->willReturn($this->createMock(originalClassName: ObjectEntityInterface::class));

		$this->step->run(output: $this->output);
	}//end testTheSaveBypassesRbacAndMultitenancy()

	/**
	 * An Application without a UUID is skipped; a refused save is listed and not counted.
	 *
	 * @return void
	 */
	public function testSkippedAndRefusedApplicationsAreReported(): void {
		$this->applications(applications: [['slug' => 'no-uuid', '@self' => ['id' => '']], ['uuid' => 'refused'], ['uuid' => 'ok']]);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				if ($object['uuid'] === 'refused') {
					throw new RuntimeException('validation failed');
				}

				return $this->createMock(originalClassName: ObjectEntityInterface::class);
			}
		);
		$warnings = [];
		$this->output->method('warning')->willReturnCallback(
			function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('Buildiq: PopulateApplicationPermissions could not save refused', ['exception' => 'validation failed']);
		$info = $this->collectInfo();

		$this->step->run(output: $this->output);

		$this->assertSame(
			expected: [
				'Skipping an Application without a resolvable UUID.',
				'1 Application(s) were refused on save and still have no permissions: refused',
			],
			actual: $warnings
		);
		$this->assertSame(expected: 'Permissions populated on 1 Application(s).', actual: $info[1]);
	}//end testSkippedAndRefusedApplicationsAreReported()

	/**
	 * A failing lookup is reported and logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingLookupIsReportedNotThrown(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));
		$this->output->expects($this->once())->method('warning')->with('Could not populate permissions: db down');
		$this->logger->expects($this->once())->method('error')
			->with('Buildiq: PopulateApplicationPermissions failed', ['exception' => 'db down']);

		$this->step->run(output: $this->output);
	}//end testAFailingLookupIsReportedNotThrown()
}//end class
