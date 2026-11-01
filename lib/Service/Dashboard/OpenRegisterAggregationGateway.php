<?php

/**
 * Buildiq OpenRegister Aggregation Gateway
 *
 * Runs a scalar aggregation through OpenRegister's in-process aggregation
 * runner. OpenRegister publishes no aggregation method on
 * `OCA\OpenRegister\Contract\ObjectServiceInterface`. The only aggregation
 * surface is the REST endpoint and the `Service\Aggregation\AggregationRunner`
 * behind it, so the runner is resolved duck-typed, the same way this app
 * already reaches `ObjectEventSubscription`. When it is absent the gateway
 * returns null and the caller renders an honest empty state rather than a
 * guessed number.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Dashboard
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-each-openregister-endpoint-is-queried-in-its-own-filter-grammar
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Dashboard;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenRegister-backed implementation of {@see AggregationGateway}.
 */
class OpenRegisterAggregationGateway implements AggregationGateway {
	/**
	 * OpenRegister's ad-hoc aggregation runner.
	 */
	private const RUNNER_CLASS = '\\OCA\\OpenRegister\\Service\\Aggregation\\AggregationRunner';

	/**
	 * OpenRegister's validated aggregation query value object.
	 */
	private const QUERY_CLASS = '\\OCA\\OpenRegister\\Service\\Aggregation\\AggregationQuery';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Server container, used to resolve OpenRegister's runner.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run a scalar aggregation.
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $requestParams Aggregations request params; filter map nested under `filter`.
	 *
	 * @return int|float|null The aggregated value, or null when it cannot be computed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-each-openregister-endpoint-is-queried-in-its-own-filter-grammar
	 */
	public function value(string $register, string $schema, array $requestParams): int|float|null {
		$runnerClass = self::RUNNER_CLASS;
		$queryClass = self::QUERY_CLASS;

		if (class_exists($runnerClass) === false || class_exists($queryClass) === false) {
			$this->logger->debug(
				'Buildiq: OpenRegister aggregation runner unavailable; widget renders an empty state.'
			);
			return null;
		}

		$filter = ($requestParams['filter'] ?? []);
		if (is_array($filter) === false) {
			$filter = [];
		}

		try {
			$runner = $this->container->get($runnerClass);
			$query = $queryClass::create(
				metric: (string)($requestParams['metric'] ?? 'count'),
				field: ($requestParams['field'] ?? null),
				filter: $filter
			);
			$envelope = $runner->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Buildiq: aggregation for a promoted dashboard widget failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}

		if (is_array($envelope) === false) {
			return null;
		}

		$value = ($envelope['value'] ?? null);
		if (is_int($value) === true || is_float($value) === true) {
			return $value;
		}

		if (is_numeric($value) === true) {
			return ($value + 0);
		}

		return null;
	}//end value()
}//end class
