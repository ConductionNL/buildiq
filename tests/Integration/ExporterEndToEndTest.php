<?php

/**
 * OpenBuild Exporter end-to-end integration test
 *
 * Exercises the file-generation pipeline against the real embedded template
 * snapshot through the one entry point production uses — ExportService::
 * generateAppZip(), which RunExportJob calls. Asserts the produced archive has
 * no unresolved tokens, is byte-equivalent across re-runs (REQ-OBEX-008), and
 * carries no `openbuild` dependency reference in its dependency manifests
 * (REQ-OBEX-010).
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Integration
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

namespace OCA\OpenBuild\Tests\Integration;

use OCA\OpenBuild\Service\DataRegisterExportBundler;
use OCA\OpenBuild\Service\ExportService;
use OCA\OpenBuild\Service\PlaceholderResolver;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Files\IAppData;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ZipArchive;

/**
 * End-to-end exporter tests against the embedded template snapshot.
 */
final class ExporterEndToEndTest extends TestCase {
	private string $templateRoot;

	/**
	 * Paths the exporter created, removed in tearDown().
	 *
	 * @var array<int,string>
	 */
	private array $litter = [];

	protected function setUp(): void {
		parent::setUp();
		$this->templateRoot = dirname(__DIR__, 2) . '/lib/Resources/template';
	}//end setUp()

	protected function tearDown(): void {
		foreach ($this->litter as $path) {
			if (is_dir($path) === true) {
				$this->rrmdir($path);
				continue;
			}

			if (file_exists($path) === true) {
				unlink($path);
			}
		}

		$this->litter = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * The exporter resolves the template into an archive with no unresolved
	 * placeholders and no leftover `openbuild` dependency reference.
	 *
	 * @return void
	 */
	public function testResolvedTreeIsStandaloneAndComplete(): void {
		if (is_dir($this->templateRoot) === false) {
			self::markTestSkipped('Embedded template snapshot not present.');
		}

		$entries = $this->export(jobUuid: 'e2e-standalone-' . bin2hex(random_bytes(4)));
		self::assertNotSame([], $entries);

		// No unresolved {{token}} in any text file.
		foreach ($entries as $relative => $contents) {
			if ($this->hasBinaryExtension($relative) === true) {
				continue;
			}

			self::assertDoesNotMatchRegularExpression(
				'/\{\{[a-zA-Z]+\}\}/',
				$contents,
				'Unresolved placeholder left in ' . $relative
			);
		}

		// REQ-OBEX-010: dependency manifests must not reference openbuild.
		foreach (['composer.json', 'package.json', 'appinfo/info.xml'] as $manifest) {
			if (array_key_exists($manifest, $entries) === false) {
				continue;
			}

			self::assertStringNotContainsStringIgnoringCase(
				'openbuild',
				$entries[$manifest],
				$manifest . ' must not reference openbuild as a dependency'
			);
		}
	}//end testResolvedTreeIsStandaloneAndComplete()

	/**
	 * A generated app declares the licence the export actually asked for, in
	 * every place it declares one.
	 *
	 * This exists because it did not. The embedded snapshot's
	 * `appinfo/info.xml` hardcoded `<licence>agpl</licence>` while the very
	 * same file's description read "Free and open source under the EUPL-1.2
	 * license", so every app OpenBuild has ever generated was born declaring a
	 * licence the fleet does not use — and nothing failed. The `{{license}}`
	 * token was already wired end to end (ExportJobService -> RunExportJob ->
	 * PlaceholderResolver, defaulting to EUPL-1.2 at all three layers) and
	 * reached exactly one file, `src/manifest.json`. info.xml never consumed
	 * it.
	 *
	 * The assertion is deliberately two-sided: it pins the resolved value AND
	 * rejects the specific wrong value that shipped. A one-sided "contains
	 * EUPL-1.2" would still pass on a file that declared both.
	 *
	 * @return void
	 */
	public function testGeneratedAppDeclaresTheRequestedLicence(): void {
		if (is_dir($this->templateRoot) === false) {
			self::markTestSkipped('Embedded template snapshot not present.');
		}

		$entries = $this->export(jobUuid: 'e2e-licence-' . bin2hex(random_bytes(4)));

		self::assertArrayHasKey(
			'appinfo/info.xml',
			$entries,
			'A generated app must ship appinfo/info.xml — without it there is no licence declaration to check.'
		);

		self::assertStringContainsString(
			'<licence>EUPL-1.2</licence>',
			$entries['appinfo/info.xml'],
			'appinfo/info.xml must declare the licence the export requested (EUPL-1.2).'
		);

		self::assertStringNotContainsString(
			'<licence>agpl</licence>',
			$entries['appinfo/info.xml'],
			'appinfo/info.xml still carries the legacy hardcoded AGPL declaration.'
		);

		// src/manifest.json and composer.json declare it too; all three must agree.
		foreach (['src/manifest.json', 'composer.json'] as $manifest) {
			if (array_key_exists($manifest, $entries) === false) {
				continue;
			}

			self::assertStringContainsString(
				'EUPL-1.2',
				$entries[$manifest],
				$manifest . ' must declare the same licence as appinfo/info.xml.'
			);
		}
	}//end testGeneratedAppDeclaresTheRequestedLicence()

	/**
	 * REQ-OBEX-008: re-exporting the same application + version yields
	 * per-file SHA-256 digests that are identical across runs.
	 *
	 * @return void
	 */
	public function testReExportIsByteEquivalent(): void {
		if (is_dir($this->templateRoot) === false) {
			self::markTestSkipped('Embedded template snapshot not present.');
		}

		$first = $this->export(jobUuid: 'e2e-run-a-' . bin2hex(random_bytes(4)));
		$second = $this->export(jobUuid: 'e2e-run-b-' . bin2hex(random_bytes(4)));

		self::assertSame(
			$this->digests($first),
			$this->digests($second),
			'Re-export of the same application must produce identical per-file digests'
		);
	}//end testReExportIsByteEquivalent()

	/**
	 * Run a real export and return the archive as `path => contents`.
	 *
	 * @param string $jobUuid The export job UUID (archive filename base).
	 *
	 * @return array<string,string> Archive entries, in archive order.
	 */
	private function export(string $jobUuid): array {
		$zipPath = $this->buildService()->generateAppZip(
			applicationUuid: 'e2e-application',
			versionSlug: '1.0.0',
			context: $this->context(),
			jobUuid: $jobUuid
		);

		$this->litter[] = $zipPath;
		$this->litter[] = sys_get_temp_dir() . '/openbuild-work/' . $jobUuid;

		self::assertFileExists($zipPath);

		$entries = [];
		$zip = new ZipArchive();
		self::assertTrue($zip->open($zipPath) === true);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = (string)$zip->getNameIndex($i);
			$entries[$name] = (string)$zip->getFromIndex($i);
		}

		$zip->close();
		return $entries;
	}//end export()

