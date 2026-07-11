<?php

/**
 * OpenBuild RuleEngineService
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
 * @spec openspec/changes/business-rules-engine/tasks.md#5.1
 * @spec openspec/changes/business-rules-engine/tasks.md#5.2
 * @spec openspec/changes/business-rules-engine/tasks.md#5.3
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runtime rule-evaluation orchestrator.
 */
class RuleEngineService
{

    /**
     * Shared OpenBuild register slug.
     */
    public const REGISTER_SLUG = 'openbuild';

    /**
     * Schema slugs for the rules-engine objects.
     */
    public const RULE_SET_SCHEMA       = 'rule-set';
    public const DECISION_TABLE_SCHEMA = 'decision-table';
    public const CONDITION_RULE_SCHEMA = 'condition-action-rule';
    public const EXECUTION_LOG_SCHEMA  = 'rule-execution-log';

    /**
     * Soft evaluation timeout in milliseconds (design.md Decision 10).
     */
    public const TIMEOUT_MS = 500;

    /**
     * Fields masked in RuleExecutionLog input when masking is enabled.
     *
     * @var array<int,string>
     */
    private const PII_FIELDS = ['bsn', 'ssn', 'email', 'phone', 'iban'];

    /**
     * Constructor.
     *
     * @param ObjectService           $objectService     OpenRegister object service.
     * @param DecisionTableEvaluator  $decisionEvaluator DMN table evaluator.
     * @param ConditionActionExecutor $conditionExecutor Condition-action chain executor.
     * @param RuleSetCacheManager     $cacheManager      Hot-reload bundle cache.
     * @param IUserSession            $userSession       Current user session.
     * @param LoggerInterface         $logger            PSR logger.
     * @param RuleActionDispatcher    $actionDispatcher  Wired side-effect dispatcher (spec REQ-AUTD-010 —
     *                                                   fixes the verified defect where side-effecting
     *                                                   actions silently no-op in wet runs because no
     *                                                   dispatcher was ever passed to the executor).
     *
     * @return void
     */
    public function __construct(
        private readonly ObjectService $objectService,
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
     * @param string              $ruleSetSlug The RuleSet slug.
     * @param array<string,mixed> $payload     The input payload.
     * @param string|null         $version     Optional pinned version (default: active).
     * @param bool                $dryRun      When true, side-effecting actions are suppressed.
     * @param bool                $maskPii     When true, mask PII fields in the audit log.
     *
     * @return array{result:array<string,mixed>,geraaktRegels:array<int,mixed>,executieDuur:int,fouten:array<int,string>}
     *
     * @throws RuntimeException When the RuleSet is not found / not owned, or on timeout.
     */
    public function evaluate(
        string $ruleSetSlug,
        array $payload,
        ?string $version=null,
        bool $dryRun=false,
        bool $maskPii=true
    ): array {
        $startedAt = microtime(true);

        $bundle = $this->loadBundle(slug: $ruleSetSlug, version: $version);
        if ($bundle === null) {
            throw new RuntimeException('RuleSet "'.$ruleSetSlug.'" not found.', 404);
        }

        $errors         = [];
        $result         = [];
        $triggeredRules = [];

        try {
            $ruleType = (string) ($bundle['ruleSet']['ruleType'] ?? 'decision-table');

            if ($ruleType === 'decision-table' && $bundle['decisionTables'] !== []) {
                $table   = $bundle['decisionTables'][0];
                $outcome = $this->decisionEvaluator->evaluate($table, $payload);
                $result  = $outcome['outputColumns'];
                if ($outcome['triggeredRuleId'] !== null) {
                    $triggeredRules[] = $outcome['triggeredRuleId'];
                }
            } else if ($ruleType === 'condition-action') {
                // Pass the wired dispatcher (spec REQ-AUTD-010) — the executor
                // itself suppresses dispatch when $dryRun is true, so it is
                // always safe to hand the real dispatcher through here.
                $outcome        = $this->conditionExecutor->execute($bundle['conditionRules'], $payload, $dryRun, $this->actionDispatcher);
                $result         = $outcome['result'];
                $errors         = $outcome['errors'];
                $triggeredRules = array_column($outcome['triggeredRules'], 'name');
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }//end try

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($durationMs > self::TIMEOUT_MS) {
            $errors[] = 'Evaluation exceeded the '.self::TIMEOUT_MS.'ms soft timeout ('.$durationMs.'ms).';
        }

        $this->persistLog(
            ruleSetSlug: $ruleSetSlug,
            version: (string) ($bundle['ruleSet']['versie'] ?? ''),
            payload: $payload,
            result: $result,
            triggeredRules: $triggeredRules,
            durationMs: $durationMs,
            errors: $errors,
            maskPii: $maskPii
        );

        return [
            'result'        => $result,
            'geraaktRegels' => $triggeredRules,
            'executieDuur'  => $durationMs,
            'fouten'        => $errors,
        ];

    }//end evaluate()

    /**
     * Load (and cache) a RuleSet bundle: the RuleSet plus its tables/rules.
     *
     * Enforces multi-tenant isolation implicitly — the ObjectService query runs
     * under the current user's tenant scope, so a slug owned by another tenant
     * resolves to an empty result (treated as not found).
     *
     * @param string      $slug    The RuleSet slug.
     * @param string|null $version Optional pinned version.
     *
     * @return array{ruleSet:array<string,mixed>,decisionTables:array<int,mixed>,conditionRules:array<int,mixed>}|null
     */
    private function loadBundle(string $slug, ?string $version): ?array
    {
        $cached = $this->cacheManager->get($slug, $version);
        if ($cached !== null) {
            return $cached;
        }

        $ruleSet = $this->findOne(schema: self::RULE_SET_SCHEMA, filters: ['slug' => $slug]);
        if ($ruleSet === null) {
            return null;
        }

        if ($version !== null && (string) ($ruleSet['versie'] ?? '') !== $version) {
            // Pinned version requested but the active row is a different one.
            // Without version-history storage we only serve the active row, so
            // a mismatch is a not-found for the pinned request.
            return null;
        }

        $bundle = [
            'ruleSet'        => $ruleSet,
            'ruleType'       => (string) ($ruleSet['ruleType'] ?? 'decision-table'),
            'decisionTables' => $this->findMany(schema: self::DECISION_TABLE_SCHEMA, filters: ['ruleSetId' => $slug]),
            'conditionRules' => $this->findMany(schema: self::CONDITION_RULE_SCHEMA, filters: ['ruleSetId' => $slug]),
        ];

        $this->cacheManager->set($slug, $bundle, $version);
        return $bundle;

    }//end loadBundle()

    /**
     * Persist a RuleExecutionLog audit record.
     *
     * @param string              $ruleSetSlug    The RuleSet slug.
     * @param string              $version        The evaluated version.
     * @param array<string,mixed> $payload        The raw input payload.
     * @param array<string,mixed> $result         The evaluation result.
     * @param array<int,mixed>    $triggeredRules Triggered rule identifiers.
     * @param int                 $durationMs     Execution duration.
     * @param array<int,string>   $errors         Any errors.
     * @param bool                $maskPii        Whether to mask PII in the logged input.
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
        bool $maskPii
    ): void {
        $user   = $this->userSession->getUser();
        $userId = '';
        if ($user !== null) {
            $userId = $user->getUID();
        }

        $loggedInput = $payload;
        if ($maskPii === true) {
            $loggedInput = $this->maskPii(payload: $payload);
        }

        $log = [
            'ruleSetId'       => $ruleSetSlug,
            'ruleSetVersie'   => $version,
            'tijdstip'        => gmdate('Y-m-d\TH:i:s\Z'),
            'triggerContext'  => 'api',
            'inputPayload'    => $loggedInput,
            'outputResultaat' => $result,
            'geraaktRegels'   => array_map('strval', $triggeredRules),
            'executieDuurMs'  => $durationMs,
            'fouten'          => $errors,
            'userId'          => $userId,
            'archived'        => false,
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
                'OpenBuild: failed to persist RuleExecutionLog',
                ['ruleSet' => $ruleSetSlug, 'exception' => $e->getMessage()]
            );
        }

    }//end persistLog()

    /**
     * Recursively mask configured PII fields in a payload.
     *
     * @param array<string,mixed> $payload The payload to mask.
     *
     * @return array<string,mixed> A masked copy.
     */
    private function maskPii(array $payload): array
    {
        $masked = [];
        foreach ($payload as $key => $value) {
            if (is_array($value) === true) {
                $masked[$key] = $this->maskPii(payload: $value);
                continue;
            }

            if (in_array(strtolower((string) $key), self::PII_FIELDS, true) === true) {
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
     * @param string              $schema  The schema slug.
     * @param array<string,mixed> $filters Equality filters.
     *
     * @return array<string,mixed>|null
     */
    private function findOne(string $schema, array $filters): ?array
    {
        $rows = $this->findMany(schema: $schema, filters: $filters, limit: 1);
        if ($rows === []) {
            return null;
        }

        return $rows[0];

    }//end findOne()

    /**
     * Find objects by schema + filters in the shared register.
     *
     * @param string              $schema  The schema slug.
     * @param array<string,mixed> $filters Equality filters.
     * @param int|null            $limit   Optional row limit.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findMany(string $schema, array $filters, ?int $limit=null): array
    {
        $config = [
            'filters' => array_merge(['register' => self::REGISTER_SLUG, 'schema' => $schema], $filters),
        ];
        if ($limit !== null) {
            $config['limit'] = $limit;
        }

        try {
            $results = $this->objectService->findAll(config: $config);
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuild: rule-engine findAll failed',
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

        return $normalised;

    }//end findMany()

    /**
     * Coerce an OR result entry to a plain array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string,mixed>
     */
    private function normalise(mixed $object): array
    {
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
