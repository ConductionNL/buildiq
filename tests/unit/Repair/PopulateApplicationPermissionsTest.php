<?php

/**
 * Unit tests for PopulateApplicationPermissions repair step.
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
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PopulateApplicationPermissions::run.
 */
class PopulateApplicationPermissionsTest extends TestCase {
	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Mock OR ObjectService.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock IOutput.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		// `ObjectService::runAsSystem()` really invokes the given callable and
		// returns its result — mirrors the real elevation, which is
		// transparent to the caller.
		$this->objectService->method('runAsSystem')->willReturnCallback(
			static fn (callable $operation) => $operation()
		);
	}//end setUp()

	/**
	 * Test that getName returns a non-empty descriptive name.
	 *
	 * @return void
	 */
	public function testGetNameReturnsDescriptiveName(): void {
		$step = new PopulateApplicationPermissions(
			logger: $this->logger,
			objectService: $this->objectService
		);

		self::assertNotEmpty($step->getName());
		self::assertStringContainsString('permissions', $step->getName());
	}//end testGetNameReturnsDescriptiveName()

	/**
	 * Applications without `permissions` get patched; those with non-empty
	 * `permissions.owners` are skipped (idempotent).
	 *
	 * @return void
	 */
	public function testRunPatchesOnlyApplicationsMissingPermissions(): void {
		$missing = [
			'@self' => ['id' => 'uuid-missing'],
			'slug' => 'legacy',
			'manifest' => ['version' => '1.0.0'],
		];
		$populated = [
			'@self' => ['id' => 'uuid-populated'],
			'slug' => 'modern',
			'manifest' => ['version' => '1.0.0'],
			'permissions' => [
				'owners' => ['team-alpha'],
				'editors' => [],
				'viewers' => [],
			],
		];

		$this->objectService->expects(self::once())
			->method('findAll')
			->willReturn([$missing, $populated]);

		$this->objectService->expects(self::once())
			->method('saveObject')
			->with(
				self::callback(static function (array $object): bool {
					return ($object['@self']['id'] ?? null) === 'uuid-missing'
						&& ($object['permissions']['owners'] ?? null) === ['admin']
						&& ($object['permissions']['editors'] ?? null) === []
						&& ($object['permissions']['viewers'] ?? null) === [];
				})
			);

		$step = new PopulateApplicationPermissions(
			logger: $this->logger,
			objectService: $this->objectService
		);
		$step->run($this->output);
	}//end testRunPatchesOnlyApplicationsMissingPermissions()

	/**
	 * Re-running the migration on a fully populated install is a no-op.
	 *
	 * @return void
	 */
	public function testRunIsIdempotentWhenAllPopulated(): void {
		$populated = [
			'@self' => ['id' => 'uuid-populated'],
			'slug' => 'modern',
			'permissions' => [
				'owners' => ['team-alpha'],
				'editors' => [],
				'viewers' => [],
			],
		];

		$this->objectService->expects(self::once())
			->method('findAll')
			->willReturn([$populated]);
		$this->objectService->expects(self::never())->method('saveObject');

		$step = new PopulateApplicationPermissions(
			logger: $this->logger,
			objectService: $this->objectService
		);
		$step->run($this->output);
	}//end testRunIsIdempotentWhenAllPopulated()

	/**
	 * Applications with `permissions.owners = []` are treated as needing
	 * migration (they'd be unreachable otherwise — REQ-OBRBAC-005).
	 *
	 * @return void
	 */
	public function testRunPatchesWhenOwnersArrayIsEmpty(): void {
		$orphan = [
			'@self' => ['id' => 'uuid-orphan'],
			'slug' => 'orphan',
			'permissions' => [
				'owners' => [],
				'editors' => ['team-alpha'],
				'viewers' => [],
			],
		];

		$this->objectService->expects(self::once())
			->method('findAll')
			->willReturn([$orphan]);
		$this->objectService->expects(self::once())->method('saveObject');

		$step = new PopulateApplicationPermissions(
			logger: $this->logger,
			objectService: $this->objectService
		);
		$step->run($this->output);
	}//end testRunPatchesWhenOwnersArrayIsEmpty()

	/**
	 * Empty Application list — no-op without exception.
	 *
	 * @return void
	 */
	public function testRunSucceedsOnEmptyApplicationList(): void {
		$this->objectService->expects(self::once())
			->method('findAll')
			->willReturn([]);
		$this->objectService->expects(self::never())->method('saveObject');

		$step = new PopulateApplicationPermissions(
			logger: $this->logger,
			objectService: $this->objectService
		);
		$step->run($this->output);
	}//end testRunSucceedsOnEmptyApplicationList()

	/**
	 * The find-and-patch body runs elevated: the repair step has no user
	 * session (Anonymous), and without `runAsSystem()` OpenRegister RBAC
	 * denies the `find`/`update` calls outright ("User 'Anonymous' does not
	 * have permission to ... objects in schema 'Application'").
	 *
	 * @return void
	 */
	public function testRunWrapsFindAndPatchInSystemContext(): void {
		// Fresh mock, scoped to this test: a single matcher owns both the
		// "called exactly once" assertion and the invoke-the-callable
		// behaviour, so there is no ambiguity with setUp()'s default stub.
		$this->objectService = $this->createMock(ObjectServiceInterface::class);

		$missing = [
			'@self' => ['id' => 'uuid-missing'],
			'slug' => 'legacy',
		];
		$this->objectService->method('findAll')->willReturn([$missing]);
		$this->objectService->expects(self::once())->method('saveObject');

		$this->objectService->expects(self::once())
			->method('runAsSystem')
			->willReturnCallback(static fn (callable $operation) => $operation());

		$step = new PopulateApplicationPermissions(
			logger: $this->logger,
			objectService: $this->objectService
		);
		$step->run($this->output);
	}//end testRunWrapsFindAndPatchInSystemContext()
	/**
	 * A row refused on save is reported, and the rows after it are still patched.
	 *
	 * @return void
	 */
	public function testARefusedRowDoesNotStopTheRest(): void {
		$rows = [];
		foreach (['uuid-a', 'uuid-b', 'uuid-c'] as $uuid) {
			$rows[] = ['@self' => ['id' => $uuid], 'slug' => $uuid];
		}

		$this->objectService->method('findAll')->willReturn($rows);
		$saved = [];
		$entity = $this->createMock(\OCA\OpenRegister\Contract\ObjectEntityInterface::class);
		$this->objectService->expects(self::exactly(6))->method('saveObject')->willReturnCallback(
			static function (array $object) use (&$saved, $entity) {
				if ($object['@self']['id'] === 'uuid-b') {
					throw new \RuntimeException('refused by a validation');
				}

				$saved[] = $object['@self']['id'];
				return $entity;
			}
		);

		$info = [];
		$warnings = [];
		$this->output->method('info')->willReturnCallback(static function (string $message) use (&$info): void {
			$info[] = $message;
		});
		$this->output->method('warning')->willReturnCallback(static function (string $message) use (&$warnings): void {
			$warnings[] = $message;
		});

		$step = new PopulateApplicationPermissions(logger: $this->logger, objectService: $this->objectService);
		$step->run($this->output);
		$step->run($this->output);

		self::assertSame(['uuid-a', 'uuid-c', 'uuid-a', 'uuid-c'], $saved);
		self::assertContains('Permissions populated on 2 Application(s).', $info);
		self::assertSame(
			array_fill(0, 2, '1 Application(s) were refused on save and still have no permissions: uuid-b'),
			$warnings,
			'each run reports only its own refusals'
		);
	}//end testARefusedRowDoesNotStopTheRest()

}//end class
