<?php

/**
 * Unit tests for the per-user delta paths of AppOverrideService.
 *
 * Covers getUserDelta, upsertUserDelta, deleteUserDelta and listUserDeltas:
 * the owner scoping (a caller only ever sees or writes their own row), the
 * allowUserOverrides gate, and the failure paths that must degrade to "no
 * delta" rather than leak or throw.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
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

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppOverrideService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the user-scoped delta methods of AppOverrideService.
 */
class AppOverrideServiceUserDeltaTest extends TestCase {

	/**
	 * Mock OR object service.
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
	 * Service under test.
	 *
	 * @var AppOverrideService
	 */
	private AppOverrideService $service;

	/**
	 * Wire mocks and the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->service = new AppOverrideService(
			objectService: $this->objectService,
			logger: $this->logger,
			appManager: $this->createMock(originalClassName: IAppManager::class)
		);
	}//end setUp()

	/**
	 * Build an entity mock that serialises to the given data.
	 *
	 * @param array<string, mixed> $data The serialised payload.
	 *
	 * @return ObjectEntity&MockObject
	 */
	private function entity(array $data): ObjectEntity&MockObject {
		$entity = $this->createMock(originalClassName: ObjectEntity::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end entity()

	/**
	 * Route searchObjectsBySlug: the hybrid app lookup and the user-row lookup.
	 *
	 * @param array<string, mixed>|null $application The hybrid Application, or null for none.
	 * @param array<int, array<string, mixed>> $userRows The user-scoped rows the search returns.
	 *
	 * @return void
	 */
	private function arrangeSearch(?array $application, array $userRows = []): void {
		$this->objectService->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $registerSlug, string $schemaSlug, array $filters) use ($application, $userRows): array {
				if (($filters['appType'] ?? null) === 'hybrid') {
					if ($application === null) {
						return [];
					}

					return [$application];
				}

				if (($filters['scope'] ?? null) === 'user') {
					return $userRows;
				}

				return [];
			}
		);
	}//end arrangeSearch()

	/**
	 * A hybrid Application payload.
	 *
	 * @param bool $allowUserOverrides The per-app flag.
	 *
	 * @return array<string, mixed>
	 */
	private function application(bool $allowUserOverrides): array {
		return [
			'id' => 'app-A',
			'slug' => 'demo',
			'appType' => 'hybrid',
			'allowUserOverrides' => $allowUserOverrides,
			'productionVersion' => 'admin-uuid',
		];
	}//end application()

	/**
	 * Without a hybrid app there is nothing to read and nothing is allowed.
	 *
	 * @return void
	 */
	public function testGetUserDeltaWithoutApplication(): void {
		$this->arrangeSearch(application: null);

		$result = $this->service->getUserDelta(appId: 'demo', uid: 'alice');

		self::assertSame(
			expected: ['allowed' => false, 'exists' => false, 'manifestDelta' => [], 'versionUuid' => null],
			actual: $result
		);
	}//end testGetUserDeltaWithoutApplication()

	/**
	 * The caller's own row is returned with its uuid; another owner's row is ignored.
	 *
	 * @return void
	 */
	public function testGetUserDeltaReturnsOnlyTheCallersRow(): void {
		$this->arrangeSearch(
			application: $this->application(allowUserOverrides: true),
			userRows: [
				['id' => 'bob-row', 'owner' => 'bob', 'application' => 'app-A', 'manifestDelta' => ['x' => 1]],
				['id' => 'other-app', 'owner' => 'alice', 'application' => 'app-B', 'manifestDelta' => ['y' => 2]],
				['id' => 'alice-row', 'owner' => 'alice', 'application' => 'app-A', 'manifestDelta' => ['z' => 3]],
			]
		);

		$result = $this->service->getUserDelta(appId: 'demo', uid: 'alice');

		self::assertTrue(condition: $result['allowed']);
		self::assertTrue(condition: $result['exists']);
		self::assertSame(expected: ['z' => 3], actual: $result['manifestDelta']);
		self::assertSame(expected: 'alice-row', actual: $result['versionUuid']);
	}//end testGetUserDeltaReturnsOnlyTheCallersRow()

