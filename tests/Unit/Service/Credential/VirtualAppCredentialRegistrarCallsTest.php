<?php

/**
 * Unit tests for the exact calls VirtualAppCredentialRegistrar makes: what it
 * asks the manifest resolver, which credential shapes count as declared,
 * which slugs form a safe app id, and what it logs on each path.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Credential;

use OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\Buildiq\Service\ManifestResolverService;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolver arguments, credential gating, app-id alphabet and log lines of the registrar.
 *
 * @covers \OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar
 */
final class VirtualAppCredentialRegistrarCallsTest extends TestCase {

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

		$this->manifestResolver = $this->createMock(originalClassName: ManifestResolverService::class);
		$this->logger           = $this->createMock(originalClassName: LoggerInterface::class);

		$this->tokenFake = new class {
			/** @var bool */
			public $registered = false;
			/** @var bool */
			public $throwOnRegister = false;
			/** @var array<int,string> */
			public $isRegisteredCalls = [];
			/** @var array<int,string> */
			public $registerAppCalls = [];

			/**
			 * Whether the app already has a broker key; records the asked id.
			 */
			public function isRegistered(string $appId): bool {
				$this->isRegisteredCalls[] = $appId;
				return $this->registered;
			}

			/**
			 * Register the app's broker key, or throw when told to.
			 */
			public function registerApp(string $appId): string {
				if ($this->throwOnRegister === true) {
					throw new RuntimeException('broker down');
				}

				$this->registerAppCalls[] = $appId;
				return 'secret';
			}
		};

		$this->registrarFake = new class {
			/** @var array<int,array{0:string,1:?string}> */
			public $calls = [];

			/**
			 * Record one Doriath registration.
			 */
			public function registerApplication(string $appId, ?string $description = null): void {
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
		$container = $this->createMock(originalClassName: ContainerInterface::class);

		return new class($this->manifestResolver, $this->logger, $container, $this->tokenFake, $this->registrarFake) extends VirtualAppCredentialRegistrar {
			/**
			 * Keep the fakes next to the real collaborators.
			 */
			public function __construct(
				ManifestResolverService $manifestResolver,
				LoggerInterface $logger,
				ContainerInterface $container,
				private $tokenFake,
				private $registrarFake,
			) {
				parent::__construct(manifestResolver: $manifestResolver, logger: $logger, container: $container);
			}

			/**
			 * Hand out the fakes instead of container services.
			 */
			protected function resolveService(string $fqcn): ?object {
				if (str_contains($fqcn, 'CredentialAppTokenService') === true) {
					return $this->tokenFake;
				}

				return $this->registrarFake;
			}
		};
	}//end makeRegistrar()

	/**
	 * The manifest is resolved for the slug's current version, on behalf of the publishing user.
	 *
	 * @return void
	 */
	public function testTheManifestIsResolvedForTheSlugAndCaller(): void {
		$caller = $this->createMock(originalClassName: IUser::class);
		$this->manifestResolver->expects($this->once())
			->method('resolve')
			->with('spectr', null, $caller)
			->willReturn(null);

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: $caller);

		$this->assertSame(expected: [], actual: $this->tokenFake->isRegisteredCalls);
	}//end testTheManifestIsResolvedForTheSlugAndCaller()

	/**
	 * Credentials that are not a list (a string, a number) do not count as declared.
	 *
	 * @return void
	 */
	public function testCredentialsThatAreNotAnArrayAreIgnored(): void {
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => 'github']);
		$this->logger->expects($this->never())->method('info');

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame(expected: [], actual: $this->tokenFake->isRegisteredCalls);
		$this->assertSame(expected: [], actual: $this->registrarFake->calls);
	}//end testCredentialsThatAreNotAnArrayAreIgnored()

	/**
	 * Lowercase letters, digits, dashes and underscores form a safe app id.
	 *
	 * @return void
	 */
	public function testDigitsDashesAndUnderscoresAreSafe(): void {
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);

		$this->makeRegistrar()->onPublish(slug: 'my_app-2', caller: null);

		$this->assertSame(expected: ['openbuild-my_app-2'], actual: $this->tokenFake->isRegisteredCalls);
		$this->assertSame(expected: ['openbuild-my_app-2'], actual: $this->tokenFake->registerAppCalls);
		$this->assertSame(expected: [['openbuild-my_app-2', null]], actual: $this->registrarFake->calls);
	}//end testDigitsDashesAndUnderscoresAreSafe()

	/**
	 * An uppercase slug is outside the alphabet: it is skipped with a warning naming the app id.
	 *
	 * @return void
	 */
	public function testAnUppercaseSlugIsSkippedWithAWarning(): void {
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Buildiq: virtual-app credential onboarding skipped — unsafe app id {appId}',
				['appId' => 'openbuild-Spectr']
			);

		$this->makeRegistrar()->onPublish(slug: 'Spectr', caller: null);

		$this->assertSame(expected: [], actual: $this->tokenFake->isRegisteredCalls);
		$this->assertSame(expected: [], actual: $this->registrarFake->calls);
	}//end testAnUppercaseSlugIsSkippedWithAWarning()

	/**
	 * A first registration is logged at info level with the app id, and nothing is logged as a warning.
	 *
	 * @return void
	 */
	public function testAFirstRegistrationIsLoggedAsInfo(): void {
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Buildiq: registered {appId} with the credential broker (manifest declares credentials)',
				['appId' => 'openbuild-spectr']
			);
		$this->logger->expects($this->never())->method('debug');
		$this->logger->expects($this->never())->method('warning');

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);
	}//end testAFirstRegistrationIsLoggedAsInfo()

	/**
	 * A re-publish of a registered app logs at debug level and never claims a new registration.
	 *
	 * @return void
	 */
	public function testAnExistingRegistrationIsLoggedAsDebug(): void {
		$this->tokenFake->registered = true;
		$this->manifestResolver->method('resolve')->willReturn(['credentials' => [['provider' => 'github']]]);
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'Buildiq: {appId} already registered with the credential broker',
				['appId' => 'openbuild-spectr']
			);
		$this->logger->expects($this->never())->method('info');

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame(expected: ['openbuild-spectr'], actual: $this->tokenFake->isRegisteredCalls);
	}//end testAnExistingRegistrationIsLoggedAsDebug()

	/**
	 * A broker failure is logged once with the app id and the exception message, and Doriath still runs.
	 *
	 * @return void
	 */
	public function testABrokerFailureIsLoggedWithItsMessage(): void {
		$this->tokenFake->throwOnRegister = true;
		$this->manifestResolver->method('resolve')->willReturn(
			['credentials' => [['provider' => 'github']], 'description' => 'Spec tracker']
		);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Buildiq: broker app-key registration skipped for {appId}',
				['appId' => 'openbuild-spectr', 'exception' => 'broker down']
			);
		$this->logger->expects($this->never())->method('info');

		$this->makeRegistrar()->onPublish(slug: 'spectr', caller: null);

		$this->assertSame(expected: [['openbuild-spectr', 'Spec tracker']], actual: $this->registrarFake->calls);
	}//end testABrokerFailureIsLoggedWithItsMessage()
}//end class
