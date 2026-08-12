<?php

/**
 * OpenBuild AgentRunLogger
 *
 * Thin wrapper that persists one `AgentRun` record per plan+execute (or
 * plan+discard) interaction issued with an `agentId` — the prompt, the
 * returned plan, every tool call's arguments and result, and the final
 * outcome (design.md Decision 2 of agent-workspace). Deliberately kept out
 * of `CopilotService`'s core plan/execute logic so the bare (non-agent)
 * copilot path is provably untouched: this class is only ever called from
 * the branches of `CopilotService` that already resolved a non-null
 * `Agent`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists `AgentRun` records (agent-workspace REQ "Every agent run is
 * transparently logged and reviewable").
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */
class AgentRunLogger {

	private const REGISTER_SLUG = 'openbuild';

	private const AGENT_RUN_SCHEMA = 'agentRun';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object surface — the only write path this class uses.
	 * @param LoggerInterface $logger PSR logger for non-fatal persistence failures.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Persist one `AgentRun` record.
	 *
	 * A logging failure is swallowed (logged, not thrown) — a broken audit
	 * write must never turn a successfully applied/rolled-back/discarded
	 * plan into a user-facing 500. The caller has already completed (or
	 * definitively failed) the underlying plan/execute/discard action by
	 * the time this is called.
	 *
	 * @param array<string, mixed> $agent The resolved `Agent` record (must carry `id`/`uuid` and `applicationSlug`).
	 * @param string $userId Acting user's UID (recorded via `ObjectService::saveObject()`'s owner-stamping).
	 * @param string $prompt The user's natural-language brief for this turn.
	 * @param array<string, mixed> $plan The plan `{summary, steps[]}`, echoed verbatim (may be empty when unparsable).
	 * @param array<int, mixed> $toolCalls Ordered `{tool, arguments, result}` tool calls this turn (empty for discarded/plan-rejected).
	 * @param string $outcome One of `applied`|`rolled-back`|`discarded`|`plan-rejected` — the final outcome of this turn.
	 *
	 * @return array<string, mixed> The persisted `AgentRun` record (normalised), or an empty array on a swallowed failure.
	 *
	 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
	 */
	public function log(array $agent, string $userId, string $prompt, array $plan, array $toolCalls, string $outcome): array {
		$agentId = (string)($agent['id'] ?? $agent['uuid'] ?? '');

		$payload = [
			'agentId' => $agentId,
			'applicationSlug' => (string)($agent['applicationSlug'] ?? ''),
			'prompt' => $prompt,
			'plan' => $plan,
			'toolCalls' => array_values($toolCalls),
			'outcome' => $outcome,
			'createdAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];

		try {
			$created = $this->objectService->saveObject(
				object: $payload,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_RUN_SCHEMA,
			);

			return $this->toArray(item: $created);
		} catch (Throwable $e) {
			$this->logger->error(
				'OpenBuild AgentRunLogger: failed to persist AgentRun for agent ' . $agentId . ' (outcome ' . $outcome . '): ' . $e->getMessage(),
				['exception' => $e, 'userId' => $userId]
			);

			return [];
		}//end try
	}//end log()

	/**
	 * Coerce an OR entity, array, or generic value into an associative array.
	 *
	 * @param mixed $item Value to coerce.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $item): array {
		if (is_array($item) === true) {
			return $item;
		}

		if (is_object($item) === true && method_exists($item, 'jsonSerialize') === true) {
			$serialised = $item->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return (array)$item;
	}//end toArray()
}//end class