	/**
	 * A malformed stored delta reads as an empty delta, not as the raw value.
	 *
	 * @return void
	 */
	public function testGetUserDeltaNormalisesANonArrayDelta(): void {
		$this->arrangeSearch(
			application: $this->application(allowUserOverrides: false),
			userRows: [['id' => 'alice-row', 'owner' => 'alice', 'application' => 'app-A', 'manifestDelta' => 'broken']]
		);

		$result = $this->service->getUserDelta(appId: 'demo', uid: 'alice');

		self::assertFalse(condition: $result['allowed']);
		self::assertTrue(condition: $result['exists']);
		self::assertSame(expected: [], actual: $result['manifestDelta']);
	}//end testGetUserDeltaNormalisesANonArrayDelta()

	/**
	 * An empty uid never searches user rows.
	 *
	 * @return void
	 */
	public function testGetUserDeltaWithEmptyUidDoesNotLookUpRows(): void {
		$this->arrangeSearch(
			application: $this->application(allowUserOverrides: true),
			userRows: [['id' => 'row', 'owner' => '', 'application' => 'app-A', 'manifestDelta' => ['a' => 1]]]
		);

		$result = $this->service->getUserDelta(appId: 'demo', uid: '');

		self::assertTrue(condition: $result['allowed']);
		self::assertFalse(condition: $result['exists']);
		self::assertNull(actual: $result['versionUuid']);
	}//end testGetUserDeltaWithEmptyUidDoesNotLookUpRows()

