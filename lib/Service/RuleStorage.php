<?php

/**
 * Buildiq Rule Storage
 *
 * The rule object reader, stored decision tables and rule set versioning. RulesController reads
 * rules, stored decision tables and rule set versions through these three collaborators; they come
 * as one object.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
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

namespace OCA\Buildiq\Service;

/**
 * The rule object reader, stored decision tables and rule set versioning.
 *
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#9.1
 */
final class RuleStorage {
	/**
	 * Constructor.
	 *
	 * @param RuleObjectReader $reader Reads rule objects.
	 * @param StoredDecisionTables $tables Stored decision tables.
	 * @param RuleSetVersioningService $versioningService Rule set versioning.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly RuleObjectReader $reader,
		public readonly StoredDecisionTables $tables,
		public readonly RuleSetVersioningService $versioningService,
	) {
	}//end __construct()
}//end class
