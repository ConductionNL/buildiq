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
 * @spec openspec/changes/business-rules-engine/tasks.md#5.1
 * @spec openspec/changes/business-rules-engine/tasks.md#5.2
 * @spec openspec/changes/business-rules-engine/tasks.md#5.3
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
	public const DECISION_TABLE_SCHEMA = 'decision-table';
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
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister object service.
	 * @param DecisionTableEvaluator $decisionEvaluator DMN table evaluator.
	 * @param ConditionActionExecutor $conditionExecutor Condition-action chain executor.
	 * @param RuleSetCacheManager $cacheManager Hot-reload bundle cache.
	 * @param IUserSession $userSession Current user session.
	 * @param LoggerInterface $logger PSR logger.
	 * @param RuleActionDispatcher $actionDispatcher Wired side-effect dispatcher (spec REQ-AUTD-010 —
	 *                                               fixes the verified defect where side-effecting
	 *                                               actions silently no-op in wet runs because no
	 *                                               dispatcher was ever passed to the executor).
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly DecisionTableEvaluator $decisionEvaluator,
		private readonly ConditionActionExecutor $conditionExecutor,
		private readonly RuleSetCacheManager $cacheManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly RuleActionDispatcher $actionDispatcher,
	) {

	}//end __construct()

	/**
	 * Evaluate a RuleSet against a payload.
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 * @param array<string,mixed> $payload The input payload.
	 * @param string|null $version Optional pinned version (default: active).
	 * @param bool $dryRun When true, side-effecting actions are suppressed.
	 * @param bool $maskPii When true, mask PII fields in the audit log.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,executionTime:int,errors:array<int,string>}
	 *
	 * @throws RuntimeException When the RuleSet is not found / not owned, or on timeout.
	 */
	public function evaluate(
		string $ruleSetSlug,
		array $payload,
		?string $version = null,
		bool $dryRun = false,
		bool $maskPii = true,
	): array {
		// Re-entry cycle guard: a rule set already active in this chain must not
		// be re-evaluated (self- or mutually-referential call-rule-set). Thrown
		// before any state mutation, log write, or side effect for this level.
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

		++$this->callDepth;
		$this->activeSlugs[] = $ruleSetSlug;
		try {
			return $this->evaluateChain(
				ruleSetSlug: $ruleSetSlug,
				payload: $payload,
				version: $version,
				dryRun: $dryRun,
				maskPii: $maskPii
			);
		} finally {
			--$this->callDepth;
			array_pop($this->activeSlugs);
		}

	}//end evaluate()

	/**
	 * Run a single RuleSet evaluation (re-entry-guarded by {@see evaluate()}).
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 * @param array<string,mixed> $payload The input payload.
	 * @param string|null $version Optional pinned version (default: active).
	 * @param bool $dryRun When true, side-effecting actions are suppressed.
	 * @param bool $maskPii When true, mask PII fields in the audit log.
	 *
	 * @return array{result:array<string,mixed>,triggeredRules:array<int,mixed>,executionTime:int,errors:array<int,string>}
	 *
	 * @throws RuntimeException When the RuleSet is not found / not owned, or on timeout.
	 */
	private function evaluateChain(
		string $ruleSetSlug,
		array $payload,
		?string $version = null,
		bool $dryRun = false,
		bool $maskPii = true,
	): array {
		$startedAt = microtime(true);

		$bundle = $this->loadBundle(slug: $ruleSetSlug, version: $version);
		if ($bundle === null) {
			throw new RuntimeException('RuleSet "' . $ruleSetSlug . '" not found.', 404);
		}

		$errors = [];
		$result = [];
		$triggeredRules = [];

		try {
			$ruleType = (string)($bundle['ruleSet']['ruleType'] ?? 'decision-table');

			if ($ruleType === 'decision-table' && $bundle['decisionTables'] !== []) {
				$table = $bundle['decisionTables'][0];
				$outcome = $this->decisionEvaluator->evaluate($table, $payload);
				$result = $outcome['outputColumns'];
				if ($outcome['triggeredRuleId'] !== null) {
					$triggeredRules[] = $outcome['triggeredRuleId'];
				}
			} elseif ($ruleType === 'condition-action') {
				// Pass the wired dispatcher (spec REQ-AUTD-010) — the executor
				// itself suppresses dispatch when $dryRun is true, so it is
				// always safe to hand the real dispatcher through here.
				$outcome = $this->conditionExecutor->execute($bundle['conditionRules'], $payload, $dryRun, $this->actionDispatcher);
				$result = $outcome['result'];
				$errors = $outcome['errors'];
				$triggeredRules = array_column($outcome['triggeredRules'], 'name');
			}
		} catch (Throwable $e) {
			$errors[] = $e->getMessage();
		}//end try

		$durationMs = (int)round((microtime(true) - $startedAt) * 1000);

		if ($durationMs > self::TIMEOUT_MS) {
			$errors[] = 'Evaluation exceeded the ' . self::TIMEOUT_MS . 'ms soft timeout (' . $durationMs . 'ms).';
		}

		$this->persistLog(
			ruleSetSlug: $ruleSetSlug,
			version: (string)($bundle['ruleSet']['version'] ?? ''),
			payload: $payload,
			result: $result,
			triggeredRules: $triggeredRules,
			durationMs: $durationMs,
			errors: $errors,
			maskPii: $maskPii
		);

		return [
			'result' => $result,
			'triggeredRules' => $triggeredRules,
			'executionTime' => $durationMs,
			'errors' => $errors,
		];

	}//end evaluateChain()

	/**
	 * Load (and cache) a RuleSet bundle: the RuleSet plus its tables/rules.
	 *
	 * Resolution runs through OpenRegister `searchObjectsBySlug` (see
	 * {@see findMany()}), which applies the schema's RBAC. IMPORTANT: `buildiq`
	 * is a system-wide register (not org-scoped), so multitenancy is intentionally
	 * bypassed and this is NOT per-owner or per-organisation read isolation — with
	 * a read-open rule-set schema, any authenticated caller can resolve a rule-set
	 * by slug. Write operations (create/update/delete) remain admin-gated at the
	 * schema. (No false "foreign slug → 404 / no IDOR" guarantee is implied.)
	 *
	 * @param string $slug The RuleSet slug.
	 * @param string|null $version Optional pinned version.
	 *
	 * @return array{ruleSet:array<string,mixed>,decisionTables:array<int,mixed>,conditionRules:array<int,mixed>}|null
	 */
	private function loadBundle(string $slug, ?string $version): ?array {
		$cached = $this->cacheManager->get($slug, $version);
		if ($cached !== null) {
			return $cached;
		}

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

		$bundle = [
			'ruleSet' => $ruleSet,
			'ruleType' => (string)($ruleSet['ruleType'] ?? 'decision-table'),
			'decisionTables' => $this->findMany(schema: self::DECISION_TABLE_SCHEMA, filters: ['ruleSetId' => $slug]),
			'conditionRules' => $this->findMany(schema: self::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $slug]),
		];

		$this->cacheManager->set($slug, $bundle, $version);
		return $bundle;
	}//end loadBundle()

	/**
	 * Persist a RuleExecutionLog audit record.
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 * @param string $version The evaluated version.
	 * @param array<string,mixed> $payload The raw input payload.
	 * @param array<string,mixed> $result The evaluation result.
	 * @param array<int,mixed> $triggeredRules Triggered rule identifiers.
	 * @param int $durationMs Execution duration.
	 * @param array<int,string> $errors Any errors.
	 * @param bool $maskPii Whether to mask PII in the logged input.
	 *
	 * @return void
	 */
	private function persistLog(
		string $ruleSetSlug,
		string $version,
		array $payload,
		array $result,
		array $triggeredRules,
		int $durationMs,
		array $errors,
		bool $maskPii,
	): void {
		$user = $this->userSession->getUser();
		$userId = '';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$loggedInput = $payload;
		if ($maskPii === true) {
			$loggedInput = $this->maskPii(payload: $payload);
		}

		$log = [
			'ruleSetId' => $ruleSetSlug,
			'ruleSetVersion' => $version,
			'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
			'triggerContext' => 'api',
			'inputPayload' => $loggedInput,
			'outputResult' => $result,
			'triggeredRules' => array_map('strval', $triggeredRules),
			'executionDurationMs' => $durationMs,
			'errors' => $errors,
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
				['ruleSet' => $ruleSetSlug, 'exception' => $e->getMessage()]
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
	 * Find objects by schema + filters in the shared register.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 * @param int|null $limit Optional row limit.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findMany(string $schema, array $filters, ?int $limit = null): array {
		// Authorization-aware resolution (harden-rules-authz-and-audit-parity,
		// M1): resolve through searchObjectsBySlug (which applies the schema's
		// RBAC) rather than a raw findAll. `buildiq` is a SYSTEM-WIDE register
		// (not org-scoped) — mirror ListAppsHandler and pass _multitenancy:false
		// so cross-org callers still resolve it (a true org filter would make
		// registerMapper->find() throw and break evaluation). Note: for a
		// read-open rule-set schema this does not isolate reads per owner/org;
		// write operations remain admin-gated at the schema.
		try {
			$results = $this->objectService->searchObjectsBySlug(
				self::REGISTER_SLUG,
				$schema,
				$filters,
				_rbac: true,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: rule-engine searchObjects failed',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($results) === false) {
			return [];
		}

		$normalised = [];
		foreach ($results as $row) {
			$normalised[] = $this->normalise(object: $row);
		}

		// The slug variant has no server-side limit param; apply the caller's
		// cap (used by findOne) client-side.
		if ($limit !== null && count($normalised) > $limit) {
			$normalised = array_slice($normalised, 0, $limit);
		}

		return $normalised;
	}//end findMany()

	/**
	 * Coerce an OR result entry to a plain array.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 */
	private function normalise(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
			if (is_array($inner) === true) {
				return $inner;
			}
		}

		return [];
	}//end normalise()
}//end class
