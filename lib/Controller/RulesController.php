<?php

/**
 * Buildiq RulesController
 *
 * REST surface for the business-rules engine (spec business-rules-engine
 * REQ-BRE-006 / REQ-BRE-004). Three endpoints:
 *
 *   - POST /api/rules/{ruleSetSlug}/evaluate   — synchronous evaluation (+ dry-run, version pin)
 *   - GET  /api/rules/{ruleSetSlug}/schema     — RuleSet metadata + active version for UI binding
 *   - POST /api/rules/{ruleSetSlug}/test-all   — run every TestCase for the RuleSet
 *
 * All endpoints carry `#[NoAdminRequired]` per ADR-005: any authenticated user
 * may evaluate a RuleSet of their own organisation. Every read goes through
 * {@see RuleObjectReader}, which applies the schema's RBAC and the caller's
 * organisation (REQ-BRE-007): a RuleSet held by another organisation resolves
 * to a 404, not a 403, so its existence does not leak. The `buildiq` register
 * itself is system-wide, so only its lookup runs without the organisation
 * filter; the object reads never do. This is organisation scope, not
 * per-owner isolation, and write operations stay admin-gated at the schema. The endpoints are
 * NOT public; an unauthenticated request is rejected by the NC middleware before
 * reaching the controller. No secrets are returned; errors are uniform envelopes
 * with no stack traces.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
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

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\RuleObjectReader;
use OCA\Buildiq\Service\RuleSetVersioningService;
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
class RulesController extends Controller {

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
	 * @param IRequest $request The current HTTP request.
	 * @param LoggerInterface $logger PSR logger.
	 * @param RuleEngineService $ruleEngine The rule-evaluation orchestrator.
	 * @param RuleSetVersioningService $versioningService Test-gate runner for test-all.
	 * @param RuleObjectReader $reader Organisation-scoped reads of rule-engine objects (REQ-BRE-007).
	 * @param IUserSession $userSession Current user session.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly RuleEngineService $ruleEngine,
		private readonly RuleSetVersioningService $versioningService,
		private readonly RuleObjectReader $reader,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * Synchronously evaluate a RuleSet against a payload.
	 *
	 * `mode: preview` is the live evaluation a form runs while it is filled
	 * in: the same authentication, RBAC, rate limit and size guard, but no
	 * execution log entry and no side-effecting action (REQ-BQLV-004).
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 *
	 * @return JSONResponse 200 with the result, 404 on miss, 408 on timeout, 422 on bad input.
	 *
	 * @spec openspec/changes/business-rules-engine/tasks.md#9.1
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-live-evaluation-leaves-no-log-trail-req-bqlv-004
	 *
	 * @no-admin-idor-exempt Authorization is delegated to OpenRegister: every read here
	 *   goes through RuleObjectReader::find(), which searches with `_rbac: true` and
	 *   `_multitenancy: true`, so the schema's RBAC and the caller's organisation both
	 *   apply and a rule set held by another organisation resolves to 404 (REQ-BRE-007).
	 *   Only the lookup of the system-wide `buildiq` register runs unfiltered.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function evaluate(string $ruleSetSlug): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		$params = $this->request->getParams();
		$payload = ($params['payload'] ?? []);
		if (is_array($payload) === false) {
			return $this->error(code: 'invalid_payload', detail: 'payload must be an object', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		// DoS guard: reject an oversized payload before evaluation or logging.
		$encoded = json_encode($payload);
		if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
			return $this->error(
				code: 'payload_too_large',
				detail: 'payload exceeds the maximum size of ' . self::MAX_PAYLOAD_BYTES . ' bytes',
				status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		}

		$dryRun = (bool)($params['dryRun'] ?? false);
		$version = null;
		if (isset($params['version']) === true) {
			$version = (string)$params['version'];
		}

		try {
			$outcome = $this->ruleEngine->evaluate(
				ruleSetSlug: $ruleSetSlug,
				payload: $payload,
				version: $version,
				dryRun: $dryRun,
				maskPii: true,
				preview: (($params['mode'] ?? null) === 'preview')
			);
		} catch (Throwable $e) {
			if ($e->getCode() === 404) {
				return $this->error(code: 'not_found', detail: 'RuleSet ' . $ruleSetSlug . ' not found', status: Http::STATUS_NOT_FOUND);
			}

			$this->logger->error(
				'Buildiq: rule evaluation failed for ' . $ruleSetSlug,
				['exception' => $e->getMessage()]
			);
			return $this->error(code: 'evaluation_failed', detail: 'Rule evaluation failed', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}//end try

		$hasTimeout = false;
		foreach ($outcome['errors'] as $fout) {
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
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
	 *
	 * @no-admin-idor-exempt Authorization is delegated to OpenRegister: every read here
	 *   goes through RuleObjectReader::find(), which searches with `_rbac: true` and
	 *   `_multitenancy: true`, so the schema's RBAC and the caller's organisation both
	 *   apply and a rule set held by another organisation resolves to 404 (REQ-BRE-007).
	 *   Only the lookup of the system-wide `buildiq` register runs unfiltered.
	 */
	#[NoAdminRequired]
	public function schema(string $ruleSetSlug): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		$ruleSet = $this->findRuleSet(slug: $ruleSetSlug);
		if ($ruleSet === null) {
			return $this->error(code: 'not_found', detail: 'RuleSet ' . $ruleSetSlug . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		$columns = $this->decisionColumns(slug: (string)($ruleSet['slug'] ?? $ruleSetSlug));

		return new JSONResponse(
			data: [
				'slug' => (string)($ruleSet['slug'] ?? $ruleSetSlug),
				'name' => (string)($ruleSet['name'] ?? ''),
				'version' => (string)($ruleSet['version'] ?? ''),
				'status' => (string)($ruleSet['status'] ?? ''),
				'ruleType' => (string)($ruleSet['ruleType'] ?? ''),
				'inputs' => $columns['inputs'],
				'outputs' => $columns['outputs'],
			],
			statusCode: Http::STATUS_OK
		);

	}//end schema()

	/**
	 * The input and output columns of a rule set's decision table, for a
	 * calculated form field or an eligibility check to bind to (REQ-BQLV-002).
	 *
	 * A condition-action rule set has no declared columns and answers two
	 * empty lists.
	 *
	 * @param string $slug The RuleSet slug.
	 *
	 * @return array{inputs:list<array{name:string,path:string,type:string}>,outputs:list<array{name:string,type:string}>}
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
	 */
	private function decisionColumns(string $slug): array {
		$inputs = [];
		$outputs = [];
		$tables = $this->query(schema: RuleEngineService::DECISION_TABLE_SCHEMA, filters: ['ruleSetId' => $slug], limit: 1);
		$table = ($tables[0] ?? []);

		foreach ((array)($table['inputColumns'] ?? []) as $column) {
			if (is_array($column) === true && is_string($column['name'] ?? null) === true) {
				$inputs[] = [
					'name' => $column['name'],
					'path' => (string)($column['expressionPath'] ?? $column['name']),
					'type' => (string)($column['type'] ?? ''),
				];
			}
		}

		foreach ((array)($table['outputColumns'] ?? []) as $column) {
			if (is_array($column) === true && is_string($column['name'] ?? null) === true) {
				$outputs[] = ['name' => $column['name'], 'type' => (string)($column['type'] ?? '')];
			}
		}

		return ['inputs' => $inputs, 'outputs' => $outputs];
	}//end decisionColumns()

	/**
	 * Run all TestCases for a RuleSet and return pass/fail per case.
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 *
	 * @return JSONResponse 200 with the test summary, or 404.
	 *
	 * @spec openspec/changes/business-rules-engine/tasks.md#9.1
	 *
	 * @no-admin-idor-exempt Authorization is delegated to OpenRegister: every read here
	 *   goes through RuleObjectReader::find(), which searches with `_rbac: true` and
	 *   `_multitenancy: true`, so the schema's RBAC and the caller's organisation both
	 *   apply and a rule set held by another organisation resolves to 404 (REQ-BRE-007).
	 *   Only the lookup of the system-wide `buildiq` register runs unfiltered.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 20, period: 60)]
	public function testAll(string $ruleSetSlug): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		$ruleSet = $this->findRuleSet(slug: $ruleSetSlug);
		if ($ruleSet === null) {
			return $this->error(code: 'not_found', detail: 'RuleSet ' . $ruleSetSlug . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		$testCases = $this->findTestCases(slug: $ruleSetSlug);
		try {
			$failures = $this->versioningService->runTestGate($ruleSetSlug, $testCases);
		} catch (Throwable $e) {
			$this->logger->error(
				'Buildiq: test-all failed for ' . $ruleSetSlug,
				['exception' => $e->getMessage()]
			);
			return $this->error(code: 'test_run_failed', detail: 'Test run failed', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$total = count($testCases);
		$failed = count($failures);

		return new JSONResponse(
			data: [
				'total' => $total,
				'passed' => ($total - $failed),
				'failed' => $failed,
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
	private function findRuleSet(string $slug): ?array {
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
	private function findTestCases(string $slug): array {
		return $this->query(schema: 'rule-test-case', filters: ['ruleSetId' => $slug], limit: null);
	}//end findTestCases()

	/**
	 * Query the shared register for objects of a schema matching filters,
	 * scoped to the caller's organisation and the schema's RBAC (REQ-BRE-007).
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 * @param int|null $limit Optional row limit.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	private function query(string $schema, array $filters, ?int $limit): array {
		return $this->reader->findScoped(schema: $schema, filters: $filters, limit: $limit);
	}//end query()

	/**
	 * Build a uniform error envelope.
	 *
	 * @param string $code Error code.
	 * @param string|null $detail Optional detail.
	 * @param int $status HTTP status code.
	 *
	 * @return JSONResponse
	 */
	private function error(string $code, ?string $detail, int $status): JSONResponse {
		$body = ['error' => $code];
		if ($detail !== null) {
			$body['detail'] = $detail;
		}

		return new JSONResponse(data: $body, statusCode: $status);
	}//end error()
}//end class
