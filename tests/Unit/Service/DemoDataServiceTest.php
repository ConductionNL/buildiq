<?php

/**
 * DemoDataServiceTest.
 *
 * The failure this service must never have is the quiet one: reporting that
 * demo data was installed on an instance where nothing was written. So the
 * assertions here are about what it refuses to do — skip on a version gate,
 * swallow a missing descriptor, or claim success without OpenRegister.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\DemoDataService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the demo import's decision table.
 */
class DemoDataServiceTest extends TestCase {
	private IAppManager&MockObject $appManager;

	private ContainerInterface&MockObject $container;

	private DemoDataService $service;

	private string $appDir;

	protected function setUp(): void {
		$this->appDir = sys_get_temp_dir() . '/buildiq-demo-' . uniqid();
		mkdir($this->appDir . '/lib/Settings', 0777, true);

		$this->appManager = $this->createMock(IAppManager::class);
		$this->container  = $this->createMock(ContainerInterface::class);

		$this->appManager->method('getAppPath')->willReturn($this->appDir);
		$this->appManager->method('getAppVersion')->willReturn('1.2.3');
		$this->appManager->method('getInstalledApps')->willReturn(['buildiq', 'openregister']);

		$this->service = new DemoDataService(
			$this->appManager,
			$this->container,
			$this->createMock(LoggerInterface::class)
		);
	}

	protected function tearDown(): void {
		$file = $this->appDir . '/lib/Settings/buildiq_mock_register.json';
		if (is_file($file) === true) {
			unlink($file);
		}
		@rmdir($this->appDir . '/lib/Settings');
		@rmdir($this->appDir . '/lib');
		@rmdir($this->appDir);
	}

	private function shipDescriptor(int $objects = 2): void {
		file_put_contents(
			$this->appDir . '/lib/Settings/buildiq_mock_register.json',
			json_encode(
				[
					'x-openregister' => ['type' => 'mock', 'app' => 'buildiq'],
					'components' => [
						'registers' => ['buildiq' => ['schemas' => ['Thing']]],
						'schemas' => ['Thing' => ['type' => 'object']],
						'objects' => array_fill(0, $objects, ['@self' => ['register' => 'buildiq', 'schema' => 'Thing']]),
					],
				]
			)
		);
	}

