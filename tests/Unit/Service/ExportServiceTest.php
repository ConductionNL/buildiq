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
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IAppData;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * Unit tests for {@see ExportService} — ZIP packaging contract.
 */
final class ExportServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir().'/openbuild-exportservice-test-'.uniqid();
        mkdir($this->tmpDir, 0o755, true);
    }//end setUp()

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
        parent::tearDown();
    }//end tearDown()

    /**
     * Resolver runs in-place across text files.
     */
    public function testResolvePlaceholdersRewritesTextFiles(): void
    {
        $service = $this->buildService();
        file_put_contents($this->tmpDir.'/info.xml', '<id>app-template</id>');
        $service->resolvePlaceholders($this->tmpDir, [
            'appId' => 'demo-app',
            'appNamespace' => 'DemoApp',
        ]);
        self::assertSame('<id>demo-app</id>', file_get_contents($this->tmpDir.'/info.xml'));
    }//end testResolvePlaceholdersRewritesTextFiles()

    /**
     * listFilesSorted yields a stable, lexicographically sorted set.
     */
    public function testListFilesSortedIsStable(): void
    {
        $service = $this->buildService();
        file_put_contents($this->tmpDir.'/zeta.txt', 'z');
        file_put_contents($this->tmpDir.'/alpha.txt', 'a');
        mkdir($this->tmpDir.'/sub');
        file_put_contents($this->tmpDir.'/sub/mid.txt', 'm');

        $files = $service->listFilesSorted($this->tmpDir);
        self::assertSame(['alpha.txt', 'sub/mid.txt', 'zeta.txt'], $files);
    }//end testListFilesSortedIsStable()

    /**
     * ZIP packaging produces an archive containing the expected entries.
     */
    public function testPackageZipProducesReadableArchive(): void
    {
        $service = $this->buildService();
        file_put_contents($this->tmpDir.'/hello.txt', 'world');

        $zipPath = $service->packageZip($this->tmpDir, 'test-uuid');
        self::assertFileExists($zipPath);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath) === true);
        $contents = $zip->getFromName('hello.txt');
        $zip->close();
        self::assertSame('world', $contents);
    }//end testPackageZipProducesReadableArchive()

    private function buildService(
        ?RegisterMapper $registerMapper=null,
        ?SchemaMapper $schemaMapper=null,
        ?ObjectService $objectService=null,
    ): ExportService {
        $appData = $this->createStub(IAppData::class);
        $bundler = new DataRegisterExportBundler(
            $registerMapper ?? $this->createMock(RegisterMapper::class),
            $schemaMapper ?? $this->createMock(SchemaMapper::class),
            $objectService ?? $this->createMock(ObjectService::class),
            new NullLogger()
        );

        return new ExportService(
            $appData,
            new PlaceholderResolver(),
            new NullLogger(),
            $bundler
        );
    }//end buildService()

    /**
     * Build a Register mock resolving the given schema ids.
     *
     * @param array<int,int> $schemaIds Schema ids the register carries.
     *
     * @return Register&\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildRegisterMock(array $schemaIds): Register
    {
        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn(7);
        $register->method('getSchemas')->willReturn($schemaIds);
        return $register;
    }//end buildRegisterMock()

    /**
     * Build a Schema mock with the given definition fields.
     *
     * @param string              $slug       Schema slug.
     * @param string              $title      Schema title.
     * @param array<int,string>   $required   Required property names.
     * @param array<string,mixed> $properties JSON Schema properties map.
     *
     * @return Schema&\PHPUnit\Framework\MockObject\MockObject
     */
    private function buildSchemaMock(string $slug, string $title, array $required, array $properties): Schema
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('getSlug')->willReturn($slug);
        $schema->method('getTitle')->willReturn($title);
        $schema->method('getDescription')->willReturn('A '.$title.' record.');
        $schema->method('getRequired')->willReturn($required);
        $schema->method('getProperties')->willReturn($properties);
        return $schema;
    }//end buildSchemaMock()

    /**
     * REQ (openbuild-exporter, data-registers-runtime): bound data
     * registers' schema definitions are bundled into every export.
     *
     * @return void
     */
    public function testBundleDataRegisterSchemasWritesSchemaDefsFileForBoundRegister(): void
    {
        $register = $this->buildRegisterMock(schemaIds: [42]);
        $schema   = $this->buildSchemaMock(
            slug: 'spectr-company',
            title: 'Company',
            required: ['name'],
            properties: ['name' => ['type' => 'string']]
        );

        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->with('spectr')->willReturn($register);

        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->with(42)->willReturn($schema);

        $service = $this->buildService(registerMapper: $registerMapper, schemaMapper: $schemaMapper);
        $service->bundleDataRegisterSchemas($this->tmpDir, [['register' => 'spectr']]);

        $schemaFile = $this->tmpDir.'/lib/Settings/data-registers/spectr.schema.json';
        self::assertFileExists($schemaFile);

        $decoded = json_decode((string) file_get_contents($schemaFile), true);
        self::assertArrayHasKey('spectr-company', $decoded['components']['schemas']);
        self::assertSame('Company', $decoded['components']['schemas']['spectr-company']['title']);
        self::assertSame(['name'], $decoded['components']['schemas']['spectr-company']['required']);
        self::assertSame(
            ['name' => ['type' => 'string']],
            $decoded['components']['schemas']['spectr-company']['properties']
        );

        // Namespaced away from an app-owned-looking filename (Decision 5).
        self::assertFileDoesNotExist($this->tmpDir.'/lib/Settings/spectr_register.json');
    }//end testBundleDataRegisterSchemasWritesSchemaDefsFileForBoundRegister()

    /**
     * REQ (openbuild-exporter, data-registers-runtime): row data is only
     * bundled when a binding's `includeData` is explicitly true.
     *
     * @return void
     */
    public function testBundleDataRegisterSchemasWritesSeedDataOnlyWhenIncludeDataTrue(): void
    {
        $register = $this->buildRegisterMock(schemaIds: [42]);
        $schema   = $this->buildSchemaMock(slug: 'spectr-company', title: 'Company', required: [], properties: []);

        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->with('spectr')->willReturn($register);

        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->with(42)->willReturn($schema);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('searchObjects')->willReturn([
            ['id' => 'row-1', 'name' => 'Acme'],
            ['id' => 'row-2', 'name' => 'Beta'],
        ]);

        $service = $this->buildService(
            registerMapper: $registerMapper,
            schemaMapper: $schemaMapper,
            objectService: $objectService
        );

        // includeData: true — both files present.
        $service->bundleDataRegisterSchemas($this->tmpDir, [['register' => 'spectr', 'includeData' => true]]);

        $schemaFile = $this->tmpDir.'/lib/Settings/data-registers/spectr.schema.json';
        $seedFile   = $this->tmpDir.'/lib/Settings/data-registers/spectr.seed-data.json';
        self::assertFileExists($schemaFile);
        self::assertFileExists($seedFile);

        $decodedSeed = json_decode((string) file_get_contents($seedFile), true);
        self::assertArrayHasKey('_comment', $decodedSeed);
        self::assertCount(2, $decodedSeed['objects']);
        self::assertSame('Acme', $decodedSeed['objects'][0]['name']);
    }//end testBundleDataRegisterSchemasWritesSeedDataOnlyWhenIncludeDataTrue()

    /**
     * REQ (openbuild-exporter, data-registers-runtime): includeData omitted
     * (or explicitly false) defaults to schema-defs-only — no seed-data file.
     *
     * @return void
     */
    public function testBundleDataRegisterSchemasOmitsSeedDataWhenIncludeDataAbsent(): void
    {
        $register = $this->buildRegisterMock(schemaIds: [42]);
        $schema   = $this->buildSchemaMock(slug: 'spectr-company', title: 'Company', required: [], properties: []);

        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->method('find')->with('spectr')->willReturn($register);

        $schemaMapper = $this->createMock(SchemaMapper::class);
        $schemaMapper->method('find')->with(42)->willReturn($schema);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->expects(self::never())->method('searchObjects');

        $service = $this->buildService(
            registerMapper: $registerMapper,
            schemaMapper: $schemaMapper,
            objectService: $objectService
        );

        // No dataRegisters[].includeData at all — request body omitted it.
        $service->bundleDataRegisterSchemas($this->tmpDir, [['register' => 'spectr']]);

        self::assertFileExists($this->tmpDir.'/lib/Settings/data-registers/spectr.schema.json');
        self::assertFileDoesNotExist($this->tmpDir.'/lib/Settings/data-registers/spectr.seed-data.json');
    }//end testBundleDataRegisterSchemasOmitsSeedDataWhenIncludeDataAbsent()

    /**
     * REQ (openbuild-exporter): an Application with no `dataRegisters`
     * produces an export tree with no `lib/Settings/data-registers/`
     * directory at all.
     *
     * @return void
     */
    public function testBundleDataRegisterSchemasNoDirectoryWhenDataRegistersEmpty(): void
    {
        $registerMapper = $this->createMock(RegisterMapper::class);
        $registerMapper->expects(self::never())->method('find');

        $service = $this->buildService(registerMapper: $registerMapper);
        $service->bundleDataRegisterSchemas($this->tmpDir, []);

        self::assertDirectoryDoesNotExist($this->tmpDir.'/lib/Settings/data-registers');
    }//end testBundleDataRegisterSchemasNoDirectoryWhenDataRegistersEmpty()

    /**
     * Non-Goal precedent: a `dataRegisters[].register` slug that does not
     * resolve in OR (dangling reference) is skipped silently — no file is
     * written for it, and it does not block other, resolvable bindings.
     *
     * @return void
     */
    public function testBundleDataRegisterSchemasSkipsDanglingRegisterReference(): void
    {
        $goodRegister = $this->buildRegisterMock(schemaIds: [1]);
        $goodSchema   = $this->buildSchemaMock(slug: 'ok-schema', title: 'Ok', required: [], properties: []);

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

        $service = $this->buildService(registerMapper: $registerMapper, schemaMapper: $schemaMapper);
        $service->bundleDataRegisterSchemas($this->tmpDir, [
            ['register' => 'ghost-register'],
            ['register' => 'spectr'],
        ]);

        self::assertFileDoesNotExist($this->tmpDir.'/lib/Settings/data-registers/ghost-register.schema.json');
        self::assertFileExists($this->tmpDir.'/lib/Settings/data-registers/spectr.schema.json');
    }//end testBundleDataRegisterSchemasSkipsDanglingRegisterReference()

    private function rrmdir(string $dir): void
    {
        if (is_dir($dir) === false) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() === true) {
                rmdir((string) $entry->getPathname());
            } else {
                unlink((string) $entry->getPathname());
            }
        }
        rmdir($dir);
    }//end rrmdir()
}//end class
