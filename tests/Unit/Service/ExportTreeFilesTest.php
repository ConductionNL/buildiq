<?php

/**
 * Filesystem chores for an export tree.
 *
 * The listing's SORT is not cosmetic: two exports of the same input must
 * produce byte-identical ZIPs, and an unordered directory walk makes that
 * impossible. The failure does not look like an error — it looks like a
 * spurious diff between two archives that should have matched.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\ExportTreeFiles;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenBuild\Service\ExportTreeFiles
 */
final class ExportTreeFilesTest extends TestCase {

	/**
	 * Subject.
	 *
	 * @var ExportTreeFiles
	 */
	private ExportTreeFiles $files;

	/**
	 * Scratch root.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Build a small tree to walk.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->files = new ExportTreeFiles();
		$this->root = sys_get_temp_dir() . '/ob-tree-' . uniqid();
		mkdir($this->root . '/lib/Settings/flows', 0o755, true);
		mkdir($this->root . '/appinfo', 0o755, true);

		// Deliberately created in an order that is NOT the sorted order.
		file_put_contents($this->root . '/lib/Settings/flows/zeta.json', '{}');
		file_put_contents($this->root . '/appinfo/info.xml', '<info/>');
		file_put_contents($this->root . '/lib/Settings/flows/alpha.json', '{}');

	}//end setUp()

	/**
	 * Remove whatever survived the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));

	}//end tearDown()

	/**
	 * The listing is relative, complete, and SORTED.
	 *
	 * @return void
	 */
	public function testTheListingIsRelativeAndSorted(): void {
		$listed = $this->files->listFilesSorted(baseDir: $this->root);

		$this->assertSame(
			expected: [
				'appinfo/info.xml',
				'lib/Settings/flows/alpha.json',
				'lib/Settings/flows/zeta.json',
			],
			actual: $listed,
			message: 'an unsorted walk makes a byte-identical ZIP impossible between two exports of the same input'
		);

	}//end testTheListingIsRelativeAndSorted()

	/**
	 * Directories are not listed — only files go into a ZIP.
	 *
	 * @return void
	 */
	public function testDirectoriesAreNotListed(): void {
		$listed = $this->files->listFilesSorted(baseDir: $this->root);

		$this->assertNotContains(needle: 'appinfo', haystack: $listed);
		$this->assertNotContains(needle: 'lib/Settings/flows', haystack: $listed);

	}//end testDirectoriesAreNotListed()

	/**
	 * A tree is removed entirely, nested files included.
	 *
	 * @return void
	 */
	public function testRemoveTreeDeletesEverythingBeneathIt(): void {
		$this->assertDirectoryExists($this->root . '/lib/Settings/flows');

		$this->files->removeTree(dir: $this->root);

		$this->assertDirectoryDoesNotExist($this->root);

	}//end testRemoveTreeDeletesEverythingBeneathIt()

	/**
	 * Removing something that is not there is a no-op, not a failure.
	 *
	 * The scratch directory may already be gone when cleanup runs — an export
	 * that failed halfway is exactly when cleanup matters most, and it must not
	 * turn one failure into two.
	 *
	 * @return void
	 */
	public function testRemovingAMissingTreeIsANoOp(): void {
		$this->files->removeTree(dir: $this->root . '/never-existed');

		$this->assertDirectoryExists($this->root, 'the surrounding tree must be untouched');

	}//end testRemovingAMissingTreeIsANoOp()
}//end class
