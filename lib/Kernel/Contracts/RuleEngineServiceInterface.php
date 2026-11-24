<?php

/**
 * Contract of RuleEngineService
 *
 * The public surface of RuleEngineService that other components call, so they depend
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
 * Contract of RuleEngineService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RuleEngineServiceInterface {

	/**
	 * Shared Buildiq register slug.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * Schema slugs for the rules-engine objects.
	 */
	public const RULE_SET_SCHEMA = 'rule-set';

	public const EXECUTION_LOG_SCHEMA = 'rule-execution-log';

	/**
	 * Soft evaluation timeout in milliseconds (design.md Decision 10).
	 */
	public const TIMEOUT_MS = 500;

	/**
	 * Evaluate a RuleSet against a payload.
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 * @param array<string,mixed> $payload The input payload.
	 * @param string|null $version Optional pinned version (default: active).
	 * @param bool $dryRun When true, side-effecting actions are suppressed.
	 * @param bool $maskPii When true, mask PII fields in the audit log.
	 * @param bool $preview When true, a live preview for a form being filled
	 *                      in: no execution log entry is written and no side-effecting action runs
	 *                      (REQ-BQLV-004). A preview answer is never trusted for a save.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,executionTime:int,errors:array<int,string>}
	 *
	 * @throws RuntimeException When the RuleSet is not found / not owned, or on timeout.
	 *
	 * @spec openspec/changes/archive/2026-11-24-forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-live-evaluation-leaves-no-log-trail-req-bqlv-004
	 */
	public function evaluate(
		string $ruleSetSlug,
		array $payload,
		?string $version = null,
		bool $dryRun = false,
		bool $maskPii = true,
		bool $preview = false,
	): array;

	/**
	 * Resolve the active rule set another app asks for, and whether that app may call it.
	 *
	 * @param string $app  The calling app id.
	 * @param string $slug The rule set slug.
	 *
	 * @return array{status:'ok'|'unknown-rule-set'|'not-allowed',ruleSet:array<string,mixed>|null}
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function resolveForApp(string $app, string $slug): array;

	/**
	 * Evaluate a rule set that resolveForApp() gave another app, every action suppressed.
	 *
	 * @param string              $app     The calling app id.
	 * @param array<string,mixed> $ruleSet The rule set resolveForApp() answered.
	 * @param array<string,mixed> $payload The checked inputs.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,executionTime:int,errors:array<int,string>,version:string}
	 *
	 * @throws RuntimeException On a cycle or an over-deep chain.
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function evaluateForApp(string $app, array $ruleSet, array $payload): array;
}//end interface
