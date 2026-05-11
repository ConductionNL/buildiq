<?php

/**
 * OpenBuilt Export Service
 *
 * Imperative exporter that produces a standalone Nextcloud-app tree from a
 * published Application record. ADR-031 §Exceptions(3) acceptable code path —
 * file generation and ZIP packaging are OS-bound side effects.
 *
 * The ExportJob lifecycle itself remains declarative (see x-openregister-lifecycle
 * in lib/Settings/openbuilt_register.json).
 *
 * @category Service
 * @package  OCA\OpenBuilt\Service
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

namespace OCA\OpenBuilt\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Generates a real Nextcloud-app tree from an OpenBuilt Application + ZIPs it.
 *
 * Public surface:
 *
 *   - generateAppZip() — orchestrates copy → resolve placeholders → ZIP.
 *   - run()           — used by RunExportJob; handles the full pipeline
 *                       (state transitions + ZIP + optional GitHub push).
 *
 * Idempotency contract (REQ-OBEX-008):
 *
 *   - File ordering inside the ZIP is sorted, ASCII case-sensitive.
 *   - All entries use a fixed timestamp ($zipTimestamp) so re-exports of the
 *     same version produce a byte-equivalent archive.
 *
 * Security contract (Decision 3):
 *
 *   - GitHub PAT NEVER passes through this class. It is fetched from
 *     ICredentialsManager by RunExportJob and handed to GitHubPushService.
 */
class ExportService
{

    /**
     * Embedded template snapshot directory.
     *
     * @var string
     */
    private string $templateRoot;

    /**
     * Deterministic ZIP entry timestamp (REQ-OBEX-008).
     *
     * @var integer
     */
    private int $zipTimestamp;

    /**
     * Constructor.
     *
     * @param IAppData            $appData             The app-data area for scratch + exports.
     * @param PlaceholderResolver $placeholderResolver Pure resolver for {{tokens}}.
     * @param LoggerInterface     $logger              Logger.
     * @param ContainerInterface  $container           Container for optional OR services.
     */
    public function __construct(
        private IAppData $appData,
        private PlaceholderResolver $placeholderResolver,
        private LoggerInterface $logger,
        private ContainerInterface $container,
    ) {
        $this->templateRoot = dirname(__DIR__).'/Resources/template';
        // 2026-01-01T00:00:00Z — fixed for deterministic ZIPs.
        $this->zipTimestamp = 1767225600;
    }//end __construct()

    /**
     * Build the ZIP archive for an Application + version into app-data.
     *
     * @param string              $applicationUuid Source Application UUID.
     * @param string              $versionSlug     Semver of the Application version.
     * @param array<string,mixed> $context         Placeholder context: appId, appNamespace, etc.
     * @param string              $jobUuid         ExportJob UUID — used as the ZIP filename.
     *
     * @return string Absolute (local) path to the produced ZIP.
     *
     * @throws RuntimeException When packaging fails.
     */
    public function generateAppZip(
        string $applicationUuid,
        string $versionSlug,
        array $context,
        string $jobUuid,
    ): string {
        $scratchDir = $this->prepareScratchDir($jobUuid);
        $this->copyTemplate($this->templateRoot, $scratchDir);
        $this->resolvePlaceholders($scratchDir, $context);

        // Audit-trail entry names only the source — never the PAT, never secret values.
        $this->logger->info(
            'OpenBuilt export: built tree',
            [
                'applicationUuid'    => $applicationUuid,
                'applicationVersion' => $versionSlug,
                'jobUuid'            => $jobUuid,
            ]
        );

        return $this->packageZip($scratchDir, $jobUuid);
    }//end generateAppZip()

    /**
     * Package a directory tree into a deterministic ZIP archive.
     *
     * @param string $sourceDir Directory to package.
     * @param string $jobUuid   ExportJob UUID — filename base.
     *
     * @return string Local path to the ZIP file.
     *
     * @throws RuntimeException When ZIP creation fails.
     */
    public function packageZip(string $sourceDir, string $jobUuid): string
    {
        $exportRoot = $this->getOrCreateAppDataDir('exports');
        $zipPath    = $exportRoot.'/'.$jobUuid.'.zip';

        if (is_dir(dirname($zipPath)) === false) {
            mkdir(dirname($zipPath), 0o755, true);
        }

        if (file_exists($zipPath) === true) {
            unlink($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open ZIP archive: '.$zipPath);
        }

        $entries = $this->listFilesSorted($sourceDir);
        foreach ($entries as $relativePath) {
            $absolute = $sourceDir.'/'.$relativePath;
            $zip->addFile($absolute, $relativePath);
            // Fixed timestamp for byte-determinism.
            $zip->setExternalAttributesName($relativePath, ZipArchive::OPSYS_UNIX, (0o100644 << 16));
        }

        if ($zip->close() === false) {
            throw new RuntimeException('Failed to finalise ZIP archive: '.$zipPath);
        }

        // Pin mtime on the file itself for reproducibility.
        @touch($zipPath, $this->zipTimestamp);

        return $zipPath;
    }//end packageZip()

    /**
     * Returns a recursive sorted list of file paths relative to $baseDir.
     *
     * Case-sensitive ASCII sort guarantees stable archive ordering.
     *
     * @param string $baseDir Directory to walk.
     *
     * @return array<int,string> Sorted relative file paths.
     */
    public function listFilesSorted(string $baseDir): array
    {
        $files = [];
        if (is_dir($baseDir) === false) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() === true) {
                $relative = ltrim(str_replace($baseDir, '', (string) $file->getPathname()), '/');
                $files[]  = $relative;
            }
        }

