<?php

/**
 * Unit tests for the buildiq:app:import, :list, :delete and :export commands.
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
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-management-cli/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Command;

use OCA\Buildiq\Command\AppDelete;
use OCA\Buildiq\Command\AppExport;
use OCA\Buildiq\Command\AppImport;
use OCA\Buildiq\Command\AppList;
use OCA\Buildiq\Kernel\Contracts\AppPackageImportServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ApplicationDeletionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ExportJobServiceInterface;
use OCA\Buildiq\Service\AppCatalogue;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the app management commands.
 *
 * @covers \OCA\Buildiq\Command\AppImport
 * @covers \OCA\Buildiq\Command\AppList
 * @covers \OCA\Buildiq\Command\AppDelete
 * @covers \OCA\Buildiq\Command\AppExport
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 */
class AppCommandsTest extends TestCase {

	/**
	 * Catalogue mock.
	 *
	 * @var AppCatalogue&MockObject
	 */
	private AppCatalogue&MockObject $catalogue;

	/**
	 * Build the catalogue mock with two apps.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->catalogue = $this->createMock(AppCatalogue::class);
		$apps = [
			'meldingen' => ['slug' => 'meldingen', 'name' => 'Meldingen', '@self' => ['id' => 'app-m']],
			'vergunningen' => ['slug' => 'vergunningen', 'name' => 'Vergunningen', '@self' => ['id' => 'app-v']],
		];
		$this->catalogue->method('apps')->willReturn(array_values($apps));
		$this->catalogue->method('app')->willReturnCallback(static fn (string $slug): ?array => ($apps[$slug] ?? null));
		$this->catalogue->method('versions')->willReturnCallback(
			static fn (string $uuid): array => match ($uuid) {
				'app-v' => [
					['slug' => 'development', 'semver' => '1.3.0', 'status' => 'draft'],
					['slug' => 'production', 'semver' => '1.2.0', 'status' => 'published'],
					['slug' => 'v1-1', 'semver' => '1.1.0', 'status' => 'published'],
				],
				default => [],
			}
		);

	}//end setUp()

	/**
	 * A command in a tester; interactive input is fed when given.
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
	 * The list prints every app with its versions and their status, and as JSON for scripts.
	 *
	 * @return void
	 */
	public function testTheListShowsEveryAppWithItsVersions(): void {
		$tester = self::tester(new AppList($this->catalogue));
		$this->assertSame(0, $tester->execute([]));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('vergunningen', $display);
		$this->assertStringContainsString('development (draft), production (published), v1-1 (published)', $display);
		$this->assertStringContainsString('meldingen', $display);

		$this->assertSame(0, $tester->execute(['--json' => true]));
		$rows = json_decode($tester->getDisplay(), true);
		$this->assertSame(['slug' => 'meldingen', 'name' => 'Meldingen', 'versions' => []], $rows[0]);
		$this->assertSame('production', $rows[1]['versions'][1]['slug']);

	}//end testTheListShowsEveryAppWithItsVersions()

	/**
	 * The import needs an existing owner and a readable file, and runs the browser's import service.
	 *
	 * @return void
	 */
	public function testTheImportRunsAsAnExistingOwner(): void {
		$file = (string)tempnam(sys_get_temp_dir(), 'bqcli');
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'sanne');
		$importer = $this->createMock(AppPackageImportServiceInterface::class);
		$importer->expects($this->once())->method('import')->with($file, '', 'vergunningen', 'sanne')
			->willReturn(['status' => 201, 'data' => ['slug' => 'vergunningen', 'warnings' => ['skills skipped']]]);
		$tester = self::tester(new AppImport($importer, $users));

		$this->assertSame(Command::INVALID, $tester->execute(['file' => $file]));
		$this->assertStringContainsString('--owner is required', $tester->getDisplay());
		$this->assertSame(Command::INVALID, $tester->execute(['file' => $file, '--owner' => 'nobody-here']));
		$this->assertStringContainsString('The user "nobody-here" does not exist', $tester->getDisplay());
		$this->assertSame(Command::INVALID, $tester->execute(['file' => $file . '-missing', '--owner' => 'sanne']));

