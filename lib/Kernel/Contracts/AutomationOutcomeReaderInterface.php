<?php

/**
 * Contract of OpenRegisterAutomationOutcomeReader
 *
 * Reads what OpenRegister recorded for the notifications and lifecycle actions
 * an automation compiled to.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of OpenRegisterAutomationOutcomeReader.
 *
 * Reading never throws: when OpenRegister has no outcome record, or the read
 * fails, the answer is an empty list and the runs dialog keeps its label.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */
interface AutomationOutcomeReaderInterface {

	/**
	 * The most outcomes one read asks for.
	 */
	public const READ_LIMIT = 500;

	/**
	 * The outcomes whose key starts with the prefix, newest first, as the current user may read them.
	 *
	 * Each outcome is `{kind, key, at, subject: {register, schema, id}, outcome, reason, recipients?}`.
	 *
	 * @param string $keyPrefix The key prefix.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
	 */
	public function outcomes(string $keyPrefix): array;
}//end interface
