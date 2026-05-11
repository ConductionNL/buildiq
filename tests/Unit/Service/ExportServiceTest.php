<?php

declare(strict_types=1);

namespace OCA\OpenBuilt\Tests\Unit\Service;

use OCA\OpenBuilt\Service\ExportService;
use OCA\OpenBuilt\Service\PlaceholderResolver;
use OCP\Files\IAppData;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
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
        $this->tmpDir = sys_get_temp_dir().'/openbuilt-exportservice-test-'.uniqid();
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

    private function buildService(): ExportService
    {
        $appData = $this->createStub(IAppData::class);
        $container = $this->createStub(ContainerInterface::class);
        return new ExportService(
            $appData,
            new PlaceholderResolver(),
            new NullLogger(),
            $container
        );
    }//end buildService()

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