		$this->assertSame(Command::SUCCESS, $tester->execute(['file' => $file, '--owner' => 'sanne', '--slug' => 'vergunningen']));
		$this->assertStringContainsString('Imported the app "vergunningen" for sanne.', $tester->getDisplay());
		$this->assertStringContainsString('Warning: skills skipped', $tester->getDisplay());
		unlink($file);

	}//end testTheImportRunsAsAnExistingOwner()

	/**
	 * A refused package exits non-zero with the parser's code and the file at fault.
	 *
	 * @return void
	 */
	public function testARefusedPackagePrintsTheCodeAndFile(): void {
		$file = (string)tempnam(sys_get_temp_dir(), 'bqcli');
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturn(true);
		$importer = $this->createMock(AppPackageImportServiceInterface::class);
		$importer->method('import')->willReturn(['status' => 422, 'data' => ['error' => 'schema_unparseable', 'detail' => 'bad JSON', 'path' => 'schemas/permit.json']]);
		$tester = self::tester(new AppImport($importer, $users));

		$this->assertSame(Command::FAILURE, $tester->execute(['file' => $file, '--owner' => 'sanne']));
		$this->assertStringContainsString('Import refused: schema_unparseable in schemas/permit.json: bad JSON', $tester->getDisplay());
		unlink($file);

	}//end testARefusedPackagePrintsTheCodeAndFile()

	/**
	 * Delete asks first: a declined prompt deletes nothing, a confirmed one keeps the data by default.
	 *
	 * @return void
	 */
	public function testDeleteAsksAndKeepsTheDataByDefault(): void {
		$deletion = $this->createMock(ApplicationDeletionServiceInterface::class);
		$deletion->expects($this->once())->method('deleteApplication')->with('app-m', 'meldingen', false)->willReturn([]);
		$tester = self::tester(new AppDelete($this->catalogue, $deletion));

		$tester->setInputs(['n']);
		$this->assertSame(Command::SUCCESS, $tester->execute(['slug' => 'meldingen']));
		$this->assertStringContainsString('Nothing was deleted.', $tester->getDisplay());

		$tester->setInputs(['y']);
		$this->assertSame(Command::SUCCESS, $tester->execute(['slug' => 'meldingen']));
		$this->assertStringContainsString('Its registers and records were kept.', $tester->getDisplay());

	}//end testDeleteAsksAndKeepsTheDataByDefault()

	/**
	 * A forced delete with its data skips the prompt, lists what it could not remove, and exits non-zero.
	 *
	 * @return void
	 */
	public function testAForcedDeleteListsWhatWasLeft(): void {
		$deletion = $this->createMock(ApplicationDeletionServiceInterface::class);
		$deletion->expects($this->once())->method('deleteApplication')->with('app-v', 'vergunningen', true)->willReturn(['register:vergunningen-production']);
		$tester = self::tester(new AppDelete($this->catalogue, $deletion));

		$this->assertSame(Command::FAILURE, $tester->execute(['slug' => 'vergunningen', '--force' => true, '--delete-data' => true]));
		$this->assertStringContainsString('register:vergunningen-production', $tester->getDisplay());

		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'onbekend', '--force' => true]));

	}//end testAForcedDeleteListsWhatWasLeft()

	/**
	 * Export writes the newest published version's archive to the output path.
	 *
	 * @return void
	 */
	public function testExportWritesTheNewestPublishedVersion(): void {
		$archive = (string)tempnam(sys_get_temp_dir(), 'bqzip');
		file_put_contents($archive, 'PK-archive');
		$target = sys_get_temp_dir() . '/bq-export-' . uniqid() . '.zip';
		$exports = $this->createMock(ExportJobServiceInterface::class);
		$exports->expects($this->once())->method('queue')
			->with('vergunningen', ['applicationUuid' => 'app-v', 'applicationVersion' => '1.2.0', 'applicationVersionSlug' => 'production', 'target' => 'zip', 'includeSeedData' => true], null)
			->willReturn('job-1');
		$exports->expects($this->once())->method('runNow')->with('job-1')->willReturn(true);
		$exports->method('resolveDownload')->with('job-1')->willReturn(['path' => $archive, 'expired' => false]);
		$tester = self::tester(new AppExport($this->catalogue, $exports));

		$this->assertSame(Command::SUCCESS, $tester->execute(['slug' => 'vergunningen', '--output' => $target, '--seed-data' => true]));
		$this->assertSame('PK-archive', file_get_contents($target));
		unlink($target);
		unlink($archive);

	}//end testExportWritesTheNewestPublishedVersion()

	/**
	 * An unknown slug, an unknown version or an unwritable path writes nothing and queues nothing.
	 *
	 * @return void
	 */
	public function testExportRefusesBeforeQueueing(): void {
		$exports = $this->createMock(ExportJobServiceInterface::class);
		$exports->expects($this->never())->method('queue');
		$tester = self::tester(new AppExport($this->catalogue, $exports));
		$target = sys_get_temp_dir() . '/bq-export-' . uniqid() . '.zip';

		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'onbekend', '--output' => $target]));
		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'vergunningen', '--output' => $target, '--app-version' => 'nope']));
		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'meldingen', '--output' => $target]));
		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'vergunningen', '--output' => '/nonexistent-dir/x.zip']));
		$this->assertSame(Command::INVALID, $tester->execute(['slug' => 'vergunningen']));
		$this->assertFileDoesNotExist($target);

	}//end testExportRefusesBeforeQueueing()

	/**
	 * A failed export job exits non-zero and writes nothing.
	 *
	 * @return void
	 */
	public function testAnExportWithoutAnArchiveFails(): void {
		$exports = $this->createMock(ExportJobServiceInterface::class);
		$exports->method('queue')->willReturn('job-2');
		$exports->method('resolveDownload')->willReturn(null);
		$tester = self::tester(new AppExport($this->catalogue, $exports));
		$target = sys_get_temp_dir() . '/bq-export-' . uniqid() . '.zip';

		$this->assertSame(Command::FAILURE, $tester->execute(['slug' => 'vergunningen', '--output' => $target, '--app-version' => 'v1-1']));
		$this->assertStringContainsString('produced no archive (job job-2)', $tester->getDisplay());
		$this->assertFileDoesNotExist($target);

	}//end testAnExportWithoutAnArchiveFails()
}//end class
