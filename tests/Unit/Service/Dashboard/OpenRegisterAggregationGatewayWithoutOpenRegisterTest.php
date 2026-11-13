<?php

/**
 * Unit tests for OpenRegisterAggregationGateway without OpenRegister.
 *
 * The other gateway test declares doubles of OpenRegister's aggregation
 * runner and query, so in its process the runner always exists. This test
 * runs in its own process, where neither class is defined, to assert the path
 * a Nextcloud without OpenRegister takes: no container lookup, a debug line,
 * and an empty widget instead of an error.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Dashboard
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

namespace OCA\Buildiq\Tests\Unit\Service\Dashboard;

use OCA\Buildiq\Service\Dashboard\OpenRegisterAggregationGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for OpenRegisterAggregationGateway::value when OpenRegister is absent.
 */
#[CoversClass(OpenRegisterAggregationGateway::class)]
class OpenRegisterAggregationGatewayWithoutOpenRegisterTest extends TestCase {

	/**
	 * Without OpenRegister's runner the value is null, the container is never
	 * asked for it, and the reason is logged at debug level only.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	public function testWithoutOpenRegisterTheWidgetIsEmpty(): void {
		$this->assertFalse(class_exists('OCA\\OpenRegister\\Service\\Aggregation\\AggregationRunner'), 'this process must not know the runner');

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('debug')
			->with('Buildiq: OpenRegister aggregation runner unavailable; widget renders an empty state.');
		$logger->expects($this->never())->method('warning');

		$gateway = new OpenRegisterAggregationGateway(container: $container, logger: $logger);

		$this->assertNull($gateway->value(register: 'buildiq', schema: 'application', requestParams: ['metric' => 'count']));
	}//end testWithoutOpenRegisterTheWidgetIsEmpty()
}//end class