	/**
	 * A failing user-row search is logged and reads as "no delta".
	 *
	 * @return void
	 */
	public function testGetUserDeltaSurvivesAFailingSearch(): void {
		$application = $this->application(allowUserOverrides: true);
		$this->objectService->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $registerSlug, string $schemaSlug, array $filters) use ($application): array {
				if (($filters['appType'] ?? null) === 'hybrid') {
					return [$application];
				}

				throw new RuntimeException('index down');
			}
		);
		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains(string: 'user delta lookup failed for alice'));

		$result = $this->service->getUserDelta(appId: 'demo', uid: 'alice');

		self::assertFalse(condition: $result['exists']);
	}//end testGetUserDeltaSurvivesAFailingSearch()

	/**
	 * Writing a user delta requires an authenticated owner.
	 *
	 * @return void
	 */
	public function testUpsertUserDeltaRefusesAnEmptyUid(): void {
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'authenticated owner');

		$this->service->upsertUserDelta(appId: 'demo', delta: ['a' => 1], uid: '');
	}//end testUpsertUserDeltaRefusesAnEmptyUid()

	/**
	 * Writing a user delta for an unknown app is refused.
	 *
	 * @return void
	 */
	public function testUpsertUserDeltaRefusesAnUnknownApp(): void {
		$this->arrangeSearch(application: null);
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'No hybrid app exists for demo.');

		$this->service->upsertUserDelta(appId: 'demo', delta: ['a' => 1], uid: 'alice');
	}//end testUpsertUserDeltaRefusesAnUnknownApp()

	/**
	 * Writing a user delta is refused when the app does not allow user overrides.
	 *
	 * @return void
	 */
	public function testUpsertUserDeltaRefusesWhenTheAppDisallowsIt(): void {
		$this->arrangeSearch(application: $this->application(allowUserOverrides: false));
		$this->objectService->expects(self::never())->method('saveObject');

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'does not allow per-user overrides');

		$this->service->upsertUserDelta(appId: 'demo', delta: ['a' => 1], uid: 'alice');
	}//end testUpsertUserDeltaRefusesWhenTheAppDisallowsIt()

	/**
	 * A first write creates a user-scoped row owned by the caller, linked to the admin version.
	 *
	 * @return void
	 */
	public function testUpsertUserDeltaCreatesAnOwnedRow(): void {
		$this->arrangeSearch(application: $this->application(allowUserOverrides: true));
		$this->objectService->method('find')->willReturn($this->entity(data: ['id' => 'admin-uuid']));

		$captured = null;
		$this->objectService->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$captured) {
				// PHPUnit passes the arguments positionally: extend, register, schema, uuid, _rbac.
				$captured = ['object' => $object, 'uuid' => $rest[3], '_rbac' => $rest[4]];
				return $this->entity(data: ['id' => 'new-row']);
			}
		);

		$result = $this->service->upsertUserDelta(appId: 'demo', delta: ['pages' => []], uid: 'Alice.Smith@example');

		self::assertSame(expected: 'demo', actual: $result['appId']);
		self::assertSame(expected: 'Alice.Smith@example', actual: $result['owner']);
		self::assertSame(expected: 'new-row', actual: $result['versionUuid']);
		self::assertNotEmpty(actual: $result['updatedAt']);

		self::assertSame(expected: 'user', actual: $captured['object']['scope']);
		self::assertSame(expected: 'Alice.Smith@example', actual: $captured['object']['owner']);
		self::assertSame(expected: 'user-alice-smith-example', actual: $captured['object']['slug']);
		self::assertSame(expected: 'app-A', actual: $captured['object']['application']);
		self::assertSame(expected: ['kind' => 'application-version', 'id' => 'admin-uuid'], actual: $captured['object']['baseRef']);
		self::assertSame(expected: ['pages' => []], actual: $captured['object']['manifestDelta']);
		self::assertFalse(condition: $captured['_rbac']);
	}//end testUpsertUserDeltaCreatesAnOwnedRow()

	/**
	 * A uid with no slug-safe characters still gets a usable slug.
	 *
	 * @return void
	 */
	public function testUpsertUserDeltaSlugFallsBackForAnUnsafeUid(): void {
		$this->arrangeSearch(application: $this->application(allowUserOverrides: true));
		$this->objectService->method('find')->willReturn(null);

		$captured = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured) {
				$captured = $object;
				return $this->entity(data: ['id' => 'new-row']);
			}
		);

		$this->service->upsertUserDelta(appId: 'demo', delta: [], uid: '@@@');

		self::assertSame(expected: 'user-me', actual: $captured['slug']);
		self::assertSame(expected: ['kind' => 'application-version', 'id' => ''], actual: $captured['baseRef']);
	}//end testUpsertUserDeltaSlugFallsBackForAnUnsafeUid()

	/**
	 * A second write updates the caller's existing row in place.
	 *
	 * @return void
	 */
	public function testUpsertUserDeltaUpdatesTheExistingRow(): void {
		$this->arrangeSearch(
			application: $this->application(allowUserOverrides: true),
			userRows: [
				[
					'id' => 'alice-row',
					'owner' => 'alice',
					'application' => 'app-A',
					'manifestDelta' => ['old' => true],
					'@self' => ['id' => 'alice-row'],
				],
			]
		);
		$this->objectService->method('find')->willReturn($this->entity(data: ['id' => 'admin-uuid']));

		$captured = null;
		$this->objectService->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object, ...$rest) use (&$captured) {
				// PHPUnit passes the arguments positionally: extend, register, schema, uuid, _rbac.
				$captured = ['object' => $object, 'uuid' => $rest[3], '_rbac' => $rest[4]];
				return $this->entity(data: ['id' => 'alice-row']);
			}
		);

		$result = $this->service->upsertUserDelta(appId: 'demo', delta: ['new' => true], uid: 'alice');

		self::assertSame(expected: 'alice-row', actual: $result['versionUuid']);
		self::assertSame(expected: ['new' => true], actual: $captured['object']['manifestDelta']);
		self::assertSame(expected: 'alice', actual: $captured['object']['owner']);
		self::assertArrayNotHasKey(key: '@self', array: $captured['object']);
		self::assertSame(expected: 'alice-row', actual: $captured['uuid']);
	}//end testUpsertUserDeltaUpdatesTheExistingRow()

	/**
	 * Deleting removes only the caller's own row.
	 *
	 * @return void
	 */
	public function testDeleteUserDeltaRemovesTheCallersRow(): void {
		$this->arrangeSearch(
			application: $this->application(allowUserOverrides: true),
			userRows: [['id' => 'alice-row', 'owner' => 'alice', 'application' => 'app-A']]
		);
		$this->objectService->expects(self::once())->method('deleteObject')
			->with(self::callback(callback: static fn ($uuid): bool => $uuid === 'alice-row'))
			->willReturn(true);

		$this->service->deleteUserDelta(appId: 'demo', uid: 'alice');
	}//end testDeleteUserDeltaRemovesTheCallersRow()

	/**
	 * Deleting is a no-op for an empty uid, an unknown app, or a caller without a row.
	 *
	 * @return void
	 */
	public function testDeleteUserDeltaIsANoOpWithoutARow(): void {
		$this->arrangeSearch(
			application: $this->application(allowUserOverrides: true),
			userRows: [['id' => 'bob-row', 'owner' => 'bob', 'application' => 'app-A']]
		);
		$this->objectService->expects(self::never())->method('deleteObject');

		$this->service->deleteUserDelta(appId: 'demo', uid: '');
		$this->service->deleteUserDelta(appId: 'demo', uid: 'alice');
	}//end testDeleteUserDeltaIsANoOpWithoutARow()

	/**
	 * Deleting for an app that does not exist touches nothing.
	 *
	 * @return void
	 */
	public function testDeleteUserDeltaWithoutApplication(): void {
		$this->arrangeSearch(application: null);
		$this->objectService->expects(self::never())->method('deleteObject');

		$this->service->deleteUserDelta(appId: 'demo', uid: 'alice');
	}//end testDeleteUserDeltaWithoutApplication()

	/**
	 * A failing delete is logged, not thrown (the operation is idempotent).
	 *
	 * @return void
	 */
	public function testDeleteUserDeltaLogsAFailingDelete(): void {
		$this->arrangeSearch(
			application: $this->application(allowUserOverrides: true),
			userRows: [['id' => 'alice-row', 'owner' => 'alice', 'application' => 'app-A']]
		);
		$this->objectService->method('deleteObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains(string: 'failed to delete user delta for demo / alice'));

		$this->service->deleteUserDelta(appId: 'demo', uid: 'alice');
	}//end testDeleteUserDeltaLogsAFailingDelete()

	/**
	 * The maintainer list holds this app's owned rows as summaries, without delta bodies.
	 *
	 * @return void
	 */
	public function testListUserDeltasSummarisesThisAppsOwnedRows(): void {
		$this->arrangeSearch(
			application: $this->application(allowUserOverrides: true),
			userRows: [
				[
					'id' => 'alice-row',
					'owner' => 'alice',
					'application' => 'app-A',
					'semver' => '0.1.0',
					'status' => 'draft',
					'manifestDelta' => ['secret' => 'value'],
					'@self' => ['updated' => '2026-11-01T10:00:00+00:00'],
				],
				['id' => 'orphan', 'owner' => '', 'application' => 'app-A'],
				['id' => 'elsewhere', 'owner' => 'bob', 'application' => 'app-B'],
			]
		);

		$result = $this->service->listUserDeltas(appId: 'demo');

		self::assertSame(
			expected: [
				[
					'owner' => 'alice',
					'versionUuid' => 'alice-row',
					'semver' => '0.1.0',
					'status' => 'draft',
					'updatedAt' => '2026-11-01T10:00:00+00:00',
				],
			],
			actual: $result
		);
	}//end testListUserDeltasSummarisesThisAppsOwnedRows()

	/**
	 * The maintainer list is empty for an unknown app or an app without a uuid.
	 *
	 * @return void
	 */
	public function testListUserDeltasIsEmptyWithoutAResolvableApp(): void {
		$this->arrangeSearch(application: ['slug' => 'demo', 'appType' => 'hybrid']);

		self::assertSame(expected: [], actual: $this->service->listUserDeltas(appId: 'demo'));
	}//end testListUserDeltasIsEmptyWithoutAResolvableApp()

	/**
	 * A failing hybrid-app lookup is logged and every read degrades to "nothing".
	 *
	 * @return void
	 */
	public function testAFailingApplicationLookupDegradesToNothing(): void {
		$this->objectService->method('searchObjectsBySlug')->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects(self::exactly(count: 3))->method('error')
			->with(self::stringContains(string: 'hybrid Application lookup failed for appId demo'));

		self::assertNull(actual: $this->service->findHybridApplication(appId: 'demo'));
		self::assertSame(expected: [], actual: $this->service->listUserDeltas(appId: 'demo'));
		self::assertNull(actual: $this->service->findByAppId(appId: 'demo'));
	}//end testAFailingApplicationLookupDegradesToNothing()

	/**
	 * A failing production-version lookup is logged and reads as "no admin delta".
	 *
	 * @return void
	 */
	public function testAFailingVersionLookupReadsAsNoDelta(): void {
		$this->arrangeSearch(application: $this->application(allowUserOverrides: false));
		$this->objectService->method('find')->willThrowException(new RuntimeException('not found'));
		$this->logger->expects(self::once())->method('error')
			->with(self::stringContains(string: 'hybrid production version lookup failed'));

		self::assertNull(actual: $this->service->findByAppId(appId: 'demo'));
	}//end testAFailingVersionLookupReadsAsNoDelta()
}//end class
