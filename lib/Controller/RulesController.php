<?php

/**
 * OpenBuild RulesController
 *
 * REST surface for the business-rules engine (spec business-rules-engine
 * REQ-BRE-006 / REQ-BRE-004). Three endpoints:
 *
 *   - POST /api/rules/{ruleSetSlug}/evaluate   — synchronous evaluation (+ dry-run, version pin)
 *   - GET  /api/rules/{ruleSetSlug}/schema     — RuleSet metadata + active version for UI binding
 *   - POST /api/rules/{ruleSetSlug}/test-all   — run every TestCase for the RuleSet
 *
 * All endpoints carry `#[NoAdminRequired]` per ADR-005: any authenticated user
 * may evaluate a RuleSet. Resolution runs through OpenRegister
 * `searchObjectsBySlug`, which applies the schema's RBAC. IMPORTANT: `openbuild`
 * is a system-wide register (not org-scoped), so this is NOT per-owner or
 * per-organisation read isolation — with a read-open rule-set schema, any
 * authenticated caller can resolve a RuleSet by slug; write operations stay
 * admin-gated at the schema. (No "foreign slug → 404 / no IDOR" guarantee.) The endpoints are
 * NOT public; an unauthenticated request is rejected by the NC middleware before
 * reaching the controller. No secrets are returned; errors are uniform envelopes
 * with no stack traces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenBuild\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/business-rules-engine/tasks.md#9.1
 * @spec openspec/changes/business-rules-engine/tasks.md#9.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Controller;

use OCA\OpenBuild\AppInfo\Application;
use OCA\OpenBuild\Service\RuleEngineService;
use OCA\OpenBuild\Service\RuleSetVersioningService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller serving the rule-evaluation API.
 */
class RulesController extends Controller
{

    /**
     * Maximum evaluate payload size in bytes (DoS hardening, harden-xss-dos-csrf).
     *
     * The payload is logged verbatim into a RuleExecutionLog, so an unbounded
     * body is an unbounded DB write repeatable up to the rate-limit ceiling.
     * 64 KiB is generous for any legitimate rule input.
     */
    private const MAX_PAYLOAD_BYTES = 65536;

