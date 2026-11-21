<?php

/**
 * ManifestBaseResolver unit test.
 *
 * A version without a base serves its blob unchanged; a version with a base
 * serves its delta merged over the live (or pinned) base; a base that cannot
 * be read, a loop and a chain that is too deep all fall back to the stored
 * blob with a reason.
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

use OCA\Buildiq\Service\BundledManifestReader;
use OCA\Buildiq\Service\ManifestBaseResolver;
use OCA\Buildiq\Service\RegisterRecordLookup;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Buildiq\Service\ManifestBaseResolver
 * @covers \OCA\Buildiq\Service\BundledManifestReader
 * @covers \OCA\Buildiq\Service\RegisterRecordLookup
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-7.2
 */
final class ManifestBaseResolverTest extends TestCase {

	/**
	 * Rows per schema slug, as OpenRegister would return them.
	 *
	 * @var array<string, list<array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * The resolver over the in-memory rows.
	 *
	 * @param IAppManager|null $appManager The app manager for fleet bases.
	 *
	 * @return ManifestBaseResolver
	 */
	private function resolver(?IAppManager $appManager = null): ManifestBaseResolver {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willReturnCallback(
			function (string $register, string $schema, array $filters = []): array {
				$this->assertSame(expected: 'buildiq', actual: $register);
				return array_values(array_filter(
					$this->rows[$schema] ?? [],
					static function (array $row) use ($filters): bool {
						foreach ($filters as $key => $value) {
							if ($key === '@self') {
								if (($row['uuid'] ?? null) !== ($value['uuid'] ?? null)) {
									return false;
								}
								continue;
							}

							if (($row[$key] ?? null) !== $value) {
								return false;
							}
						}
						return true;
					}
				));
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$bundles = null;
		if ($appManager !== null) {
			$bundles = new BundledManifestReader(appManager: $appManager, logger: $logger);
		}

		return new ManifestBaseResolver(
			records: new RegisterRecordLookup(objectService: $objects, logger: $logger),
			logger: $logger,
			bundles: $bundles
		);
	}//end resolver()

	/**
	 * A manifest with the given pages.
	 *
	 * @param array<int, array<string, mixed>> $pages The pages.
	 * @param string                           $version The manifest version.
	 *
	 * @return array<string, mixed>
	 */
	private static function manifest(array $pages, string $version = '1.0.0'): array {
		return ['version' => $version, 'menu' => [], 'pages' => $pages];
	}//end manifest()

	/**
	 * A legacy version (blob, no baseRef) serves its blob byte for byte, and nothing is looked up.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-6.1
	 */
	public function testALegacyBlobIsServedUnchanged(): void {
		$blob = self::manifest(pages: [['id' => 'home', 'title' => 'Home']]);
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('searchObjectsBySlug');
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$resolver = new ManifestBaseResolver(records: new RegisterRecordLookup(objectService: $objects, logger: $logger), logger: $logger);

		$result = $resolver->resolveVersion(version: ['manifest' => $blob, 'manifestDelta' => ['pages' => []]]);

		$this->assertSame(expected: $blob, actual: $result['manifest']);
		$this->assertSame(expected: [], actual: $result['orphanedDeltaPaths']);
		$this->assertSame(expected: [], actual: $result['diagnostics']);
		$this->assertSame(expected: $blob, actual: $resolver->manifestOf(version: ['manifest' => $blob, 'baseRef' => ['kind' => 'template']]));
	}//end testALegacyBlobIsServedUnchanged()

	/**
	 * A template base: the delta is merged over the template's manifest.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function testATemplateBaseIsMergedWithTheDelta(): void {
		$this->rows['application-template'] = [[
			'uuid' => 't-1',
			'slug' => 'permits',
			'version' => '2.0.0',
			'manifest' => self::manifest(pages: [['id' => 'home', 'title' => 'Home'], ['id' => 'list', 'title' => 'List']]),
		]];

		$result = $this->resolver()->resolveVersion(version: [
			'manifest' => (object)[],
			'baseRef' => ['kind' => 'template', 'id' => 'permits'],
			'manifestDelta' => ['pages' => [['id' => 'list', 'title' => 'Permits']]],
		]);

		$this->assertSame(expected: ['Home', 'Permits'], actual: array_column($result['manifest']['pages'], 'title'));
		$this->assertSame(expected: [], actual: $result['diagnostics']);
	}//end testATemplateBaseIsMergedWithTheDelta()

	/**
	 * A template is also found by uuid, and a pin that does not match its version is not used.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function testATemplatePinMustMatchItsVersion(): void {
		$this->rows['application-template'] = [[
			'uuid' => 't-1',
			'slug' => 'permits',
			'version' => '2.0.0',
			'manifest' => self::manifest(pages: [['id' => 'home', 'title' => 'Home']]),
		]];
		$blob = self::manifest(pages: [['id' => 'own', 'title' => 'Own']]);

		$pinned = $this->resolver()->resolveVersion(version: [
			'manifest' => $blob,
			'baseRef' => ['kind' => 'template', 'id' => 't-1', 'manifestVersion' => '2.0.0'],
		]);
		$this->assertSame(expected: ['Home'], actual: array_column($pinned['manifest']['pages'], 'title'));

		$stale = $this->resolver()->resolveVersion(version: [
			'manifest' => $blob,
			'baseRef' => ['kind' => 'template', 'id' => 't-1', 'manifestVersion' => '1.0.0'],
		]);
		$this->assertSame(expected: $blob, actual: $stale['manifest']);
		$this->assertSame(expected: ['base template:t-1 could not be read'], actual: $stale['diagnostics']);
	}//end testATemplatePinMustMatchItsVersion()

	/**
	 * A Buildiq app base follows its live production version: a page the base gains later is served.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function testAnAppBaseFollowsItsProductionVersion(): void {
		$this->rows['built-app'] = [['uuid' => 'app-1', 'slug' => 'base-app', 'productionVersion' => 'v-2']];
		$this->rows['applicationVersion'] = [
			[
				'uuid' => 'v-1',
				'application' => 'app-1',
				'slug' => 'v1',
				'semver' => '1.0.0',
				'status' => 'published',
				'manifest' => self::manifest(pages: [['id' => 'home', 'title' => 'Home']]),
			],
			[
				'uuid' => 'v-2',
				'application' => 'app-1',
				'slug' => 'v2',
				'semver' => '1.1.0',
				'status' => 'published',
				'manifest' => self::manifest(pages: [['id' => 'home', 'title' => 'Home'], ['id' => 'new', 'title' => 'New']]),
			],
		];
		$derived = [
			'manifest' => (object)[],
			'baseRef' => ['kind' => 'buildiq-app', 'id' => 'base-app'],
			'manifestDelta' => ['pages' => [['id' => 'home', 'title' => 'Start']]],
		];

		$live = $this->resolver()->resolveVersion(version: $derived);
		$this->assertSame(expected: ['Start', 'New'], actual: array_column($live['manifest']['pages'], 'title'));

		$derived['baseRef']['manifestVersion'] = '1.0.0';
		$pinned = $this->resolver()->resolveVersion(version: $derived);
		$this->assertSame(expected: ['Start'], actual: array_column($pinned['manifest']['pages'], 'title'));
	}//end testAnAppBaseFollowsItsProductionVersion()

	/**
	 * A pin to a draft, an app the caller may not see, and a production version of another app are not bases.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function testAnAppBaseIsNeverADraftOrAHiddenApp(): void {
		$this->rows['built-app'] = [['uuid' => 'app-1', 'slug' => 'base-app', 'productionVersion' => 'v-x']];
		$this->rows['applicationVersion'] = [
			[
				'uuid' => 'v-d',
				'application' => 'app-1',
				'slug' => 'next',
				'status' => 'draft',
				'manifest' => self::manifest(pages: [['id' => 'draft']]),
			],
			[
				'uuid' => 'v-x',
				'application' => 'other-app',
				'slug' => 'x',
				'status' => 'published',
				'manifest' => self::manifest(pages: [['id' => 'foreign']]),
			],
		];
		$blob = self::manifest(pages: [['id' => 'own']]);

		$draft = $this->resolver()->manifestOf(version: [
			'manifest' => $blob,
			'baseRef' => ['kind' => 'buildiq-app', 'id' => 'base-app', 'manifestVersion' => 'next'],
		]);
		$this->assertSame(expected: $blob, actual: $draft);

		$foreign = $this->resolver()->manifestOf(version: ['manifest' => $blob, 'baseRef' => ['kind' => 'buildiq-app', 'id' => 'app-1']]);
		$this->assertSame(expected: $blob, actual: $foreign);

		$this->rows['built-app'][0]['productionVersion'] = ['uuid' => 'v-d'];
		$hidden = $this->resolver()->manifestOf(
			version: ['manifest' => $blob, 'baseRef' => ['kind' => 'buildiq-app', 'id' => 'base-app']],
			visible: static fn (array $app): bool => false
		);
		$this->assertSame(expected: $blob, actual: $hidden);
	}//end testAnAppBaseIsNeverADraftOrAHiddenApp()

	/**
	 * An application-version base is resolved recursively: user delta over admin delta over template.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function testAVersionBaseIsResolvedThroughItsOwnBase(): void {
		$this->rows['application-template'] = [[
			'uuid' => 't-1',
			'slug' => 'tpl',
			'version' => '1',
			'manifest' => self::manifest(pages: [['id' => 'home', 'title' => 'Home'], ['id' => 'list', 'title' => 'List']]),
		]];
		$this->rows['applicationVersion'] = [[
			'uuid' => 'admin',
			'manifest' => (object)[],
			'baseRef' => ['kind' => 'template', 'id' => 'tpl'],
			'manifestDelta' => ['pages' => [['id' => 'list', 'title' => 'Cases']]],
		]];

		$result = $this->resolver()->resolveVersion(version: [
			'manifest' => (object)[],
			'baseRef' => ['kind' => 'application-version', 'id' => 'admin'],
			'manifestDelta' => ['pages' => [['id' => 'home', '$op' => 'remove']]],
		]);

		$this->assertSame(expected: ['Cases'], actual: array_column($result['manifest']['pages'], 'title'));
	}//end testAVersionBaseIsResolvedThroughItsOwnBase()

	/**
	 * A delta entry the base lacks is skipped and reported; the rest still merges.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-7.2
	 */
	public function testAnOrphanedDeltaPathIsSkippedAndReported(): void {
		$this->rows['application-template'] = [[
			'uuid' => 't-1',
			'slug' => 'tpl',
			'version' => '1',
			'manifest' => self::manifest(pages: [['id' => 'home', 'title' => 'Home']]),
		]];

		$result = $this->resolver()->resolveVersion(version: [
			'manifest' => (object)[],
			'baseRef' => ['kind' => 'template', 'id' => 'tpl'],
			'manifestDelta' => ['pages' => [['id' => 'gone', '$op' => 'remove'], ['id' => 'home', 'title' => 'Start']]],
		]);

		$this->assertSame(expected: ['Start'], actual: array_column($result['manifest']['pages'], 'title'));
		$this->assertNotSame(expected: [], actual: $result['orphanedDeltaPaths']);
	}//end testAnOrphanedDeltaPathIsSkippedAndReported()

	/**
	 * A loop of bases stops at the last version reached, which serves its own blob.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.2
	 */
	public function testALoopOfBasesStopsWithAReason(): void {
		$this->rows['applicationVersion'] = [
			['uuid' => 'a', 'manifest' => self::manifest(pages: [['id' => 'a-page']]), 'baseRef' => ['kind' => 'application-version', 'id' => 'b']],
			['uuid' => 'b', 'manifest' => self::manifest(pages: [['id' => 'b-page']]), 'baseRef' => ['kind' => 'application-version', 'id' => 'a']],
		];

		$result = $this->resolver()->resolveVersion(version: $this->rows['applicationVersion'][0]);

		$this->assertSame(expected: ['a-page'], actual: array_column($result['manifest']['pages'], 'id'));
		$this->assertSame(expected: ['the base chain loops or is too deep at application-version:b'], actual: $result['diagnostics']);
	}//end testALoopOfBasesStopsWithAReason()

	/**
	 * A chain deeper than the cap stops at the cap.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.2
	 */
	public function testAChainDeeperThanTheCapStops(): void {
		$depth = ManifestBaseResolver::MAX_DEPTH + 2;
		for ($i = 0; $i < $depth; $i++) {
			$this->rows['applicationVersion'][] = [
				'uuid' => 'v' . $i,
				'manifest' => self::manifest(pages: [['id' => 'p' . $i]]),
				'baseRef' => ['kind' => 'application-version', 'id' => 'v' . ($i + 1)],
			];
		}

		$result = $this->resolver()->resolveVersion(version: $this->rows['applicationVersion'][0]);

		$this->assertSame(expected: ['p' . ManifestBaseResolver::MAX_DEPTH], actual: array_column($result['manifest']['pages'], 'id'));
		$this->assertStringContainsString(needle: 'too deep', haystack: $result['diagnostics'][0]);
	}//end testAChainDeeperThanTheCapStops()

	/**
	 * A fleet base reads the app's bundled manifest; a missing app, a bad id or a wrong pin falls back.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function testAFleetBaseReadsTheBundledManifest(): void {
		$dir = sys_get_temp_dir() . '/bq-fleet-' . uniqid();
		mkdir($dir . '/src', 0777, true);
		file_put_contents($dir . '/src/manifest.json', json_encode(self::manifest(pages: [['id' => 'cases', 'title' => 'Cases']], version: '3.1.0')));
		$apps = $this->createMock(originalClassName: IAppManager::class);
		$apps->method('getAppPath')->willReturnCallback(static function (string $appId) use ($dir): string {
			if ($appId !== 'caseapp') {
				throw new \OCP\App\AppPathNotFoundException($appId);
			}
			return $dir;
		});
		$blob = (object)[];
		$resolver = $this->resolver(appManager: $apps);

		$merged = $resolver->manifestOf(version: [
			'manifest' => $blob,
			'baseRef' => ['kind' => 'fleet-app', 'id' => 'caseapp'],
			'manifestDelta' => ['pages' => [['id' => 'cases', 'title' => 'Zaken']]],
		]);
		$this->assertSame(expected: ['Zaken'], actual: array_column($merged['pages'], 'title'));

		$this->assertSame(
			expected: $blob,
			actual: $resolver->manifestOf(version: ['manifest' => $blob, 'baseRef' => ['kind' => 'fleet-app', 'id' => 'missing']])
		);
		$this->assertSame(
			expected: $blob,
			actual: $resolver->manifestOf(version: ['manifest' => $blob, 'baseRef' => ['kind' => 'fleet-app', 'id' => '../etc']])
		);
		$this->assertSame(
			expected: $blob,
			actual: $resolver->manifestOf(version: [
				'manifest' => $blob,
				'baseRef' => ['kind' => 'fleet-app', 'id' => 'caseapp', 'manifestVersion' => '2.0.0'],
			])
		);
		$this->assertSame(
			expected: $blob,
			actual: $this->resolver()->manifestOf(version: ['manifest' => $blob, 'baseRef' => ['kind' => 'fleet-app', 'id' => 'caseapp']])
		);

		unlink($dir . '/src/manifest.json');
		rmdir($dir . '/src');
		rmdir($dir);
	}//end testAFleetBaseReadsTheBundledManifest()

	/**
	 * An unknown kind and a failing lookup fall back to the blob.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-3.1
	 */
	public function testAnUnknownKindOrAFailingLookupFallsBack(): void {
		$blob = self::manifest(pages: [['id' => 'own']]);
		$this->assertSame(
			expected: $blob,
			actual: $this->resolver()->manifestOf(version: ['manifest' => $blob, 'baseRef' => ['kind' => 'elsewhere', 'id' => 'x']])
		);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('searchObjectsBySlug')->willThrowException(new \RuntimeException('register down'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$resolver = new ManifestBaseResolver(records: new RegisterRecordLookup(objectService: $objects, logger: $logger), logger: $logger);
		$this->assertSame(
			expected: $blob,
			actual: $resolver->manifestOf(version: ['manifest' => $blob, 'baseRef' => ['kind' => 'template', 'id' => 'tpl']])
		);
	}//end testAnUnknownKindOrAFailingLookupFallsBack()
}//end class
