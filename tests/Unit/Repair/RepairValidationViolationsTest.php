<?php

/**
 * Unit tests for the RepairValidationViolations repair step.
 *
 * Runs the step against an in-memory store of Application and
 * ApplicationVersion rows: every row that breaks a register validation is
 * repaired or reported, every other row is left alone, the counts are
 * reported, a second run changes nothing, and no failure escapes.
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
 *
 * @covers \OCA\Buildiq\Repair\RepairValidationViolations
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 *
 * @spec openspec/specs/unified-app-model/spec.md#requirement-application-carries-a-baseref-linking-a-hybrid-app-to-its-fleet-base
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\RepairValidationViolations;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for RepairValidationViolations.
 */
class RepairValidationViolationsTest extends TestCase {
	/**
	 * A version uuid.
	 *
	 * @var string
	 */
	private const SELF = '3f2b6a1e-7c4d-4e8a-9b1f-0a2c3d4e5f60';

	/**
	 * Another version uuid.
	 *
	 * @var string
	 */
	private const OTHER = '9d8c7b6a-5f4e-4d3c-8b2a-1f0e9d8c7b6a';

	/**
	 * Stored rows by schema slug, then uuid.
	 *
	 * @var array<string, array<string, array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Every save, in order, as [schema, uuid, object, register].
	 *
	 * @var array<int, array{0: mixed, 1: mixed, 2: array<string,mixed>, 3: mixed}>
	 */
	private array $saves = [];

	/**
	 * Mock OR object service backed by the store.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up the store and the object service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->store = [
			'built-app' => [
				'a-missing' => ['slug' => 'files', 'appType' => 'hybrid'],
				'a-blank' => ['slug' => ' deck ', 'appType' => 'hybrid', 'baseRef' => ['kind' => 'application-version', 'id' => '  ', 'note' => 'kept']],
				'a-string' => ['slug' => 'mail', 'appType' => 'hybrid', 'baseRef' => 'mail'],
				'a-noslug' => ['slug' => '', 'appType' => 'hybrid', 'baseRef' => null],
				'a-good' => ['slug' => 'calendar', 'appType' => 'hybrid', 'baseRef' => ['kind' => 'fleet-app', 'id' => 'calendar']],
				'a-virtual' => ['slug' => 'mine', 'appType' => 'virtual'],
				'a-legacy' => ['slug' => 'old'],
			],
			'applicationVersion' => [
				self::SELF => ['promotesTo' => strtoupper(self::SELF), 'scope' => 'admin'],
				self::OTHER => ['promotesTo' => 'urn:uuid:' . self::OTHER],
				'v-onward' => ['promotesTo' => self::OTHER],
				'v-nowhere' => ['promotesTo' => null],
				'v-braced' => ['promotesTo' => '{V-BRACED}'],
				'5e6f7a8b-9c0d-4e1f-8a2b-3c4d5e6f7a8b' => ['promotesTo' => '5E6F7A8B9C0D4E1F8A2B3C4D5E6F7A8B'],
				'v-ownerless' => ['scope' => 'user', 'owner' => ' '],
				'v-owned' => ['scope' => 'user', 'owner' => 'alice'],
				'v-admin' => ['scope' => 'admin'],
			],
		];
		$this->saves = [];

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('runAsSystem')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$this->objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $rbac = true, bool $multitenancy = true): array {
				$this->assertSame([false, false], [$rbac, $multitenancy], 'findAll runs without RBAC and multitenancy');
				return $this->find(config: $config);
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, mixed $uuid = null, bool $rbac = true, bool $multitenancy = true): ObjectEntityInterface {
				$this->assertSame([false, false], [$rbac, $multitenancy], 'saveObject runs without RBAC and multitenancy');
				$this->saves[] = [$schema, $uuid, $object, $register];
				$this->store[(string)$schema][(string)$uuid] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

	}//end setUp()

	/**
	 * Every violating row is repaired or reported, and nothing else is saved.
	 *
	 * @return void
	 */
	public function testRepairsViolatingRowsOnly(): void {
		$this->runStep();

		$saved = [];
		foreach ($this->saves as [$schema, $uuid, $object, $register]) {
			$this->assertSame('buildiq', $register);
			$this->assertArrayNotHasKey('@self', $object);
			$saved[$schema . '/' . $uuid] = $object;
		}

		$this->assertSame(
			[
				'built-app/a-missing' => ['slug' => 'files', 'appType' => 'hybrid', 'baseRef' => ['kind' => 'fleet-app', 'id' => 'files']],
				'built-app/a-blank' => ['slug' => ' deck ', 'appType' => 'hybrid', 'baseRef' => ['kind' => 'fleet-app', 'id' => 'deck', 'note' => 'kept']],
				'built-app/a-string' => ['slug' => 'mail', 'appType' => 'hybrid', 'baseRef' => ['kind' => 'fleet-app', 'id' => 'mail']],
				'applicationVersion/' . self::SELF => ['promotesTo' => null, 'scope' => 'admin'],
				'applicationVersion/' . self::OTHER => ['promotesTo' => null],
				'applicationVersion/v-braced' => ['promotesTo' => null],
				'applicationVersion/5e6f7a8b-9c0d-4e1f-8a2b-3c4d5e6f7a8b' => ['promotesTo' => null],
			],
			$saved
		);

	}//end testRepairsViolatingRowsOnly()