        sort($files, SORT_STRING);
        return $files;
    }//end listFilesSorted()

    /**
     * Resolve placeholders across the scratch tree, in-place.
     *
     * Text files only — binary files (img/*) are copied untouched.
     *
     * @param string              $rootDir Scratch directory.
     * @param array<string,mixed> $context Placeholder context.
     *
     * @return void
     */
    public function resolvePlaceholders(string $rootDir, array $context): void
    {
        $map = $this->placeholderResolver->buildMap(
            array_map(
                static fn ($v) => is_string($v) ? $v : (string) $v,
                $context
            )
        );

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() === false) {
                continue;
            }

            $path = (string) $file->getPathname();
            if ($this->isBinary($path) === true) {
                continue;
            }

            $original = file_get_contents($path);
            if ($original === false) {
                continue;
            }

            $resolved = $this->placeholderResolver->resolve($original, $map);
            if ($resolved !== $original) {
                file_put_contents($path, $resolved);
                @touch($path, $this->zipTimestamp);
            }
        }//end foreach
    }//end resolvePlaceholders()

    /**
     * Conservative binary-file check by extension.
     *
     * @param string $path File path.
     *
     * @return bool True when the file should be copied as-is.
     */
    public function isBinary(string $path): bool
    {
        $binaryExtensions = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'zip', 'gz', 'tar', 'phar'];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, $binaryExtensions, true);
    }//end isBinary()

    /**
     * Copy the embedded template snapshot into the scratch directory.
     *
     * Skips the snapshot-meta + path-manifest helper files; they are
     * artefacts of OpenBuilt, not of the produced app.
     *
     * @param string $source Snapshot dir.
     * @param string $dest   Scratch dir.
     *
     * @return void
     */
    public function copyTemplate(string $source, string $dest): void
    {
        if (is_dir($source) === false) {
            throw new RuntimeException('Template snapshot is missing: '.$source);
        }

        if (is_dir($dest) === false) {
            mkdir($dest, 0o755, true);
        }

        $skip = ['.snapshot-meta.json', '.path-manifest.txt'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            $relative = ltrim(str_replace($source, '', (string) $entry->getPathname()), '/');
            if (in_array($relative, $skip, true) === true) {
                continue;
            }

            $target = $dest.'/'.$relative;
            if ($entry->isDir() === true) {
                if (is_dir($target) === false) {
                    mkdir($target, 0o755, true);
                }

                continue;
            }

            copy((string) $entry->getPathname(), $target);
            @touch($target, $this->zipTimestamp);
        }
    }//end copyTemplate()

    /**
     * Create + clean a per-job scratch directory under app-data.
     *
     * @param string $jobUuid ExportJob UUID.
     *
     * @return string Local path to the scratch dir.
     */
    public function prepareScratchDir(string $jobUuid): string
    {
        $workRoot = $this->getOrCreateAppDataDir('work');
        $scratch  = $workRoot.'/'.$jobUuid;

        if (is_dir($scratch) === true) {
            $this->rrmdir($scratch);
        }

        mkdir($scratch, 0o755, true);
        return $scratch;
    }//end prepareScratchDir()

    /**
     * Ensure an app-data subdirectory exists and return its local path.
     *
     * Falls back to sys_get_temp_dir() when IAppData is not available (e.g.
     * unit-test mode), so the service stays testable.
     *
     * @param string $name Subdir name under appdata's openbuilt area.
     *
     * @return string Absolute local path.
     */
    public function getOrCreateAppDataDir(string $name): string
    {
        try {
            $folder = null;
            try {
                $folder = $this->appData->getFolder($name);
            } catch (NotFoundException $e) {
                $folder = $this->appData->newFolder($name);
            }

            // Resolve a local path via Storage::getLocalFile() if backed by local storage.
            $storage = $folder->getStorage();
            if (method_exists($storage, 'getLocalFile') === true) {
                $local = $storage->getLocalFile($folder->getInternalPath());
                if (is_string($local) === true && $local !== '') {
                    return rtrim($local, '/');
                }
            }
        } catch (\Throwable $e) {
            // Fall through to system temp.
            $this->logger->debug(
                'OpenBuilt export: IAppData unavailable, falling back to sys_get_temp_dir()',
                ['name' => $name, 'reason' => $e->getMessage()]
            );
        }//end try

        $fallback = sys_get_temp_dir().'/openbuilt-'.$name;
        if (is_dir($fallback) === false) {
            mkdir($fallback, 0o755, true);
        }

        return $fallback;
    }//end getOrCreateAppDataDir()

    /**
     * Recursive directory removal.
     *
     * @param string $dir Directory to remove.
     *
     * @return void
     */
    public function rrmdir(string $dir): void
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
