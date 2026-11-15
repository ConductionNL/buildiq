<?php

/**
 * Unit tests for the export archives kept in app data.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ExportArchiveStore;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Each finished export ZIP is kept in app data as exports/{jobId}.zip, read
 * back from there, and purged after a day.
 */
final class ExportArchiveStoreTest extends TestCase {
	/**
	 * A local ZIP written for a test.
	 *
	 * @var string
	 */
	private string $zip;

	/**
	 * Write the local archive.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->zip = sys_get_temp_dir() . '/buildiq-archive-store-' . uniqid() . '.zip';
		file_put_contents($this->zip, 'PK archive bytes');
	}//end setUp()

	/**
	 * Remove the local archive.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if (file_exists($this->zip) === true) {
			unlink($this->zip);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The first store creates the folder and the file with the ZIP's bytes.
	 *
	 * @return void
	 */
	public function testStoreCreatesTheFolderAndTheFile(): void {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->with('job-1.zip')->willReturn(false);
		$folder->expects(self::once())->method('newFile')->with('job-1.zip', 'PK archive bytes');

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('exports')->willThrowException(new NotFoundException());
		$appData->expects(self::once())->method('newFolder')->with('exports')->willReturn($folder);

		self::assertTrue((new ExportArchiveStore($appData, new NullLogger()))->store('job-1', $this->zip));
	}//end testStoreCreatesTheFolderAndTheFile()

	/**
	 * A re-export of the same job replaces the archive's content.
	 *
	 * @return void
	 */
	public function testStoreReplacesAnExistingArchive(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->expects(self::once())->method('putContent')->with('PK archive bytes');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturn(true);
		$folder->method('getFile')->with('job-1.zip')->willReturn($file);
		$folder->expects(self::never())->method('newFile');
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);

		self::assertTrue((new ExportArchiveStore($appData, new NullLogger()))->store('job-1', $this->zip));
	}//end testStoreReplacesAnExistingArchive()

	/**
	 * Nothing is stored for an id that is not a job id, for a missing local
	 * file, or when app data refuses the write.
	 *
	 * @return void
	 */
	public function testStoreRefusesBadInputAndSurvivesAFailingWrite(): void {
		$appData = $this->createMock(IAppData::class);
		$appData->expects(self::never())->method('getFolder');
		$store = new ExportArchiveStore($appData, new NullLogger());
		self::assertFalse($store->store('../etc/passwd', $this->zip));
		self::assertFalse($store->store('job-1', $this->zip . '.missing'));

		$failing = $this->createMock(IAppData::class);
		$failing->method('getFolder')->willThrowException(new RuntimeException('storage down'));
		self::assertFalse((new ExportArchiveStore($failing, new NullLogger()))->store('job-1', $this->zip));
	}//end testStoreRefusesBadInputAndSurvivesAFailingWrite()

	/**
	 * Read returns the stored bytes, and null when there is no archive or the
	 * id is not a job id.
	 *
	 * @return void
	 */
	public function testReadReturnsTheBytesOrNull(): void {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturn('PK stored');
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getFile')->willReturnCallback(
			static function (string $name) use ($file): ISimpleFile {
				if ($name === 'job-1.zip') {
					return $file;
				}

				throw new NotFoundException();
			}
		);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->with('exports')->willReturn($folder);
		$store = new ExportArchiveStore($appData, new NullLogger());

		self::assertSame('PK stored', $store->read('job-1'));
		self::assertNull($store->read('job-2'));
		self::assertNull($store->read('../job-1'));
	}//end testReadReturnsTheBytesOrNull()

	/**
	 * Purge deletes the archives older than the window and keeps the rest and
	 * anything that is not an archive.
	 *
	 * @return void
	 */
	public function testPurgeDeletesOnlyExpiredArchives(): void {
		$now = 1_800_000_000;
		$old = $this->file('old.zip', $now - 90000, true);
		$fresh = $this->file('fresh.zip', $now - 60, false);
		$other = $this->file('notes.txt', $now - 90000, false);
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('getDirectoryListing')->willReturn([$old, $fresh, $other]);
		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturn($folder);

		self::assertSame(1, (new ExportArchiveStore($appData, new NullLogger()))->purgeOlderThan($now, 86400));

		$missing = $this->createMock(IAppData::class);
		$missing->method('getFolder')->willThrowException(new NotFoundException());
		self::assertSame(0, (new ExportArchiveStore($missing, new NullLogger()))->purgeOlderThan($now, 86400));
	}//end testPurgeDeletesOnlyExpiredArchives()

	/**
	 * A file in app data with an expectation on its deletion.
	 *
	 * @param string $name    Its name.
	 * @param int    $mtime   Its modification time.
	 * @param bool   $deleted Whether it must be deleted.
	 *
	 * @return ISimpleFile
	 */
	private function file(string $name, int $mtime, bool $deleted): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMTime')->willReturn($mtime);
		$file->expects($deleted === true ? self::once() : self::never())->method('delete');
		return $file;
	}//end file()
}//end class
