<?php

/**
 * Contract of CopilotService
 *
 * The public surface of CopilotService that other components call, so they depend
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

use OCA\Buildiq\Exception\CopilotException;

/**
 * Contract of CopilotService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CopilotServiceInterface {

	/**
	 * Discard a reviewed proposal without executing it — still writes a transparent
	 * `AgentRun` record (agent-workspace spec "A discarded proposal is still logged").
	 * Only meaningful for the agent-scoped chat surface: the bare copilot panel
	 * never calls this (D3 — omitted agent props, zero behavioural change).
	 *
	 * @param string $agentId The `Agent` id this turn belongs to.
	 * @param string $userId Acting user's UID.
	 * @param string $prompt The user's natural-language brief for this turn.
	 * @param array<string, mixed> $plan The plan `{summary, steps[]}` that was reviewed and discarded.
	 * @param string|null $traceId The trace id the plan returned, so the run keeps its model steps (REQ-BQAG-004).
	 *
	 * @return array<string, mixed> The persisted `AgentRun` record.
	 *
	 * @throws CopilotException On an unknown agent or RBAC denial.
	 *
	 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
	 */
	public function discard(string $agentId, string $userId, string $prompt, array $plan, ?string $traceId = null): array;

	/**
	 * Execute an approved plan atomically through the MCP handler layer.
	 *
	 * Re-validates the plan (the server never trusts the client's review),
	 * snapshots every touched version's manifest, dispatches each step in
	 * order (createApp first, promoteVersion last) through
	 * `BuildiqToolProvider::invokeTool()`, and on any step failure rolls
	 * every snapshot back and deletes a plan-created application. When
	 * `$agentId` is given, the resolved agent's tool allow-list is
	 * re-applied to the revalidation and a transparent `AgentRun` record is
	 * persisted regardless of outcome (agent-workspace design.md Decision 2).
	 *
	 * @param array<string, mixed> $plan The reviewed plan `{summary, steps[]}`, echoed back verbatim.
	 * @param string $userId Acting user's UID.
	 * @param string|null $agentId Optional `Agent` id this plan was planned with — re-resolved
	 *                             server-side, never trusted from the client beyond the id.
	 * @param string $prompt The original user brief for this turn, echoed back so the
	 *                       resulting `AgentRun` record carries it. Ignored when
	 *                       `$agentId` is null.
	 * @param string|null $traceId The trace id the plan returned, so the run keeps its model steps (REQ-BQAG-004).
	 *
	 * @return array{results: array<int, array<string, mixed>>}
	 *
	 * @throws CopilotException On revalidation failure, an unknown agent, RBAC denial, or a mid-plan step failure.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
	 */
	public function execute(array $plan, string $userId, ?string $agentId = null, string $prompt = '', ?string $traceId = null): array;

	/**
	 * Probe copilot availability.
	 *
	 * @return array{available: bool, reason?: string}
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 */
	public function health(): array;

	/**
	 * Turn a natural-language brief into a validated plan. Performs zero
	 * builder writes (an agent-scoped rejected plan still writes exactly
	 * one `AgentRun` audit record — never an Application/ApplicationVersion/
	 * schema/manifest write).
	 *
	 * @param string $brief User's natural-language brief (1-2000 chars).
	 * @param string|null $appSlug Optional target app slug. Ignored (overridden by the resolved
	 *                             agent's `applicationSlug`) when `$agentId` is given — an agent's
	 *                             app scope is never taken from client input (agent-workspace
	 *                             design.md "scoped to the Application the agent belongs to").
	 * @param string $userId Acting user's UID.
	 * @param string|null $agentId Optional `Agent` id narrowing the effective tool allow-list and
	 *                             prefixing the agent's instructions onto the system prompt
	 *                             (agent-workspace design.md Decision 1).
	 * @param string|null $versionSlug The version the caller is editing. Named in the prompt, and
	 *                                 filled in on any step that leaves `versionSlug` out, so a
	 *                                 plan lands on the version the user is looking at rather
	 *                                 than on the tools' `development` default.
	 *
	 * @return array{summary: string, steps: array<int, array<string, mixed>>, manifests: array<string, array{current: array, predicted: array}>}
	 *
	 * @throws CopilotException On unavailability, invalid input, RBAC denial, an unknown agent, or an unparsable/invalid plan.
	 *
	 * @spec openspec/specs/ai-copilot/spec.md
	 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/ai-copilot/spec.md
	 */
	public function plan(string $brief, ?string $appSlug, string $userId, ?string $agentId = null, ?string $versionSlug = null): array;
}//end interface
