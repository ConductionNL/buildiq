<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Buildiq\Service\Icon\SvgRasteriser;
use OCA\Buildiq\Service\IconService;
use OCA\Buildiq\Service\MobileProjectWriter;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see MobileProjectWriter}: the bundle id, the configuration, the images and the README.
 *
 * The project's place in the export archive is asserted in ExportServiceTest.
 *
 * @spec openspec/changes/archive/2026-11-28-pages-installable-offline-and-mobile/tasks.md#T08
 */
#[CoversClass(MobileProjectWriter::class)]
final class MobileProjectWriterTest extends TestCase {

	private string $root = '';

	private IconService&MockObject $icons;

	private SvgRasteriser&MockObject $rasteriser;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/buildiq-mobile-' . bin2hex(random_bytes(6));
		mkdir($this->root);
		$this->icons = $this->createMock(IconService::class);
		$this->rasteriser = $this->createMock(SvgRasteriser::class);
	}//end setUp()

	protected function tearDown(): void {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $entry) {
			if ($entry->isDir() === true) {
				rmdir((string)$entry->getPathname());
			} else {
				unlink((string)$entry->getPathname());
			}
		}

		rmdir($this->root);
	}//end tearDown()

	/**
	 * The writer, running at the given URL.
	 *
	 * @param string $runUrl The run URL the URL generator answers.
	 *
	 * @return MobileProjectWriter
	 */
	private function writer(string $runUrl = 'https://cloud.example.nl/index.php/apps/buildiq/run/toezicht/'): MobileProjectWriter {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('linkToRouteAbsolute')
			->with('buildiq.dashboard.run', ['slug' => 'toezicht'])
			->willReturn($runUrl);

		return new MobileProjectWriter($urls, $this->icons, $this->rasteriser);
	}//end writer()

	/**
	 * An icon stream holding the given bytes.
	 *
	 * @param string $bytes    The icon.
	 * @param string $mimeType Its media type.
	 *
	 * @return void
	 */
	private function icon(string $bytes, string $mimeType): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $bytes);
		rewind($stream);
		$this->icons->method('getIconStream')->with('toezicht', false)->willReturn(['stream' => $stream, 'mimeType' => $mimeType]);
	}//end icon()

	/**
	 * A file of the written project.
	 *
	 * @param string $path The path inside mobile/.
	 *
	 * @return string
	 */
	private function read(string $path): string {
		return (string)file_get_contents($this->root . '/mobile/' . $path);
	}//end read()

	public function testTheDefaultBundleIdIsNlHostSlugInBundleIdCharacters(): void {
		self::assertSame('nl.cloudexamplenl.toezicht', MobileProjectWriter::defaultBundleId(host: 'cloud.example.nl', slug: 'toezicht'));
		self::assertSame('nl.a10002.a9wooapp', MobileProjectWriter::defaultBundleId(host: '10.0.0.2', slug: '9-woo-app'));
		self::assertSame('nl.instance.app', MobileProjectWriter::defaultBundleId(host: '', slug: '--'));
	}//end testTheDefaultBundleIdIsNlHostSlugInBundleIdCharacters()

	public function testABundleIdNeedsTwoPartsEachStartingWithALetter(): void {
		self::assertTrue(MobileProjectWriter::isValidBundleId(bundleId: 'nl.gemeente.Toezicht_2'));
		foreach (['toezicht', 'nl..toezicht', 'nl.1gemeente.app', 'nl.gemeente-x.app', 'nl.gemeente.', ''] as $bundleId) {
			self::assertFalse(MobileProjectWriter::isValidBundleId(bundleId: $bundleId), $bundleId);
		}
	}//end testABundleIdNeedsTwoPartsEachStartingWithALetter()

	public function testTheProjectOpensTheRunPageAndAllowsOnlyThisInstance(): void {
		$this->icon(bytes: 'PNGBYTES', mimeType: 'image/png');

		$result = $this->writer()->write(rootDir: $this->root, slug: 'toezicht', appName: ' Toezicht ', bundleId: 'nl.gemeente.toezicht');

		self::assertSame(
			['bundleId' => 'nl.gemeente.toezicht', 'runUrl' => 'https://cloud.example.nl/index.php/apps/buildiq/run/toezicht/', 'icons' => true],
			$result
		);
		self::assertSame(
			[
				'appId' => 'nl.gemeente.toezicht',
				'appName' => 'Toezicht',
				'webDir' => 'www',
				'server' => [
					'url' => 'https://cloud.example.nl/index.php/apps/buildiq/run/toezicht/',
					'cleartext' => false,
					'allowNavigation' => ['cloud.example.nl'],
				],
				'android' => ['allowMixedContent' => false],
			],
			json_decode($this->read('capacitor.config.json'), true)
		);
		$package = json_decode($this->read('package.json'), true);
		self::assertSame('toezicht-mobile', $package['name']);
		self::assertSame(['@capacitor/android', '@capacitor/core', '@capacitor/ios'], array_keys($package['dependencies']));
		self::assertSame('PNGBYTES', $this->read('resources/icon.png'));
		self::assertSame('PNGBYTES', $this->read('resources/splash.png'));
		self::assertStringContainsString('href="https://cloud.example.nl/index.php/apps/buildiq/run/toezicht/"', $this->read('www/index.html'));
		$readme = $this->read('README.md');
		self::assertStringStartsWith("# Toezicht mobile app\n", $readme);
		self::assertStringContainsString('`nl.gemeente.toezicht`', $readme);
		self::assertStringContainsString('were made from the app\'s icon', $readme);
		self::assertStringContainsString('identity provider that refuses embedded browsers', $readme);
	}//end testTheProjectOpensTheRunPageAndAllowsOnlyThisInstance()

	public function testAnEmptyBundleIdTakesTheDefaultAndAPlainHttpInstanceAllowsCleartext(): void {
		$this->icons->method('getIconStream')->willThrowException(new RuntimeException('no icon'));

		$result = $this->writer(runUrl: 'http://localhost:8080/index.php/apps/buildiq/run/toezicht/')
			->write(rootDir: $this->root, slug: 'toezicht', appName: '');

		self::assertSame('nl.localhost.toezicht', $result['bundleId']);
		self::assertFalse($result['icons']);
		$config = json_decode($this->read('capacitor.config.json'), true);
		self::assertSame('toezicht', $config['appName']);
		self::assertTrue($config['server']['cleartext']);
		self::assertSame(['localhost'], $config['server']['allowNavigation']);
		self::assertFileDoesNotExist($this->root . '/mobile/resources/icon.png');
		self::assertStringContainsString('could not make PNG images', $this->read('README.md'));
	}//end testAnEmptyBundleIdTakesTheDefaultAndAPlainHttpInstanceAllowsCleartext()

	public function testAnSvgIconIsRasterisedToTheStoreSizes(): void {
		$this->icon(bytes: '<svg/>', mimeType: 'image/svg+xml');
		$this->rasteriser->expects($this->exactly(2))->method('toPng')
			->willReturnCallback(static fn (string $svg, int $size): string => $svg . '@' . $size);

		$result = $this->writer()->write(rootDir: $this->root, slug: 'toezicht', appName: 'Toezicht');

		self::assertTrue($result['icons']);
		self::assertSame('<svg/>@1024', $this->read('resources/icon.png'));
		self::assertSame('<svg/>@2732', $this->read('resources/splash.png'));
		self::assertFileDoesNotExist($this->root . '/mobile/resources/icon.svg');
	}//end testAnSvgIconIsRasterisedToTheStoreSizes()

	public function testAnSvgIconThatCannotBeRasterisedIsWrittenAsItIs(): void {
		$this->icon(bytes: '<svg/>', mimeType: 'image/svg+xml');
		$this->rasteriser->method('toPng')->willReturn(null);

		$result = $this->writer()->write(rootDir: $this->root, slug: 'toezicht', appName: 'Toezicht');

		self::assertFalse($result['icons']);
		self::assertSame('<svg/>', $this->read('resources/icon.svg'));
		self::assertFileDoesNotExist($this->root . '/mobile/resources/icon.png');
		self::assertStringContainsString('`resources/icon.svg` holds the icon', $this->read('README.md'));
	}//end testAnSvgIconThatCannotBeRasterisedIsWrittenAsItIs()

	public function testAnEmptyOrUnknownIconWritesNoImages(): void {
		foreach ([['', 'image/png'], ['GIF89a', 'image/gif']] as [$bytes, $mimeType]) {
			$this->setUp();
			$this->icon(bytes: $bytes, mimeType: $mimeType);

			$result = $this->writer()->write(rootDir: $this->root, slug: 'toezicht', appName: 'Toezicht');

			self::assertFalse($result['icons'], $mimeType);
			self::assertSame([], array_values(array_diff((array)scandir($this->root . '/mobile/resources'), ['.', '..'])), $mimeType);
			$this->tearDown();
		}

		$this->setUp();
	}//end testAnEmptyOrUnknownIconWritesNoImages()

	public function testAnInvalidBundleIdIsRefusedBeforeAnythingIsWritten(): void {
		$this->icons->expects($this->never())->method('getIconStream');

		try {
			$this->writer()->write(rootDir: $this->root, slug: 'toezicht', appName: 'Toezicht', bundleId: 'toezicht');
			self::fail('An invalid bundle id was accepted.');
		} catch (InvalidArgumentException $e) {
			self::assertSame('The bundle id "toezicht" is not valid: use parts like nl.example.app.', $e->getMessage());
		}

		self::assertDirectoryDoesNotExist($this->root . '/mobile');
	}//end testAnInvalidBundleIdIsRefusedBeforeAnythingIsWritten()

	public function testTheFallbackPageEscapesTheName(): void {
		$this->icon(bytes: 'PNG', mimeType: 'image/png');

		$this->writer()->write(rootDir: $this->root, slug: 'toezicht', appName: 'A <b>"&"</b>');

		self::assertStringContainsString('<title>A &lt;b&gt;&quot;&amp;&quot;&lt;/b&gt;</title>', $this->read('www/index.html'));
	}//end testTheFallbackPageEscapesTheName()
}//end class
