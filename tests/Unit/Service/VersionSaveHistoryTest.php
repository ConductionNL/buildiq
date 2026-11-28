<?php

/**
 * VersionSaveHistoryTest
 *
 * @category  Tests
 * @package   OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use DateTime;
use OCA\Buildiq\Service\VersionSaveHistory;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use PHPUnit\Framework\TestCase;

/**
 * Keeping, listing and reading the saves of a version.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
class VersionSaveHistoryTest extends TestCase {

	/**
	 * The stored files, by folder path and file name.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $files = [];

	/**
	 * The store under test, over in-memory app data.
	 *
	 * @var VersionSaveHistory
	 */
	private VersionSaveHistory $saves;

	/**
	 * Wire in-memory app data and a clock that moves on by a second per save.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->method('getFolder')->willReturnCallback(fn (string $name): ISimpleFolder => $this->folder(path: $name, create: false));
		$appData->method('newFolder')->willReturnCallback(fn (string $name): ISimpleFolder => $this->folder(path: $name, create: true));

		$time = $this->createMock(originalClassName: ITimeFactory::class);
		$tick = 0;
		$time->method('getDateTime')->willReturnCallback(static function () use (&$tick): DateTime {
			$tick++;
			return new DateTime('2026-11-24T10:00:' . sprintf('%02d', $tick) . '+00:00');
		});

		$this->saves = new VersionSaveHistory(appData: $appData, time: $time);
	}//end setUp()

	/**
	 * A manifest with these page ids.
	 *
	 * @param list<string> $pages The page ids.
	 *
	 * @return array<string,mixed>
	 */
	private static function manifest(array $pages): array {
		return ['version' => '1.0.0', 'pages' => array_map(static fn (string $id): array => ['id' => $id, 'type' => 'index'], $pages)];
	}//end manifest()

	/**
	 * Scenario "A maker undoes a broken page change": the save before the one that removed `permits` still holds it,
	 * and the rollback, saved again, is the newest save.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function theSaveBeforeABrokenChangeStillHoldsThePage(): void {
		$before = $this->saves->record(versionUuid: 'version-1', manifest: self::manifest(pages: ['permits', 'dashboard']), user: 'sanne');
		$this->saves->record(versionUuid: 'version-1', manifest: self::manifest(pages: ['dashboard']), user: 'sanne');

		$saves = $this->saves->saves(versionUuid: 'version-1');
		self::assertCount(expectedCount: 2, haystack: $saves);
		self::assertSame(expected: ['dashboard'], actual: $saves[0]['pages']);
		self::assertSame(expected: ['permits', 'dashboard'], actual: $saves[1]['pages']);
		self::assertSame(expected: $before, actual: $saves[1]['id']);
		self::assertSame(expected: 'sanne', actual: $saves[1]['user']);
		self::assertSame(expected: '2026-11-24T10:00:01+00:00', actual: $saves[1]['savedAt']);

		$restored = $this->saves->manifest(versionUuid: 'version-1', saveId: (string)$before);
		self::assertSame(expected: self::manifest(pages: ['permits', 'dashboard']), actual: $restored);

		$this->saves->record(versionUuid: 'version-1', manifest: $restored, user: 'sanne');
		$after = $this->saves->saves(versionUuid: 'version-1');
		self::assertCount(expectedCount: 3, haystack: $after);
		self::assertSame(expected: ['permits', 'dashboard'], actual: $after[0]['pages']);
	}//end theSaveBeforeABrokenChangeStillHoldsThePage()

	/**
	 * A save that leaves the manifest as it was (a status or semver change) is not kept again.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function aSaveWithTheSameManifestIsNotKeptTwice(): void {
		self::assertNotNull(actual: $this->saves->record(versionUuid: 'version-1', manifest: self::manifest(pages: ['permits']), user: ''));
		self::assertNull(actual: $this->saves->record(versionUuid: 'version-1', manifest: self::manifest(pages: ['permits']), user: ''));
		self::assertCount(expectedCount: 1, haystack: $this->saves->saves(versionUuid: 'version-1'));
	}//end aSaveWithTheSameManifestIsNotKeptTwice()

	/**
	 * At most MAX_SAVES saves are kept per version; the oldest go first.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function onlyTheNewestSavesAreKept(): void {
		$first = $this->saves->record(versionUuid: 'version-1', manifest: self::manifest(pages: ['page-0']), user: '');
		for ($i = 1; $i <= VersionSaveHistory::MAX_SAVES; $i++) {
			$this->saves->record(versionUuid: 'version-1', manifest: self::manifest(pages: ['page-' . $i]), user: '');
		}

		$saves = $this->saves->saves(versionUuid: 'version-1');
		self::assertCount(expectedCount: VersionSaveHistory::MAX_SAVES, haystack: $saves);
		self::assertNotContains(needle: $first, haystack: array_column($saves, 'id'));
		self::assertSame(expected: ['page-' . VersionSaveHistory::MAX_SAVES], actual: $saves[0]['pages']);
	}//end onlyTheNewestSavesAreKept()

	/**
	 * Versions keep their own saves; unknown versions, unknown saves and ids that could leave the folder give nothing.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function versionsAreKeptApartAndBadIdsGiveNothing(): void {
		$this->saves->record(versionUuid: 'version-1', manifest: self::manifest(pages: ['permits']), user: '');

		self::assertSame(expected: [], actual: $this->saves->saves(versionUuid: 'version-2'));
		self::assertNull(actual: $this->saves->manifest(versionUuid: 'version-1', saveId: 'missing'));
		self::assertNull(actual: $this->saves->manifest(versionUuid: 'version-1', saveId: '../version-2/x'));
		self::assertNull(actual: $this->saves->record(versionUuid: '../etc', manifest: self::manifest(pages: ['x']), user: ''));
		self::assertSame(expected: [], actual: $this->saves->saves(versionUuid: '../etc'));
	}//end versionsAreKeptApartAndBadIdsGiveNothing()

	/**
	 * Before anything is saved there is no folder, and listing creates none.
	 *
	 * @test
	 *
	 * @return void
	 */
	public function listingBeforeAnySaveCreatesNothing(): void {
		self::assertSame(expected: [], actual: $this->saves->saves(versionUuid: 'version-1'));
		self::assertSame(expected: [], actual: $this->files);
	}//end listingBeforeAnySaveCreatesNothing()

	/**
	 * An in-memory folder at a path.
	 *
	 * @param string $path   The folder path.
	 * @param bool   $create Whether to make it when it is missing.
	 *
	 * @return ISimpleFolder
	 *
	 * @throws NotFoundException When it is missing and not to be made.
	 */
	private function folder(string $path, bool $create): ISimpleFolder {
		if (isset($this->files[$path]) === false) {
			if ($create === false) {
				throw new NotFoundException();
			}

			$this->files[$path] = [];
		}

		$folder = $this->createMock(originalClassName: ISimpleFolder::class);
		$folder->method('getFolder')->willReturnCallback(fn (string $name): ISimpleFolder => $this->folder(path: $path . '/' . $name, create: false));
		$folder->method('newFolder')->willReturnCallback(fn (string $name): ISimpleFolder => $this->folder(path: $path . '/' . $name, create: true));
		$folder->method('fileExists')->willReturnCallback(fn (string $name): bool => isset($this->files[$path][$name]));
		$folder->method('getFile')->willReturnCallback(fn (string $name): ISimpleFile => $this->file(path: $path, name: $name));
		$folder->method('newFile')->willReturnCallback(function (string $name, $content) use ($path): ISimpleFile {
			$this->files[$path][$name] = (string)$content;
			return $this->file(path: $path, name: $name);
		});
		$folder->method('getDirectoryListing')->willReturnCallback(
			fn (): array => array_map(fn (string $name): ISimpleFile => $this->file(path: $path, name: $name), array_keys($this->files[$path]))
		);

		return $folder;
	}//end folder()

	/**
	 * An in-memory file.
	 *
	 * @param string $path The folder path.
	 * @param string $name The file name.
	 *
	 * @return ISimpleFile
	 */
	private function file(string $path, string $name): ISimpleFile {
		$file = $this->createMock(originalClassName: ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturnCallback(fn (): string => ($this->files[$path][$name] ?? ''));
		$file->method('delete')->willReturnCallback(function () use ($path, $name): void {
			unset($this->files[$path][$name]);
		});

		return $file;
	}//end file()
}//end class
