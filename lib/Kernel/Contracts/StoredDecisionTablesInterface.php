<?php

/**
 * Contract of StoredDecisionTables
 *
 * The public surface of StoredDecisionTables that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

use RuntimeException;

/**
 * Contract of StoredDecisionTables.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StoredDecisionTablesInterface {

	/**
	 * The prefix of every key this app writes.
	 */
	public const KEY_PREFIX = 'buildiq.';

	/**
	 * Whether OpenRegister already holds a table under a key.
	 *
	 * @param string $key The table's key.
	 *
	 * @return bool True when a row carries the key.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function exists(string $key): bool;

	/**
	 * The key of the table that belongs to a resolved rule set row.
	 *
	 * @param array<string,mixed> $ruleSet The rule set as the reader returns it.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function keyOfRuleSet(array $ruleSet): string;

	/**
	 * Write a rule set's table into OpenRegister, replacing the one it had.
	 *
	 * The CALLER authorises: the write runs without OpenRegister's register
	 * RBAC, because the repair step runs without a user. The one HTTP path,
	 * RulesController::saveDecisionTable(), refuses anyone but an administrator
	 * before it gets here, which is who could write a table in buildiq's own
	 * register before the move.
	 *
	 * @param array<string,mixed> $ruleSet The resolved rule set (slug, name, @self.organisation).
	 * @param array<string,mixed> $table   The table in buildiq's authoring shape.
	 *
	 * @return array<string,mixed> The stored row as written.
	 *
	 * @throws RuntimeException When OpenRegister refuses the write, including a table its validator cannot run.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	public function save(array $ruleSet, array $table): array;
}//end interface
