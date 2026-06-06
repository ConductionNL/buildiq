<?php

/**
 * OpenBuild Exporter end-to-end integration test
 *
 * Exercises the file-generation pipeline against the real embedded template
 * snapshot: copy → placeholder-resolve → package ZIP. Asserts the produced
 * tree has no unresolved tokens, is byte-equivalent across re-runs
 * (REQ-OBEX-008), and carries no `openbuild` dependency reference in its
 * dependency manifests (REQ-OBEX-010).
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Integration;

use OCA\OpenBuild\Service\ExportService;
use OCA\OpenBuild\Service\PlaceholderResolver;
use OCP\Files\IAppData;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * End-to-end exporter tests against the embedded template snapshot.
 */
final class ExporterEndToEndTest extends TestCase
{
    private string $templateRoot;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templateRoot = dirname(__DIR__, 2).'/lib/Resources/template';
        $this->workDir      = sys_get_temp_dir().'/openbuild-e2e-'.uniqid();
        mkdir($this->workDir, 0o755, true);
    }//end setUp()

    protected function tearDown(): void
    {
        $this->rrmdir($this->workDir);
        parent::tearDown();
    }//end tearDown()

    /**
     * The exporter resolves the template into a tree with no unresolved
     * placeholders and no leftover `openbuild` dependency reference.
     *
     * @return void
     */
    public function testResolvedTreeIsStandaloneAndComplete(): void
    {
        if (is_dir($this->templateRoot) === false) {
            self::markTestSkipped('Embedded template snapshot not present.');
        }

        $service = $this->buildService();
        $dest    = $this->workDir.'/tree';
        mkdir($dest, 0o755, true);

        $service->copyTemplate($this->templateRoot, $dest);
        $service->resolvePlaceholders($dest, $this->context());

        // No unresolved {{token}} in any text file.
        foreach ($service->listFilesSorted($dest) as $relative) {
            if ($service->isBinary($dest.'/'.$relative) === true) {
                continue;
            }

            $contents = (string) file_get_contents($dest.'/'.$relative);
            self::assertDoesNotMatchRegularExpression(
                '/\{\{[a-zA-Z]+\}\}/',
                $contents,
                'Unresolved placeholder left in '.$relative
            );
        }

        // REQ-OBEX-010: dependency manifests must not reference openbuild.
        foreach (['composer.json', 'package.json', 'appinfo/info.xml'] as $manifest) {
            $path = $dest.'/'.$manifest;
            if (file_exists($path) === false) {
                continue;
            }

            self::assertStringNotContainsStringIgnoringCase(
                'openbuild',
                (string) file_get_contents($path),
                $manifest.' must not reference openbuild as a dependency'
            );
        }
    }//end testResolvedTreeIsStandaloneAndComplete()

    /**
     * REQ-OBEX-008: re-packaging the same resolved tree yields per-file
     * SHA-256 digests that are identical across runs.
     *
     * @return void
     */
    public function testReExportIsByteEquivalent(): void
    {
        if (is_dir($this->templateRoot) === false) {
            self::markTestSkipped('Embedded template snapshot not present.');
        }

        $service = $this->buildService();
        $dest    = $this->workDir.'/tree';
        mkdir($dest, 0o755, true);
        $service->copyTemplate($this->templateRoot, $dest);
        $service->resolvePlaceholders($dest, $this->context());

        $first  = $service->packageZip($dest, 'run-a');
        $second = $service->packageZip($dest, 'run-b');

        self::assertSame(
            $this->zipDigests($first),
            $this->zipDigests($second),
            'Re-export of the same tree must produce identical per-file digests'
        );
    }//end testReExportIsByteEquivalent()

    /**
     * Compute a map of entry-name → SHA-256 for every file in a ZIP.
     *
     * @param string $zipPath ZIP path.
     *
     * @return array<string,string> Entry-name → digest.
     */
    private function zipDigests(string $zipPath): array
    {
        $digests = [];
        $zip     = new ZipArchive();
        $zip->open($zipPath);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name           = (string) $zip->getNameIndex($i);
            $digests[$name] = hash('sha256', (string) $zip->getFromIndex($i));
        }

        $zip->close();
        ksort($digests);
        return $digests;
    }//end zipDigests()

    /**
     * Build the placeholder context for a sample export.
     *
     * @return array<string,string> Context map.
     */
    private function context(): array
    {
        return [
            'appId'          => 'melding-systeem',
            'appNamespace'   => 'MeldingSysteem',
            'appName'        => 'Melding Systeem',
            'appDescription' => 'Exported from OpenBuild',
            'appVersion'     => '1.0.0',
            'authorName'     => 'OpenBuild Citizen Developer',
            'authorEmail'    => 'dev@conduction.nl',
            'license'        => 'EUPL-1.2',
        ];
    }//end context()

    /**
     * Build an ExportService wired with stubbed IAppData.
     *
     * @return ExportService Service under test.
     */
    private function buildService(): ExportService
    {
        $appData = $this->createStub(IAppData::class);
        return new ExportService($appData, new PlaceholderResolver(), new NullLogger());
    }//end buildService()

    /**
     * Recursive directory removal helper.
     *
     * @param string $dir Directory to remove.
     *
     * @return void
     */
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