    /**
     * Constructor.
     *
     * @param IRequest                 $request           The current HTTP request.
     * @param LoggerInterface          $logger            PSR logger.
     * @param RuleEngineService        $ruleEngine        The rule-evaluation orchestrator.
     * @param RuleSetVersioningService $versioningService Test-gate runner for test-all.
     * @param ObjectService            $objectService     OpenRegister object service.
     * @param IUserSession             $userSession       Current user session.
     *
     * @return void
     */
    public function __construct(
        IRequest $request,
        private readonly LoggerInterface $logger,
        private readonly RuleEngineService $ruleEngine,
        private readonly RuleSetVersioningService $versioningService,
        private readonly ObjectService $objectService,
        private readonly IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);

    }//end __construct()

    /**
     * Synchronously evaluate a RuleSet against a payload.
     *
     * @param string $ruleSetSlug The RuleSet slug.
     *
     * @return JSONResponse 200 with the result, 404 on miss, 408 on timeout, 422 on bad input.
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#9.1
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function evaluate(string $ruleSetSlug): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
        }

        $params  = $this->request->getParams();
        $payload = ($params['payload'] ?? []);
        if (is_array($payload) === false) {
            return $this->error(code: 'invalid_payload', detail: 'payload must be an object', status: Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        // DoS guard: reject an oversized payload before evaluation or logging.
        $encoded = json_encode($payload);
        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return $this->error(
                code: 'payload_too_large',
                detail: 'payload exceeds the maximum size of '.self::MAX_PAYLOAD_BYTES.' bytes',
                status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE
            );
        }

        $dryRun  = (bool) ($params['dryRun'] ?? false);
        $version = null;
        if (isset($params['version']) === true) {
            $version = (string) $params['version'];
        }

        try {
            $outcome = $this->ruleEngine->evaluate(
                ruleSetSlug: $ruleSetSlug,
                payload: $payload,
                version: $version,
                dryRun: $dryRun
            );
        } catch (Throwable $e) {
            if ($e->getCode() === 404) {
                return $this->error(code: 'not_found', detail: 'RuleSet '.$ruleSetSlug.' not found', status: Http::STATUS_NOT_FOUND);
            }

            $this->logger->error(
                'OpenBuild: rule evaluation failed for '.$ruleSetSlug,
                ['exception' => $e->getMessage()]
            );
            return $this->error(code: 'evaluation_failed', detail: 'Rule evaluation failed', status: Http::STATUS_UNPROCESSABLE_ENTITY);
        }//end try

        $hasTimeout = false;
        foreach ($outcome['fouten'] as $fout) {
            if (str_contains($fout, 'timeout') === true) {
                $hasTimeout = true;
                break;
            }
        }

        $status = Http::STATUS_OK;
        if ($hasTimeout === true) {
            $status = Http::STATUS_REQUEST_TIMEOUT;
        }

        return new JSONResponse(data: $outcome, statusCode: $status);

    }//end evaluate()

    /**
     * Return a RuleSet's metadata + active version for UI binding.
     *
     * @param string $ruleSetSlug The RuleSet slug.
     *
     * @return JSONResponse 200 with the schema metadata, or 404.
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#9.1
     */
    #[NoAdminRequired]
    public function schema(string $ruleSetSlug): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
        }

        $ruleSet = $this->findRuleSet(slug: $ruleSetSlug);
        if ($ruleSet === null) {
            return $this->error(code: 'not_found', detail: 'RuleSet '.$ruleSetSlug.' not found', status: Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(
            data: [
                'slug'     => (string) ($ruleSet['slug'] ?? $ruleSetSlug),
                'naam'     => (string) ($ruleSet['naam'] ?? ''),
                'versie'   => (string) ($ruleSet['versie'] ?? ''),
                'status'   => (string) ($ruleSet['status'] ?? ''),
                'ruleType' => (string) ($ruleSet['ruleType'] ?? ''),
            ],
            statusCode: Http::STATUS_OK
        );

    }//end schema()

    /**
     * Run all TestCases for a RuleSet and return pass/fail per case.
     *
     * @param string $ruleSetSlug The RuleSet slug.
     *
     * @return JSONResponse 200 with the test summary, or 404.
     *
     * @spec openspec/changes/business-rules-engine/tasks.md#9.1
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 20, period: 60)]
    public function testAll(string $ruleSetSlug): JSONResponse
    {
        if ($this->userSession->getUser() === null) {
            return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
        }

        $ruleSet = $this->findRuleSet(slug: $ruleSetSlug);
        if ($ruleSet === null) {
            return $this->error(code: 'not_found', detail: 'RuleSet '.$ruleSetSlug.' not found', status: Http::STATUS_NOT_FOUND);
        }

        $testCases = $this->findTestCases(slug: $ruleSetSlug);
        try {
            $failures = $this->versioningService->runTestGate($ruleSetSlug, $testCases);
        } catch (Throwable $e) {
            $this->logger->error(
                'OpenBuild: test-all failed for '.$ruleSetSlug,
                ['exception' => $e->getMessage()]
            );
            return $this->error(code: 'test_run_failed', detail: 'Test run failed', status: Http::STATUS_UNPROCESSABLE_ENTITY);
        }

        $total  = count($testCases);
        $failed = count($failures);

        return new JSONResponse(
            data: [
                'total'    => $total,
                'passed'   => ($total - $failed),
                'failed'   => $failed,
                'failures' => $failures,
            ],
            statusCode: Http::STATUS_OK
        );

    }//end testAll()

    /**
     * Resolve a RuleSet by slug under the caller's tenant scope.
     *
     * @param string $slug The RuleSet slug.
     *
     * @return array<string,mixed>|null
     */
    private function findRuleSet(string $slug): ?array
    {
        $rows = $this->query(schema: RuleEngineService::RULE_SET_SCHEMA, filters: ['slug' => $slug], limit: 1);
        if ($rows === []) {
            return null;
        }

        return $rows[0];

    }//end findRuleSet()

    /**
     * Resolve every TestCase for a RuleSet.
     *
     * @param string $slug The RuleSet slug.
     *
     * @return array<int,array<string,mixed>>
     */
    private function findTestCases(string $slug): array
    {
        return $this->query(schema: 'rule-test-case', filters: ['ruleSetId' => $slug], limit: null);

    }//end findTestCases()

    /**
     * Query the shared register for objects of a schema matching filters.
     *
     * @param string              $schema  The schema slug.
     * @param array<string,mixed> $filters Equality filters.
     * @param int|null            $limit   Optional row limit.
     *
     * @return array<int,array<string,mixed>>
     */
    private function query(string $schema, array $filters, ?int $limit): array
    {
        // Authorization-aware resolution (harden-rules-authz-and-audit-parity,
        // M1): resolve through searchObjectsBySlug (which applies the schema's
        // RBAC) rather than a raw findAll. `openbuild` is a SYSTEM-WIDE register
        // (not org-scoped) — mirror ListAppsHandler and pass _multitenancy:false
        // so cross-org callers still resolve it (a true org filter would throw
        // and break resolution).
        try {
            $results = $this->objectService->searchObjectsBySlug(
                RuleEngineService::REGISTER_SLUG,
                $schema,
                $filters,
                _rbac: true,
                _multitenancy: false
            );
        } catch (Throwable $e) {
            $this->logger->warning(
                'OpenBuild: RulesController query failed',
                ['schema' => $schema, 'exception' => $e->getMessage()]
            );
            return [];
        }

        if (is_array($results) === true && $limit !== null && count($results) > $limit) {
            $results = array_slice($results, 0, $limit);
        }

        if (is_array($results) === false) {
            return [];
        }

        $out = [];
        foreach ($results as $row) {
            if (is_array($row) === true) {
                $out[] = $row;
                continue;
            }

            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $serialised = $row->jsonSerialize();
                if (is_array($serialised) === true) {
                    $out[] = $serialised;
                }
            }
        }

        return $out;

    }//end query()

    /**
     * Build a uniform error envelope.
     *
     * @param string      $code   Error code.
     * @param string|null $detail Optional detail.
     * @param int         $status HTTP status code.
     *
     * @return JSONResponse
     */
    private function error(string $code, ?string $detail, int $status): JSONResponse
    {
        $body = ['error' => $code];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }

        return new JSONResponse(data: $body, statusCode: $status);

    }//end error()
}//end class
