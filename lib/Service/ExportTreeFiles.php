<?php

/**
 * Filesystem chores for an export tree.
 *
 * Recursive delete and deterministic listing: generic file handling that knows
 * nothing about applications, versions or ZIPs. It lived on `ExportService`,
 * where it contributed to a class complexity of 52 against a threshold of 50 —
 * and where "how do we walk a directory" sat beside "what does an exported app
 * contain", which are not the same subject.
 *
 * Extracted rather than suppressed. The complexity number was a fair report of
 * a class doing two jobs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Walks and removes export scratch trees.
 */
class ExportTreeFiles {

	/**
	 * Remove a directory and everything under it.
	 *
	 * @param string $dir The directory.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openbuild-exports-flows-and-agents/specs/app-export-completeness/spec.md#requirement-an-export-must-carry-the-flows-the-application-binds-and-the-agents-that-point-at-it
	 */
	public function removeTree(string $dir): void {
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
	}//end removeTree()

	/**
	 * Absolute paths of every FILE under a directory, unsorted.
	 *
	 * For callers that need to touch each file's contents rather than list
	 * them. Kept here with the other walkers so one class knows how to walk a
	 * tree — having three iterator classes imported into a service that also
	 * builds ZIPs is what phpmd's coupling metric was reporting.
	 *
	 * @param string $dir The root to walk.
	 *
	 * @return array<int, string> Absolute file paths.
	 *
	 * @spec openspec/changes/openbuild-exports-flows-and-agents/specs/app-export-completeness/spec.md#requirement-an-export-must-carry-the-flows-the-application-binds-and-the-agents-that-point-at-it
	 */
	public function filePaths(string $dir): array {
		$paths = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $entry) {
			if ($entry->isFile() === false) {
				continue;
			}

			$paths[] = (string)$entry->getPathname();
		}

		return $paths;
	}//end filePaths()

	/**
	 * Copy a tree, skipping named entries and stamping a fixed mtime.
	 *
	 * The timestamp is fixed so two exports of the same input produce
	 * byte-identical ZIPs; a live mtime makes every archive differ from the
	 * last for no reason a reader can see.
	 *
	 * @param string $source Source root.
	 * @param string $dest Destination root.
	 * @param array<int, string> $skip Relative paths to leave behind.
	 * @param integer $timestamp mtime to stamp on every copied file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openbuild-exports-flows-and-agents/specs/app-export-completeness/spec.md#requirement-an-export-must-carry-the-flows-the-application-binds-and-the-agents-that-point-at-it
	 */
	public function copyTree(string $source, string $dest, array $skip, int $timestamp): void {
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
			touch($target, $timestamp);
		}
	}//end copyTree()

	/**
	 * Every file under a directory, relative and sorted.
	 *
	 * Sorted because the ZIP must be byte-identical between two exports of the
	 * same input: an unordered walk makes a deterministic archive impossible
	 * and the difference shows up as a spurious diff, not as an error.
	 *
	 * @param string $baseDir The root to walk.
	 *
	 * @return array<int, string> Relative paths, sorted.
	 *
	 * @spec openspec/changes/openbuild-exports-flows-and-agents/specs/app-export-completeness/spec.md#requirement-an-export-must-carry-the-flows-the-application-binds-and-the-agents-that-point-at-it
	 */
	public function listFilesSorted(string $baseDir): array {
		$files = [];
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $entry) {
			if ($entry->isFile() === false) {
				continue;
			}

			$files[] = ltrim(str_replace($baseDir, '', (string)$entry->getPathname()), '/');
		}

		sort($files);

		return $files;
	}//end listFilesSorted()
}//end class
