<?php

/**
 * Unit tests for AppPackageImportService: the archive checks, the package
 * filter, the parser's refusals and the hand-over to the install seam.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/lifecycle-import-app-and-cli/specs/app-package-import/spec.md#requirement-the-archive-is-read-and-never-run-req-bqic-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Controller\ApplicationsController;
use OCA\Buildiq\Service\AppPackageImportService;
use OCA\Buildiq\Service\AppRepoParser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Tests for importing an exported archive.
 *
 * @covers \OCA\Buildiq\Service\AppPackageImportService
 * @uses   \OCA\Buildiq\Service\AppRepoParser
 * @uses   \OCA\Buildiq\Exception\AppRepoParseException
 * @uses   \OCA\Buildiq\Service\SchemaSlugClaims
 */
class AppPackageImportServiceTest extends TestCase {

	/**
	 * The install seam mock.
	 *
	 * @var ApplicationsController&MockObject
	 */
	private ApplicationsController&MockObject $install;

	/**
	 * The archives written by this test, removed afterwards.
	 *
	 * @var array<int,string>
	 */
	private array $archives = [];

	/**
	 * Build the install seam mock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->install = $this->createMock(ApplicationsController::class);

	}//end setUp()

	/**
	 * Remove the archives.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->archives as $archive) {
			@unlink($archive);
		}

		parent::tearDown();

	}//end tearDown()

	/**
	 * The service under test, with the real parser.
	 *
	 * @return AppPackageImportService The service.
	 */
	private function service(): AppPackageImportService {
		return new AppPackageImportService(new AppRepoParser(), $this->install);

	}//end service()

	/**
	 * Write an archive with the given entries.
	 *
	 * @param array<string,string> $entries  Contents by path.
	 * @param array<int,string>    $symlinks Paths to mark as Unix symlinks.
	 *
	 * @return string The archive path.
	 */
	private function archive(array $entries, array $symlinks = []): string {
		$path = (string)tempnam(sys_get_temp_dir(), 'bqimport');
		$zip = new ZipArchive();
		$zip->open($path, (ZipArchive::CREATE | ZipArchive::OVERWRITE));
		foreach ($entries as $name => $contents) {
			$zip->addFromString($name, $contents);
		}

		foreach ($symlinks as $name) {
			$zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ((0120777 << 16)));
		}

