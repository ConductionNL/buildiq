<?php

/**
 * Buildiq AgentRunLogger
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
 * @package  OCA\Buildiq\Service
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

namespace OCA\Buildiq\Service;

use DateTimeImmutable;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Persists `AgentRun` records (agent-workspace REQ "Every agent run is
 * transparently logged and reviewable").
 *
 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
 */
class AgentRunLogger {

	private const REGISTER_SLUG = 'buildiq';

	private const AGENT_RUN_SCHEMA = 'agentRun';

	/**
	 * Cache namespace of the model steps waiting between plan and execute.
	 */
	private const TRACE_NAMESPACE = 'buildiq-agent-run-trace';

	/**
	 * How long a plan's model steps wait for the run, in seconds.
	 */
	public const TRACE_TTL = 3600;

	/**
	 * The distributed cache the steps wait in, or null when none is configured.
	 *
	 * @var ICache|null
	 */
	private ?ICache $traceCache = null;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object surface — the only write path this class uses.
	 * @param LoggerInterface $logger PSR logger for non-fatal persistence failures.
	 * @param ICacheFactory|null $cacheFactory Distributed cache for the model steps between plan and execute.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
		?ICacheFactory $cacheFactory = null,
	) {
		if ($cacheFactory !== null) {
			$this->traceCache = $cacheFactory->createDistributed(self::TRACE_NAMESPACE);
		}
	}//end __construct()

	/**
	 * Keep a plan's model steps until the run is written, and return the id that finds them.
	 *
	 * The plan step writes no run, so its steps wait in the distributed cache for
	 * one hour. The key holds the agent and the user, so a trace id replayed by
	 * another user, or for another agent, finds nothing.
	 *
	 * @param array<string, mixed> $agent  The `Agent` record.
	 * @param string               $userId The planning user.
	 * @param array<string, mixed> $trace  `{modelSteps, knowledgeUsed, durationMs}`.
	 *
	 * @return string The trace id, or an empty string when no cache is configured.
	 *
	 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
	 */
	public function rememberTrace(array $agent, string $userId, array $trace): string {
		if ($this->traceCache === null) {
			return '';
		}

		$traceId = bin2hex(random_bytes(16));
		try {
			$this->traceCache->set(
				$this->traceKey(agentId: self::agentIdOf(agent: $agent), userId: $userId, traceId: $traceId),
				json_encode($trace, JSON_THROW_ON_ERROR),
				self::TRACE_TTL
			);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq AgentRunLogger: could not keep the model steps of a plan: ' . $e->getMessage());
			return '';
		}

		return $traceId;
	}//end rememberTrace()


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
	 * @param string|null $traceId The trace id the plan returned, to attach its cached model steps.
	 * @param array<string, mixed>|null $trace The model steps in hand (a plan rejected in the same request); wins over `$traceId`.
	 *
	 * @return array<string, mixed> The persisted `AgentRun` record (normalised), or an empty array on a swallowed failure.
	 *
	 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function log(
		array $agent,
		string $userId,
		string $prompt,
		array $plan,
		array $toolCalls,
		string $outcome,
		?string $traceId = null,
		?array $trace = null,
	): array {
		$agentId = self::agentIdOf(agent: $agent);
		$trace ??= $this->takeTrace(agentId: $agentId, userId: $userId, traceId: (string)$traceId);

		$payload = [
			'agentId' => $agentId,
			'applicationSlug' => (string)($agent['applicationSlug'] ?? ''),
			'prompt' => $prompt,
			'plan' => $plan,
			'toolCalls' => array_values($toolCalls),
			'outcome' => $outcome,
			'modelSteps' => array_values((array)($trace['modelSteps'] ?? [])),
			'knowledgeUsed' => array_values((array)($trace['knowledgeUsed'] ?? [])),
			'durationMs' => (int)($trace['durationMs'] ?? 0),
			'traceMissing' => $trace === null,
			'createdAt' => (new DateTimeImmutable())->format(DATE_ATOM),
		];

		try {
			$created = $this->objectService->saveObject(
				object: $payload,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_RUN_SCHEMA,
			);

			return ObjectRecord::castArray(object: $created);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq AgentRunLogger: failed to persist AgentRun for agent ' . $agentId . ' (outcome ' . $outcome . '): ' . $e->getMessage(),
				['exception' => $e, 'userId' => $userId]
			);

			return [];
		}//end try
	}//end log()

	/**
	 * The cached model steps of a plan, removed from the cache, or null when they are gone.
	 *
	 * @param string $agentId The agent the run belongs to.
	 * @param string $userId  The user writing the run.
	 * @param string $traceId The trace id the plan returned.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-a-run-keeps-its-model-steps-req-bqag-004
	 */
	private function takeTrace(string $agentId, string $userId, string $traceId): ?array {
		if ($this->traceCache === null || preg_match('/^[0-9a-f]{32}$/', $traceId) !== 1) {
			return null;
		}

		$key = $this->traceKey(agentId: $agentId, userId: $userId, traceId: $traceId);
		$raw = $this->traceCache->get($key);
		if (is_string($raw) === false) {
			return null;
		}

		$this->traceCache->remove($key);
		$trace = json_decode($raw, true);
		if (is_array($trace) === false) {
			return null;
		}

		return $trace;
	}//end takeTrace()

	/**
	 * The cache key of a trace: agent, user and trace id together.
	 *
	 * @param string $agentId The agent.
	 * @param string $userId  The user.
	 * @param string $traceId The trace id.
	 *
	 * @return string
	 */
	private function traceKey(string $agentId, string $userId, string $traceId): string {
		return hash('sha256', $agentId . "\n" . $userId . "\n" . $traceId);
	}//end traceKey()

	/**
	 * The id of an `Agent` record.
	 *
	 * @param array<string, mixed> $agent The record.
	 *
	 * @return string
	 */
	private static function agentIdOf(array $agent): string {
		return (string)($agent['id'] ?? $agent['uuid'] ?? '');
	}//end agentIdOf()
}//end class
