<?php

/**
 * Unit tests for VirtualAppCredentialRegistrar's failure paths and description choice.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Credential;

use OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\Buildiq\Service\ManifestResolverService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A failing resolver or Doriath registrar never fails a publish; the description is the first usable manifest field.
 *
 * @covers \OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar
 */
class VirtualAppCredentialRegistrarFailuresTest extends TestCase {
	/**
	 * @var ManifestResolverService&MockObject
	 */
	private $manifestResolver;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * Recording fake for the broker app-key service.
	 *
	 * @var object
	 */
	private $tokenFake;

	/**
	 * Recording fake for the per-app Doriath registrar.
	 *
	 * @var object
	 */
	private $registrarFake;

	/**
	 * Set up the fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->manifestResolver = $this->createMock(ManifestResolverService::class);
		$this->logger           = $this->createMock(LoggerInterface::class);

		$this->tokenFake = new class {
			/** @var array<int,string> */
			public $registerAppCalls = [];

			public function isRegistered(string $appId): bool {
				return false;
			}

			public function registerApp(string $appId): string {
				$this->registerAppCalls[] = $appId;
				return 'secret';
			}
		};

		$this->registrarFake = new class {
			/** @var array<int,array{0:string,1:?string}> */
			public $calls = [];
			/** @var bool */
			public $throwOnRegister = false;

			public function registerApplication(string $appId, ?string $description = null): void {
				if ($this->throwOnRegister === true) {
					throw new RuntimeException('doriath down');
				}

				$this->calls[] = [$appId, $description];
			}
		};
	}//end setUp()

	/**
	 * A registrar whose service resolution returns the fakes.
	 *
	 * @return VirtualAppCredentialRegistrar
	 */
	private function makeRegistrar(): VirtualAppCredentialRegistrar {
		return new class($this->manifestResolver, $this->logger, $this->createMock(ContainerInterface::class), $this->tokenFake, $this->registrarFake) extends VirtualAppCredentialRegistrar {
			public function __construct(
				ManifestResolverService $manifestResolver,
				LoggerInterface $logger,
				ContainerInterface $container,
				private $tokenFake,
				private $registrarFake,
			) {
				parent::__construct($manifestResolver, $logger, $container);
			}

			protected function resolveService(string $fqcn): ?object {
				if (str_contains($fqcn, 'CredentialAppTokenService') === true) {
					return $this->tokenFake;
				}

				return $this->registrarFake;
			}
		};
	}//end makeRegistrar()

	/**
	 * A resolver that throws is logged with the slug and onboards nothing.
	 *
	 * @return void
	 */
	public function testAThrowingManifestResolverIsSwallowed(): void {
		$this->manifestResolver->method('resolve')->willThrowException(new RuntimeException('manifest unreadable'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Buildiq: virtual-app credential onboarding skipped for {slug}',
			['slug' => 'spectr', 'exception' => 'manifest unreadable']
		);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame([], $this->tokenFake->registerAppCalls);
		$this->assertSame([], $this->registrarFake->calls);
	}//end testAThrowingManifestResolverIsSwallowed()

	/**
	 * A Doriath failure is logged with the app id after the broker key was registered.
	 *
	 * @return void
	 */
	public function testAThrowingDoriathRegistrarIsSwallowed(): void {
		$this->registrarFake->throwOnRegister = true;
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);
		$this->logger->expects($this->once())->method('warning')->with(
			'Buildiq: per-app Doriath registration skipped for {appId}',
			['appId' => 'openbuild-spectr', 'exception' => 'doriath down']
		);
		$this->logger->expects($this->once())->method('info');

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame(['openbuild-spectr'], $this->tokenFake->registerAppCalls);
	}//end testAThrowingDoriathRegistrarIsSwallowed()

	/**
	 * Manifests and the description each one yields.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:?string}>
	 */
	public static function descriptions(): array {
		$credentials = ['credentials' => [['provider' => 'github']]];
		return [
			'description wins'       => [$credentials + ['description' => 'Tracks specs', 'name' => 'Spectr', 'title' => 'T'], 'Tracks specs'],
			'empty description'      => [$credentials + ['description' => '', 'name' => 'Spectr', 'title' => 'T'], 'Spectr'],
			'non-string name'        => [$credentials + ['name' => ['nl' => 'Spectr'], 'title' => 'Spec tracker'], 'Spec tracker'],
			'nothing usable'         => [$credentials + ['description' => null, 'name' => 42], null],
		];
	}//end descriptions()

	/**
	 * The Doriath description is the first non-empty string of description, name, title.
	 *
	 * @param array<string,mixed> $manifest The manifest.
	 * @param string|null         $expected The description passed on.
	 *
	 * @return void
	 *
	 * @dataProvider descriptions
	 */
	public function testTheDescriptionIsTheFirstUsableField(array $manifest, ?string $expected): void {
		$this->manifestResolver->method('resolve')->willReturn($manifest);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame([['openbuild-spectr', $expected]], $this->registrarFake->calls);
	}//end testTheDescriptionIsTheFirstUsableField()

	/**
	 * Without OpenRegister's services installed nothing is asked of the container and nothing is logged as a failure.
	 *
	 * @return void
	 */
	public function testAbsentOpenRegisterClassesAreNotResolved(): void {
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');
		$this->logger->expects($this->never())->method('warning');

		(new VirtualAppCredentialRegistrar($this->manifestResolver, $this->logger, $container))->onPublish(slug: 'spectr', caller: null);
	}//end testAbsentOpenRegisterClassesAreNotResolved()
}//end class