	/**
	 * The counts are reported, and each ownerless user version is named.
	 *
	 * @return void
	 */
	public function testReportsTheCounts(): void {
		$output = $this->runStep();

		$this->assertSame(
			[
				'info' => [
					'Repair-validation-violations: hybrid apps without baseRef.id 4 (backfilled 3), '
					. 'versions promoting to themselves 4 (cleared 4), user-scoped versions without owner 1 (left unchanged), failed 1.',
				],
				'warning' => [
					'Repair-validation-violations: user-scoped versions without an owner are refused on their next save; '
					. 'assign an owner or delete them: v-ownerless',
				],
			],
			$output
		);

	}//end testReportsTheCounts()

	/**
	 * A second run finds only what it cannot repair, and saves nothing.
	 *
	 * @return void
	 */
	public function testSecondRunChangesNothing(): void {
		$this->runStep();
		$stored = $this->store;
		$this->saves = [];

		$output = $this->runStep();

		$this->assertSame([], $this->saves);
		$this->assertSame($stored, $this->store);
		$this->assertSame(
			'Repair-validation-violations: hybrid apps without baseRef.id 1 (backfilled 0), '
			. 'versions promoting to themselves 0 (cleared 0), user-scoped versions without owner 1 (left unchanged), failed 1.',
			$output['info'][0]
		);

	}//end testSecondRunChangesNothing()

	/**
	 * One instance run twice reports each run on its own.
	 *
	 * @return void
	 */
	public function testEachRunOfOneInstanceReportsItsOwnCounts(): void {
		$step = new RepairValidationViolations(logger: $this->createMock(LoggerInterface::class), objectService: $this->objectService);
		$this->runStep(step: $step);

		$output = $this->runStep(step: $step);

		$this->assertSame(
			[
				'info' => [
					'Repair-validation-violations: hybrid apps without baseRef.id 1 (backfilled 0), '
					. 'versions promoting to themselves 0 (cleared 0), user-scoped versions without owner 1 (left unchanged), failed 1.',
				],
				'warning' => [
					'Repair-validation-violations: user-scoped versions without an owner are refused on their next save; '
					. 'assign an owner or delete them: v-ownerless',
				],
			],
			$output
		);

	}//end testEachRunOfOneInstanceReportsItsOwnCounts()

	/**
	 * A save that fails is counted, and the run goes on to the next row.
	 *
	 * @return void
	 */
	public function testFailedSaveIsCountedAndTheRunGoesOn(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('runAsSystem')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$objectService->method('findAll')->willReturnCallback(fn (array $config): array => $this->find(config: $config));
		$objectService->expects($this->exactly(7))->method('saveObject')->willThrowException(new RuntimeException('refused'));

		$output = $this->runStep(objectService: $objectService);

		$this->assertStringEndsWith('(backfilled 0), versions promoting to themselves 4 (cleared 0), user-scoped versions without owner 1 (left unchanged), failed 8.', $output['info'][0]);

	}//end testFailedSaveIsCountedAndTheRunGoesOn()

