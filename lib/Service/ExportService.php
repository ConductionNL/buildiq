<?php

/**
 * OpenBuild Export Service
 *
 * Imperative exporter that produces a standalone Nextcloud-app tree from a
 * published Application record. ADR-031 §Exceptions(3) acceptable code path —
 * file generation and ZIP packaging are OS-bound side effects.
 *
 * The ExportJob lifecycle itself remains declarative (see x-openregister-lifecycle
 * in lib/Settings/openbuild_register.json).
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-40
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-41
 *
 * @SPDX-License-Identifier: EUPL-1.2
 * @SPDX-FileCopyrightText:  2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use FilesystemIterator;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Generates a real Nextcloud-app tree from an OpenBuild Application + ZIPs it.
 *
 * Public surface — an entry point is public only when production calls it:
 *
 *   - generateAppZip()  — RunExportJob::run(); orchestrates copy → resolve
 *                         placeholders → bundle data registers → ZIP.
 *   - scratchTreeDir()  — RunExportJob::run(); pure path resolver so the
 *                         GitHub push target can read the generated tree.
 *   - buildScaffoldMap() — the same pipeline returning an in-memory
 *                         `path => contents` map instead of a ZIP, for a
 *                         config-set repo publish.
 *
 * Every remaining step (copyTemplate, resolvePlaceholders, packageZip,
 * listFilesSorted, isBinary, prepareScratchDir, getOrCreateAppDataDir,
 * rrmdir, bundleDataRegisterSchemas) is an implementation detail of those
 * three and is private. They were public only so the tests could reach them;
 * the tests now drive the real entry points instead.
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
 *
 * @spec openspec/changes/openbuild-exporter/tasks.md#task-4.1
 */
class ExportService {

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
	 * @param IAppData $appData The app-data area for scratch + exports.
	 * @param PlaceholderResolver $placeholderResolver Pure resolver for {{tokens}}.
	 * @param LoggerInterface $logger Logger.
	 * @param DataRegisterExportBundler $dataRegisterBundler Bundles bound data registers into the exported
	 *                                                       tree (data-registers-runtime) — a dedicated
	 *                                                       collaborator so this class's own coupling/
	 *                                                       complexity stays within PHPMD's thresholds.
	 */
	public function __construct(
		private IAppData $appData,
		private PlaceholderResolver $placeholderResolver,
		private LoggerInterface $logger,
		private DataRegisterExportBundler $dataRegisterBundler,
	) {
		$this->templateRoot = dirname(__DIR__) . '/Resources/template';
		// 2026-01-01T00:00:00Z — fixed for deterministic ZIPs.
		$this->zipTimestamp = 1767225600;
	}//end __construct()

	/**
	 * Build the ZIP archive for an Application + version into app-data.
	 *
	 * @param string $applicationUuid Source Application UUID.
	 * @param string $versionSlug Semver of the Application version.
	 * @param array<string,mixed> $context Placeholder context: appId, appNamespace, etc.
	 * @param string $jobUuid ExportJob UUID — used as the ZIP
	 *                        filename.
	 * @param array<int,mixed> $dataRegisters Source Application's `dataRegisters` bindings + the
	 *                                        per-export includeData choice for each
	 *                                        (data-registers-runtime design.md Decision 5).
	 *                                        Default `[]`. Untrusted shape — see
	 *                                        bundleDataRegisterSchemas().
	 *
	 * @return string Absolute (local) path to the produced ZIP.
	 *
	 * @throws RuntimeException When packaging fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-40
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
	 */
	public function generateAppZip(
		string $applicationUuid,
		string $versionSlug,
		array $context,
		string $jobUuid,
		array $dataRegisters = [],
	): string {
		$scratchDir = $this->prepareScratchDir(jobUuid: $jobUuid);
		$this->copyTemplate(source: $this->templateRoot, dest: $scratchDir);
		$this->resolvePlaceholders(rootDir: $scratchDir, context: $context);
		$this->bundleDataRegisterSchemas(rootDir: $scratchDir, dataRegisters: $dataRegisters);

		// Audit-trail entry names only the source — never the PAT, never secret values.
		$this->logger->info(
			'OpenBuild export: built tree',
			[
				'applicationUuid' => $applicationUuid,
				'applicationVersion' => $versionSlug,
				'jobUuid' => $jobUuid,
			]
		);

		return $this->packageZip(sourceDir: $scratchDir, jobUuid: $jobUuid);
	}//end generateAppZip()

