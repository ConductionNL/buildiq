<?php

/**
 * Buildiq Aggregation Gateway
 *
 * The seam between WidgetItemProjector and OpenRegister's aggregations
 * endpoint. It exists because the two OpenRegister endpoints this projector
 * uses spell their filters OPPOSITELY, and each answers the other's shape with
 * a confident wrong number rather than an error:
 *
 *   - the OBJECTS endpoint wants BARE keys and reads `filter[x]` as the empty set;
 *   - the AGGREGATIONS endpoint wants `filter[x]` and DROPS a bare key,
 *     returning the whole register.
 *
 * So this gateway's input is documented, and asserted in tests, as the
 * aggregations endpoint's own request parameters: `['metric' => …, 'filter' =>
 * ['<property>' => <value>]]`, which is exactly what `filter[<property>]=<value>`
 * parses into. Handing it a bare key is a defect, not a variation.
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

/**
 * Runs one scalar aggregation against OpenRegister.
 */
interface AggregationGateway {
	/**
	 * Run a scalar aggregation.
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param array<string,mixed> $requestParams Aggregations request params; filter map nested under `filter`.
	 *
	 * @return int|float|null The aggregated value, or null when it cannot be computed.
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-each-openregister-endpoint-is-queried-in-its-own-filter-grammar
	 */
	public function value(string $register, string $schema, array $requestParams): int|float|null;
}//end interface
