<?php

/**
 * Golden test for ExportService::generateAppZip().
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\DataRegisterExportBundler;
use OCA\Buildiq\Service\ExportAppContentBundler;
use OCA\Buildiq\Service\ExportBundlers;
use OCA\Buildiq\Service\ExportContents;
use OCA\Buildiq\Service\ExportService;
use OCA\Buildiq\Service\ExportTarget;
use OCA\Buildiq\Service\FlowAndAgentExportBundler;
use OCA\Buildiq\Service\PlaceholderResolver;
use OCA\Buildiq\Service\ServerBundleWriter;
use OCP\Files\IAppData;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ZipArchive;

/**
 * Pins what generateAppZip() builds for every combination of source,
 * content bundler, server bundle flag and writer, data registers, flows,
 * application slug and context: the arguments each bundler receives, the
 * archive entries (name and content hash), lastContent(), lastSkipped()
 * and the log lines. Set GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\ExportService
 * @uses   \OCA\Buildiq\Service\ExportContents
 * @uses   \OCA\Buildiq\Service\ExportTarget
 * @uses   \OCA\Buildiq\Service\ExportTreeFiles
 * @uses   \OCA\Buildiq\Service\PlaceholderResolver
 * @uses   \OCA\Buildiq\Service\ExportBundlers
 */
class ExportZipGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/export-zip.golden.json';

	/**
	 * Calls recorded from the doubles, in order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $calls = [];

	/**
	 * Every case, by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function cases(): array {
		$context = [
			'appId' => 'Demo App',
			'appNamespace' => 'DemoApp',
			'appName' => 'Demo App',
			'appVersion' => '1.2.3',
			'authorName' => 'Dev',
			'authorEmail' => 'dev@conduction.nl',
			'license' => 'EUPL-1.2',
		];
		$source = ['application' => ['slug' => 'demo'], 'version' => ['slug' => 'development'], 'includeSeedData' => true];
		$registers = [['register' => 'r1', 'includeData' => true], ['register' => 'r2']];
		$flows = [['flow' => 'f1'], 'loose'];
		$cases = [];
		foreach ([null, $source] as $si => $src) {
			foreach ([true, false] as $withContent) {
				foreach ([false, true] as $bundleFlag) {
					foreach ([true, false] as $withWriter) {
						$cases[sprintf('src%d content%d flag%d writer%d', $si, (int)$withContent, (int)$bundleFlag, (int)$withWriter)] = [
							'applicationUuid' => 'uuid-' . $si,
							'versionSlug' => '1.2.' . $si,
							'context' => $context,
							'dataRegisters' => $registers,
							'flows' => $flows,
							'applicationSlug' => 'demo-slug',
							'source' => $src,
							'includeServerBundle' => $bundleFlag,
							'withContent' => $withContent,
							'withWriter' => $withWriter,
						];
					}
				}
			}
		}

		$cases['defaults only'] = [
			'applicationUuid' => 'uuid-d',
			'versionSlug' => '0.0.1',
			'context' => ['appId' => ''],
			'withContent' => true,
			'withWriter' => true,
		];
		$cases['context without appId'] = [
			'applicationUuid' => '',
			'versionSlug' => '',
			'context' => ['appName' => 'X'],
			'source' => [],
			'withContent' => true,
			'withWriter' => false,
		];
		return $cases;
	}//end cases()

	/**
	 * Run every case and compare with the recorded fixture.
	 *
	 * @return void
	 */
	public function testGenerateAppZipMatchesGolden(): void {
		$actual = [];
		foreach ($this->cases() as $name => $case) {
			$actual[$name] = $this->runCase(name: $name, case: $case);
		}

		if (file_exists(self::FIXTURE) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($actual as $name => $result) {
			self::assertSame($expected[$name] ?? null, json_decode((string)json_encode($result), true), $name);
		}

		self::assertSame(array_keys($expected), array_keys($actual));
	}//end testGenerateAppZipMatchesGolden()

	/**
	 * Build the service for one case, export, and record the outcome.
	 *
	 * @param string $name Case name.
	 * @param array<string,mixed> $case The case.
	 *
	 * @return array<string,mixed> What the case produced.
	 */
	private function runCase(string $name, array $case): array {
		$this->calls = [];
		$jobUuid = 'golden-' . substr(sha1($name), 0, 12);
		$service = $this->buildService(withContent: $case['withContent'], withWriter: $case['withWriter']);

		$zipPath = $this->export(service: $service, jobUuid: $jobUuid, case: $case);

		$entries = [];
		$zip = new ZipArchive();
		self::assertTrue($zip->open($zipPath) === true);
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$entries[(string)$zip->getNameIndex($i)] = sha1((string)$zip->getFromIndex($i));
		}

		$zip->close();
		unlink($zipPath);
		self::assertFileExists($service->scratchTreeDir(jobUuid: $jobUuid) . '/appinfo/info.xml', $name . ': the tree stays where the GitHub push reads it');
		$this->removeTree(dir: $service->scratchTreeDir(jobUuid: $jobUuid));

		return [
			'zip' => basename($zipPath),
			'entries' => $entries,
			'lastContent' => $service->lastContent(),
			'lastSkipped' => $service->lastSkipped(),
			'calls' => $this->calls,
		];
	}//end runCase()

	/**
	 * Call generateAppZip() with the case's arguments, leaving out the ones the case leaves out.
	 *
	 * @param ExportService $service The service.
	 * @param string $jobUuid Job UUID.
	 * @param array<string,mixed> $case The case.
	 *
	 * @return string The ZIP path.
	 */
	private function export(ExportService $service, string $jobUuid, array $case): string {
		$target = ['applicationUuid' => $case['applicationUuid'], 'versionSlug' => $case['versionSlug'], 'jobUuid' => $jobUuid];
		$contents = ['context' => $case['context']];
		$flag = [];
		foreach (['applicationSlug' => 'target', 'dataRegisters' => 'contents', 'flows' => 'contents', 'source' => 'contents', 'includeServerBundle' => 'flag'] as $key => $into) {
			if (array_key_exists($key, $case) === true) {
				${$into}[$key] = $case[$key];
			}
		}

		return $service->generateAppZip(new ExportTarget(...$target), new ExportContents(...$contents), ...$flag);
	}//end export()

	/**
	 * The service with recording doubles for every bundler.
	 *
	 * @param bool $withContent Wire a content bundler.
	 * @param bool $withWriter Wire a server bundle writer.
	 *
	 * @return ExportService The service.
	 */
	private function buildService(bool $withContent, bool $withWriter): ExportService {
		$registers = $this->createMock(DataRegisterExportBundler::class);
		$registers->method('bundle')->willReturnCallback(function (string $rootDir, array $dataRegisters): void {
			$this->record(call: 'registers.bundle', args: ['rootDir' => $rootDir, 'dataRegisters' => $dataRegisters]);
		});

		$flows = $this->createMock(FlowAndAgentExportBundler::class);
		$flows->method('bundle')->willReturnCallback(function (string $rootDir, array $flows, string $applicationSlug): array {
			$this->record(call: 'flows.bundle', args: ['rootDir' => $rootDir, 'flows' => $flows, 'applicationSlug' => $applicationSlug]);
			return [['kind' => 'flow', 'ref' => $applicationSlug . '#' . count($flows), 'reason' => 'golden']];
		});

		$content = null;
		if ($withContent === true) {
			$content = $this->createMock(ExportAppContentBundler::class);
			$content->method('bundle')->willReturnCallback(function (string $rootDir, array $source, string $appId, string $semver): array {
				$this->record(call: 'content.bundle', args: ['rootDir' => $rootDir, 'source' => $source, 'appId' => $appId, 'semver' => $semver]);
				file_put_contents($rootDir . '/golden-content.txt', $appId . '@' . $semver);
				return ['pages' => count($source), 'menu' => 1, 'schemas' => 2, 'records' => 3];
			});
		}

		$writer = null;
		if ($withWriter === true) {
			$writer = $this->createMock(ServerBundleWriter::class);
			$writer->method('write')->willReturnCallback(function (string $rootDir): void {
				$this->record(call: 'writer.write', args: ['rootDir' => $rootDir]);
				mkdir($rootDir . '/deploy');
				file_put_contents($rootDir . '/deploy/golden.txt', 'deploy');
			});
			$writer->method('pointReadme')->willReturnCallback(function (string $rootDir): void {
				$this->record(call: 'writer.pointReadme', args: ['rootDir' => $rootDir]);
			});
		}

		$test = $this;
		$logger = new class($test) extends AbstractLogger {
			/**
			 * @param ExportZipGoldenTest $test The recording test.
			 */
			public function __construct(
				private ExportZipGoldenTest $test,
			) {
			}

			/**
			 * @param mixed $level Level.
			 * @param string|\Stringable $message Message.
			 * @param array<string,mixed> $context Context.
			 *
			 * @return void
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->test->record(call: 'log.' . $level, args: ['message' => (string)$message, 'context' => $context]);
			}
		};

		return new ExportService(
			appData: $this->createStub(IAppData::class),
			placeholderResolver: new PlaceholderResolver(),
			logger: $logger,
			bundlers: new ExportBundlers(
				dataRegisterBundler: $registers,
				flowAndAgentBundler: $flows,
				contentBundler: $content,
				serverBundleWriter: $writer
			)
		);
	}//end buildService()

	/**
	 * Record one call, with the scratch directory replaced by a token.
	 *
	 * @param string $call Call name.
	 * @param array<string,mixed> $args Arguments by name.
	 *
	 * @return void
	 */
	public function record(string $call, array $args): void {
		$json = (string)json_encode($args, JSON_UNESCAPED_SLASHES);
		$json = (string)preg_replace('#"' . preg_quote(sys_get_temp_dir(), '#') . '/buildiq-work/[^"]*"#', '"<scratch>"', $json);
		$this->calls[] = ['call' => $call, 'args' => json_decode($json, true)];
	}//end record()

	/**
	 * Remove a directory tree.
	 *
	 * @param string $dir The directory.
	 *
	 * @return void
	 */
	private function removeTree(string $dir): void {
		if (is_dir($dir) === false) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($items as $item) {
			if ($item->isDir() === true) {
				rmdir($item->getPathname());
				continue;
			}

			unlink($item->getPathname());
		}

		rmdir($dir);
	}//end removeTree()
}//end class
