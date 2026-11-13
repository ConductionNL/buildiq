<?php

/**
 * Contract of AutomationCompilerService
 *
 * The public surface of AutomationCompilerService that other components call, so they depend
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

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;

/**
 * Contract of AutomationCompilerService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AutomationCompilerServiceInterface {

	/**
	 * Shared Buildiq register slug.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * Schema slug of the Automation object itself.
	 */
	public const AUTOMATION_SCHEMA = 'automation';

	/**
	 * Apply a compiled plan: idempotent upsert of every artifact, removing
	 * any prior-provenance artifact no longer present in the new plan.
	 *
	 * @param array<string,mixed> $automation The Automation object (must carry an `id`/`uuid`).
	 * @param array<string,mixed> $plan The CompiledPlan from {@see compile()}.
	 * @param array<string,mixed> $priorProvenance The automation's PREVIOUS `provenance` block (empty on first compile).
	 *
	 * @return array<string,mixed> The new `provenance` block.
	 *
	 * @spec openspec/changes/archive/2026-07-11-automation-designer/tasks.md#2.2
	 */
	public function apply(array $automation, array $plan, array $priorProvenance = []): array;

	/**
	 * Aggregate state of the automation's most recently initialised approval
	 * chain instantiation (spec REQ-AUTD-007 / task 5.1).
	 *
	 * Reads the compiled `ApprovalChain` (by provenance name + the
	 * automation's own `trigger.schema`) and returns the status of the
	 * most-recently-created `ApprovalStep` on it. v1 compiles exactly one
	 * step per `approval` action (design.md Decision 1), so that step's own
	 * `pending|approved|rejected` status IS the aggregate state.
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 * @param array<string,mixed> $provenance The automation's `provenance` block.
	 *
	 * @return string One of `none|pending|approved|rejected`.
	 *
	 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#5.1
	 * @spec openspec/specs/automation-designer/spec.md#requirement-dry-run-test-panel-evaluates-an-automation-without-side-effects-req-autd-007
	 */
	public function approvalState(array $automation, array $provenance): string;

	/**
	 * Compile an automation to its CompiledPlan (pure — no I/O).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 *
	 * @return array{notifications:array<int,array<string,mixed>>,lifecycleActions:array<int,array<string,mixed>>,schedules:array<int,array<string,mixed>>,ruleSet:?array<string,mixed>,conditionActionRule:?array<string,mixed>,approvalChain:?array<string,mixed>,hash:string}
	 *
	 * @throws UnsupportedAutomationCombinationException When the matrix (or condition placement) rejects the shape.
	 *
	 * @spec openspec/changes/archive/2026-07-11-automation-designer/tasks.md#2.1
	 * @spec openspec/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-existing-declarative-primitives-req-autd-004
	 */
	public function compile(array $automation): array;

	/**
	 * Compile an automation IN-MEMORY to a rules-backend-shaped
	 * ConditionActionRule for the dry-run test panel (design.md Decision 9) —
	 * unlike {@see compile()}, this does NOT enforce the matrix by trigger
	 * type and never mints/persists a RuleSet: every matrix cell's actions
	 * map 1:1 onto the executor's typed action records so the SAME dry-run
	 * panel works for every trigger, without requiring a persisted RuleSet
	 * (event/schedule automations never have one).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 *
	 * @return array<string,mixed> A synthetic ConditionActionRule-shaped record.
	 *
	 * @spec openspec/changes/archive/2026-07-11-automation-designer/tasks.md#3.1
	 * @spec openspec/specs/automation-designer/spec.md#requirement-dry-run-test-panel-evaluates-an-automation-without-side-effects-req-autd-007
	 */
	public function compileDryRunRule(array $automation): array;

	/**
	 * Map one Automation action record to a ConditionActionRule typed action.
	 *
	 * PUBLIC (not merely an internal compile-time helper): also reused by
	 * {@see \OCA\Buildiq\Listener\ApprovalOutcomeListener} to map an
	 * `approval` action's `onApprove`/`onReject` follow-up action records to
	 * the SAME typed-action shape {@see \OCA\Buildiq\Service\RuleActionDispatcher}
	 * already dispatches — the follow-up fires once per approval outcome
	 * through the identical dispatch mechanism the rules backend uses, per
	 * design.md Decision 3 of automation-approval-steps ("through the same
	 * dialect/notification compilation the automation already uses").
	 *
	 * @param array<string,mixed> $action The Automation action record.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#2.1
	 */
	public function mapActionToRuleAction(array $action): array;

	/**
	 * Remove exactly the provenance-listed artifacts (design.md Decision 4 —
	 * hand-authored non-`aut-` entries are never touched).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 * @param array<string,mixed> $provenance The automation's `provenance` block.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-07-11-automation-designer/tasks.md#2.2
	 * @spec openspec/specs/automation-designer/spec.md#requirement-an-automation-is-managed-as-one-unit-with-provenance-req-autd-005
	 */
	public function remove(array $automation, array $provenance): void;

	/**
	 * Recompute drift: compare the live artifacts against the last-applied
	 * `provenance.compiledHash` (design.md Decision 4).
	 *
	 * @param array<string,mixed> $automation The Automation object.
	 * @param array<string,mixed> $provenance The automation's `provenance` block.
	 *
	 * @return array{drift:bool,compiledHash:?string,liveHash:?string}
	 *
	 * @spec openspec/changes/archive/2026-07-11-automation-designer/tasks.md#2.2
	 * @spec openspec/specs/automation-designer/spec.md#requirement-an-automation-is-managed-as-one-unit-with-provenance-req-autd-005
	 */
	public function status(array $automation, array $provenance): array;
}//end interface
