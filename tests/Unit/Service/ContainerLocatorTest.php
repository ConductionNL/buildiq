<?php

/**
 * Unit tests for ContainerLocator: optional services resolved from the
 * server container, and null for unknown classes, resolution errors and
 * non-object entries.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ContainerLocator;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for resolving optional services.
 *
 * @covers \OCA\Buildiq\Service\ContainerLocator
 */
final class ContainerLocatorTest extends TestCase {

	/**
	 * A known class is resolved under its name without the leading backslash.
	 *
	 * @return void
	 */
	public function testAKnownClassIsResolved(): void {
		$service = new \ArrayObject();
		$container = $this->createMock(IServerContainer::class);
		$container->expects($this->once())->method('get')->with('ArrayObject')->willReturn($service);

		$locator = new ContainerLocator($container, $this->createMock(LoggerInterface::class));

		self::assertSame($service, $locator->get('\\ArrayObject'));
	}//end testAKnownClassIsResolved()

	/**
	 * A class or interface that does not exist is never asked of the container.
	 *
	 * @return void
	 */
	public function testAnUnknownClassIsNullWithoutAskingTheContainer(): void {
		$container = $this->createMock(IServerContainer::class);
		$container->expects($this->never())->method('get');

		$locator = new ContainerLocator($container, $this->createMock(LoggerInterface::class));

		self::assertNull($locator->get('OCA\\NotInstalled\\Service\\Missing'));
	}//end testAnUnknownClassIsNullWithoutAskingTheContainer()

	/**
	 * An interface resolves too; a resolution error is logged at debug level and answered with null.
	 *
	 * @return void
	 */
	public function testAResolutionErrorIsLoggedAndNull(): void {
		$container = $this->createMock(IServerContainer::class);
		$container->expects($this->once())->method('get')->with('Countable')->willThrowException(new RuntimeException('no binding'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('debug')->with('Buildiq: optional service "Countable" could not be resolved: no binding');

		self::assertNull((new ContainerLocator($container, $logger))->get('Countable'));
	}//end testAResolutionErrorIsLoggedAndNull()

	/**
	 * A container entry that is not an object is answered with null.
	 *
	 * @return void
	 */
	public function testANonObjectEntryIsNull(): void {
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturn('a string');

		self::assertNull((new ContainerLocator($container, $this->createMock(LoggerInterface::class)))->get('ArrayObject'));
	}//end testANonObjectEntryIsNull()
}//end class