	/**
	 * Build the installable NC-app scaffold as an in-memory `path => contents` map.
	 *
	 * The same copy → resolve-placeholders pipeline `generateAppZip()` uses, but
	 * returning the tree as a map instead of a ZIP, so a config-set repo publish
	 * (GitHubAppSyncService) can fold the scaffold in and produce ONE repo that is
	 * both the config-set store AND an app-store-installable, standalone
	 * nc-vue app (info.xml + the manifest runtime + OpenRegister as its data layer).
	 *
	 * NOTE — no production caller on this remote yet. The call site
	 * (`GitHubAppSyncService::scaffoldFor()`, folded into `push()`) exists only
	 * on the Codeberg line and was NOT part of the rescue that brought this
	 * method across; GitHub's `GitHubAppSyncService` has diverged since (it
	 * gained `AppChannelApplier`, `OUTCOME_FORBIDDEN` and richer broker
	 * logging), so porting it is its own change, not a file-copy. This method
	 * is therefore public as a designed entry point that is currently unwired,
	 * NOT because a test needs to reach it. See the follow-up issue.
	 *
	 * @param array<string,mixed> $context Placeholder context: appId, appNamespace, appName, appVersion, authorName, authorEmail, license.
	 * @param array<int,mixed> $dataRegisters Optional bound data-register schemas to bundle.
	 *
	 * @return array<string,string> Ordered `path => contents` map of the resolved scaffold.
	 *
	 * @spec openspec/changes/github-app-sync/specs/github-app-sync/spec.md
	 */
	public function buildScaffoldMap(array $context, array $dataRegisters = []): array {
		$jobUuid = 'scaffold-' . ((string)($context['appId'] ?? 'app')) . '-' . uniqid();
		$scratch = $this->prepareScratchDir(jobUuid: $jobUuid);

		try {
			$this->copyTemplate(source: $this->templateRoot, dest: $scratch);
			$this->resolvePlaceholders(rootDir: $scratch, context: $context);
			if ($dataRegisters !== []) {
				$this->bundleDataRegisterSchemas(rootDir: $scratch, dataRegisters: $dataRegisters);
			}

			$map = [];
			foreach ($this->listFilesSorted(baseDir: $scratch) as $relative) {
				$contents = file_get_contents($scratch . '/' . $relative);
				if ($contents !== false) {
					$map[$relative] = $contents;
				}
			}

			return $map;
		} finally {
			$this->rrmdir(dir: $scratch);
		}//end try

	}//end buildScaffoldMap()

	/**
	 * Bundle every `dataRegisters` binding's schema definitions (always) and
	 * row data (only when that binding's `includeData` is true) into the
	 * exported tree (spec openbuild-exporter, ADDED Requirements "Bound
	 * data registers' schema definitions are bundled into every export" +
	 * "Per-binding includeData toggle controls data-register row-data
	 * inclusion"). Delegates to {@see DataRegisterExportBundler} (a
	 * dedicated collaborator — see design.md Decision 5 and this class's
	 * own constructor docblock for why), then pins every written file's
	 * mtime to the same deterministic `$zipTimestamp` every other file in
	 * the exported tree uses, preserving REQ-OBEX-008 byte-equivalence
	 * across re-exports.
	 *
	 * @param string $rootDir Scratch directory (exported tree root).
	 * @param array<int,mixed> $dataRegisters Bindings + per-export includeData choice — see
	 *                                        DataRegisterExportBundler::bundle() for the shape note.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
	 */
	private function bundleDataRegisterSchemas(string $rootDir, array $dataRegisters): void {
		$this->dataRegisterBundler->bundle(rootDir: $rootDir, dataRegisters: $dataRegisters);
		$this->pinDataRegisterFileTimestamps(rootDir: $rootDir);
	}//end bundleDataRegisterSchemas()