		$zip->close();
		$this->archives[] = $path;
		return $path;

	}//end archive()

	/**
	 * A minimal exported package, plus whatever else the export carries.
	 *
	 * @param array<string,string> $extra More entries.
	 *
	 * @return array<string,string> The entries.
	 */
	private static function package(array $extra = []): array {
		return array_merge(
			[
				'openbuild-app.json' => (string)json_encode(['slug' => 'vergunningen', 'name' => 'Vergunningen', 'version' => '1.2.0', 'formatVersion' => '1.0', 'appType' => 'virtual']),
				'manifest.json' => (string)json_encode(['version' => '1.0.0', 'menu' => [], 'pages' => []]),
				'schemas/permit.json' => (string)json_encode(['slug' => 'permit', 'title' => 'Permit', 'properties' => ['name' => ['type' => 'string']]]),
			],
			$extra
		);

	}//end package()

	/**
	 * An exported archive is parsed and installed under its own slug and name, owned by the caller,
	 * and the exported Nextcloud app code in it is never read.
	 *
	 * @return void
	 */
	public function testAnExportedArchiveIsInstalledForTheCaller(): void {
		$archive = $this->archive(self::package(['lib/Evil.php' => '<?php exit;', 'appinfo/info.xml' => '<info/>', 'data/permit.jsonl' => "{\"name\":\"a\"}\n", 'README.md' => '# x']));
		$this->install->expects($this->once())->method('installFromTemplateArray')
			->willReturnCallback(
				function (array $template, string $name, string $newSlug, string $ownerUid): array {
					$this->assertSame('Vergunningen', $name);
					$this->assertSame('vergunningen', $newSlug);
					$this->assertSame('sanne', $ownerUid);
					$this->assertSame('permit', $template['companionSchemas'][0]['slug']);
					return ['status' => 201, 'data' => ['slug' => $newSlug, 'uuid' => 'app-1']];
				}
			);

		$this->assertSame(['status' => 201, 'data' => ['slug' => 'vergunningen', 'uuid' => 'app-1']], $this->service()->import($archive, '', '', 'sanne'));

		$read = $this->service()->readPackage($archive);
		$this->assertSame(['openbuild-app.json', 'manifest.json', 'schemas/permit.json', 'data/permit.jsonl'], array_keys($read));

	}//end testAnExportedArchiveIsInstalledForTheCaller()

	/**
	 * A given slug and name win over the archive's own, and a taken slug is the seam's 409, passed on.
	 *
	 * @return void
	 */
	public function testAGivenSlugIsUsedAndATakenOneIsPassedOn(): void {
		$archive = $this->archive(self::package());
		$this->install->expects($this->once())->method('installFromTemplateArray')
			->with($this->anything(), 'Vergunningen test', 'vergunningen-test', 'sanne')
			->willReturn(['status' => 409, 'data' => ['error' => 'slug_collision', 'detail' => 'vergunningen-test']]);

		$result = $this->service()->import($archive, 'Vergunningen test', 'vergunningen-test', 'sanne');

		$this->assertSame(409, $result['status']);
		$this->assertSame('slug_collision', $result['data']['error']);

	}//end testAGivenSlugIsUsedAndATakenOneIsPassedOn()

	/**
	 * A slug that is not kebab-case is refused before anything is installed.
	 *
	 * @return void
	 */
	public function testABadSlugIsRefused(): void {
		$this->install->expects($this->never())->method('installFromTemplateArray');

		$result = $this->service()->import($this->archive(self::package()), '', 'Not A Slug', 'sanne');

		$this->assertSame(400, $result['status']);
		$this->assertSame('invalid_slug', $result['data']['error']);

	}//end testABadSlugIsRefused()

	/**
	 * Unsafe archives are refused as a whole, naming the entry, and nothing is installed.
	 *
	 * @return void
	 */
	public function testUnsafeArchivesAreRefused(): void {
		$this->install->expects($this->never())->method('installFromTemplateArray');
		$cases = [
			'archive_unsafe_path' => [$this->archive(self::package(['../../config/config.php' => 'x'])), '../../config/config.php'],
			'archive_symlink' => [$this->archive(self::package(['schemas/link.json' => '/etc/passwd']), ['schemas/link.json']), 'schemas/link.json'],
			'schema_unparseable' => [$this->archive(self::package(['schemas/permit.json' => '{not json'])), 'schemas/permit.json'],
		];

		foreach ($cases as $code => [$archive, $path]) {
			$result = $this->service()->import($archive, '', '', 'sanne');
			$this->assertSame(422, $result['status'], $code);
			$this->assertSame($code, $result['data']['error']);
			$this->assertSame($path, $result['data']['path']);
		}

	}//end testUnsafeArchivesAreRefused()

	/**
	 * An absolute path and a Windows drive path are unsafe too.
	 *
	 * @return void
	 */
	public function testAbsolutePathsAreUnsafe(): void {
		foreach (['/etc/passwd', 'C:/Windows/x', 'schemas\\..\\..\\x'] as $name) {
			$result = $this->service()->import($this->archive(self::package([$name => 'x'])), '', '', 'sanne');
			$this->assertSame('archive_unsafe_path', $result['data']['error'], $name);
		}

	}//end testAbsolutePathsAreUnsafe()

	/**
	 * An archive over the entry cap is refused before it is read, as too large.
	 *
	 * @return void
	 */
	public function testAnArchiveOverTheEntryCapIsRefused(): void {
		$entries = self::package();
		for ($index = 0; $index < AppPackageImportService::MAX_ENTRIES; $index++) {
			$entries['assets/' . $index . '.txt'] = '';
		}

		$result = $this->service()->import($this->archive($entries), '', '', 'sanne');

		$this->assertSame(413, $result['status']);
		$this->assertSame('archive_too_many_entries', $result['data']['error']);

	}//end testAnArchiveOverTheEntryCapIsRefused()

	/**
	 * A file that is not a zip, or is missing, is refused as unreadable.
	 *
	 * @return void
	 */
	public function testANonArchiveIsUnreadable(): void {
		$path = (string)tempnam(sys_get_temp_dir(), 'bqimport');
		file_put_contents($path, 'not a zip');
		$this->archives[] = $path;

		$this->assertSame('archive_unreadable', $this->service()->import($path, '', '', 'sanne')['data']['error']);
		$this->assertSame('archive_unreadable', $this->service()->import($path . '-missing', '', '', 'sanne')['data']['error']);

	}//end testANonArchiveIsUnreadable()

	/**
	 * Only the package paths are package files; folders and the app's own code are not.
	 *
	 * @return void
	 */
	public function testOnlyPackagePathsAreRead(): void {
		foreach (['openbuild-app.json', 'manifest.json', 'schemas/a.json', 'flows/f.json', 'agents/a.json', 'data/a.jsonl', 'connectors/c.json'] as $path) {
			$this->assertTrue(AppPackageImportService::isPackagePath($path), $path);
		}

		foreach (['lib/A.php', 'src/main.js', 'appinfo/info.xml', 'schemas/', 'README.md', 'sub/manifest.json'] as $path) {
			$this->assertFalse(AppPackageImportService::isPackagePath($path), $path);
		}

	}//end testOnlyPackagePathsAreRead()
}//end class
