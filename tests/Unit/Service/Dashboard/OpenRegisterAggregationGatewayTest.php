<?php

/**
 * Unit tests for OpenRegisterAggregationGateway.
 *
 * The gateway asks OpenRegister's aggregation runner for one KPI value. These
 * tests declare minimal doubles of the runner and query classes (OpenRegister
 * is not installed in the unit suite) and cover the query it builds, the
 * numeric coercion of the answer, and every path that must degrade to an
 * empty widget (null) instead of an error.
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

namespace OCA\OpenRegister\Search\Service\Aggregation {
	if (class_exists(AggregationQuery::class, autoload: false) === false) {
		/**
		 * Test double of OpenRegister's AggregationQuery: records what create() received.
		 */
		class AggregationQuery {
			/**
			 * Store the query parts.
			 *
			 * @param string $metric The metric.
			 * @param mixed $field The field.
			 * @param array<string, mixed> $filter The filter.
			 */
			public function __construct(public string $metric, public mixed $field, public array $filter) {
			}

			/**
			 * Named constructor mirroring the real one.
			 *
			 * @param string $metric The metric.
			 * @param mixed $field The field.
			 * @param array<string, mixed> $filter The filter.
			 *
			 * @return self
			 */
			public static function create(string $metric, mixed $field, array $filter): self {
				return new self(metric: $metric, field: $field, filter: $filter);
			}
		}
	}
}

namespace OCA\OpenRegister\Service\Aggregation {
	if (class_exists(AggregationRunner::class, autoload: false) === false) {
		/**
		 * Test double of OpenRegister's AggregationRunner: answers from a fixture.
		 */
		class AggregationRunner {
			/**
			 * The last call's arguments.
			 *
			 * @var array<string, mixed>
			 */
			public array $lastCall = [];

			/**
			 * Store the fixture.
			 *
			 * @param mixed $envelope What runAdhocByRef returns, or a Throwable to throw.
			 */
			public function __construct(private mixed $envelope = null) {
			}

			/**
			 * Return the fixture or throw it.
			 *
			 * @param string $registerRef The register.
			 * @param string $schemaRef The schema.
			 * @param object $query The query.
			 *
			 * @return mixed
			 */
			public function runAdhocByRef(string $registerRef, string $schemaRef, object $query): mixed {
				$this->lastCall = ['register' => $registerRef, 'schema' => $schemaRef, 'query' => $query];
				if ($this->envelope instanceof \Throwable) {
					throw $this->envelope;
				}

				return $this->envelope;
			}
		}
	}
}

namespace OCA\Buildiq\Tests\Unit\Service\Dashboard {

	use OCA\Buildiq\Service\Dashboard\OpenRegisterAggregationGateway;
	use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Psr\Container\ContainerInterface;
	use Psr\Log\LoggerInterface;
	use RuntimeException;

	/**
	 * Tests for OpenRegisterAggregationGateway::value.
	 */
	class OpenRegisterAggregationGatewayTest extends TestCase {

		/**
		 * Build the gateway over a runner that answers with the given envelope.
		 *
		 * @param AggregationRunner $runner The runner double.
		 * @param LoggerInterface|null $logger An optional logger mock.
		 *
		 * @return OpenRegisterAggregationGateway
		 */
		private function gateway(AggregationRunner $runner, ?LoggerInterface $logger = null): OpenRegisterAggregationGateway {
			$container = $this->createMock(originalClassName: ContainerInterface::class);
			$container->method('get')->willReturn($runner);

			return new OpenRegisterAggregationGateway(
				container: $container,
				logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
			);
		}//end gateway()

		/**
		 * The runner receives the register, schema and a query built from the request.
		 *
		 * @return void
		 */
		public function testBuildsTheQueryFromTheRequest(): void {
			$runner = new AggregationRunner(envelope: ['value' => 12]);

			$value = $this->gateway(runner: $runner)->value(
				register: 'cases',
				schema: 'case',
				requestParams: ['metric' => 'sum', 'field' => 'amount', 'filter' => ['status' => 'open']]
			);

			self::assertSame(expected: 12, actual: $value);
			self::assertSame(expected: 'cases', actual: $runner->lastCall['register']);
			self::assertSame(expected: 'case', actual: $runner->lastCall['schema']);
			self::assertSame(expected: 'sum', actual: $runner->lastCall['query']->metric);
			self::assertSame(expected: 'amount', actual: $runner->lastCall['query']->field);
			self::assertSame(expected: ['status' => 'open'], actual: $runner->lastCall['query']->filter);
		}//end testBuildsTheQueryFromTheRequest()

		/**
		 * Without parameters the query is a plain count, and a non-array filter is dropped.
		 *
		 * @return void
		 */
		public function testDefaultsToACountWithoutFilter(): void {
			$runner = new AggregationRunner(envelope: ['value' => 3]);

			$this->gateway(runner: $runner)->value(register: 'r', schema: 's', requestParams: ['filter' => 'status=open']);

			self::assertSame(expected: 'count', actual: $runner->lastCall['query']->metric);
			self::assertNull(actual: $runner->lastCall['query']->field);
			self::assertSame(expected: [], actual: $runner->lastCall['query']->filter);
		}//end testDefaultsToACountWithoutFilter()

		/**
		 * Answers from the runner and what the widget gets.
		 *
		 * @return array<string, array{0: mixed, 1: int|float|null}>
		 */
		public static function envelopes(): array {
			return [
				'integer' => [['value' => 7], 7],
				'float' => [['value' => 2.5], 2.5],
				'numeric string' => [['value' => '41'], 41],
				'decimal string' => [['value' => '0.75'], 0.75],
				'non-numeric string' => [['value' => 'many'], null],
				'missing value' => [['count' => 3], null],
				'not an array' => ['7', null],
			];
		}//end envelopes()

		/**
		 * The answer is coerced to a number or reads as empty.
		 *
		 * @param mixed $envelope The runner's answer.
		 * @param int|float|null $expected The widget value.
		 *
		 * @return void
		 */
		#[DataProvider('envelopes')]
		public function testCoercesTheAnswer(mixed $envelope, int|float|null $expected): void {
			$value = $this->gateway(runner: new AggregationRunner(envelope: $envelope))
				->value(register: 'r', schema: 's', requestParams: []);

			self::assertSame(expected: $expected, actual: $value);
		}//end testCoercesTheAnswer()

		/**
		 * A failing runner is logged and the widget renders empty.
		 *
		 * @return void
		 */
		public function testAFailingRunnerReadsAsEmpty(): void {
			$logger = $this->createMock(originalClassName: LoggerInterface::class);
			$logger->expects(self::once())->method('warning')
				->with(self::stringContains(string: 'aggregation for a promoted dashboard widget failed: index gone'));

			$value = $this->gateway(runner: new AggregationRunner(envelope: new RuntimeException('index gone')), logger: $logger)
				->value(register: 'r', schema: 's', requestParams: []);

			self::assertNull(actual: $value);
		}//end testAFailingRunnerReadsAsEmpty()
	}//end class
}