	/**
	 * Pin every file `bundleDataRegisterSchemas()` just wrote to the
	 * deterministic `$zipTimestamp` (REQ-OBEX-008) — mirrors how every
	 * other write in this class (`copyTemplate()`, `resolvePlaceholders()`)
	 * touches its own output. Kept here (not in the bundler) because the
	 * ZIP-determinism contract is this class's concern, not the bundler's.
	 *
	 * @param string $rootDir Scratch directory (exported tree root).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-4.2
	 */
	private function pinDataRegisterFileTimestamps(string $rootDir): void {
		$targetDir = $rootDir . '/lib/Settings/data-registers';
		if (is_dir($targetDir) === false) {
			return;
		}

		$entries = scandir($targetDir);
		if ($entries === false) {
			return;
		}

		foreach ($entries as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			touch($targetDir . '/' . $entry, $this->zipTimestamp);
		}
	}//end pinDataRegisterFileTimestamps()

	/**
	 * Package a directory tree into a deterministic ZIP archive.
	 *
	 * @param string $sourceDir Directory to package.
	 * @param string $jobUuid ExportJob UUID — filename base.
	 *
	 * @return string Local path to the ZIP file.
	 *
	 * @throws RuntimeException When ZIP creation fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
	 */
	private function packageZip(string $sourceDir, string $jobUuid): string {
		$exportRoot = $this->getOrCreateAppDataDir(name: 'exports');
		$zipPath = $exportRoot . '/' . $jobUuid . '.zip';

		if (is_dir(dirname($zipPath)) === false) {
			mkdir(dirname($zipPath), 0o755, true);
		}

		if (file_exists($zipPath) === true) {
			unlink($zipPath);
		}

		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new RuntimeException('Unable to open ZIP archive: ' . $zipPath);
		}

		$entries = $this->listFilesSorted(baseDir: $sourceDir);
		foreach ($entries as $relativePath) {
			$absolute = $sourceDir . '/' . $relativePath;
			$zip->addFile($absolute, $relativePath);
			// Fixed timestamp for byte-determinism.
			$zip->setExternalAttributesName($relativePath, ZipArchive::OPSYS_UNIX, (0o100644 << 16));
		}

		if ($zip->close() === false) {
			throw new RuntimeException('Failed to finalise ZIP archive: ' . $zipPath);
		}

		// Pin mtime on the file itself for reproducibility.
		touch($zipPath, $this->zipTimestamp);

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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-41
	 */
	private function listFilesSorted(string $baseDir): array {
		$files = [];
		if (is_dir($baseDir) === false) {
			return $files;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if ($file->isFile() === true) {
				$relative = ltrim(str_replace($baseDir, '', (string)$file->getPathname()), '/');
				$files[] = $relative;
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
	 * @param string $rootDir Scratch directory.
	 * @param array<string,mixed> $context Placeholder context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-40
	 */
	private function resolvePlaceholders(string $rootDir, array $context): void {
		$stringContext = [];
		foreach ($context as $key => $value) {
			$stringContext[$key] = (string)$value;
		}

		$map = $this->placeholderResolver->buildMap(context: $stringContext);

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS)
		);
		foreach ($iterator as $file) {
			if ($file->isFile() === false) {
				continue;
			}

			$path = (string)$file->getPathname();
			if ($this->isBinary(path: $path) === true) {
				continue;
			}

			$original = file_get_contents($path);
			if ($original === false) {
				continue;
			}

			$resolved = $this->placeholderResolver->resolve(content: $original, map: $map);
			if ($resolved !== $original) {
				file_put_contents($path, $resolved);
				touch($path, $this->zipTimestamp);
			}
		}//end foreach
	}//end resolvePlaceholders()

	/**
	 * Conservative binary-file check by extension.
	 *
	 * @param string $path File path.
	 *
	 * @return bool True when the file should be copied as-is.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-40
	 */
	private function isBinary(string $path): bool {
		$binaryExtensions = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'zip', 'gz', 'tar', 'phar'];
		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($ext, $binaryExtensions, true);
	}//end isBinary()

