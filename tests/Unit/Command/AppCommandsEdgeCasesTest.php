<?php

/**
 * Unit tests for the less-travelled paths of the buildiq:app:import, :delete and :export commands.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-lifecycle-import-app-and-cli/specs/app-management-cli/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Command;

use OCA\Buildiq\Command\AppDelete;
use OCA\Buildiq\Command\AppExport;
use OCA\Buildiq\Command\AppImport;
use OCA\Buildiq\Kernel\Contracts\AppCatalogueInterface;
use OCA\Buildiq\Kernel\Contracts\AppPackageImportServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ApplicationDeletionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ExportJobServiceInterface;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Edge-case tests for the app management commands.
 *
 * @covers \OCA\Buildiq\Command\AppImport
 * @covers \OCA\Buildiq\Command\AppDelete
 * @covers \OCA\Buildiq\Command\AppExport
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class AppCommandsEdgeCasesTest extends TestCase {

	/**
	 * Catalogue mock.
	 *
	 * @var AppCatalogueInterface&MockObject
	 */
	private AppCatalogueInterface&MockObject $catalogue;

	/**
	 * Build the catalogue mock: one app with versions, one record without a uuid.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$apps = [
			'vergunningen' => ['slug' => 'vergunningen', 'name' => 'Vergunningen', '@self' => ['id' => 'app-v']],
			'zonder-id' => ['slug' => 'zonder-id', 'name' => 'Zonder id'],
		];
		$this->catalogue = $this->createMock(AppCatalogueInterface::class);
		$this->catalogue->method('app')->willReturnCallback(static fn (string $slug): ?array => ($apps[$slug] ?? null));
		$this->catalogue->method('versions')->willReturn(
			[
				['slug' => 'v1-10', 'semver' => '1.10.0', 'status' => 'published'],
				['slug' => 'v1-9', 'semver' => '1.9.0', 'status' => 'published'],
				['slug' => 'development', 'semver' => '2.0.0', 'status' => 'draft'],
			]
		);

	}//end setUp()

	/**
	 * A command in a tester.
	 *
	 * @param Command $command The command.
	 *
	 * @return CommandTester The tester.
	 */
	private static function tester(Command $command): CommandTester {
		$application = new Application();
		$application->add($command);
		return new CommandTester($command);

	}//end tester()

	/**
	 * An importer that is never called.
	 *
	 * @return AppPackageImportServiceInterface&MockObject The importer.
	 */
	private function unusedImporter(): AppPackageImportServiceInterface&MockObject {
		$importer = $this->createMock(AppPackageImportServiceInterface::class);
		$importer->expects($this->never())->method('importWithRecords');
		return $importer;

	}//end unusedImporter()

	/**
	 * A blank owner is refused like a missing one, before the user lookup.
	 *
	 * @return void
	 */
	public function testABlankOwnerIsRefusedBeforeTheLookup(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->never())->method('userExists');
		$tester = self::tester(new AppImport($this->unusedImporter(), $users));

		$this->assertSame(Command::INVALID, $tester->execute(['file' => __FILE__, '--owner' => '   ']));
		$this->assertStringContainsString('--owner is required', $tester->getDisplay());

	}//end testABlankOwnerIsRefusedBeforeTheLookup()

	/**
	 * A folder given as the archive is not a readable file and imports nothing.
	 *
	 * @return void
	 */
	public function testAFolderIsNotAnArchive(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$tester = self::tester(new AppImport($this->unusedImporter(), $users));

		$folder = sys_get_temp_dir();
		$this->assertSame(Command::INVALID, $tester->execute(['file' => $folder, '--owner' => 'sanne']));
		$this->assertStringContainsString('The file "' . $folder . '" cannot be read; nothing was imported.', $tester->getDisplay());

	}//end testAFolderIsNotAnArchive()

	/**
	 * The owner is trimmed, the name is passed on, and a structured warning is printed as JSON.
	 *
	 * @return void
	 */
	public function testANonStringWarningIsPrintedAsJson(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects($this->once())->method('userExists')->with('sanne')->willReturn(true);
		$importer = $this->createMock(AppPackageImportServiceInterface::class);
		$importer->expects($this->once())->method('importWithRecords')
			->with(__FILE__, ['name' => 'Vergunningen 2', 'slug' => '', 'owner' => 'sanne', 'records' => false])
			->willReturn(['status' => 201, 'data' => ['slug' => 'vergunningen-2', 'warnings' => [['code' => 'skill_skipped', 'file' => 'skills/a.md']]]]);
		$tester = self::tester(new AppImport($importer, $users));

		$this->assertSame(Command::SUCCESS, $tester->execute(['file' => __FILE__, '--owner' => ' sanne ', '--name' => 'Vergunningen 2']));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('Imported the app "vergunningen-2" for sanne.', $display);
		$this->assertStringContainsString('Warning: {"code":"skill_skipped","file":"skills\/a.md"}', $display);
		$this->assertStringNotContainsString('records imported', $display);

	}//end testANonStringWarningIsPrintedAsJson()

	/**
	 * A refusal without a code, path or scalar detail still says it was refused, with the generic code only.
	 *
	 * @return void
	 */
	public function testABareRefusalFallsBackToTheGenericCode(): void {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$importer = $this->createMock(AppPackageImportServiceInterface::class);
		$importer->method('importWithRecords')->willReturn(['status' => 400, 'data' => ['path' => '', 'detail' => ['nested' => true]]]);
		$tester = self::tester(new AppImport($importer, $users));

		$this->assertSame(Command::FAILURE, $tester->execute(['file' => __FILE__, '--owner' => 'sanne']));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('Import refused: error', $display);
		$this->assertStringNotContainsString(' in ', $display);
		$this->assertStringNotContainsString('error:', $display);

	}//end testABareRefusalFallsBackToTheGenericCode()

	/**
	 * An app record without a uuid counts as unknown and deletes nothing.
	 *
	 * @return void
	 */
	public function testDeleteOfAnAppWithoutAUuidIsInvalid(): void {
		$deletion = $this->createMock(ApplicationDeletionServiceInterface::class);
		$deletion->expects($this->never())->method('deleteApplication');
		$tester = self::tester(new AppDelete($this->catalogue, $deletion));

		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'zonder-id', '--force' => true]));
		$this->assertStringContainsString('No app with the slug "zonder-id".', $tester->getDisplay());

	}//end testDeleteOfAnAppWithoutAUuidIsInvalid()

	/**
	 * A non-interactive run without --force takes the default answer, no, and deletes nothing.
	 *
	 * @return void
	 */
	public function testANonInteractiveDeleteWithoutForceDeletesNothing(): void {
		$deletion = $this->createMock(ApplicationDeletionServiceInterface::class);
		$deletion->expects($this->never())->method('deleteApplication');
		$tester = self::tester(new AppDelete($this->catalogue, $deletion));

		$this->assertSame(Command::SUCCESS, $tester->execute(['slug' => 'vergunningen'], ['interactive' => false]));
		$this->assertStringContainsString('Nothing was deleted.', $tester->getDisplay());

	}//end testANonInteractiveDeleteWithoutForceDeletesNothing()

	/**
	 * Confirming a delete with its data names the data in the question and says it went too.
	 *
	 * @return void
	 */
	public function testAConfirmedDeleteWithDataSaysTheDataWentToo(): void {
		$deletion = $this->createMock(ApplicationDeletionServiceInterface::class);
		$deletion->expects($this->once())->method('deleteApplication')->with('app-v', 'vergunningen', true)->willReturn([]);
		$tester = self::tester(new AppDelete($this->catalogue, $deletion));

		$tester->setInputs(['y']);
		$this->assertSame(Command::SUCCESS, $tester->execute(['slug' => 'vergunningen', '--delete-data' => true]));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('Delete the app "vergunningen" and its versions, AND its registers and records? [y/N]', $display);
		$this->assertStringContainsString('Deleted the app "vergunningen" and its versions. Its registers and records were deleted too.', $display);

	}//end testAConfirmedDeleteWithDataSaysTheDataWentToo()

	/**
	 * The default version is the highest published semver (1.10 over 1.9, a draft never), run as the given user.
	 *
	 * @return void
	 */
	public function testExportPicksTheHighestPublishedSemverAndRunsAsTheUser(): void {
		$archive = (string)tempnam(sys_get_temp_dir(), 'bqzip');
		file_put_contents($archive, 'PK');
		$target = sys_get_temp_dir() . '/bq-export-' . uniqid() . '.zip';
		$exports = $this->createMock(ExportJobServiceInterface::class);
		$exports->expects($this->once())->method('queue')
			->with('vergunningen', ['applicationUuid' => 'app-v', 'applicationVersion' => '1.10.0', 'applicationVersionSlug' => 'v1-10', 'target' => 'zip', 'includeSeedData' => false], 'sanne')
			->willReturn('job-3');
		$exports->method('resolveDownload')->willReturn(['path' => $archive, 'expired' => false]);
		$tester = self::tester(new AppExport($this->catalogue, $exports));

		$this->assertSame(Command::SUCCESS, $tester->execute(['slug' => 'vergunningen', '--output' => ' ' . $target . ' ', '--user' => ' sanne ']));
		$this->assertStringContainsString('Wrote "vergunningen" v1-10 to ' . $target . '.', $tester->getDisplay());
		$this->assertSame('PK', file_get_contents($target));
		unlink($target);
		unlink($archive);

	}//end testExportPicksTheHighestPublishedSemverAndRunsAsTheUser()

	/**
	 * A named draft version can be exported; a blank --user runs as the default (null).
	 *
	 * @return void
	 */
	public function testANamedDraftVersionIsExported(): void {
		$exports = $this->createMock(ExportJobServiceInterface::class);
		$exports->expects($this->once())->method('queue')
			->with('vergunningen', $this->callback(static fn (array $p): bool => $p['applicationVersionSlug'] === 'development' && $p['applicationVersion'] === '2.0.0'), null)
			->willReturn('job-4');
		$exports->method('resolveDownload')->willReturn(['path' => '/nonexistent-archive.zip', 'expired' => false]);
		$tester = self::tester(new AppExport($this->catalogue, $exports));
		$target = sys_get_temp_dir() . '/bq-export-' . uniqid() . '.zip';

		$this->assertSame(Command::FAILURE, $tester->execute(['slug' => 'vergunningen', '--output' => $target, '--app-version' => 'development', '--user' => '  ']));
		$this->assertStringContainsString('produced no archive (job job-4)', $tester->getDisplay());
		$this->assertFileDoesNotExist($target);

	}//end testANamedDraftVersionIsExported()

	/**
	 * A failing queue or run is reported with its message and exits non-zero.
	 *
	 * @return void
	 */
	public function testAThrowingExportIsReported(): void {
		$exports = $this->createMock(ExportJobServiceInterface::class);
		$exports->method('queue')->willReturn('job-5');
		$exports->method('runNow')->willThrowException(new RuntimeException('worker gone'));
		$exports->expects($this->never())->method('resolveDownload');
		$tester = self::tester(new AppExport($this->catalogue, $exports));
		$target = sys_get_temp_dir() . '/bq-export-' . uniqid() . '.zip';

		$this->assertSame(Command::FAILURE, $tester->execute(['slug' => 'vergunningen', '--output' => $target]));
		$this->assertStringContainsString('The export failed: worker gone', $tester->getDisplay());

	}//end testAThrowingExportIsReported()

	/**
	 * A folder as the output, or an app without a uuid, is refused before anything is queued.
	 *
	 * @return void
	 */
	public function testAFolderOutputAndAnAppWithoutUuidAreRefused(): void {
		$exports = $this->createMock(ExportJobServiceInterface::class);
		$exports->expects($this->never())->method('queue');
		$tester = self::tester(new AppExport($this->catalogue, $exports));
		$folder = sys_get_temp_dir();

		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'vergunningen', '--output' => $folder]));
		$this->assertStringContainsString('The output path "' . $folder . '" is a folder; name the file. Nothing was written.', $tester->getDisplay());

		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'zonder-id', '--output' => $folder . '/x.zip']));
		$this->assertStringContainsString('No app with the slug "zonder-id". Nothing was written.', $tester->getDisplay());

	}//end testAFolderOutputAndAnAppWithoutUuidAreRefused()
}//end class