	/**
	 * A stand-in for OpenRegister's importer that records how it was called.
	 *
	 * 🔴 ITS REPLY IS THE SUBJECT, NOT SCENERY. OpenRegister reports what it
	 * WROTE in `objects`, what it left alone in `unchanged`, and what it refused
	 * in `skipped` — it does not error on an object whose schema will not
	 * resolve. So the reply shape is exactly what decides whether the service
	 * can tell a seeded instance from an empty one.
	 *
	 * @param integer $wrote     Objects the importer claims to have written.
	 * @param integer $unchanged Objects already present and identical.
	 * @param integer $skipped   Objects it refused.
	 *
	 * @return object The fake.
	 */
	private function importerSpy(int $wrote = 2, int $unchanged = 0, int $skipped = 0): object {
		return new class($wrote, $unchanged, $skipped) {
			/** @var array<string, mixed> */
			public array $seen = [];

			/**
			 * @param integer $wrote     Objects written.
			 * @param integer $unchanged Objects already present.
			 * @param integer $skipped   Objects refused.
			 */
			public function __construct(
				private readonly int $wrote,
				private readonly int $unchanged,
				private readonly int $skipped
			) {
			}

			/**
			 * @param string               $appId   Config identity.
			 * @param array<string, mixed> $data    Descriptor.
			 * @param string               $version App version.
			 * @param boolean              $force   Whether the version gate is bypassed.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force): array {
				$this->seen = ['appId' => $appId, 'version' => $version, 'force' => $force];
				return [
					'registers' => ['buildiq'],
					'schemas'   => ['Thing'],
					'objects'   => array_fill(0, $this->wrote, ['id' => 'x']),
					'unchanged' => ['objects' => $this->unchanged],
					'skipped'   => ['objects' => $this->skipped],
				];
			}
		};
	}

	public function testItImportsTheDescriptorAndReportsTheCounts(): void {
		$this->shipDescriptor(objects: 5);
		$spy = $this->importerSpy(wrote: 5);
		$this->container->method('get')->willReturn($spy);

		$result = $this->service->install();

		$this->assertSame(5, $result['objects']);
		$this->assertSame(5, $result['declared']);
		$this->assertSame(0, $result['skipped']);
		$this->assertSame(1, $result['registers']);
		$this->assertSame(1, $result['schemas']);
	}

	/**
	 * 🔴 THE REPORTED COUNT IS WHAT LANDED. Measured on the shared instance on
	 * 2026-09-15: the wizard reported "Demo data installed: 18 objects" while
	 * OpenRegister had skipped all 18, because the descriptor addressed schemas
	 * by name and OpenRegister resolves them by slug. A count read off the file
	 * repeats the request and can never expose that.
	 */
	public function testItReportsWhatLandedAndTheGapRatherThanWhatWasAskedFor(): void {
		$this->shipDescriptor(objects: 5);
		$spy = $this->importerSpy(wrote: 3, skipped: 2);
		$this->container->method('get')->willReturn($spy);

		$result = $this->service->install();

		$this->assertSame(3, $result['objects']);
		$this->assertSame(5, $result['declared']);
		$this->assertSame(2, $result['skipped']);
	}

	/**
	 * A dataset that declares objects and seeds none of them is a failed
	 * import, not a quiet success — the same rule OpenRegister's own
	 * RegisterDescriptorService applies.
	 */
	public function testADatasetThatSeedsNothingThrowsInsteadOfReportingSuccess(): void {
		$this->shipDescriptor(objects: 5);
		$spy = $this->importerSpy(wrote: 0, skipped: 5);
		$this->container->method('get')->willReturn($spy);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('declares 5 object(s) but OpenRegister imported none of them');

		$this->service->install();
	}

	/**
	 * Re-running the import is documented as safe, and the second run writes
	 * nothing because everything is already there. That is landed data, so it
	 * must not read as the "seeded nothing" failure above.
	 */
	public function testObjectsAlreadyPresentCountAsLandedSoARerunIsNotAFailure(): void {
		$this->shipDescriptor(objects: 5);
		$spy = $this->importerSpy(wrote: 0, unchanged: 5);
		$this->container->method('get')->willReturn($spy);

		$result = $this->service->install();

		$this->assertSame(5, $result['objects']);
		$this->assertSame(0, $result['skipped']);
	}

	/**
	 * 🔴 THE IMPORT IS FORCED. OpenRegister version-gates a non-forced import
	 * and SKIPS silently when the version has not moved. An operator who asks
	 * for demo data and is told it worked, on an instance where nothing was
	 * written, has been lied to by a version compare.
	 */
	public function testTheImportIsForcedSoAVersionGateCannotSilentlySkipIt(): void {
		$this->shipDescriptor();
		$spy = $this->importerSpy();
		$this->container->method('get')->willReturn($spy);

		$this->service->install();

		$this->assertTrue($spy->seen['force'], 'a version gate must not be able to skip an explicit request');
	}

	/**
	 * Its own configuration identity, so the demo import and the real
	 * configuration import cannot mask one another's version gate.
	 */
	public function testItImportsUnderItsOwnConfigurationIdentity(): void {
		$this->shipDescriptor();
		$spy = $this->importerSpy();
		$this->container->method('get')->willReturn($spy);

		$this->service->install();

		$this->assertSame('buildiq.demo', $spy->seen['appId']);
	}

	public function testAMissingDescriptorThrowsRatherThanReportingSuccess(): void {
		$this->container->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->service->install();
	}

	public function testUnparsableJsonThrowsRatherThanImportingNothing(): void {
		file_put_contents($this->appDir . '/lib/Settings/buildiq_mock_register.json', 'not json');
		$this->container->expects($this->never())->method('get');

		$this->expectException(RuntimeException::class);
		$this->service->install();
	}

	/**
	 * 🔴 NAMES THE MISSING APP. "Something went wrong" on a cross-app lookup
	 * leaves an operator with nothing to act on; a cross-app class is a runtime
	 * lookup that finds nobody rather than erroring usefully.
	 */
	public function testWithoutOpenRegisterItRefusesAndSaysWhichAppIsMissing(): void {
		$this->shipDescriptor();
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appDir);
		$appManager->method('getAppVersion')->willReturn('1.2.3');
		$appManager->method('getInstalledApps')->willReturn(['buildiq']);

		$service = new DemoDataService($appManager, $this->container, $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/OpenRegister/');
		$service->install();
	}

	public function testIsAvailableReflectsWhetherTheDescriptorShips(): void {
		$this->assertFalse($this->service->isAvailable(), 'no descriptor on disk');
		$this->shipDescriptor();
		$this->assertTrue($this->service->isAvailable());
	}
}
