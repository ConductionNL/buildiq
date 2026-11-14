<?php

/**
 * Unit tests for ReleaseTestGate.
 *
 * The gate in front of a release and a promotion onto production: a green
 * suite passes, a failing one stops with the stored run, an owner may release
 * anyway with a reason, an editor may not, and every run is kept.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use DateTime;
use OCA\Buildiq\Exception\ReleaseOverrideRefusedException;
use OCA\Buildiq\Exception\ReleaseTestsFailedException;
use OCA\Buildiq\Service\AppTestSuiteService;
use OCA\Buildiq\Service\ReleaseTestGate;
use OCA\Buildiq\Support\ReleaseOverride;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the gate's outcomes and the run it stores.
 *
 * @covers \OCA\Buildiq\Service\ReleaseTestGate
 * @uses   \OCA\Buildiq\Exception\ReleaseOverrideRefusedException
 * @uses   \OCA\Buildiq\Exception\ReleaseTestsFailedException
 * @uses   \OCA\Buildiq\Support\ReleaseOverride
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class ReleaseTestGateTest extends TestCase {

	private const APPLICATION = ['id' => 'uuid-app', 'slug' => 'vergunningen'];
	private const VERSION = ['id' => 'uuid-dev', 'manifest' => ['pages' => []]];

	private const FAILED_CHECK = [
		'kind' => AppTestSuiteService::KIND_STRUCTURE,
		'name' => 'permits: column kvkNumber',
		'outcome' => AppTestSuiteService::FAILED,
		'message' => 'Schema permit has no property kvkNumber.',
	];

	private const PASSED_CHECK = [
		'kind' => AppTestSuiteService::KIND_STRUCTURE,
		'name' => 'permits: schema permit',
		'outcome' => AppTestSuiteService::PASSED,
		'message' => '',
	];

	/**
	 * @var AppTestSuiteService&MockObject
	 */
	private AppTestSuiteService&MockObject $suite;

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * The runs handed to the store.
	 *
	 * @var list<array<string, mixed>>
	 */
	private array $stored = [];

	private ReleaseTestGate $gate;

	/**
	 * Set up the gate over a suite double and a store that answers an id.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->suite = $this->createMock(AppTestSuiteService::class);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null): ObjectEntity {
				$this->stored[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('getUuid')->willReturn('run-' . count($this->stored));
				return $entity;
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-14T09:00:00+00:00'));

		$this->gate = new ReleaseTestGate(
			suite: $this->suite,
			objectService: $this->objectService,
			time: $time,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A green suite lets the release through and is kept as a release run.
	 *
	 * @return void
	 */
	public function testAGreenSuitePassesAndIsKept(): void {
		$this->suiteAnswers(checks: [self::PASSED_CHECK]);

		$run = $this->gate->guard(application: self::APPLICATION, version: self::VERSION, trigger: ReleaseTestGate::TRIGGER_RELEASE);

		self::assertSame('run-1', $run['id']);
		self::assertCount(1, $this->stored);
		self::assertSame('buildiq', $this->stored[0]['register']);
		self::assertSame('app-test-run', $this->stored[0]['schema']);
		self::assertSame(
			[
				'application' => 'uuid-app',
				'version' => 'uuid-dev',
				'ranAt' => '2026-11-14T09:00:00+00:00',
				'trigger' => 'release',
				'passed' => 1,
				'failed' => 0,
				'skipped' => 0,
				'report' => [self::PASSED_CHECK],
			],
			$this->stored[0]['object']
		);
	}//end testAGreenSuitePassesAndIsKept()

	/**
	 * A failing suite stops with the stored run, which carries the failed check (REQ-BQRT-004).
	 *
	 * @return void
	 */
	public function testAFailingSuiteStopsWithTheReport(): void {
		$this->suiteAnswers(checks: [self::PASSED_CHECK, self::FAILED_CHECK]);

		try {
			$this->gate->guard(application: self::APPLICATION, version: self::VERSION, trigger: ReleaseTestGate::TRIGGER_PROMOTION);
			self::fail('A failing suite must stop the release.');
		} catch (ReleaseTestsFailedException $e) {
			self::assertSame('release_tests_failed', $e->getErrorCode());
			self::assertSame(1, $e->getRun()['failed']);
			self::assertSame([self::PASSED_CHECK, self::FAILED_CHECK], $e->getRun()['report']);
			self::assertSame('run-1', $e->getRun()['id']);
		}

		self::assertCount(1, $this->stored);
		self::assertSame('promotion', $this->stored[0]['object']['trigger']);
		self::assertArrayNotHasKey('override', $this->stored[0]['object']);
	}//end testAFailingSuiteStopsWithTheReport()

	/**
	 * An owner releases anyway; the run records the failure, the owner and the reason.
	 *
	 * @return void
	 */
	public function testAnOwnerReleasesAnywayAndTheRunRecordsWhoAndWhy(): void {
		$this->suiteAnswers(checks: [self::FAILED_CHECK]);
		$reason = 'Column removed on purpose, page fixed in next release';

		$run = $this->gate->guard(
			application: self::APPLICATION,
			version: self::VERSION,
			trigger: ReleaseTestGate::TRIGGER_RELEASE,
			override: new ReleaseOverride(reason: $reason, userId: 'olivia', byOwner: true)
		);

		self::assertSame('run-1', $run['id']);
		self::assertSame(1, $this->stored[0]['object']['failed']);
		self::assertSame(['user' => 'olivia', 'reason' => $reason], $this->stored[0]['object']['override']);
	}//end testAnOwnerReleasesAnywayAndTheRunRecordsWhoAndWhy()

	/**
	 * An editor cannot release past a failing suite, and nothing is stored as an override.
	 *
	 * @return void
	 */
	public function testAnEditorOverrideIsRefused(): void {
		$this->suiteAnswers(checks: [self::FAILED_CHECK]);

		try {
			$this->gate->guard(
				application: self::APPLICATION,
				version: self::VERSION,
				trigger: ReleaseTestGate::TRIGGER_RELEASE,
				override: new ReleaseOverride(reason: 'Looks fine to me', userId: 'eddie', byOwner: false)
			);
			self::fail('An editor must not override.');
		} catch (ReleaseOverrideRefusedException $e) {
			self::assertSame(ReleaseOverrideRefusedException::NOT_OWNER, $e->getErrorCode());
			self::assertSame(403, $e->getStatus());
		}

		self::assertSame([], $this->stored);
	}//end testAnEditorOverrideIsRefused()

	/**
	 * An owner's override without a reason is refused as a bad request.
	 *
	 * @return void
	 */
	public function testAnOverrideWithoutAReasonIsRefused(): void {
		$this->suiteAnswers(checks: [self::FAILED_CHECK]);

		try {
			$this->gate->guard(
				application: self::APPLICATION,
				version: self::VERSION,
				trigger: ReleaseTestGate::TRIGGER_RELEASE,
				override: new ReleaseOverride(reason: '  ', userId: 'olivia', byOwner: true)
			);
			self::fail('An override needs a reason.');
		} catch (ReleaseOverrideRefusedException $e) {
			self::assertSame(ReleaseOverrideRefusedException::REASON_REQUIRED, $e->getErrorCode());
			self::assertSame(400, $e->getStatus());
		}
	}//end testAnOverrideWithoutAReasonIsRefused()

	/**
	 * An override that cannot be recorded does not let the release through.
	 *
	 * @return void
	 */
	public function testAnOverrideThatCannotBeRecordedStopsTheRelease(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willThrowException(new RuntimeException('store down'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-14T09:00:00+00:00'));
		$gate = new ReleaseTestGate(suite: $this->suite, objectService: $objectService, time: $time, logger: $this->createMock(LoggerInterface::class));
		$this->suiteAnswers(checks: [self::FAILED_CHECK]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The override could not be recorded, so the version was not released.');
		$gate->guard(
			application: self::APPLICATION,
			version: self::VERSION,
			trigger: ReleaseTestGate::TRIGGER_RELEASE,
			override: new ReleaseOverride(reason: 'Hotfix', userId: 'olivia', byOwner: true)
		);
	}//end testAnOverrideThatCannotBeRecordedStopsTheRelease()

	/**
	 * A green run whose store fails still lets the release through, without an id.
	 *
	 * @return void
	 */
	public function testAGreenRunThatCannotBeStoredStillPasses(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willThrowException(new RuntimeException('store down'));
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-11-14T09:00:00+00:00'));
		$gate = new ReleaseTestGate(suite: $this->suite, objectService: $objectService, time: $time, logger: $this->createMock(LoggerInterface::class));
		$this->suiteAnswers(checks: [self::PASSED_CHECK]);

		$run = $gate->guard(application: self::APPLICATION, version: self::VERSION, trigger: ReleaseTestGate::TRIGGER_RELEASE);

		self::assertSame('', $run['id']);
		self::assertSame(1, $run['passed']);
	}//end testAGreenRunThatCannotBeStoredStillPasses()

	/**
	 * "Run tests" keeps a manual run, failures and all, and never throws on them (REQ-BQRT-005).
	 *
	 * @return void
	 */
	public function testAManualRunIsKeptWithItsFailures(): void {
		$this->suiteAnswers(checks: [self::FAILED_CHECK]);

		$run = $this->gate->runManually(application: self::APPLICATION, version: self::VERSION);

		self::assertSame('manual', $run['trigger']);
		self::assertSame(1, $run['failed']);
		self::assertSame('run-1', $run['id']);
		self::assertSame('manual', $this->stored[0]['object']['trigger']);
	}//end testAManualRunIsKeptWithItsFailures()

	/**
	 * The request's `override` parameter: an object with a reason, a bare
	 * reason, a "yes" without one, or nothing.
	 *
	 * @return void
	 */
	public function testTheOverrideIsReadFromTheRequest(): void {
		$fromObject = ReleaseOverride::fromRequest(param: ['reason' => ' Hotfix '], userId: 'olivia', byOwner: true);
		self::assertNotNull($fromObject);
		self::assertSame('Hotfix', $fromObject->reason);
		self::assertSame('olivia', $fromObject->userId);
		self::assertTrue($fromObject->byOwner);

		self::assertSame('Hotfix', ReleaseOverride::fromRequest(param: 'Hotfix', userId: 'u', byOwner: false)?->reason);
		self::assertSame('', ReleaseOverride::fromRequest(param: true, userId: 'u', byOwner: true)?->reason);
		self::assertNull(ReleaseOverride::fromRequest(param: null, userId: 'u', byOwner: true));
		self::assertNull(ReleaseOverride::fromRequest(param: '', userId: 'u', byOwner: true));
		self::assertNull(ReleaseOverride::fromRequest(param: false, userId: 'u', byOwner: true));
	}//end testTheOverrideIsReadFromTheRequest()

	/**
	 * Make the suite double answer these checks, counted.
	 *
	 * @param list<array<string, string>> $checks The checks.
	 *
	 * @return void
	 */
	private function suiteAnswers(array $checks): void {
		$counts = array_count_values(array_column($checks, 'outcome'));
		$this->suite->method('run')->with(self::APPLICATION, self::VERSION)->willReturn(
			[
				'passed' => (int)($counts['passed'] ?? 0),
				'failed' => (int)($counts['failed'] ?? 0),
				'skipped' => (int)($counts['skipped'] ?? 0),
				'checks' => $checks,
			]
		);
	}//end suiteAnswers()
}//end class
