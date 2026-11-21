<?php

/**
 * ManifestResolverService serves a delta-mode version merged over its base.
 *
 * The production version (inline) and a named version both go through
 * ManifestBaseResolver; a legacy blob is served as stored; a base app of
 * another organisation is not read for a caller who may not see it.
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

use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\ManifestBaseResolver;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\ManifestWidgetRoleFilter;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Service\ManifestResolverService
 */
final class ManifestResolverDeltaTest extends TestCase {

	private const TEMPLATE = [
		'uuid' => 't-1',
		'slug' => 'permits',
		'version' => '1.0.0',
		'manifest' => ['version' => '1.0.0', 'menu' => [], 'pages' => [['id' => 'home', 'title' => 'Home'], ['id' => 'list', 'title' => 'List']]],
	];

	/**
	 * The resolver over one application, its named versions and Buildiq's base rows.
	 *
	 * @param array<string, mixed>                      $application The application found by slug.
	 * @param array<int, array<string, mixed>>          $versions    Versions found by slug + application.
	 * @param array<string, list<array<string, mixed>>> $baseRows    Rows the base resolver reads, per schema.
	 *
	 * @return ManifestResolverService
	 */
	private function service(array $application, array $versions = [], array $baseRows = []): ManifestResolverService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('searchObjects')->willReturnCallback(
			static fn (array $query): array => (isset($query['application']) === true) ? $versions : [$application]
		);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			static function (string $register, string $schema, array $filters = []) use ($baseRows): array {
				return array_values(array_filter(
					$baseRows[$schema] ?? [],
					static fn (array $row): bool => ($row['slug'] ?? null) === ($filters['slug'] ?? null)
						|| ($row['uuid'] ?? null) === ($filters['@self']['uuid'] ?? null)
				));
			}
		);

		$register = $this->getMockBuilder(Register::class)->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(1);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(2);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('getUserGroups')->willReturn([]);
		$logger = $this->createMock(LoggerInterface::class);

		return new ManifestResolverService(
			objectService: $objects,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			logger: $logger,
			permissionResolver: new PermissionResolver($groupManager, $logger),
			widgetRoleFilter: new ManifestWidgetRoleFilter(visibility: new AppVisibilityResolver(), groupManager: $groupManager),
			baseResolver: new ManifestBaseResolver(objectService: $objects, logger: $logger),
		);
	}//end service()

	/**
	 * A caller with the given uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end user()

	/**
	 * The production version's delta is merged over its template; no baseRef or delta reaches the caller.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.1
	 */
	public function testTheProductionVersionServesItsDeltaOverItsBase(): void {
		$service = $this->service(
			application: ['uuid' => 'app-1', 'slug' => 'crm', 'productionVersion' => [
				'uuid' => 'v-1',
				'manifest' => (object)[],
				'baseRef' => ['kind' => 'template', 'id' => 'permits'],
				'manifestDelta' => ['pages' => [['id' => 'list', 'title' => 'Permits']]],
			]],
			baseRows: ['application-template' => [self::TEMPLATE]]
		);

		$manifest = $service->resolve('crm', null, null);

		$this->assertSame(['Home', 'Permits'], array_column($manifest['pages'], 'title'));
		$this->assertArrayNotHasKey('baseRef', $manifest);
		$this->assertArrayNotHasKey('manifestDelta', $manifest);
	}//end testTheProductionVersionServesItsDeltaOverItsBase()

	/**
	 * A named version an owner opens is merged too, and a legacy production blob is served as stored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.1
	 */
	public function testANamedVersionIsMergedAndALegacyBlobIsNot(): void {
		$application = ['uuid' => 'app-1', 'slug' => 'crm', 'productionVersion' => ['uuid' => 'v-1', 'manifest' => ['pages' => [['id' => 'legacy']]]], 'permissions' => ['owners' => ['user:alice'], 'editors' => []]];
		$draft = ['uuid' => 'v-2', 'slug' => 'next', 'application' => 'app-1', 'manifest' => (object)[], 'baseRef' => ['kind' => 'template', 'id' => 'permits'], 'manifestDelta' => ['pages' => [['id' => 'home', '$op' => 'remove']]]];
		$service = $this->service(application: $application, versions: [$draft], baseRows: ['application-template' => [self::TEMPLATE]]);

		$this->assertSame(['pages' => [['id' => 'legacy']]], $service->resolve('crm', null, null));
		$this->assertSame(['List'], array_column($service->resolve('crm', 'next', $this->user('alice'))['pages'], 'title'));
	}//end testANamedVersionIsMergedAndALegacyBlobIsNot()

	/**
	 * A base app scoped to an organisation the caller cannot see is not read; the stored blob is served.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-4.1
	 */
	public function testABaseAppTheCallerCannotSeeIsNotRead(): void {
		$service = $this->service(
			application: ['uuid' => 'app-1', 'slug' => 'crm', 'productionVersion' => [
				'uuid' => 'v-1',
				'manifest' => ['pages' => [['id' => 'own']]],
				'baseRef' => ['kind' => 'buildiq-app', 'id' => 'secret'],
			]],
			baseRows: [
				'built-app' => [['uuid' => 'app-2', 'slug' => 'secret', 'organisationScope' => 'holder', 'productionVersion' => 'v-9']],
				'applicationVersion' => [['uuid' => 'v-9', 'application' => 'app-2', 'manifest' => ['pages' => [['id' => 'theirs']]]]],
			]
		);

		$this->assertSame(['pages' => [['id' => 'own']]], $service->resolve('crm', null, $this->user('bob')));
	}//end testABaseAppTheCallerCannotSeeIsNotRead()
}//end class
