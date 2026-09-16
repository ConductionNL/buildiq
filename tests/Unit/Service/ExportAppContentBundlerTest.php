<?php

/**
 * Unit tests for ExportAppContentBundler.
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
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ExportAppContentBundler;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolution;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The exported tree carries the application's pages, menu, schemas and records.
 */
final class ExportAppContentBundlerTest extends TestCase {
	private const APP_UUID = '11111111-1111-4111-8111-111111111111';

	private const PROD_UUID = '22222222-2222-4222-8222-222222222222';

	private const DEV_UUID = '33333333-3333-4333-8333-333333333333';

	/**
	 * The scratch tree of the current test.
	 *
	 * @var string
	 */
	private string $root = '';

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/bq-content-test-' . bin2hex(random_bytes(4));
		mkdir($this->root . '/src', 0o755, true);
		mkdir($this->root . '/lib/Settings', 0o755, true);
		file_put_contents(
			$this->root . '/src/manifest.json',
			json_encode(['id' => 'demo-app', 'name' => 'Demo', 'version' => '0.1.0', 'navigation' => [], 'pages' => [], 'deepLinks' => []])
		);
		file_put_contents(
			$this->root . '/lib/Settings/demo_app_register.json',
			json_encode([
				'openapi' => '3.0.0',
				'info' => ['title' => 'Template', 'version' => '0.1.0'],
				'components' => ['schemas' => ['example' => ['slug' => 'example']]],
			])
		);
	}//end setUp()

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
		parent::tearDown();
	}//end tearDown()

	/**
	 * The development version is picked by slug even though production shares its semver.
	 */
	public function testResolveSourcePicksTheVersionBySlug(): void {
		$source = $this->bundler()->resolveSource(applicationUuid: self::APP_UUID, semver: '0.1.0', versionSlug: 'development');

		self::assertNotNull($source);
		self::assertSame('development', $source['version']['slug']);
	}//end testResolveSourcePicksTheVersionBySlug()

	/**
	 * Without a slug, a shared semver resolves to the production version.
	 */
	public function testResolveSourcePrefersProductionForASharedSemver(): void {
		$source = $this->bundler()->resolveSource(applicationUuid: self::APP_UUID, semver: '0.1.0');

		self::assertNotNull($source);
		self::assertSame('production', $source['version']['slug']);
	}//end testResolveSourcePrefersProductionForASharedSemver()

	/**
	 * An unknown application resolves to null, so the job can fail loudly.
	 */
	public function testResolveSourceReturnsNullForAnUnknownApplication(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn(null);

		self::assertNull($this->bundler(objectService: $objectService)->resolveSource(applicationUuid: 'nope', semver: '1.0.0'));
	}//end testResolveSourceReturnsNullForAnUnknownApplication()

	/**
	 * The standalone app's manifest gets the real pages and menu, with the
	 * register and schema renamed to the exported app's own names.
	 */
	public function testBundleWritesThePagesAndMenuIntoTheAppManifest(): void {
		$bundler = $this->bundler();
		$source = $bundler->resolveSource(applicationUuid: self::APP_UUID, semver: '0.1.0', versionSlug: 'development');
		$summary = $bundler->bundle(rootDir: $this->root, source: $source, appId: 'demo-app', semver: '0.1.0', includeSeedData: false);

		$manifest = $this->json('src/manifest.json');
		self::assertSame('demo-app', $manifest['id'], 'the identity comes from the template');
		self::assertCount(2, $manifest['pages']);
		self::assertCount(1, $manifest['menu']);
		self::assertArrayNotHasKey('navigation', $manifest);
		self::assertSame('demo-app', $manifest['pages'][1]['config']['register']);
		self::assertSame('message', $manifest['pages'][1]['config']['schema']);
		self::assertSame(['pages' => 2, 'menu' => 1, 'schemas' => 1, 'records' => 0], $summary);
	}//end testBundleWritesThePagesAndMenuIntoTheAppManifest()

	/**
	 * The register file carries the app's schemas instead of the template example.
	 */
	public function testBundleWritesTheSchemasIntoTheAppRegister(): void {
		$this->bundleDevelopment(includeSeedData: false);

		$register = $this->json('lib/Settings/demo_app_register.json');
		self::assertSame(['message'], array_keys($register['components']['schemas']));
		self::assertSame(['body'], $register['components']['schemas']['message']['required']);
		self::assertSame(['message'], $register['components']['registers']['demo-app']['schemas']);
		self::assertSame('Demo things', $register['info']['title']);
		self::assertArrayNotHasKey('objects', $register['components']);
	}//end testBundleWritesTheSchemasIntoTheAppRegister()

	/**
	 * The archive root holds the layout another Buildiq instance imports.
	 */
	public function testBundleWritesThePortableLayout(): void {
		$this->bundleDevelopment(includeSeedData: false);

		self::assertSame('demo-things', $this->json('openbuild-app.json')['slug']);
		self::assertCount(2, $this->json('manifest.json')['pages']);
		self::assertSame('message', $this->json('schemas/message.json')['slug']);
		self::assertFileDoesNotExist($this->root . '/data/message.jsonl');
		self::assertStringContainsString('# Demo things', (string)file_get_contents($this->root . '/README.md'));
	}//end testBundleWritesThePortableLayout()

	/**
	 * With seed data on, the records go in both layouts, stripped of their source identity.
	 */
	public function testBundleWritesRecordsOnlyWhenSeedDataIsIncluded(): void {
		$this->bundleDevelopment(includeSeedData: true);

		$lines = array_values(array_filter(explode("\n", (string)file_get_contents($this->root . '/data/message.jsonl'))));
		self::assertCount(2, $lines);
		$first = json_decode($lines[0], true);
		// Sorted by record key, so the UUID-keyed record comes before `hello`.
		self::assertSame('World', $first['body']);
		self::assertArrayNotHasKey('@self', $first);
		self::assertArrayNotHasKey('id', $first);

		$objects = $this->json('lib/Settings/demo_app_register.json')['components']['objects'];
		self::assertCount(2, $objects);
		self::assertSame('aaaaaaaa-0000-4000-8000-000000000002', $objects[0]['@self']['slug'], 'a record without a slug is keyed by its UUID');
		self::assertSame(['register' => 'demo-app', 'schema' => 'message', 'slug' => 'hello'], $objects[1]['@self']);
	}//end testBundleWritesRecordsOnlyWhenSeedDataIsIncluded()

	/**
	 * Run the bundler on the development version.
	 *
	 * @param bool $includeSeedData Whether to include records.
	 *
	 * @return void
	 */
	private function bundleDevelopment(bool $includeSeedData): void {
		$bundler = $this->bundler();
		$source = $bundler->resolveSource(applicationUuid: self::APP_UUID, semver: '0.1.0', versionSlug: 'development');
		self::assertNotNull($source);
		$bundler->bundle(rootDir: $this->root, source: $source, appId: 'demo-app', semver: '0.1.0', includeSeedData: $includeSeedData);
	}//end bundleDevelopment()

	/**
	 * Decode a JSON file from the tree.
	 *
	 * @param string $relative The relative path.
	 *
	 * @return array<string,mixed> The decoded file.
	 */
	private function json(string $relative): array {
		$path = $this->root . '/' . $relative;
		self::assertFileExists($path);
		return json_decode((string)file_get_contents($path), true);
	}//end json()

	/**
	 * Build the bundler over a fake instance holding one app with two versions.
	 *
	 * @param ObjectServiceInterface|null $objectService Override for the object service.
	 *
	 * @return ExportAppContentBundler
	 */
	private function bundler(?ObjectServiceInterface $objectService = null): ExportAppContentBundler {
		if ($objectService === null) {
			$objectService = $this->createMock(ObjectServiceInterface::class);
			$objectService->method('find')->willReturn(
				$this->entity([
					'id' => self::APP_UUID,
					'slug' => 'demo-things',
					'name' => 'Demo things',
					'description' => 'Keeps demo things.',
					'productionVersion' => self::PROD_UUID,
				])
			);
			$objectService->method('searchObjectsBySlug')->willReturn([
				$this->entity($this->version(uuid: self::DEV_UUID, slug: 'development')),
				$this->entity(['id' => 'other', 'application' => 'another-app', 'slug' => 'development', 'semver' => '0.1.0']),
				$this->entity($this->version(uuid: self::PROD_UUID, slug: 'production')),
			]);
			$objectService->method('searchObjects')->willReturn([
				$this->entity(['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'slug' => 'hello', 'body' => 'Hello', '@self' => ['id' => 'x']]),
				$this->entity(['id' => 'aaaaaaaa-0000-4000-8000-000000000002', 'body' => 'World']),
			]);
		}

		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(40);
		$register->method('getSchemas')->willReturn([7]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturnCallback(
			static function ($slug) use ($register): Register {
				if ($slug !== 'openbuild-demo-things-development') {
					throw new \RuntimeException('no register ' . $slug);
				}

				return $register;
			}
		);

		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(7);
		$schema->method('getSlug')->willReturn('demo-things-development-message');
		$schema->method('getTitle')->willReturn('Message');
		$schema->method('getDescription')->willReturn('');
		$schema->method('getVersion')->willReturn('');
		$schema->method('getRequired')->willReturn(['body']);
		$schema->method('getProperties')->willReturn(['body' => ['type' => 'string']]);
		$schema->method('getConfiguration')->willReturn(null);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$resolver = $this->createMock(RegisterSlugResolverInterface::class);
		$resolver->method('resolve')->willReturn(
			new RegisterSlugResolution('buildiq', 'buildiq', RegisterSlugResolution::RESOLVED, ['buildiq'], ['buildiq'])
		);

		return new ExportAppContentBundler($objectService, $registerMapper, $schemaMapper, $resolver, new NullLogger());
	}//end bundler()

	/**
	 * A version row of the demo app.
	 *
	 * @param string $uuid The version UUID.
	 * @param string $slug The version slug.
	 *
	 * @return array<string,mixed> The row.
	 */
	private function version(string $uuid, string $slug): array {
		$register = 'openbuild-demo-things-' . $slug;
		$pages = [
			['id' => 'Dashboard', 'route' => '/', 'type' => 'dashboard'],
			['id' => 'Messages', 'route' => '/messages', 'type' => 'index', 'config' => ['register' => $register, 'schema' => 'demo-things-' . $slug . '-message']],
		];
		if ($slug === 'production') {
			$pages = [];
		}

		return [
			'id' => $uuid,
			'application' => self::APP_UUID,
			'slug' => $slug,
			'semver' => '0.1.0',
			'register' => $register,
			'manifest' => [
				'version' => '1.0.0',
				'menu' => [['id' => 'messages', 'label' => 'Messages', 'route' => 'Messages']],
				'pages' => $pages,
			],
		];
	}//end version()

	/**
	 * An object entity double serialising to the given data.
	 *
	 * @param array<string,mixed> $data The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);
		return $entity;
	}//end entity()
}//end class
