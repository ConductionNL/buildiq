<?php

/**
 * Buildiq RuleEngineService
 *
 * The single evaluation surface for the business-rules engine. Loads a RuleSet
 * bundle by slug (from the hot-reload cache or OpenRegister), evaluates its
 * DecisionTable and/or ConditionActionRules against an input payload, persists
 * a RuleExecutionLog audit record (AVG art. 22), and returns the result.
 *
 * Multi-tenant isolation, PII masking, the 500 ms soft timeout (design.md
 * Decision 10) and dry-run mode all live here. The actual evaluation algorithms
 * are delegated to DecisionTableEvaluator and ConditionActionExecutor — this
 * service is the orchestrator and the OpenRegister boundary (ADR-022: only the
 * real ObjectService API find/findAll/saveObject/searchObject is used).
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
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#5.1
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#5.2
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#5.3
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runtime rule-evaluation orchestrator.
 */
class RuleEngineService {

	/**
	 * Shared Buildiq register slug.
	 */
	public const REGISTER_SLUG = 'buildiq';

	/**
	 * Schema slugs for the rules-engine objects.
	 */
	public const RULE_SET_SCHEMA = 'rule-set';
	public const CONDITION_RULE_SCHEMA = 'condition-action-rule';
	public const EXECUTION_LOG_SCHEMA = 'rule-execution-log';

	/**
	 * Soft evaluation timeout in milliseconds (design.md Decision 10).
	 */
	public const TIMEOUT_MS = 500;

	/**
	 * Maximum `call-rule-set` chain depth (DoS hardening, harden-xss-dos-csrf).
	 *
	 * A condition-action rule may dispatch a `call-rule-set` action that
	 * re-enters {@see evaluate()} via RuleActionDispatcher. Without a bound a
	 * self- or mutually-referential rule set recurses until the worker crashes,
	 * writing a log row and firing side effects at every level. Legitimate
	 * nesting is shallow, so 10 is generous.
	 */
	private const MAX_CALL_DEPTH = 10;

	/**
	 * Maximum recursion depth of {@see maskPii()} (defence-in-depth against a
	 * deeply-nested payload; primary bound is the controller's payload cap).
	 */
	private const MAX_MASK_DEPTH = 64;

	/**
	 * Fields masked in RuleExecutionLog input when masking is enabled.
	 *
	 * @var array<int,string>
	 */
	private const PII_FIELDS = ['bsn', 'ssn', 'email', 'phone', 'iban'];

	/**
	 * Current `call-rule-set` chain depth (re-entry guard, harden-xss-dos-csrf).
	 *
	 * @var integer
	 */
	private int $callDepth = 0;

	/**
	 * Rule-set slugs currently active in the evaluation chain (cycle guard).
	 *
	 * @var array<int,string>
	 */
	private array $activeSlugs = [];

	/**
	 * DMN table evaluator.
	 *
	 * @var DecisionTableEvaluator
	 */
	private readonly DecisionTableEvaluator $decisionEvaluator;

	/**
	 * Condition-action chain executor.
	 *
	 * @var ConditionActionExecutor
	 */
	private readonly ConditionActionExecutor $conditionExecutor;

	/**
	 * Wired side-effect dispatcher (spec REQ-AUTD-010 — fixes the verified defect where side-effecting
	 * actions silently no-op in wet runs because no dispatcher was ever passed to the executor).
	 *
	 * @var RuleActionDispatcher
	 */
	private readonly RuleActionDispatcher $actionDispatcher;

