<?php

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\DataRegisterExportBundler;
use OCA\OpenBuild\Service\ExportService;
use OCA\OpenBuild\Service\PlaceholderResolver;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IAppData;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * Unit tests for {@see ExportService}.
 *
 * Every test drives one of the three real entry points — generateAppZip(),
 * buildScaffoldMap() or scratchTreeDir(). The pipeline steps (copyTemplate,
 * resolvePlaceholders, packageZip, listFilesSorted, isBinary,
 * prepareScratchDir, getOrCreateAppDataDir, rrmdir,
 * bundleDataRegisterSchemas) are private implementation detail and are
 * asserted through their effect on what those entry points produce.
 */
final class ExportServiceTest extends TestCase {
	/**
	 * Paths created by the service under test, removed in tearDown().
	 *
	 * @var array<int,string>
	 */
	private array $litter = [];

	protected function tearDown(): void {
		foreach ($this->litter as $path) {
			if (is_dir($path) === true) {
				$this->rrmdir($path);
				continue;
			}

			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		$this->litter = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * REQ-OBEX-003: the exported tree is the template with every `{{token}}`
	 * resolved against the export context.
	 */
	public function testGenerateAppZipResolvesPlaceholdersAcrossTheTree(): void {
		$entries = $this->export();

		self::assertArrayHasKey('appinfo/info.xml', $entries);
		self::assertStringContainsString('<id>demo-app</id>', $entries['appinfo/info.xml']);
		self::assertStringContainsString('DemoApp', $entries['appinfo/info.xml']);

		foreach ($entries as $relative => $contents) {
			if ($this->hasBinaryExtension($relative) === true) {
				continue;
			}

			self::assertDoesNotMatchRegularExpression(
				'/\{\{[a-zA-Z]+\}\}/',
				$contents,
				'Unresolved placeholder left in ' . $relative
			);
		}
	}//end testGenerateAppZipResolvesPlaceholdersAcrossTheTree()

	/**
	 * REQ-OBEX-008: archive entries are written in a stable, case-sensitive
	 * ASCII sort, so two exports of the same tree line up entry-for-entry.
	 */
	public function testGenerateAppZipOrdersArchiveEntriesLexicographically(): void {
		$names = array_keys($this->export());

		$sorted = $names;
		sort($sorted, SORT_STRING);

		self::assertSame($sorted, $names, 'ZIP entries must be in stable ASCII order');
		self::assertNotEmpty($names);
	}//end testGenerateAppZipOrdersArchiveEntriesLexicographically()

	/**
	 * The snapshot bookkeeping files are artefacts of OpenBuild, not of the
	 * produced app, and must never reach the exported tree.
	 */
	public function testGenerateAppZipOmitsSnapshotHelperFiles(): void {
		$entries = $this->export();

		self::assertArrayNotHasKey('.snapshot-meta.json', $entries);
		self::assertArrayNotHasKey('.path-manifest.txt', $entries);
	}//end testGenerateAppZipOmitsSnapshotHelperFiles()

	/**
	 * REQ (openbuild-exporter, data-registers-runtime): bound data
	 * registers' schema definitions are bundled into every export.
	 */
	public function testGenerateAppZipBundlesSchemaDefsForBoundRegister(): void {
		$register = $this->buildRegisterMock(schemaIds: [42]);
		$schema = $this->buildSchemaMock(
			slug: 'spectr-company',
			title: 'Company',
			required: ['name'],
			properties: ['name' => ['type' => 'string']]
		);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->with('spectr')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->with(42)->willReturn($schema);

		$entries = $this->export(
			dataRegisters: [['register' => 'spectr']],
			service: $this->buildService(registerMapper: $registerMapper, schemaMapper: $schemaMapper)
		);

		self::assertArrayHasKey('lib/Settings/data-registers/spectr.schema.json', $entries);

		$decoded = json_decode($entries['lib/Settings/data-registers/spectr.schema.json'], true);
		self::assertArrayHasKey('spectr-company', $decoded['components']['schemas']);
		self::assertSame('Company', $decoded['components']['schemas']['spectr-company']['title']);
		self::assertSame(['name'], $decoded['components']['schemas']['spectr-company']['required']);
		self::assertSame(
			['name' => ['type' => 'string']],
			$decoded['components']['schemas']['spectr-company']['properties']
		);

		// Namespaced away from an app-owned-looking filename (Decision 5).
		self::assertArrayNotHasKey('lib/Settings/spectr_register.json', $entries);
	}//end testGenerateAppZipBundlesSchemaDefsForBoundRegister()

	/**
	 * REQ (openbuild-exporter, data-registers-runtime): row data is only
	 * bundled when a binding's `includeData` is explicitly true.
	 */
	public function testGenerateAppZipWritesSeedDataOnlyWhenIncludeDataTrue(): void {
		$register = $this->buildRegisterMock(schemaIds: [42]);
		$schema = $this->buildSchemaMock(slug: 'spectr-company', title: 'Company', required: [], properties: []);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->with('spectr')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->with(42)->willReturn($schema);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('searchObjects')->willReturn([
			['id' => 'row-1', 'name' => 'Acme'],
			['id' => 'row-2', 'name' => 'Beta'],
		]);

		$entries = $this->export(
			dataRegisters: [['register' => 'spectr', 'includeData' => true]],
			service: $this->buildService(
				registerMapper: $registerMapper,
				schemaMapper: $schemaMapper,
				objectService: $objectService
			)
		);

		self::assertArrayHasKey('lib/Settings/data-registers/spectr.schema.json', $entries);
		self::assertArrayHasKey('lib/Settings/data-registers/spectr.seed-data.json', $entries);

		$decodedSeed = json_decode($entries['lib/Settings/data-registers/spectr.seed-data.json'], true);
		self::assertArrayHasKey('_comment', $decodedSeed);
		self::assertCount(2, $decodedSeed['objects']);
		self::assertSame('Acme', $decodedSeed['objects'][0]['name']);
	}//end testGenerateAppZipWritesSeedDataOnlyWhenIncludeDataTrue()

	/**
	 * REQ (openbuild-exporter, data-registers-runtime): includeData omitted
	 * (or explicitly false) defaults to schema-defs-only — no seed-data file,
	 * and no row read against OpenRegister at all.
	 */
	public function testGenerateAppZipOmitsSeedDataWhenIncludeDataAbsent(): void {
		$register = $this->buildRegisterMock(schemaIds: [42]);
		$schema = $this->buildSchemaMock(slug: 'spectr-company', title: 'Company', required: [], properties: []);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->with('spectr')->willReturn($register);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->with(42)->willReturn($schema);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects(self::never())->method('searchObjects');

		// No dataRegisters[].includeData at all — the request body omitted it.
		$entries = $this->export(
			dataRegisters: [['register' => 'spectr']],
			service: $this->buildService(
				registerMapper: $registerMapper,
				schemaMapper: $schemaMapper,
				objectService: $objectService
			)
		);

		self::assertArrayHasKey('lib/Settings/data-registers/spectr.schema.json', $entries);
		self::assertArrayNotHasKey('lib/Settings/data-registers/spectr.seed-data.json', $entries);
	}//end testGenerateAppZipOmitsSeedDataWhenIncludeDataAbsent()

	/**
	 * REQ (openbuild-exporter): an Application with no `dataRegisters`
	 * produces an export tree with no `lib/Settings/data-registers/`
	 * entries at all — and never touches OpenRegister.
	 */
	public function testGenerateAppZipWritesNoDataRegisterDirectoryWhenNoneBound(): void {
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->expects(self::never())->method('find');

		$entries = $this->export(
			dataRegisters: [],
			service: $this->buildService(registerMapper: $registerMapper)
		);

		$bundled = array_filter(
			array_keys($entries),
			static fn (string $path): bool => str_starts_with($path, 'lib/Settings/data-registers/')
		);

		self::assertSame([], array_values($bundled));
	}//end testGenerateAppZipWritesNoDataRegisterDirectoryWhenNoneBound()

	/**
	 * Non-Goal precedent: a `dataRegisters[].register` slug that does not
	 * resolve in OR (dangling reference) is skipped silently — nothing is
	 * bundled for it, and it does not block other, resolvable bindings.
	 */
	public function testGenerateAppZipSkipsDanglingRegisterReference(): void {
		$goodRegister = $this->buildRegisterMock(schemaIds: [1]);
		$goodSchema = $this->buildSchemaMock(slug: 'ok-schema', title: 'Ok', required: [], properties: []);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper
			->method('find')
			->willReturnCallback(function (string $slug) use ($goodRegister) {
				if ($slug === 'ghost-register') {
					throw new \RuntimeException('not found');
				}

				return $goodRegister;
			});

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->with(1)->willReturn($goodSchema);

		$entries = $this->export(
			dataRegisters: [
				['register' => 'ghost-register'],
				['register' => 'spectr'],
			],
			service: $this->buildService(registerMapper: $registerMapper, schemaMapper: $schemaMapper)
		);

		self::assertArrayNotHasKey('lib/Settings/data-registers/ghost-register.schema.json', $entries);
		self::assertArrayHasKey('lib/Settings/data-registers/spectr.schema.json', $entries);
	}//end testGenerateAppZipSkipsDanglingRegisterReference()

	/**
	 * buildScaffoldMap() returns the same resolved tree as an in-memory map,
	 * carrying the NC-app files that make a config-set repo app-store
	 * installable and standalone on nc-vue.
	 */
	public function testBuildScaffoldMapProducesAnInstallableStandaloneApp(): void {
		$map = $this->buildService()->buildScaffoldMap(context: $this->context());

		$this->assertArrayHasKey('appinfo/info.xml', $map);
		$this->assertArrayHasKey('package.json', $map);
		$this->assertArrayHasKey('src/main.js', $map);

		// Placeholders resolved to the app.
		$this->assertStringContainsString('demo-app', $map['appinfo/info.xml']);
		// The build packages the nc-vue stack itself.
		$this->assertStringContainsString('@conduction/nextcloud-vue', $map['package.json']);
		// OpenRegister is declared its data-layer dependency.
		$this->assertStringContainsString('OpenRegister', $map['appinfo/info.xml']);
	}//end testBuildScaffoldMapProducesAnInstallableStandaloneApp()

	/**
	 * buildScaffoldMap() stages on disk but hands back only the map — it must
	 * leave no scratch tree behind, on the success path or on the throw path.
	 */
	public function testBuildScaffoldMapRemovesItsScratchDirectory(): void {
		$appId = 'cleanup-' . bin2hex(random_bytes(4));
		$context = $this->context();
		$context['appId'] = $appId;

		$map = $this->buildService()->buildScaffoldMap(context: $context);

		self::assertNotSame([], $map);
		self::assertSame(
			[],
			glob(sys_get_temp_dir() . '/openbuild-work/scaffold-' . $appId . '-*'),
			'buildScaffoldMap() must remove its scratch tree'
		);
	}//end testBuildScaffoldMapRemovesItsScratchDirectory()

	/**
	 * scratchTreeDir() is the pure, job-scoped path resolver RunExportJob uses
	 * to hand the generated tree to the GitHub push target: deterministic,
	 * distinct per job, and it never creates or wipes the directory itself.
	 */
	public function testScratchTreeDirIsAPureJobScopedPathResolver(): void {
		$service = $this->buildService();
		$jobUuid = 'probe-' . bin2hex(random_bytes(4));

		$path = $service->scratchTreeDir(jobUuid: $jobUuid);

		self::assertSame(sys_get_temp_dir() . '/openbuild-work/' . $jobUuid, $path);
		self::assertSame($path, $service->scratchTreeDir(jobUuid: $jobUuid));
		self::assertNotSame($path, $service->scratchTreeDir(jobUuid: $jobUuid . '-other'));
		self::assertDirectoryDoesNotExist($path);
	}//end testScratchTreeDirIsAPureJobScopedPathResolver()

	/**
	 * Run a real export and return the archive as `path => contents`.
	 *
	 * @param array<int,mixed> $dataRegisters Bindings handed to the export.
	 * @param ExportService|null $service Service under test.
	 *
	 * @return array<string,string> Archive entries, in archive order.
	 */
	private function export(array $dataRegisters = [], ?ExportService $service = null): array {
		$jobUuid = 'unit-' . bin2hex(random_bytes(6));

		$zipPath = ($service ?? $this->buildService())->generateAppZip(
			applicationUuid: 'app-uuid',
			versionSlug: '1.2.3',
			context: $this->context(),
			jobUuid: $jobUuid,
			dataRegisters: $dataRegisters
		);

		$this->litter[] = $zipPath;
		$this->litter[] = sys_get_temp_dir() . '/openbuild-work/' . $jobUuid;

		self::assertFileExists($zipPath);

		$entries = [];
		$zip = new ZipArchive();
		self::assertTrue($zip->open($zipPath) === true);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$entries[$name] = (string)$zip->getFromIndex($i);
		}

		$zip->close();
		return $entries;
	}//end export()

	/**
	 * The placeholder context used by every export in this class.
	 *
	 * @return array<string,string> Context map.
	 */
	private function context(): array {
		return [
			'appId' => 'demo-app',
			'appNamespace' => 'DemoApp',
			'appName' => 'Demo App',
			'appDescription' => 'Exported from OpenBuild',
			'appVersion' => '1.2.3',
			'authorName' => 'Dev',
			'authorEmail' => 'dev@conduction.nl',
			'license' => 'agpl',
		];
	}//end context()

	/**
	 * The test's own view of which exported files are binary assets.
	 *
	 * Deliberately the test's own list, not the service's — asking the service
	 * what to skip would make the assertion agree with itself.
	 *
	 * @param string $path Relative path inside the export.
	 *
	 * @return bool True when the file is a binary asset.
	 */
	private function hasBinaryExtension(string $path): bool {
		$binary = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'zip', 'gz', 'tar', 'phar'];
		return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $binary, true);
	}//end hasBinaryExtension()

	private function buildService(
		?RegisterMapper $registerMapper = null,
		?SchemaMapper $schemaMapper = null,
		?ObjectServiceInterface $objectService = null,
	): ExportService {
		$appData = $this->createStub(IAppData::class);
		$bundler = new DataRegisterExportBundler(
			$registerMapper ?? $this->createMock(RegisterMapper::class),
			$schemaMapper ?? $this->createMock(SchemaMapper::class),
			$objectService ?? $this->createMock(ObjectServiceInterface::class),
			new NullLogger()
		);

		return new ExportService(
			$appData,
			new PlaceholderResolver(),
			new NullLogger(),
			$bundler,
			// The flow/agent bundler. Injected as a no-op double: these tests
			// are about the data-register path and the ZIP mechanics, and a
			// real one here would couple them to a second store.
			new \OCA\OpenBuild\Service\FlowAndAgentExportBundler(
				$this->createMock(\OCA\OpenRegister\Db\FlowMapper::class),
				$this->createMock(\OCA\OpenRegister\Service\ObjectService::class),
				new NullLogger()
			)
		);
	}//end buildService()

	/**
	 * Build a Register mock resolving the given schema ids.
	 *
	 * @param array<int,int> $schemaIds Schema ids the register carries.
	 *
	 * @return Register&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function buildRegisterMock(array $schemaIds): Register {
		$register = $this->createMock(Register::class);
		$register->method('getId')->willReturn(7);
		$register->method('getSchemas')->willReturn($schemaIds);
		return $register;
	}//end buildRegisterMock()

	/**
	 * Build a Schema mock with the given definition fields.
	 *
	 * @param string $slug Schema slug.
	 * @param string $title Schema title.
	 * @param array<int,string> $required Required property names.
	 * @param array<string,mixed> $properties JSON Schema properties map.
	 *
	 * @return Schema&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function buildSchemaMock(string $slug, string $title, array $required, array $properties): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getSlug')->willReturn($slug);
		$schema->method('getTitle')->willReturn($title);
		$schema->method('getDescription')->willReturn('A ' . $title . ' record.');
		$schema->method('getRequired')->willReturn($required);
		$schema->method('getProperties')->willReturn($properties);
		return $schema;
	}//end buildSchemaMock()

	private function rrmdir(string $dir): void {
		if (is_dir($dir) === false) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $entry) {
			if ($entry->isDir() === true) {
				rmdir((string)$entry->getPathname());
			} else {
				unlink((string)$entry->getPathname());
			}
		}

		rmdir($dir);
	}//end rrmdir()
}//end class
