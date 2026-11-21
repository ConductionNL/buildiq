<?php

/**
 * EditorBaseService unit test.
 *
 * An owner or editor of the app gets the base of a version they may open;
 * anyone else, an unknown app and an unknown version get null.
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

use OCA\Buildiq\Kernel\Contracts\ManifestResolverServiceInterface;
use OCA\Buildiq\Kernel\Contracts\PermissionResolverInterface;
use OCA\Buildiq\Service\EditorBaseService;
use OCA\Buildiq\Service\ManifestBaseResolver;
use OCA\Buildiq\Service\RegisterRecordLookup;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Service\EditorBaseService
 * @covers \OCA\Buildiq\Service\ManifestBaseResolver
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
final class EditorBaseServiceTest extends TestCase {

	private const TEMPLATE = [
		'uuid' => 't-1',
		'slug' => 'permits',
		'version' => '1',
		'manifest' => ['pages' => [['id' => 'home', 'title' => 'Home']]],
	];

	/**
	 * The service over one app, one version and one template.
	 *
	 * @param array<string, string>|null $ids      What the manifest resolver answers for the caller.
	 * @param bool                       $designer Whether the caller is an owner or editor.
	 * @param array<string, mixed>       $version  The version.
	 *
	 * @return EditorBaseService
	 */
	private function service(?array $ids, bool $designer, array $version): EditorBaseService {
		$rows = [
			'built-app' => [['uuid' => 'app-1', 'slug' => 'crm', 'permissions' => ['owners' => ['user:alice']]]],
			'applicationVersion' => [$version],
			'application-template' => [self::TEMPLATE],
		];
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $register, string $schema, array $filters = []) use ($rows): array {
				return array_values(array_filter(
					$rows[$schema] ?? [],
					static fn (array $row): bool => (isset($filters['@self']) === true && ($row['uuid'] ?? null) === $filters['@self']['uuid'])
						|| (isset($filters['slug']) === true && ($row['slug'] ?? null) === $filters['slug'])
				));
			}
		);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$manifests = $this->createMock(originalClassName: ManifestResolverServiceInterface::class);
		$manifests->method('resolveVersionForCaller')->willReturn($ids);
		$permissions = $this->createMock(originalClassName: PermissionResolverInterface::class);
		$permissions->method('resolveUserGroups')->willReturn([]);
		$permissions->method('matchesCaller')->willReturnCallback(
			static function (array $permissions, IUser $caller, array $userGroups, ?bool $allowAdminBypass, array $roles) use ($designer): bool {
				self::assertFalse(condition: $allowAdminBypass);
				self::assertSame(expected: ['owners', 'editors'], actual: $roles);
				return $designer;
			}
		);
		$permissions->method('seesApplication')->willReturn(true);
		$records = new RegisterRecordLookup(objectService: $objects, logger: $logger);

		return new EditorBaseService(
			manifests: $manifests,
			permissions: $permissions,
			records: $records,
			bases: new ManifestBaseResolver(records: $records, logger: $logger)
		);
	}//end service()

	/**
	 * A delta-mode version of an app the caller designs.
	 *
	 * @return array<string, mixed>
	 */
	private static function deltaVersion(): array {
		return [
			'uuid' => 'v-1',
			'application' => 'app-1',
			'manifest' => [],
			'baseRef' => ['kind' => 'template', 'id' => 'permits'],
			'manifestDelta' => ['pages' => [['id' => 'home', 'title' => 'Start'], ['id' => 'gone', '$op' => 'remove']]],
		];
	}//end deltaVersion()

	/**
	 * An owner or editor gets the base and the delta paths that target nothing in it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
	 */
	public function testADesignerGetsTheBaseAndTheOrphans(): void {
		$service = $this->service(ids: ['applicationUuid' => 'app-1', 'versionUuid' => 'v-1'], designer: true, version: self::deltaVersion());

		$result = $service->authorizeBase(appSlug: 'crm', versionSlug: 'next', caller: $this->createMock(originalClassName: IUser::class));

		$this->assertSame(expected: self::TEMPLATE['manifest'], actual: $result['base']);
		$this->assertNotSame(expected: [], actual: $result['orphanedDeltaPaths']);
	}//end testADesignerGetsTheBaseAndTheOrphans()

	/**
	 * A version without a base answers no base, so the designer edits its blob.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
	 */
	public function testABlobVersionHasNoBase(): void {
		$service = $this->service(
			ids: ['applicationUuid' => 'app-1', 'versionUuid' => 'v-1'],
			designer: true,
			version: ['uuid' => 'v-1', 'manifest' => ['pages' => []]]
		);

		$result = $service->authorizeBase(appSlug: 'crm', versionSlug: null, caller: $this->createMock(originalClassName: IUser::class));

		$this->assertSame(expected: ['base' => null, 'orphanedDeltaPaths' => []], actual: $result);
	}//end testABlobVersionHasNoBase()

	/**
	 * A viewer, a version the caller may not open and an unknown version all get null.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
	 */
	public function testAnyoneElseGetsNothing(): void {
		$caller = $this->createMock(originalClassName: IUser::class);

		$viewer = $this->service(ids: ['applicationUuid' => 'app-1', 'versionUuid' => 'v-1'], designer: false, version: self::deltaVersion());
		$this->assertNull(actual: $viewer->authorizeBase(appSlug: 'crm', versionSlug: null, caller: $caller));

		$closed = $this->service(ids: null, designer: true, version: self::deltaVersion());
		$this->assertNull(actual: $closed->authorizeBase(appSlug: 'crm', versionSlug: 'next', caller: $caller));

		$missing = $this->service(ids: ['applicationUuid' => 'app-1', 'versionUuid' => 'v-9'], designer: true, version: self::deltaVersion());
		$this->assertNull(actual: $missing->authorizeBase(appSlug: 'crm', versionSlug: null, caller: $caller));

		$noApp = $this->service(ids: ['applicationUuid' => 'app-9', 'versionUuid' => 'v-1'], designer: true, version: self::deltaVersion());
		$this->assertNull(actual: $noApp->authorizeBase(appSlug: 'crm', versionSlug: null, caller: $caller));
	}//end testAnyoneElseGetsNothing()
}//end class
