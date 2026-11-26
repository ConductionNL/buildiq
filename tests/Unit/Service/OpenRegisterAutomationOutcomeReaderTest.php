<?php

/**
 * Unit tests for OpenRegisterAutomationOutcomeReader.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\OpenRegisterAutomationOutcomeReader;
use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Db\AutomationRecordFilter;
use OCA\OpenRegister\Service\AutomationRecordService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The reader asks OpenRegister by key prefix, answers the outcome shape, and answers nothing when the read fails.
 */
final class OpenRegisterAutomationOutcomeReaderTest extends TestCase {

	/**
	 * A container that resolves the record service to this one.
	 *
	 * @param mixed $service The service.
	 *
	 * @return ContainerInterface
	 */
	private function container(mixed $service): ContainerInterface {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with(AutomationRecordService::class)->willReturn($service);
		return $container;
	}//end container()

	/**
	 * The records come back in the outcome shape, asked for by key prefix and bounded.
	 *
	 * @return void
	 */
	public function testTheOutcomesAreReadByKeyPrefix(): void {
		$outcome = ['kind' => 'notification', 'key' => 'aut-7f3c-1', 'at' => '2026-11-24T09:30:00+00:00', 'outcome' => 'sent'];
		$service = $this->createMock(originalClassName: AutomationRecordService::class);
		$service->expects($this->once())->method('find')
			->with(
				$this->callback(
					callback: static fn (AutomationRecordFilter $filter): bool => $filter->keyPrefix === 'aut-7f3c'
						&& $filter->limit === OpenRegisterAutomationOutcomeReader::READ_LIMIT
				)
			)
			->willReturn([new AutomationRecord(outcome: $outcome)]);

		$reader = new OpenRegisterAutomationOutcomeReader(
			container: $this->container(service: $service),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: [$outcome], actual: $reader->outcomes(keyPrefix: 'aut-7f3c'));
	}//end testTheOutcomesAreReadByKeyPrefix()

	/**
	 * Without a prefix OpenRegister is not asked.
	 *
	 * @return void
	 */
	public function testAnEmptyPrefixReadsNothing(): void {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$reader = new OpenRegisterAutomationOutcomeReader(container: $container, logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertSame(expected: [], actual: $reader->outcomes(keyPrefix: ''));
	}//end testAnEmptyPrefixReadsNothing()

	/**
	 * A failed read is logged and answers no outcome.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswersNothing(): void {
		$service = $this->createMock(originalClassName: AutomationRecordService::class);
		$service->method('find')->willThrowException(new RuntimeException('database gone'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$reader = new OpenRegisterAutomationOutcomeReader(container: $this->container(service: $service), logger: $logger);

		$this->assertSame(expected: [], actual: $reader->outcomes(keyPrefix: 'aut-7f3c'));
	}//end testAFailedReadAnswersNothing()

	/**
	 * A container answering something else than the record service answers no outcome.
	 *
	 * @return void
	 */
	public function testAnUnexpectedServiceAnswersNothing(): void {
		$reader = new OpenRegisterAutomationOutcomeReader(
			container: $this->container(service: new \stdClass()),
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

		$this->assertSame(expected: [], actual: $reader->outcomes(keyPrefix: 'aut-7f3c'));
	}//end testAnUnexpectedServiceAnswersNothing()
}//end class
