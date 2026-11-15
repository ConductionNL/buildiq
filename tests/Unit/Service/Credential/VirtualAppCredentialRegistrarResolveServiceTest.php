<?php

/**
 * Unit tests for VirtualAppCredentialRegistrar's optional-service resolution.
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

use ArrayObject;
use OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar;
use OCA\Buildiq\Service\ManifestResolverService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An installed service is taken from the container; one the container cannot
 * build is logged and treated as absent.
 *
 * @covers \OCA\Buildiq\Service\Credential\VirtualAppCredentialRegistrar
 */
class VirtualAppCredentialRegistrarResolveServiceTest extends TestCase {
	/**
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private $container;

	/**
	 * Set up the logger and the container.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->container = $this->createMock(ContainerInterface::class);
	}//end setUp()

	/**
	 * The registrar with its resolution reachable from the test.
	 *
	 * @return VirtualAppCredentialRegistrar
	 */
	private function makeRegistrar(): VirtualAppCredentialRegistrar {
		return new class($this->createMock(ManifestResolverService::class), $this->logger, $this->container) extends VirtualAppCredentialRegistrar {
			/**
			 * The protected resolution, as the registrar runs it.
			 *
			 * @param string $fqcn The service FQCN.
			 *
			 * @return object|null
			 */
			public function resolve(string $fqcn): ?object {
				return $this->resolveService(fqcn: $fqcn);
			}
		};
	}//end makeRegistrar()

	/**
	 * An installed class is fetched from the container by its name and returned as is.
	 *
	 * @return void
	 */
	public function testAnInstalledServiceComesFromTheContainer(): void {
		$service = new ArrayObject();
		$this->container->expects($this->once())->method('get')->with(ArrayObject::class)->willReturn($service);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame($service, $this->makeRegistrar()->resolve(fqcn: ArrayObject::class));
	}//end testAnInstalledServiceComesFromTheContainer()

	/**
	 * A container that cannot build the service is logged with the class name and answers null.
	 *
	 * @return void
	 */
	public function testAServiceTheContainerCannotBuildIsLoggedAndAbsent(): void {
		$this->container->method('get')->willThrowException(new RuntimeException('no factory'));
		$this->logger->expects($this->once())->method('warning')->with(
			'Buildiq: failed to resolve {fqcn}',
			['fqcn' => ArrayObject::class, 'exception' => 'no factory']
		);

		$this->assertNull($this->makeRegistrar()->resolve(fqcn: ArrayObject::class));
	}//end testAServiceTheContainerCannotBuildIsLoggedAndAbsent()

	/**
	 * A class that is not installed is never asked of the container.
	 *
	 * @return void
	 */
	public function testAMissingClassIsNotAskedOfTheContainer(): void {
		$this->container->expects($this->never())->method('get');
		$this->logger->expects($this->never())->method('warning');

		$this->assertNull($this->makeRegistrar()->resolve(fqcn: 'OCA\\OpenRegister\\Service\\Credential\\NoSuchService'));
	}//end testAMissingClassIsNotAskedOfTheContainer()
}//end class