	/**
	 * Without a register or schema there is nothing to repair, and no error.
	 *
	 * @return void
	 */
	public function testMissingRegisterIsANoOp(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('runAsSystem')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$objectService->method('findAll')->willThrowException(new RuntimeException('register not found'));
		$objectService->expects($this->never())->method('saveObject');

		$output = $this->runStep(objectService: $objectService);

		$this->assertSame(
			[
				'info' => [
					'Repair-validation-violations: hybrid apps without baseRef.id 0 (backfilled 0), '
					. 'versions promoting to themselves 0 (cleared 0), user-scoped versions without owner 0 (left unchanged), failed 0.',
				],
				'warning' => [],
			],
			$output
		);

	}//end testMissingRegisterIsANoOp()

	/**
	 * A failure outside the per-row handling is reported, never thrown.
	 *
	 * @return void
	 */
	public function testFailureIsReportedNotThrown(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('runAsSystem')->willThrowException(new RuntimeException('no system context'));

		$output = $this->runStep(objectService: $objectService);

		$this->assertSame(['info' => [], 'warning' => ['Repair-validation-violations: could not run (no system context).']], $output);

	}//end testFailureIsReportedNotThrown()

	/**
	 * Rows come back as OpenRegister entities as well as arrays.
	 *
	 * @return void
	 */
	public function testReadsEntities(): void {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'a-entity'], 'slug' => 'talk', 'appType' => 'hybrid']);
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('runAsSystem')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$objectService->method('findAll')->willReturnCallback(static fn (array $config): array => ($config['filters']['schema'] === 'built-app' ? [$entity] : []));
		$objectService->expects($this->once())->method('saveObject')->with(
			['slug' => 'talk', 'appType' => 'hybrid', 'baseRef' => ['kind' => 'fleet-app', 'id' => 'talk']],
			$this->anything(),
			'buildiq',
			'built-app',
			'a-entity'
		)->willReturn($entity);

		$this->runStep(objectService: $objectService);

	}//end testReadsEntities()

	/**
	 * The step runs in both repair lists, before the import that applies the validations.
	 *
	 * @return void
	 */
	public function testRunsBeforeInitializeSettings(): void {
		// Parsed from a string: Nextcloud's bootstrap sets a null external entity loader, which refuses load_file().
		$info = simplexml_load_string((string) file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
		$this->assertNotFalse($info);
		foreach (['post-migration', 'install'] as $list) {
			$steps = array_map('strval', $info->xpath('/info/repair-steps/' . $list . '/step'));
			$repair = array_search('OCA\Buildiq\Repair\RepairValidationViolations', $steps, true);
			$import = array_search('OCA\Buildiq\Repair\InitializeSettings', $steps, true);
			$this->assertIsInt($repair, $list);
			$this->assertIsInt($import, $list);
			$this->assertLessThan($import, $repair, $list);
		}

	}//end testRunsBeforeInitializeSettings()

	/**
	 * Run the step and collect what it writes to the output.
	 *
	 * @param ObjectServiceInterface|null $objectService The object service, or the store-backed one.
	 * @param RepairValidationViolations|null $step The step to run, or a new one.
	 *
	 * @return array{info: array<int,string>, warning: array<int,string>}
	 */
	private function runStep(?ObjectServiceInterface $objectService = null, ?RepairValidationViolations $step = null): array {
		$written = ['info' => [], 'warning' => []];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $message) use (&$written): void {
			$written['info'][] = $message;
		});
		$output->method('warning')->willReturnCallback(static function (string $message) use (&$written): void {
			$written['warning'][] = $message;
		});

		$step ??= new RepairValidationViolations(
			logger: $this->createMock(LoggerInterface::class),
			objectService: ($objectService ?? $this->objectService)
		);
		$step->run($output);

		return $written;
	}//end runStep()

	/**
	 * The stored rows findAll() returns for a register/schema filter, with `@self` ids.
	 *
	 * @param array<string,mixed> $config The findAll() config.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function find(array $config): array {
		$this->assertSame('buildiq', $config['filters']['register']);
		$rows = [];
		foreach (($this->store[$config['filters']['schema']] ?? []) as $uuid => $row) {
			$rows[] = ['@self' => ['id' => (string)$uuid]] + $row;
		}

		return $rows;
	}//end find()
}//end class
