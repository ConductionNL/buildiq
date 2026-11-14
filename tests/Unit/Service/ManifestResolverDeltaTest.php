<?php

/**
 * Unit tests for the base + delta branch of ManifestResolverService
 * (app-delta-override): a legacy blob and a hybrid app are served as stored;
 * a delta-mode version serves its base with its delta merged over it, never
 * the raw delta; orphans and an unresolved base are diagnostics only; a
 * built-app chain follows the live base, stops at a cycle and at its depth
 * cap; an unrenderable merge is refused.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-7.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AppVisibilityResolver;
use OCA\Buildiq\Service\ManifestBaseResolver;
use OCA\Buildiq\Service\ManifestDeltaMerger;
use OCA\Buildiq\Service\ManifestResolutionException;
use OCA\Buildiq\Service\ManifestResolverService;
use OCA\Buildiq\Service\ManifestWidgetRoleFilter;
use OCA\Buildiq\Service\PermissionResolver;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Service\ManifestResolverService
 * @covers \OCA\Buildiq\Service\ManifestResolutionException
 * @uses   \OCA\Buildiq\Service\ManifestDeltaMerger
 * @uses   \OCA\Buildiq\Service\ManifestDeltaShape
 * @uses   \OCA\Buildiq\Service\PermissionResolver
 * @uses   \OCA\Buildiq\Service\ManifestWidgetRoleFilter
 * @uses   \OCA\Buildiq\Service\AppVisibilityResolver
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
final class ManifestResolverDeltaTest extends TestCase {

	private ObjectServiceInterface&MockObject $objectService;

	private ManifestBaseResolver&MockObject $bases;

	/**
	 * Applications by slug, as the object store returns them.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $applications = [];

	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('searchObjects')->willReturnCallback(
			fn (array $query): array => isset($this->applications[(string)($query['slug'] ?? '')]) === true ? [$this->applications[(string)$query['slug']]] : []
		);
		$this->bases = $this->createMock(ManifestBaseResolver::class);
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ManifestResolverService
	 */
	private function service(): ManifestResolverService {
		$register = $this->getMockBuilder(Register::class)->onlyMethods(['getId'])->getMock();
		$register->method('getId')->willReturn(1);
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($register);
		$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getId'])->getMock();
		$schema->method('getId')->willReturn(2);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($schema);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('getUserGroups')->willReturn([]);

		return new ManifestResolverService(
			objectService: $this->objectService,
			registerMapper: $registers,
			schemaMapper: $schemas,
			logger: $this->createMock(LoggerInterface::class),
			permissionResolver: new PermissionResolver($groups, $this->createMock(LoggerInterface::class)),
			widgetRoleFilter: new ManifestWidgetRoleFilter(visibility: new AppVisibilityResolver(), groupManager: $groups),
			deltaMerger: new ManifestDeltaMerger(),
			baseResolver: $this->bases,
		);
	}//end service()

	/**
	 * A two-page base manifest.
	 *
	 * @return array<string, mixed>
	 */
	private static function base(): array {
		return [
			'version' => '1.0.0',
			'pages' => [
				['id' => 'home', 'title' => 'Home', 'type' => 'dashboard'],
				['id' => 'list', 'title' => 'List', 'type' => 'index'],
			],
		];
	}//end base()

	/**
	 * A built app whose production version is inline.
	 *
	 * @param string $slug The slug.
	 * @param array<string, mixed> $version The production version.
	 *
	 * @return void
	 */
	private function app(string $slug, array $version): void {
		$this->applications[$slug] = ['uuid' => 'uuid-' . $slug, 'slug' => $slug, 'productionVersion' => $version];
	}//end app()

	public function testALegacyBlobIsServedUnchangedWithoutAnyBaseLookup(): void {
		$this->bases->expects($this->never())->method('template');
		$this->bases->expects($this->never())->method('fleetApp');
		$blob = ['version' => '1.0.0', 'pages' => [['id' => 'home', 'title' => 'Home']], 'menu' => [['id' => 'm', 'route' => 'home']]];

		$served = $this->service()->resolveVersionManifest(version: ['manifest' => $blob], application: [], caller: null);

		$this->assertSame($blob, $served);
	}

	public function testAHybridAppKeepsItsClientSideMerge(): void {
		$this->bases->expects($this->never())->method('fleetApp');
		$version = ['manifest' => [], 'baseRef' => ['kind' => 'fleet-app', 'id' => 'pipelinq'], 'manifestDelta' => ['pages' => []]];

		$this->assertSame([], $this->service()->resolveVersionManifest(version: $version, application: ['appType' => 'hybrid'], caller: null));
	}

	public function testADeltaOverATemplateIsServedMergedWithoutTheRawDelta(): void {
		$this->bases->expects($this->once())->method('template')->with('crm')->willReturn(self::base());
		$version = [
			'manifest' => [],
			'baseRef' => ['kind' => 'template', 'id' => 'crm'],
			'manifestDelta' => ['pages' => [['id' => 'home', 'title' => 'Start']]],
		];

		$served = $this->service()->resolveVersionManifest(version: $version, application: [], caller: null);

		$this->assertSame(['Start', 'List'], array_column($served['pages'], 'title'));
		$this->assertSame('dashboard', $served['pages'][0]['type']);
		$this->assertArrayNotHasKey('baseRef', $served);
		$this->assertArrayNotHasKey('manifestDelta', $served);
		$this->assertArrayNotHasKey('orphanedDeltaPaths', $served);
	}

	public function testAnOrphanIsSkippedAndKeptAsADiagnosticOnly(): void {
		$this->bases->method('template')->willReturn(self::base());
		$service = $this->service();
		$version = [
			'baseRef' => ['kind' => 'template', 'id' => 'crm'],
			'manifestDelta' => ['pages' => [['id' => 'gone', '$op' => 'remove']]],
		];

		$served = $service->resolveVersionManifest(version: $version, application: [], caller: null);

		$this->assertSame(['home', 'list'], array_column($served['pages'], 'id'));
		$this->assertNotSame([], $service->diagnostics()['orphanedDeltaPaths']);
		$this->assertStringContainsString('gone', implode(' ', $service->diagnostics()['orphanedDeltaPaths']));
	}

	public function testAnUnresolvedBaseFallsBackToTheStoredBlob(): void {
		$this->bases->method('template')->willReturn(null);
		$service = $this->service();
		$version = [
			'manifest' => self::base(),
			'baseRef' => ['kind' => 'template', 'id' => 'deleted'],
			'manifestDelta' => ['pages' => [['id' => 'list', 'title' => 'All']]],
		];

		$served = $service->resolveVersionManifest(version: $version, application: [], caller: null);

		$this->assertSame(['Home', 'All'], array_column($served['pages'], 'title'));
		$this->assertSame(['kind' => 'template', 'id' => 'deleted'], $service->diagnostics()['unresolvedBase']);
	}

	public function testABuiltAppBaseIsItsLiveProductionManifest(): void {
		$this->app('base-app', ['manifest' => self::base()]);
		$this->app('derived', ['baseRef' => ['kind' => 'buildiq-app', 'id' => 'base-app'], 'manifestDelta' => ['pages' => [['id' => 'list', 'title' => 'Cases']]]]);

		$served = $this->service()->resolve(appSlug: 'derived', versionSlug: null, caller: null);

		$this->assertSame(['Home', 'Cases'], array_column($served['pages'], 'title'));

		// A page the base gains later is inherited on the next fetch.
		$base = self::base();
		$base['pages'][] = ['id' => 'reports', 'title' => 'Reports'];
		$this->app('base-app', ['manifest' => $base]);
		$this->assertSame(['Home', 'Cases', 'Reports'], array_column($this->service()->resolve(appSlug: 'derived', versionSlug: null, caller: null)['pages'], 'title'));
	}

	public function testACycleStopsAndReportsIt(): void {
		$this->app('a', ['manifest' => self::base(), 'baseRef' => ['kind' => 'buildiq-app', 'id' => 'b'], 'manifestDelta' => ['pages' => [['id' => 'home', 'title' => 'A']]]]);
		$this->app('b', ['manifest' => self::base(), 'baseRef' => ['kind' => 'buildiq-app', 'id' => 'a'], 'manifestDelta' => ['pages' => [['id' => 'list', 'title' => 'B']]]]);
		$service = $this->service();

		$served = $service->resolve(appSlug: 'a', versionSlug: null, caller: null);

		$this->assertSame(['A', 'B'], array_column($served['pages'], 'title'));
		$this->assertTrue($service->diagnostics()['cycle']);
	}

	public function testAChainDeeperThanTheCapStopsAtTheDeepestSafeBase(): void {
		$this->app('app0', ['manifest' => self::base()]);
		for ($i = 1; $i <= (ManifestResolverService::MAX_BASE_DEPTH + 2); $i++) {
			$this->app('app' . $i, ['manifest' => self::base(), 'baseRef' => ['kind' => 'buildiq-app', 'id' => 'app' . ($i - 1)], 'manifestDelta' => ['pages' => [['id' => 'home', 'title' => 'T' . $i]]]]);
		}

		$service = $this->service();
		$served = $service->resolve(appSlug: 'app' . (ManifestResolverService::MAX_BASE_DEPTH + 2), versionSlug: null, caller: null);

		$this->assertSame('T' . (ManifestResolverService::MAX_BASE_DEPTH + 2), $served['pages'][0]['title']);
		$this->assertTrue($service->diagnostics()['cycle']);
	}

	public function testAPinnedBaseVersionThatDoesNotResolveIsAnUnresolvedBase(): void {
		$this->app('base-app', ['manifest' => self::base()]);
		$service = $this->service();
		$version = ['manifest' => self::base(), 'baseRef' => ['kind' => 'buildiq-app', 'id' => 'base-app', 'version' => 'v-unknown'], 'manifestDelta' => []];

		$service->resolveVersionManifest(version: $version, application: [], caller: null);

		$this->assertSame('v-unknown', $service->diagnostics()['unresolvedBase']['version']);
	}

	public function testAnUnknownBaseKindIsUnresolved(): void {
		$service = $this->service();

		$served = $service->resolveVersionManifest(version: ['manifest' => self::base(), 'baseRef' => ['kind' => 'elsewhere', 'id' => 'x']], application: [], caller: null);

		$this->assertSame(self::base(), $served);
		$this->assertNotNull($service->diagnostics()['unresolvedBase']);
	}

	public function testAnUnrenderableMergeIsRefused(): void {
		$this->bases->method('template')->willReturn(['pages' => []]);

		$this->expectException(ManifestResolutionException::class);

		$this->service()->resolveVersionManifest(version: ['baseRef' => ['kind' => 'template', 'id' => 'broken'], 'manifestDelta' => []], application: [], caller: null);
	}

	public function testTheEditorGetsTheBaseOfAVersionItMayOpen(): void {
		$this->applications['derived'] = ['uuid' => 'uuid-derived', 'slug' => 'derived', 'productionVersion' => 'v-1'];
		$this->objectService->method('find')->willReturn(self::entity(uuid: 'v-1', data: ['application' => 'uuid-derived', 'baseRef' => ['kind' => 'template', 'id' => 'crm'], 'manifestDelta' => []]));
		$this->bases->method('template')->with('crm')->willReturn(self::base());
		$caller = $this->createMock(\OCP\IUser::class);

		$found = $this->service()->resolveBaseForVersion(appSlug: 'derived', versionSlug: null, caller: $caller);

		$this->assertSame(['baseRef' => ['kind' => 'template', 'id' => 'crm'], 'base' => self::base()], $found);
		$this->assertNull($this->service()->resolveBaseForVersion(appSlug: 'derived', versionSlug: null, caller: null), 'an anonymous caller gets nothing');
	}

	public function testAVersionWithoutABaseHasNoBaseForTheEditor(): void {
		$this->applications['plain'] = ['uuid' => 'uuid-plain', 'slug' => 'plain', 'productionVersion' => 'v-2'];
		$this->objectService->method('find')->willReturn(self::entity(uuid: 'v-2', data: ['manifest' => self::base()]));
		$this->bases->expects($this->never())->method('template');

		$found = $this->service()->resolveBaseForVersion(appSlug: 'plain', versionSlug: null, caller: $this->createMock(\OCP\IUser::class));

		$this->assertSame(['baseRef' => null, 'base' => null], $found);
	}

	/**
	 * An object entity as the store returns it.
	 *
	 * @param string $uuid The uuid.
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return \OCA\OpenRegister\Db\ObjectEntity
	 */
	private static function entity(string $uuid, array $data): \OCA\OpenRegister\Db\ObjectEntity {
		$entity = new \OCA\OpenRegister\Db\ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setObject($data);
		return $entity;
	}//end entity()
}//end class
