<?php

/**
 * Unit tests for ApplicationTestsService.
 *
 * The Tests tab's data: the app and its version by slug, the record tests and
 * rule sets that belong to the app, the latest run per version, and record
 * tests written and removed only on the app they belong to.
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

use InvalidArgumentException;
use OCA\Buildiq\Service\ApplicationTestsService;
use OCA\Buildiq\Service\RuleObjectReader;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers the tab's reads and the record-test writes.
 *
 * @covers \OCA\Buildiq\Service\ApplicationTestsService
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class ApplicationTestsServiceTest extends TestCase {

	private const APP = ['id' => 'uuid-app', 'slug' => 'vergunningen'];

	/**
	 * Rows the reader answers, by schema.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Service under test.
	 *
	 * @var ApplicationTestsService
	 */
	private ApplicationTestsService $service;

	/**
	 * Set up the service over a reader answering $rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$reader = $this->createMock(RuleObjectReader::class);
		$reader->method('findAsSystem')->willReturnCallback(
			fn (string $schema, array $filters): array => $this->rows[$schema] ?? []
		);
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->service = new ApplicationTestsService(objects: $reader, objectService: $this->objectService);
	}//end setUp()

	/**
	 * The app and its version are found by slug; a version of another app is not.
	 *
	 * @return void
	 */
	public function testTheAppAndItsVersionAreFoundBySlug(): void {
		$this->rows['built-app'] = [['id' => 'uuid-other', 'slug' => 'other'], self::APP];
		$this->rows['applicationVersion'] = [
			['id' => 'v-foreign', 'slug' => 'development', 'application' => 'uuid-other'],
			['id' => 'v-dev', 'slug' => 'development', 'application' => 'uuid-app'],
		];

		self::assertSame(self::APP, $this->service->application(slug: 'vergunningen'));
		self::assertNull($this->service->application(slug: 'missing'));
		self::assertSame('v-dev', $this->service->version(application: self::APP, slug: 'development')['id']);
		self::assertNull($this->service->version(application: self::APP, slug: 'production'));
	}//end testTheAppAndItsVersionAreFoundBySlug()

	/**
	 * The overview holds only the app's own record tests and rule sets, and the
	 * latest run of each version (REQ-BQRT-005).
	 *
	 * @return void
	 */
	public function testTheOverviewHoldsTheAppsOwnTestsAndTheLatestRunPerVersion(): void {
		$this->rows['app-record-test'] = [
			['id' => 't-1', 'application' => 'uuid-app', 'name' => 'Permit needs an applicant'],
			['id' => 't-2', 'application' => 'uuid-other', 'name' => 'Not ours'],
		];
		$this->rows['rule-set'] = [
			['slug' => 'permit-fees', 'ownerApp' => 'vergunningen'],
			['slug' => 'other-fees', 'ownerApp' => 'other'],
		];
		$this->rows['app-test-run'] = [
			['id' => 'r-1', 'application' => 'uuid-app', 'version' => 'v-dev', 'ranAt' => '2026-11-13T09:00:00+00:00'],
			['id' => 'r-2', 'application' => 'uuid-app', 'version' => 'v-dev', 'ranAt' => '2026-11-14T08:00:00+00:00'],
			['id' => 'r-3', 'application' => 'uuid-app', 'version' => 'v-prod', 'ranAt' => '2026-11-01T08:00:00+00:00'],
			['id' => 'r-4', 'application' => 'uuid-other', 'version' => 'v-x', 'ranAt' => '2026-11-14T10:00:00+00:00'],
		];

		$overview = $this->service->overview(application: self::APP);

		self::assertSame(['t-1'], array_column($overview['recordTests'], 'id'));
		self::assertSame(['permit-fees'], array_column($overview['ruleSets'], 'slug'));
		self::assertSame(['v-dev', 'v-prod'], array_keys($overview['latestRuns']));
		self::assertSame('r-2', $overview['latestRuns']['v-dev']['id']);
		self::assertSame('r-3', $overview['latestRuns']['v-prod']['id']);
	}//end testTheOverviewHoldsTheAppsOwnTestsAndTheLatestRunPerVersion()

	/**
	 * A new record test is stored on the app as the system, its fields cleaned.
	 *
	 * @return void
	 */
	public function testARecordTestIsStoredOnTheApp(): void {
		$stored = null;
		$this->objectService->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $rbac = true) use (&$stored): ObjectEntity {
				$stored = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $rbac];
				$entity = $this->createMock(ObjectEntity::class);
				$entity->method('jsonSerialize')->willReturn(['id' => 't-new'] + $object);
				return $entity;
			}
		);

		$saved = $this->service->saveRecordTest(
			application: self::APP,
			input: [
				'name' => ' Permit needs an applicant ',
				'schema' => 'permit',
				'record' => ['title' => 'Shed'],
				'expect' => 'refuse',
				'expectedFields' => ['applicant', ' ', ''],
			]
		);

		self::assertSame('t-new', $saved['id']);
		self::assertSame('buildiq', $stored['register']);
		self::assertSame('app-record-test', $stored['schema']);
		self::assertNull($stored['uuid']);
		self::assertFalse($stored['rbac']);
		self::assertSame('uuid-app', $stored['object']['application']);
		self::assertSame('Permit needs an applicant', $stored['object']['name']);
		self::assertEquals((object)['title' => 'Shed'], $stored['object']['record']);
		self::assertSame(['applicant'], $stored['object']['expectedFields']);
	}//end testARecordTestIsStoredOnTheApp()

	/**
	 * A test expected to be accepted carries no refusal fields.
	 *
	 * @return void
	 */
	public function testAnAcceptTestCarriesNoRefusalFields(): void {
		$stored = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$stored): ObjectEntity {
				$stored = $object;
				return $this->createMock(ObjectEntity::class);
			}
		);

		$this->service->saveRecordTest(
			application: self::APP,
			input: ['name' => 'A full permit', 'schema' => 'permit', 'record' => [], 'expect' => 'accept', 'expectedFields' => ['applicant']]
		);

		self::assertSame([], $stored['expectedFields']);
		self::assertEquals((object)[], $stored['record']);
	}//end testAnAcceptTestCarriesNoRefusalFields()

	/**
	 * Invalid input is refused with the reason, and nothing is stored.
	 *
	 * @return void
	 */
	public function testInvalidRecordTestsAreRefused(): void {
		$this->objectService->expects(self::never())->method('saveObject');
		$valid = ['name' => 'N', 'schema' => 'permit', 'record' => ['a' => 1], 'expect' => 'accept'];

		foreach ([
			'A record test needs a name and a schema.' => ['name' => ''] + $valid,
			'A record test expects "accept" or "refuse".' => ['expect' => 'maybe'] + $valid,
			'The sample record must be an object.' => ['record' => ['a', 'b']] + $valid,
			'The fields a record is refused on must be a list.' => ['expectedFields' => 'applicant'] + $valid,
		] as $message => $input) {
			try {
				$this->service->saveRecordTest(application: self::APP, input: $input);
				self::fail('Expected a refusal: ' . $message);
			} catch (InvalidArgumentException $e) {
				self::assertSame($message, $e->getMessage());
			}
		}
	}//end testInvalidRecordTestsAreRefused()

	/**
	 * A record test of another app is neither changed nor removed.
	 *
	 * @return void
	 */
	public function testARecordTestOfAnotherAppIsNotTouched(): void {
		$this->rows['app-record-test'] = [['id' => 't-foreign', 'application' => 'uuid-other']];
		$this->objectService->expects(self::never())->method('saveObject');
		$this->objectService->expects(self::never())->method('deleteObject');

		$input = ['name' => 'N', 'schema' => 'permit', 'record' => ['a' => 1], 'expect' => 'accept'];
		self::assertNull($this->service->saveRecordTest(application: self::APP, input: $input, id: 't-foreign'));
		self::assertFalse($this->service->deleteRecordTest(application: self::APP, id: 't-foreign'));
	}//end testARecordTestOfAnotherAppIsNotTouched()

	/**
	 * The app's own record test is changed under its id, and removed.
	 *
	 * @return void
	 */
	public function testTheAppsOwnRecordTestIsChangedAndRemoved(): void {
		$this->rows['app-record-test'] = [['id' => 't-1', 'application' => 'uuid-app']];
		$this->objectService->expects(self::once())->method('saveObject')
			->with(self::anything(), self::anything(), 'buildiq', 'app-record-test', 't-1')
			->willReturn($this->createMock(ObjectEntity::class));
		$this->objectService->expects(self::once())->method('deleteObject')
			->with('t-1', 'buildiq', 'app-record-test', false, false)
			->willReturn(true);

		$input = ['name' => 'N', 'schema' => 'permit', 'record' => ['a' => 1], 'expect' => 'accept'];
		self::assertIsArray($this->service->saveRecordTest(application: self::APP, input: $input, id: 't-1'));
		self::assertTrue($this->service->deleteRecordTest(application: self::APP, id: 't-1'));
	}//end testTheAppsOwnRecordTestIsChangedAndRemoved()
}//end class
