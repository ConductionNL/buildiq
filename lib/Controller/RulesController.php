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
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#9.1
 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#9.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\CallerContext;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\RuleObjectReader;
use OCA\Buildiq\Service\RuleStorage;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
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
	 * @param RuleStorage $storage The rule object reader, stored decision tables and rule set versioning.
	 * @param CallerContext $caller The caller's session, group memberships and the shared permission matcher.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly LoggerInterface $logger,
		private readonly RuleEngineService $ruleEngine,
		private readonly RuleStorage $storage,
		private readonly CallerContext $caller,
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
	 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#9.1
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
		if ($this->caller->userSession->getUser() === null) {
			return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		$params = $this->request->getParams();
		$refusal = $this->payloadRefusal(payload: ($params['payload'] ?? []));
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$outcome = $this->engineOutcome(ruleSetSlug: $ruleSetSlug, params: $params);
		} catch (Throwable $e) {
			return $this->evaluationFailure(ruleSetSlug: $ruleSetSlug, error: $e);
		}

		return new JSONResponse(data: $outcome, statusCode: $this->outcomeStatus(outcome: $outcome));
	}//end evaluate()

	/**
	 * Refuse a payload that is not an object or exceeds the maximum size.
	 *
	 * The size check is the DoS guard: an oversized payload is rejected before
	 * evaluation or logging.
	 *
	 * @param mixed $payload The request's `payload` parameter.
	 *
	 * @return JSONResponse|null The 422/413 refusal, or null when the payload is acceptable.
	 */
	private function payloadRefusal(mixed $payload): ?JSONResponse {
		if (is_array($payload) === false) {
			return $this->error(code: 'invalid_payload', detail: 'payload must be an object', status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		$encoded = json_encode($payload);
		if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
			return $this->error(
				code: 'payload_too_large',
				detail: 'payload exceeds the maximum size of ' . self::MAX_PAYLOAD_BYTES . ' bytes',
				status: Http::STATUS_REQUEST_ENTITY_TOO_LARGE
			);
		}

		return null;
	}//end payloadRefusal()

	/**
	 * Run the engine for an accepted request: dry-run flag, pinned version and
	 * preview mode come from the request, PII is always masked.
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 * @param array<string,mixed> $params The request parameters, payload already accepted.
	 *
	 * @return array<string,mixed> The engine outcome.
	 *
	 * @throws Throwable When the engine fails; a 404 code means the RuleSet is missing.
	 */
	private function engineOutcome(string $ruleSetSlug, array $params): array {
		$dryRun = (bool)($params['dryRun'] ?? false);
		$version = null;
		if (isset($params['version']) === true) {
			$version = (string)$params['version'];
		}

		return $this->ruleEngine->evaluate(
			ruleSetSlug: $ruleSetSlug,
			payload: (array)($params['payload'] ?? []),
			version: $version,
			dryRun: $dryRun,
			maskPii: true,
			preview: (($params['mode'] ?? null) === 'preview')
		);
	}//end engineOutcome()

	/**
	 * Answer an engine failure: 404 for a missing RuleSet, otherwise log and 422.
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 * @param Throwable $error The engine failure.
	 *
	 * @return JSONResponse The uniform error envelope.
	 */
	private function evaluationFailure(string $ruleSetSlug, Throwable $error): JSONResponse {
		if ($error->getCode() === 404) {
			return $this->error(code: 'not_found', detail: 'RuleSet ' . $ruleSetSlug . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		$this->logger->error(
			'Buildiq: rule evaluation failed for ' . $ruleSetSlug,
			['exception' => $error->getMessage()]
		);
		return $this->error(code: 'evaluation_failed', detail: 'Rule evaluation failed', status: Http::STATUS_UNPROCESSABLE_ENTITY);
	}//end evaluationFailure()

	/**
	 * The HTTP status for an engine outcome: 408 when any error reports a timeout.
	 *
	 * @param array<string,mixed> $outcome The engine outcome.
	 *
	 * @return int 200 or 408.
	 */
	private function outcomeStatus(array $outcome): int {
		foreach ($outcome['errors'] as $fout) {
			if (str_contains($fout, 'timeout') === true) {
				return Http::STATUS_REQUEST_TIMEOUT;
			}
		}

		return Http::STATUS_OK;
	}//end outcomeStatus()

	/**
	 * Return a RuleSet's metadata + active version for UI binding.
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 *
	 * @return JSONResponse 200 with the schema metadata, or 404.
	 *
	 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#9.1
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
		if ($this->caller->userSession->getUser() === null) {
			return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		$ruleSet = $this->findRuleSet(slug: $ruleSetSlug);
		if ($ruleSet === null) {
			return $this->error(code: 'not_found', detail: 'RuleSet ' . $ruleSetSlug . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		if (is_string($ruleSet['slug'] ?? null) === false || $ruleSet['slug'] === '') {
			$ruleSet['slug'] = $ruleSetSlug;
		}

		$columns = $this->decisionColumns(ruleSet: $ruleSet);

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
	 * Write a rule set's decision table into OpenRegister's decision-tables register.
	 *
	 * The table editor sends buildiq's authoring shape (input columns with an
	 * expression path, rules as a condition map and a value map); the table is
	 * stored in the shape OpenRegister evaluates, under the key of the rule set
	 * the caller resolved. OpenRegister refuses a table it cannot run, and that
	 * refusal comes back as 422 with its reason.
	 *
	 * Admin-only, as writing a decision table was in buildiq's own register: the
	 * guard lives in the method body, the posture SettingsController::update()
	 * uses, because `#[NoAdminRequired]` alone would open the write to any
	 * signed-in user.
	 *
	 * @param string $ruleSetSlug The RuleSet slug.
	 *
	 * @return JSONResponse 200 with the stored table, 401, 403, 404 or 422.
	 *
	 * @spec openspec/changes/decision-tables-live-in-openregister/specs/business-rules-engine/spec.md
	 */
	#[NoAdminRequired]
	public function saveDecisionTable(string $ruleSetSlug): JSONResponse {
		$user = $this->caller->userSession->getUser();
		if ($user === null) {
			return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caller->groupManager->isInGroup($user->getUID(), 'admin') === false) {
			return $this->error(code: 'forbidden', detail: 'Only an administrator can change a decision table.', status: Http::STATUS_FORBIDDEN);
		}

		$ruleSet = $this->findRuleSet(slug: $ruleSetSlug);
		if ($ruleSet === null) {
			return $this->error(code: 'not_found', detail: 'RuleSet ' . $ruleSetSlug . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		$table = [
			'hitPolicy' => (string)($this->request->getParam('hitPolicy', 'first') ?? 'first'),
			'inputColumns' => (array)($this->request->getParam('inputColumns', []) ?? []),
			'outputColumns' => (array)($this->request->getParam('outputColumns', []) ?? []),
			'rules' => (array)($this->request->getParam('rules', []) ?? []),
		];

		try {
			$stored = $this->storage->tables->save(ruleSet: $ruleSet, table: $table);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: decision table for ' . $ruleSetSlug . ' was refused',
				['exception' => $e->getMessage()]
			);
			return $this->error(code: 'invalid_decision_table', detail: $e->getMessage(), status: Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return new JSONResponse(data: ['key' => $stored['key'], 'table' => $stored], statusCode: Http::STATUS_OK);
	}//end saveDecisionTable()

	/**
	 * The input and output columns of a rule set's decision table, for a
	 * calculated form field or an eligibility check to bind to (REQ-BQLV-002).
	 *
	 * A condition-action rule set has no declared columns and answers two
	 * empty lists.
	 *
	 * @param array<string,mixed> $ruleSet The resolved RuleSet.
	 *
	 * @return array{inputs:list<array{name:string,path:string,type:string}>,outputs:list<array{name:string,type:string}>}
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002
	 */
	private function decisionColumns(array $ruleSet): array {
		$inputs = [];
		$outputs = [];
		$tables = $this->storage->tables->forRuleSet(ruleSet: $ruleSet);
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
	 * @spec openspec/changes/archive/2026-06-14-business-rules-engine/tasks.md#9.1
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
		if ($this->caller->userSession->getUser() === null) {
			return $this->error(code: 'unauthenticated', detail: null, status: Http::STATUS_UNAUTHORIZED);
		}

		$ruleSet = $this->findRuleSet(slug: $ruleSetSlug);
		if ($ruleSet === null) {
			return $this->error(code: 'not_found', detail: 'RuleSet ' . $ruleSetSlug . ' not found', status: Http::STATUS_NOT_FOUND);
		}

		$testCases = $this->findTestCases(slug: $ruleSetSlug);
		try {
			$failures = $this->storage->versioningService->runTestGate($ruleSetSlug, $testCases);
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
		return $this->storage->reader->findScoped(schema: $schema, filters: $filters, limit: $limit);
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