	/**
	 * Copy the embedded template snapshot into the scratch directory.
	 *
	 * Skips the snapshot-meta + path-manifest helper files; they are
	 * artefacts of OpenBuild, not of the produced app.
	 *
	 * @param string $source Snapshot dir.
	 * @param string $dest Scratch dir.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-40
	 */
	private function copyTemplate(string $source, string $dest): void {
		if (is_dir($source) === false) {
			throw new RuntimeException('Template snapshot is missing: ' . $source);
		}

		if (is_dir($dest) === false) {
			mkdir($dest, 0o755, true);
		}

		$skip = ['.snapshot-meta.json', '.path-manifest.txt'];

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $entry) {
			$relative = ltrim(str_replace($source, '', (string)$entry->getPathname()), '/');
			if (in_array($relative, $skip, true) === true) {
				continue;
			}

			$target = $dest . '/' . $relative;
			if ($entry->isDir() === true) {
				if (is_dir($target) === false) {
					mkdir($target, 0o755, true);
				}

				continue;
			}

			copy((string)$entry->getPathname(), $target);
			touch($target, $this->zipTimestamp);
		}
	}//end copyTemplate()

	/**
	 * Create + clean a per-job scratch directory under app-data.
	 *
	 * @param string $jobUuid ExportJob UUID.
	 *
	 * @return string Local path to the scratch dir.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
	 */
	private function prepareScratchDir(string $jobUuid): string {
		$scratch = $this->scratchTreeDir(jobUuid: $jobUuid);

		if (is_dir($scratch) === true) {
			$this->rrmdir(dir: $scratch);
		}

		mkdir($scratch, 0o755, true);
		return $scratch;
	}//end prepareScratchDir()

	/**
	 * Resolve the work scratch directory path that holds the generated tree
	 * for a job. The GitHub push target reads the tree from here after
	 * generateAppZip() has populated it. This is a pure path resolver — it
	 * does NOT create or wipe the directory (see prepareScratchDir for that).
	 *
	 * @param string $jobUuid ExportJob UUID.
	 *
	 * @return string Absolute path to the scratch tree directory.
	 *
	 * @spec openspec/changes/openbuild-exporter/tasks.md#task-6.2
	 */
	public function scratchTreeDir(string $jobUuid): string {
		return $this->getOrCreateAppDataDir(name: 'work') . '/' . $jobUuid;
	}//end scratchTreeDir()

	/**
	 * Ensure an export-staging subdirectory exists and return its local path.
	 *
	 * The exporter does heavy filesystem-level work (recursive copies,
	 * deterministic ZIP packaging, mtime pinning) that ISimpleFolder /
	 * IAppData cannot satisfy without local-path access. ISimpleFolder is a
	 * deliberately storage-opaque abstraction — calling getStorage() /
	 * getInternalPath() on it (as the WIP code did) is invalid and was
	 * flagged by PHPStan.
	 *
	 * We therefore stage on a deterministic local path under
	 * sys_get_temp_dir()/openbuild-{name}, and additionally pin the IAppData
	 * folder existence so the surrounding Nextcloud bookkeeping (quotas,
	 * audit, cleanup) is informed of our use. This satisfies the
	 * security/cleanup contract (CleanupExpiredExports purges by job UUID)
	 * while remaining ISimpleFolder-safe.
	 *
	 * @param string $name Subdir name under appdata's openbuild area.
	 *
	 * @return string Absolute local path.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
	 */
	private function getOrCreateAppDataDir(string $name): string {
		// Best-effort: make sure the IAppData folder exists so any
		// surrounding bookkeeping (quota, cleanup, audit) is aware of the
		// openbuild namespace. We do NOT rely on it for local-path access —
		// ISimpleFolder is storage-opaque by design.
		try {
			try {
				$this->appData->getFolder($name);
			} catch (NotFoundException $e) {
				$this->appData->newFolder($name);
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				'OpenBuild export: IAppData folder hint failed (continuing on local temp)',
				['name' => $name, 'reason' => $e->getMessage()]
			);
		}//end try

		$local = sys_get_temp_dir() . '/openbuild-' . $name;
		if (is_dir($local) === false) {
			mkdir($local, 0o755, true);
		}

		return $local;
	}//end getOrCreateAppDataDir()

	/**
	 * Recursive directory removal.
	 *
	 * @param string $dir Directory to remove.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-35
	 */
	private function rrmdir(string $dir): void {
		if (is_dir($dir) === false) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $entry) {
			$path = (string)$entry->getPathname();
			if ($entry->isDir() === true) {
				rmdir($path);
				continue;
			}

			unlink($path);
		}

		rmdir($dir);
	}//end rrmdir()
}//end class