	/**
	 * Hot-reload bundle cache.
	 *
	 * @var RuleSetCacheManager
	 */
	private readonly RuleSetCacheManager $cacheManager;

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param RuleEvaluators $evaluators The decision table evaluator, condition executor, action dispatcher and rule cache.
	 * @param IUserSession $userSession Current user session.
	 * @param LoggerInterface $logger PSR logger.
	 * @param RuleObjectReader $reader Organisation-scoped reads of rule-engine objects (REQ-BRE-007).
	 * @param StoredDecisionTables $tables A rule set's decision table, held in OpenRegister's decision-tables register (decision 196).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		RuleEvaluators $evaluators,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly RuleObjectReader $reader,
		private readonly StoredDecisionTables $tables,
	) {
		$this->decisionEvaluator = $evaluators->decisionEvaluator;
		$this->conditionExecutor = $evaluators->conditionExecutor;
		$this->actionDispatcher = $evaluators->actionDispatcher;
		$this->cacheManager = $evaluators->cacheManager;

	}//end __construct()

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
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-live-evaluation-leaves-no-log-trail-req-bqlv-004
	 */
	public function evaluate(
		string $ruleSetSlug,
		array $payload,
		?string $version = null,
		bool $dryRun = false,
		bool $maskPii = true,
		bool $preview = false,
	): array {
		$options = new RuleEvaluationOptions(version: $version, dryRun: ($dryRun === true || $preview === true), maskPii: $maskPii, preview: $preview);
		return $this->evaluateGuarded(evaluation: new RuleSetEvaluation(ruleSetSlug: $ruleSetSlug, payload: $payload, options: $options));

	}//end evaluate()

	/**
	 * Evaluate a RuleSet inside the re-entry and depth guards of a call-rule-set chain.
	 *
	 * @param RuleSetEvaluation $evaluation The rule set, payload and options.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,executionTime:int,errors:array<int,string>}
	 *
	 * @throws RuntimeException On a cycle, an over-deep chain, a missing RuleSet or a timeout.
	 */
	private function evaluateGuarded(RuleSetEvaluation $evaluation): array {
		$this->assertCanEnter(ruleSetSlug: $evaluation->ruleSetSlug);

		++$this->callDepth;
		$this->activeSlugs[] = $evaluation->ruleSetSlug;
		try {
			return $this->evaluateChain(evaluation: $evaluation);
		} finally {
			--$this->callDepth;
			array_pop($this->activeSlugs);
		}

	}//end evaluateGuarded()

	/**
	 * Refuse a rule set that is already active in this chain, or a chain that is too deep.
	 *
	 * Thrown before any state mutation, log write, or side effect for this level.
	 *
	 * @param string $ruleSetSlug The RuleSet slug about to be evaluated.
	 *
	 * @return void
	 *
	 * @throws RuntimeException With code 508 on a cycle or an over-deep chain.
	 */
	private function assertCanEnter(string $ruleSetSlug): void {
		// Re-entry cycle guard: a rule set already active in this chain must not
		// be re-evaluated (self- or mutually-referential call-rule-set).
		if (in_array($ruleSetSlug, $this->activeSlugs, true) === true) {
			throw new RuntimeException(
				'Rule-set cycle detected: "' . $ruleSetSlug . '" is already being evaluated in this chain.',
				508
			);
		}

		// Depth guard: bound the call-rule-set chain length.
		if ($this->callDepth >= self::MAX_CALL_DEPTH) {
			throw new RuntimeException(
				'Rule-set call chain exceeded the maximum depth of ' . self::MAX_CALL_DEPTH . '.',
				508
			);
		}

	}//end assertCanEnter()

	/**
	 * Run a single RuleSet evaluation (re-entry-guarded by {@see evaluateGuarded()}).
	 *
	 * @param RuleSetEvaluation $evaluation The rule set, payload and options.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,executionTime:int,errors:array<int,string>}
	 *
	 * @throws RuntimeException When the RuleSet is not found / not owned, or on timeout.
	 */
	private function evaluateChain(RuleSetEvaluation $evaluation): array {
		$startedAt = microtime(true);

		$bundle = $this->loadBundle(slug: $evaluation->ruleSetSlug, version: $evaluation->options->version);
		if ($bundle === null) {
			throw new RuntimeException('RuleSet "' . $evaluation->ruleSetSlug . '" not found.', 404);
		}

		$answer = $this->runBundle(bundle: $bundle, evaluation: $evaluation);
		$outcome = $this->timedOutcome(answer: $answer, startedAt: $startedAt);

		if ($evaluation->options->preview === false) {
			$this->persistLog(run: $this->runRecord(evaluation: $evaluation, bundle: $bundle), outcome: $outcome);
		}

		return $outcome;
	}//end evaluateChain()

	/**
	 * Evaluate a loaded bundle by its rule type; a throwing evaluator becomes an error.
	 *
	 * @param array<string,mixed> $bundle     The RuleSet bundle.
	 * @param RuleSetEvaluation   $evaluation The rule set, payload and options.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,errors:array<int,string>}
	 */
	private function runBundle(array $bundle, RuleSetEvaluation $evaluation): array {
		try {
			$ruleType = (string)($bundle['ruleSet']['ruleType'] ?? 'decision-table');

			if ($ruleType === 'decision-table' && $bundle['decisionTables'] !== []) {
				return $this->runDecisionTable(table: $bundle['decisionTables'][0], payload: $evaluation->payload);
			}

			if ($ruleType === 'condition-action') {
				return $this->runConditionRules(rules: $bundle['conditionRules'], evaluation: $evaluation);
			}
		} catch (Throwable $e) {
			return ['result' => [], 'triggeredRules' => [], 'errors' => [$e->getMessage()]];
		}

		return ['result' => [], 'triggeredRules' => [], 'errors' => []];
	}//end runBundle()

	/**
	 * Evaluate the first decision table of a RuleSet.
	 *
	 * @param array<string,mixed> $table   The decision table.
	 * @param array<string,mixed> $payload The input payload.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,errors:array<int,string>}
	 */
	private function runDecisionTable(array $table, array $payload): array {
		$outcome = $this->decisionEvaluator->evaluate($table, $payload);
		$result = $outcome['outputColumns'];
		$triggeredRules = [];
		if ($outcome['triggeredRuleId'] !== null) {
			$triggeredRules[] = $outcome['triggeredRuleId'];
		}

		return ['result' => $result, 'triggeredRules' => $triggeredRules, 'errors' => []];
	}//end runDecisionTable()

	/**
	 * Execute the condition-action rules of a RuleSet.
	 *
	 * Pass the wired dispatcher (spec REQ-AUTD-010) — the executor itself
	 * suppresses dispatch when dry-run is set, so it is always safe to hand the
	 * real dispatcher through here.
	 *
	 * @param array<int,mixed>  $rules      The condition-action rules.
	 * @param RuleSetEvaluation $evaluation The rule set, payload and options.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,errors:array<int,string>}
	 */
	private function runConditionRules(array $rules, RuleSetEvaluation $evaluation): array {
		$outcome = $this->conditionExecutor->execute($rules, $evaluation->payload, $evaluation->options->dryRun, $this->actionDispatcher);

		return [
			'result' => $outcome['result'],
			'triggeredRules' => array_column($outcome['triggeredRules'], 'name'),
			'errors' => $outcome['errors'],
		];
	}//end runConditionRules()

	/**
	 * The evaluation answer with its duration, flagging a run over the soft timeout.
	 *
	 * @param array{result:array<string,mixed>,triggeredRules:array<int,mixed>,errors:array<int,string>} $answer    What the evaluators answered.
	 * @param float                                                                                       $startedAt When the evaluation started.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,executionTime:int,errors:array<int,string>}
	 */
	private function timedOutcome(array $answer, float $startedAt): array {
		$durationMs = (int)round((microtime(true) - $startedAt) * 1000);

		$errors = $answer['errors'];
		if ($durationMs > self::TIMEOUT_MS) {
			$errors[] = 'Evaluation exceeded the ' . self::TIMEOUT_MS . 'ms soft timeout (' . $durationMs . 'ms).';
		}

		return [
			'result' => $answer['result'],
			'triggeredRules' => $answer['triggeredRules'],
			'executionTime' => $durationMs,
			'errors' => $errors,
		];
	}//end timedOutcome()

	/**
	 * What the execution log records about the run itself.
	 *
	 * @param RuleSetEvaluation   $evaluation The rule set, payload and options.
	 * @param array<string,mixed> $bundle     The RuleSet bundle.
	 *
	 * @return array{ruleSetSlug:string,version:string,payload:array<string,mixed>,maskPii:bool}
	 */
	private function runRecord(RuleSetEvaluation $evaluation, array $bundle): array {
		return [
			'ruleSetSlug' => $evaluation->ruleSetSlug,
			'version' => (string)($bundle['ruleSet']['version'] ?? ''),
			'payload' => $evaluation->payload,
			'maskPii' => $evaluation->options->maskPii,
		];
	}//end runRecord()

	/**
	 * Load (and cache) a RuleSet bundle: the RuleSet plus its tables/rules.
	 *
	 * Every read goes through {@see RuleObjectReader}, which applies the
	 * schema's RBAC and the caller's organisation (REQ-BRE-007): a rule set
	 * held by another organisation resolves to null, so the caller answers 404.
	 * The decision table is then read from OpenRegister's decision-tables
	 * register under a key derived from that resolved rule set, so it is only
	 * reachable through a rule set the caller could already see.
	 *
	 * The rule set itself is read on every call, never from the cache. The
	 * cache is distributed and shared by every caller, so a bundle keyed by
	 * slug alone handed the first caller's rule set to everyone for the TTL.
	 * The key now carries the resolved rule set's identity, and only its
	 * tables and rules are served from it.
	 *
	 * @param string $slug The RuleSet slug.
	 * @param string|null $version Optional pinned version.
	 *
	 * @return array{ruleSet:array<string,mixed>,decisionTables:array<int,mixed>,conditionRules:array<int,mixed>}|null
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	private function loadBundle(string $slug, ?string $version): ?array {
		$ruleSet = $this->findOne(schema: self::RULE_SET_SCHEMA, filters: ['slug' => $slug]);
		if ($ruleSet === null) {
			return null;
		}

		if ($version !== null && (string)($ruleSet['version'] ?? '') !== $version) {
			// Pinned version requested but the active row is a different one.
			// Without version-history storage we only serve the active row, so
			// a mismatch is a not-found for the pinned request.
			return null;
		}

		$cacheKey = $slug . '#' . $this->identityOf(ruleSet: $ruleSet);
		$cached = $this->cacheManager->get($cacheKey, $version);
		if ($cached !== null) {
			$cached['ruleSet'] = $ruleSet;
			return $cached;
		}

		$bundle = [
			'ruleSet' => $ruleSet,
			'ruleType' => (string)($ruleSet['ruleType'] ?? 'decision-table'),
			'decisionTables' => $this->tables->forRuleSet(ruleSet: $ruleSet),
			'conditionRules' => $this->findMany(schema: self::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $slug]),
		];

		$this->cacheManager->set($cacheKey, $bundle, $version);
		return $bundle;
	}//end loadBundle()

	/**
	 * What tells this rule set apart from another organisation's rule set with
	 * the same slug: its object id, and its organisation and version.
	 *
	 * @param array<string,mixed> $ruleSet The resolved rule set.
	 *
	 * @return string
	 */
	private function identityOf(array $ruleSet): string {
		$self = [];
		if (is_array($ruleSet['@self'] ?? null) === true) {
			$self = $ruleSet['@self'];
		}

		return implode(
			'|',
			[
				(string)($ruleSet['id'] ?? ($self['id'] ?? ($self['uuid'] ?? ''))),
				(string)($self['organisation'] ?? ''),
				(string)($ruleSet['version'] ?? ''),
			]
		);
	}//end identityOf()

	/**
	 * Persist a RuleExecutionLog audit record.
	 *
	 * @param array{ruleSetSlug:string,version:string,payload:array<string,mixed>,maskPii:bool} $run The run:
	 *        the RuleSet slug and version, the raw input payload, and whether to mask PII in the log.
	 * @param array{result:array<string,mixed>,triggeredRules:array<int,mixed>,executionTime:int,errors:array<int,string>} $outcome The answer.
	 *
	 * @return void
	 */
	private function persistLog(array $run, array $outcome): void {
		$user = $this->userSession->getUser();
		$userId = '';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$loggedInput = $run['payload'];
		if ($run['maskPii'] === true) {
			$loggedInput = $this->maskPii(payload: $run['payload']);
		}

		$log = [
			'ruleSetId' => $run['ruleSetSlug'],
			'ruleSetVersion' => $run['version'],
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'triggerContext' => 'api',
			'inputPayload' => $loggedInput,
			'outputResult' => $outcome['result'],
			'triggeredRules' => array_map('strval', $outcome['triggeredRules']),
			'executionDurationMs' => $outcome['executionTime'],
			'errors' => $outcome['errors'],
			'userId' => $userId,
			'archived' => false,
		];

		try {
			$this->objectService->saveObject(
				object: $log,
				register: self::REGISTER_SLUG,
				schema: self::EXECUTION_LOG_SCHEMA
			);
		} catch (Throwable $e) {
			// A failed audit write must not abort the evaluation response, but
			// it is a compliance gap — log at error so ops alerting catches it.
			$this->logger->error(
				'Buildiq: failed to persist RuleExecutionLog',
				['ruleSet' => $run['ruleSetSlug'], 'exception' => $e->getMessage()]
			);
		}

	}//end persistLog()

	/**
	 * Recursively mask configured PII fields in a payload.
	 *
	 * @param array<string,mixed> $payload The payload to mask.
	 * @param int $depth Current recursion depth (guards against a deeply-nested payload).
	 *
	 * @return array<string,mixed> A masked copy.
	 */
	private function maskPii(array $payload, int $depth = 0): array {
		// Defence-in-depth: a payload nested beyond the cap is redacted wholesale
		// rather than recursed into, so a pathological structure cannot exhaust
		// the stack while building the audit record.
		if ($depth >= self::MAX_MASK_DEPTH) {
			return ['***' => '***'];
		}

		$masked = [];
		foreach ($payload as $key => $value) {
			if (is_array($value) === true) {
				$masked[$key] = $this->maskPii(payload: $value, depth: ($depth + 1));
				continue;
			}

			if (in_array(strtolower((string)$key), self::PII_FIELDS, true) === true) {
				$masked[$key] = '***';
				continue;
			}

			$masked[$key] = $value;
		}

		return $masked;
	}//end maskPii()

	/**
	 * Find a single object by schema + filters in the shared register.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findMany(schema: $schema, filters: $filters, limit: 1);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end findOne()

	/**
	 * Find objects by schema + filters in the shared register, scoped to the
	 * caller's organisation and the schema's RBAC (REQ-BRE-007).
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 * @param int|null $limit Optional row limit.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	private function findMany(string $schema, array $filters, ?int $limit = null): array {
		return $this->reader->findScoped(schema: $schema, filters: $filters, limit: $limit);
	}//end findMany()
}//end class
