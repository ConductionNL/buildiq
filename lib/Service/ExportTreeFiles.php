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
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   AGPL-3.0-or-later
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

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
	 * Every file under a directory, relative and sorted.
	 *
	 * Sorted because the ZIP must be byte-identical between two exports of the
	 * same input: an unordered walk makes a deterministic archive impossible
	 * and the difference shows up as a spurious diff, not as an error.
	 *
	 * @param string $baseDir The root to walk.
	 *
	 * @return array<int, string> Relative paths, sorted.
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
