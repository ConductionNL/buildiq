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
 * @package  OCA\OpenBuild\Tests\Unit\Service
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

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\ApplicationDeletionService;
use OCA\OpenBuild\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
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
	private ObjectService&MockObject $objectService;

	/**
	 * @var RegisterService&MockObject
	 */
	private RegisterService&MockObject $registerService;

	/**
	 * @var RegisterMapper&MockObject
	 */
	private RegisterMapper&MockObject $registerMapper;

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
			objectService: $this->objectService,
			registerService: $this->registerService,
			registerMapper: $this->registerMapper,
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
}//end class
