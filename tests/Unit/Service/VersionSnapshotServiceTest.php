<?php

/**
 * Unit tests for VersionSnapshotService (change version-snapshots).
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

use OCA\Buildiq\Exception\VersionSnapshotException;
use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\Buildiq\Service\VersionSnapshotService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for VersionSnapshotService.
 */
class VersionSnapshotServiceTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var IGroupManager&MockObject
	 */
	private IGroupManager&MockObject $groupManager;

	/**
	 * Stored objects by schema slug, then id.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	private array $store = [];

	/**
	 * Every saveObject call: [schema, object].
	 *
	 * @var array<int,array{0: string, 1: array<string,mixed>}>
	 */
	private array $saves = [];

	/**
	 * Service under test.
	 */
	private VersionSnapshotService $service;

	/**
	 * Wire an in-memory object store behind the OpenRegister mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('getUserGroups')->willReturn([]);
		$this->groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'root');

		$registerMapper = $this->createMock(RegisterMapper::class);
		$register = new Register();
		$register->setId(1);
		$registerMapper->method('find')->willReturn($register);

		$schemaIds = [
			ApplicationVersionService::APPLICATION_SCHEMA => 10,
			ApplicationVersionService::APPLICATION_VERSION_SCHEMA => 11,
			VersionSnapshotService::SNAPSHOT_SCHEMA => 12,
		];
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			static function (string|int $slug) use ($schemaIds): Schema {
				$schema = new Schema();
				$schema->setId($schemaIds[(string)$slug]);
				$schema->setSlug((string)$slug);
				return $schema;
			}
		);
		$slugBySchemaId = array_flip($schemaIds);

		$this->store = [
			ApplicationVersionService::APPLICATION_SCHEMA => [
				'app-1' => [
					'id' => 'app-1',
					'slug' => 'shop',
					'productionVersion' => 'v-prod',
					'permissions' => ['owners' => ['user:alice'], 'editors' => [], 'viewers' => ['user:vera']],
				],
			],
			ApplicationVersionService::APPLICATION_VERSION_SCHEMA => [
				'v-dev' => ['id' => 'v-dev', 'slug' => 'development', 'application' => 'app-1', 'manifest' => ['pages' => ['dev']]],
				'v-prod' => ['id' => 'v-prod', 'slug' => 'production', 'application' => 'app-1', 'manifest' => ['pages' => ['m2']]],
			],
			VersionSnapshotService::SNAPSHOT_SCHEMA => [
				's-old' => ['id' => 's-old', 'application' => 'app-1', 'version' => 'v-prod', 'label' => 'v1', 'manifest' => ['pages' => ['m1']], 'takenAt' => '2026-09-01T10:00:00+00:00', 'kind' => 'manual'],
				's-other' => ['id' => 's-other', 'application' => 'app-2', 'version' => 'v-x', 'label' => 'x', 'manifest' => ['pages' => ['x']], 'takenAt' => '2026-09-02T10:00:00+00:00', 'kind' => 'manual'],
			],
		];

		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query) use ($slugBySchemaId): array {
				$slug = $slugBySchemaId[$query['@self']['schema']];
				// Deliberately ignore the property filters: the service must check them itself.
				return array_values($this->store[$slug]);
			}
		);
		$this->objectService->method('find')->willReturnCallback(
			function (string $id, mixed $extend = [], bool $files = false, mixed $register = null, mixed $schema = null): ObjectEntity {
				if (isset($this->store[$schema][$id]) === false) {
					throw new RuntimeException('Object not found');
				}

				return $this->entity(payload: $this->store[$schema][$id]);
			}
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object, mixed $extend = [], mixed $register = null, mixed $schema = null, ?string $uuid = null): ObjectEntity {
				$id = ($uuid ?? ('new-' . count($this->saves)));
				$object['id'] = $id;
				$this->saves[] = [(string)$schema, $object];
				$this->store[(string)$schema][$id] = $object;
				return $this->entity(payload: $object);
			}
		);

		$this->service = new VersionSnapshotService(
			objectService: $this->objectService,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			permissionResolver: new PermissionResolver($this->groupManager, $this->createMock(LoggerInterface::class)),
			versionService: $this->createMock(ApplicationVersionService::class),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * An owner snapshots the production version with a label.
	 *
	 * @return void
	 */
	public function testOwnerTakesALabelledSnapshotOfProduction(): void {
		$snapshot = $this->service->takeSnapshot(appSlug: 'shop', versionSlug: '', label: '  before tasks page ', caller: $this->user('alice'));

		self::assertSame('before tasks page', $snapshot['label']);
		self::assertSame(['pages' => ['m2']], $snapshot['manifest']);
		self::assertSame('v-prod', $snapshot['version']);
		self::assertSame('app-1', $snapshot['application']);
		self::assertSame('alice', $snapshot['takenBy']);
		self::assertSame(VersionSnapshotService::KIND_MANUAL, $snapshot['kind']);
		self::assertNotEmpty($snapshot['takenAt']);
	}//end testOwnerTakesALabelledSnapshotOfProduction()

	/**
	 * A named version is snapshotted, not production.
	 *
	 * @return void
	 */
	public function testSnapshotOfANamedVersion(): void {
		$snapshot = $this->service->takeSnapshot(appSlug: 'shop', versionSlug: 'development', label: 'dev', caller: $this->user('alice'));

		self::assertSame('v-dev', $snapshot['version']);
		self::assertSame(['pages' => ['dev']], $snapshot['manifest']);
	}//end testSnapshotOfANamedVersion()

	/**
	 * A viewer may list but not take a snapshot.
	 *
	 * @return void
	 */
	public function testViewerCannotTakeASnapshot(): void {
		try {
			$this->service->takeSnapshot(appSlug: 'shop', versionSlug: '', label: 'nope', caller: $this->user('vera'));
			self::fail('a viewer must be refused');
		} catch (VersionSnapshotException $e) {
			self::assertSame(403, $e->getStatus());
		}

		self::assertSame([], $this->saves);
		self::assertCount(1, $this->service->listSnapshots(appSlug: 'shop', versionSlug: '', caller: $this->user('vera')));
	}//end testViewerCannotTakeASnapshot()

	/**
	 * A user without a role cannot even list.
	 *
	 * @return void
	 */
	public function testStrangerCannotListSnapshots(): void {
		$this->expectException(VersionSnapshotException::class);
		$this->service->listSnapshots(appSlug: 'shop', versionSlug: '', caller: $this->user('mallory'));
	}//end testStrangerCannotListSnapshots()

	/**
	 * An empty label is refused before anything is stored.
	 *
	 * @return void
	 */
	public function testEmptyLabelIsRefused(): void {
		try {
			$this->service->takeSnapshot(appSlug: 'shop', versionSlug: '', label: '   ', caller: $this->user('alice'));
			self::fail('an empty label must be refused');
		} catch (VersionSnapshotException $e) {
			self::assertSame(400, $e->getStatus());
		}

		self::assertSame([], $this->saves);
	}//end testEmptyLabelIsRefused()

	/**
	 * The list holds only this version's snapshots, newest first.
	 *
	 * @return void
	 */
	public function testListIsScopedAndNewestFirst(): void {
		$this->service->takeSnapshot(appSlug: 'shop', versionSlug: '', label: 'v2', caller: $this->user('alice'));

		$labels = array_column($this->service->listSnapshots(appSlug: 'shop', versionSlug: '', caller: $this->user('alice')), 'label');

		self::assertSame(['v2', 'v1'], $labels);
	}//end testListIsScopedAndNewestFirst()

	/**
	 * Restoring keeps the replaced manifest as a Previous draft snapshot first.
	 *
	 * @return void
	 */
	public function testRestoreKeepsAPreviousDraftThenWritesTheSnapshot(): void {
		$result = $this->service->restoreSnapshot(appSlug: 'shop', snapshotUuid: 's-old', caller: $this->user('alice'));

		self::assertCount(2, $this->saves);
		[$firstSchema, $kept] = $this->saves[0];
		self::assertSame(VersionSnapshotService::SNAPSHOT_SCHEMA, $firstSchema);
		self::assertSame(VersionSnapshotService::KIND_PREVIOUS_DRAFT, $kept['kind']);
		self::assertSame('Previous draft', $kept['label']);
		self::assertSame(['pages' => ['m2']], $kept['manifest']);

		[$secondSchema, $version] = $this->saves[1];
		self::assertSame(ApplicationVersionService::APPLICATION_VERSION_SCHEMA, $secondSchema);
		self::assertSame('v-prod', $version['id']);
		self::assertSame(['pages' => ['m1']], $version['manifest']);
		self::assertSame(['pages' => ['m1']], $result['version']['manifest']);
		self::assertSame('Previous draft', $result['previousDraft']['label']);
	}//end testRestoreKeepsAPreviousDraftThenWritesTheSnapshot()

	/**
	 * A snapshot of another app is not found, and nothing changes.
	 *
	 * @return void
	 */
	public function testSnapshotOfAnotherAppCannotBeRestored(): void {
		try {
			$this->service->restoreSnapshot(appSlug: 'shop', snapshotUuid: 's-other', caller: $this->user('alice'));
			self::fail('a foreign snapshot must be refused');
		} catch (VersionSnapshotException $e) {
			self::assertSame(404, $e->getStatus());
		}

		self::assertSame([], $this->saves);
	}//end testSnapshotOfAnotherAppCannotBeRestored()

	/**
	 * A viewer cannot restore.
	 *
	 * @return void
	 */
	public function testViewerCannotRestore(): void {
		$this->expectException(VersionSnapshotException::class);
		$this->service->restoreSnapshot(appSlug: 'shop', snapshotUuid: 's-old', caller: $this->user('vera'));
	}//end testViewerCannotRestore()

	/**
	 * An admin without a role passes through the audited bypass.
	 *
	 * @return void
	 */
	public function testAdminWithoutRoleMayTakeASnapshot(): void {
		$snapshot = $this->service->takeSnapshot(appSlug: 'shop', versionSlug: '', label: 'by admin', caller: $this->user('root'));

		self::assertSame('root', $snapshot['takenBy']);
	}//end testAdminWithoutRoleMayTakeASnapshot()

	/**
	 * An ObjectEntity that serialises to the payload.
	 *
	 * @param array<string,mixed> $payload The payload
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string,mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string,mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;
		return $entity;
	}//end entity()

	/**
	 * A mock user.
	 *
	 * @param string $uid The user id
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()
}//end class