	/**
	 * Compute a map of entry-name → SHA-256 for a set of archive entries.
	 *
	 * @param array<string,string> $entries Archive entries.
	 *
	 * @return array<string,string> Entry-name → digest.
	 */
	private function digests(array $entries): array {
		$digests = [];
		foreach ($entries as $name => $contents) {
			$digests[$name] = hash('sha256', $contents);
		}

		ksort($digests);
		return $digests;
	}//end digests()

	/**
	 * The test's own view of which exported files are binary assets.
	 *
	 * Deliberately the test's own list, not the service's — asking the service
	 * what to skip would make the assertion agree with itself.
	 *
	 * @param string $path Relative path inside the export.
	 *
	 * @return bool True when the file is a binary asset.
	 */
	private function hasBinaryExtension(string $path): bool {
		$binary = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'zip', 'gz', 'tar', 'phar'];
		return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $binary, true);
	}//end hasBinaryExtension()

	/**
	 * Build the placeholder context for a sample export.
	 *
	 * @return array<string,string> Context map.
	 */
	private function context(): array {
		return [
			'appId' => 'melding-systeem',
			'appNamespace' => 'MeldingSysteem',
			'appName' => 'Melding Systeem',
			'appDescription' => 'Exported from OpenBuild',
			'appVersion' => '1.0.0',
			'authorName' => 'OpenBuild Citizen Developer',
			'authorEmail' => 'dev@conduction.nl',
			'license' => 'EUPL-1.2',
		];
	}//end context()

	/**
	 * Build an ExportService wired with stubbed IAppData.
	 *
	 * @return ExportService Service under test.
	 */
	private function buildService(): ExportService {
		$appData = $this->createStub(IAppData::class);
		$bundler = new DataRegisterExportBundler(
			$this->createStub(RegisterMapper::class),
			$this->createStub(SchemaMapper::class),
			$this->createStub(ObjectService::class),
			new NullLogger()
		);

		return new ExportService(
			$appData,
			new PlaceholderResolver(),
			new NullLogger(),
			$bundler,
			// The flow/agent bundler. Injected as a no-op double: these tests
			// are about the data-register path and the ZIP mechanics, and a
			// real one here would couple them to a second store.
			new \OCA\OpenBuild\Service\FlowAndAgentExportBundler(
				$this->createMock(\OCA\OpenRegister\Db\FlowMapper::class),
				$this->createMock(\OCA\OpenRegister\Service\ObjectService::class),
				new NullLogger()
			)
		);
	}//end buildService()

	/**
	 * Recursive directory removal helper.
	 *
	 * @param string $dir Directory to remove.
	 *
	 * @return void
	 */
	private function rrmdir(string $dir): void {
		if (is_dir($dir) === false) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $entry) {
			if ($entry->isDir() === true) {
				rmdir((string)$entry->getPathname());
			} else {
				unlink((string)$entry->getPathname());
			}
		}

		rmdir($dir);
	}//end rrmdir()
}//end class
