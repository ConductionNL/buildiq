<?php

/**
 * Unit tests for RuleEngineService.
 *
 * Covers REQ-BRE-006 / REQ-BRE-007 / REQ-BRE-009: decision-table evaluation,
 * not-found handling, RuleExecutionLog persistence and PII masking. The
 * OpenRegister boundary is mocked; the evaluation algorithms are the real
 * DecisionTableEvaluator / ConditionActionExecutor.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\ConditionActionExecutor;
use OCA\Buildiq\Service\DecisionTableEvaluator;
use OCA\OpenRegister\Service\Dmn\DecisionTableEvaluator as SharedEvaluator;
use OCA\Buildiq\Service\ExpressionEvaluator;
use OCA\Buildiq\Service\RuleActionDispatcher;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\RuleObjectReader;
use OCA\Buildiq\Service\RuleSetCacheManager;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see RuleEngineService}.
 */
final class RuleEngineServiceTest extends TestCase {

	/**
	 * Numeric ids of the rule-engine schemas in the `buildiq` register.
	 *
	 * @var array<string,int>
	 */
	private const SCHEMA_IDS = [
		'rule-set' => 101,
		'decision-table' => 102,
		'condition-action-rule' => 103,
		'rule-execution-log' => 104,
		'rule-test-case' => 105,
	];

	/**
	 * Mock OpenRegister object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Mock cache manager (always a miss so OR is queried).
	 *
	 * @var RuleSetCacheManager&MockObject
	 */
	private RuleSetCacheManager&MockObject $cacheManager;

	/**
	 * Mock user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * The service under test.
	 *
	 * @var RuleEngineService
	 */
	private RuleEngineService $service;

	/**
	 * The real organisation-scoped reader, over the mocked object service.
	 *
	 * @var RuleObjectReader
	 */
	private RuleObjectReader $reader;

	/**
	 * Mock wired action dispatcher (spec REQ-AUTD-010).
	 *
	 * @var RuleActionDispatcher&MockObject
	 */
	private RuleActionDispatcher&MockObject $actionDispatcher;

	/**
	 * Wire the service with real evaluators and mocked boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->cacheManager = $this->createMock(RuleSetCacheManager::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$this->cacheManager->method('get')->willReturn(null);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->reader = new RuleObjectReader(
			$this->objectService,
			$this->registerMapper(),
			$this->schemaMapper(),
			$this->createMock(LoggerInterface::class),
		);

		$evaluator = new ExpressionEvaluator();
		$this->actionDispatcher = $this->createMock(RuleActionDispatcher::class);
		$this->service = new RuleEngineService(
			$this->objectService,
			new DecisionTableEvaluator($evaluator, $this->sharedEvaluator()),
			new ConditionActionExecutor($evaluator),
			$this->cacheManager,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->actionDispatcher,
			$this->reader,
		);

	}//end setUp()

	/**
	 * OpenRegister's shared evaluator, standing in for the real matcher.
	 *
	 * This suite is about RuleEngineService: resolution, scoping, caching, PII
	 * masking, recursion limits and dispatch. It reaches a decision table on
	 * the way, so it needs an evaluator that decides something, but WHICH rule
	 * matches is OpenRegister's contract and is proven there. The double picks
	 * the first rule, which is what the real evaluator does for the eligible
	 * payload these tests use.
	 *
	 * @return SharedEvaluator The double.
	 */
	private function sharedEvaluator(): SharedEvaluator {
		$shared = $this->createMock(SharedEvaluator::class);
		$shared->method('evaluate')->willReturnCallback(
			static function (array $decisionTable, array $inputs): array {
				return [
					'outputs' => [],
					'matchedRuleIds' => ['0'],
					'hitPolicy' => $decisionTable['hitPolicy'],
				];
			}
		);

		return $shared;

	}//end sharedEvaluator()

	/**
	 * Build the loan RuleSet + DecisionTable rows returned by findAll.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function loanFindAllResults(string $schema): array {
		if ($schema === 'rule-set') {
			return [['slug' => 'loan-eligibility', 'version' => '1.0.0', 'ruleType' => 'decision-table']];
		}

		if ($schema === 'decision-table') {
			return [
				[
					'ruleSetId' => 'loan-eligibility',
					'hitPolicy' => 'first',
					'inputColumns' => [
						['name' => 'applicantAge', 'expressionPath' => 'applicant.age'],
						['name' => 'monthlyIncome', 'expressionPath' => 'applicant.monthlyIncome'],
						['name' => 'creditScore', 'expressionPath' => 'applicant.creditScore'],
					],
					'outputColumns' => [['name' => 'decision', 'defaultValue' => 'deny']],
					'rules' => [
						[
							'conditions' => ['applicantAge' => '>=18', 'monthlyIncome' => '>=2000', 'creditScore' => '>=600'],
							'values' => ['decision' => 'approve'],
							'label' => 'approve',
						],
						['conditions' => [], 'values' => ['decision' => 'deny'], 'label' => 'deny'],
					],
				],
			];
		}

		return [];
	}//end loanFindAllResults()

	/**
	 * A valid loan payload yields an approve decision and logs the execution.
	 *
	 * @return void
	 */
	public function testEvaluateLoanApprove(): void {
		$this->stubScopedRows(
			function (string $registerSlug, string $schema, array $filters = []): array {
				return $this->loanFindAllResults($schema);
			}
		);

		// The audit log write happens once.
		$this->objectService->expects($this->once())->method('saveObject');

		$outcome = $this->service->evaluate(
			'loan-eligibility',
			['applicant' => ['age' => 30, 'monthlyIncome' => 3000, 'creditScore' => 700]]
		);

		$this->assertSame('approve', $outcome['result']['decision']);
		$this->assertContains('approve', $outcome['triggeredRules']);
		$this->assertSame([], $outcome['errors']);

	}//end testEvaluateLoanApprove()

	/**
	 * REQ-BQLV-004: a preview evaluation answers the same result and writes no
	 * rule execution log entry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-live-evaluation-leaves-no-log-trail-req-bqlv-004
	 */
	public function testPreviewWritesNoExecutionLog(): void {
		$this->stubScopedRows(
			function (string $registerSlug, string $schema, array $filters = []): array {
				return $this->loanFindAllResults($schema);
			}
		);

		$this->objectService->expects($this->never())->method('saveObject');

		$outcome = $this->service->evaluate(
			ruleSetSlug: 'loan-eligibility',
			payload: ['applicant' => ['age' => 30, 'monthlyIncome' => 3000, 'creditScore' => 700]],
			preview: true
		);

		$this->assertSame('approve', $outcome['result']['decision']);

	}//end testPreviewWritesNoExecutionLog()

	/**
	 * REQ-BQLV-004: a preview runs on every keystroke, so it never fires a
	 * rule's side-effecting action either.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-live-evaluation-leaves-no-log-trail-req-bqlv-004
	 */
	public function testPreviewDoesNotInvokeDispatcher(): void {
		$this->stubScopedRows(
			function (string $registerSlug, string $schema, array $filters = []): array {
				return $this->conditionActionFindAllResults($schema);
			}
		);

		$this->actionDispatcher->expects($this->never())->method('__invoke');
		$this->objectService->expects($this->never())->method('saveObject');

		$outcome = $this->service->evaluate(ruleSetSlug: 'escalate', payload: [], preview: true);

		$this->assertContains('always-notify', $outcome['triggeredRules'], 'sanity: rule fired');

	}//end testPreviewDoesNotInvokeDispatcher()

	/**
	 * An unknown RuleSet slug raises a 404-coded exception.
	 *
	 * @return void
	 */
	public function testEvaluateNotFound(): void {
		$this->stubScopedRows(static fn (): array => []);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);
		$this->service->evaluate('does-not-exist', []);

	}//end testEvaluateNotFound()

	/**
	 * PII fields are masked in the persisted RuleExecutionLog input.
	 *
	 * @return void
	 */
	public function testPiiMasking(): void {
		$this->stubScopedRows(
			function (string $registerSlug, string $schema, array $filters = []): array {
				return $this->loanFindAllResults($schema);
			}
		);

		$capturedLog = null;
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use (&$capturedLog): \OCA\OpenRegister\Db\ObjectEntity {
				$capturedLog = $object;
				return new \OCA\OpenRegister\Db\ObjectEntity();
			}
		);

		$this->service->evaluate(
			'loan-eligibility',
			['applicant' => ['age' => 30, 'monthlyIncome' => 3000, 'creditScore' => 700], 'bsn' => '123456789'],
			null,
			false,
			true
		);

		$this->assertNotNull($capturedLog);
		$this->assertSame('***', $capturedLog['inputPayload']['bsn']);

	}//end testPiiMasking()

	/**
	 * Build a condition-action RuleSet + one unconditional rule carrying a
	 * send-notification action.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function conditionActionFindAllResults(string $schema): array {
		if ($schema === 'rule-set') {
			return [['slug' => 'escalate', 'version' => '1.0.0', 'ruleType' => 'condition-action']];
		}

		if ($schema === 'condition-action-rule') {
			return [
				[
					'ruleSetId' => 'escalate',
					'name' => 'always-notify',
					'condition' => '',
					'actions' => [
						['type' => 'send-notification', 'parameters' => ['subject' => 'hello', 'recipientUid' => 'alice']],
					],
					'active' => true,
				],
			];
		}

		return [];
	}//end conditionActionFindAllResults()

	/**
	 * REQ-AUTD-010: a wet (non-dry-run) evaluation invokes the wired dispatcher
	 * for a triggered rule's side-effecting action.
	 *
	 * @return void
	 */
	public function testWetEvaluationInvokesDispatcher(): void {
		$this->stubScopedRows(
			function (string $registerSlug, string $schema, array $filters = []): array {
				return $this->conditionActionFindAllResults($schema);
			}
		);

		$this->actionDispatcher->expects($this->once())
			->method('__invoke')
			->with('send-notification', $this->anything(), $this->anything());

		$outcome = $this->service->evaluate('escalate', [], null, false);

		$this->assertContains('always-notify', $outcome['triggeredRules'] ?? [], 'sanity: rule fired');

	}//end testWetEvaluationInvokesDispatcher()

	/**
	 * REQ-AUTD-010: a dry-run evaluation never invokes the dispatcher.
	 *
	 * @return void
	 */
	public function testDryRunDoesNotInvokeDispatcher(): void {
		$this->stubScopedRows(
			function (string $registerSlug, string $schema, array $filters = []): array {
				return $this->conditionActionFindAllResults($schema);
			}
		);

		$this->actionDispatcher->expects($this->never())->method('__invoke');

		$this->service->evaluate('escalate', [], null, true);

	}//end testDryRunDoesNotInvokeDispatcher()

	/**
	 * DoS guard: a rule set whose evaluation re-enters itself (a self-referential
	 * call-rule-set) is refused as a cycle rather than recursing forever.
	 *
	 * @return void
	 */
	public function testCallRuleSetSelfReferenceIsRefused(): void {
		$conditionExecutor = $this->createMock(ConditionActionExecutor::class);
		$service = new RuleEngineService(
			$this->objectService,
			$this->createMock(DecisionTableEvaluator::class),
			$conditionExecutor,
			$this->cacheManager,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->actionDispatcher,
			$this->reader,
		);

		$this->stubScopedRows(
			function (string $registerSlug, string $schema, array $filters = []): array {
				if ($schema === RuleEngineService::RULE_SET_SCHEMA) {
					return [['slug' => 'loop', 'version' => '1.0', 'ruleType' => 'condition-action']];
				}

				if ($schema === RuleEngineService::CONDITION_RULE_SCHEMA) {
					return [['name' => 'r1']];
				}

				return [];
			}
		);

		// The executor re-enters the engine with the SAME slug (a call-rule-set
		// action pointing at its own rule set) — the nested call is refused and
		// surfaced as an evaluation error.
		$conditionExecutor->method('execute')->willReturnCallback(
			function () use ($service): array {
				$service->evaluate(ruleSetSlug: 'loop', payload: []);
				return ['result' => [], 'errors' => [], 'triggeredRules' => []];
			}
		);

		$outcome = $service->evaluate(ruleSetSlug: 'loop', payload: []);
		$this->assertStringContainsStringIgnoringCase('cycle', implode(' ', $outcome['errors']));

	}//end testCallRuleSetSelfReferenceIsRefused()

	/**
	 * DoS guard: a chain of distinct rule sets calling one another is bounded by
	 * the maximum call depth — the executor fires at most MAX_CALL_DEPTH times.
	 *
	 * @return void
	 */
	public function testCallRuleSetDepthIsBounded(): void {
		$conditionExecutor = $this->createMock(ConditionActionExecutor::class);
		$service = new RuleEngineService(
			$this->objectService,
			$this->createMock(DecisionTableEvaluator::class),
			$conditionExecutor,
			$this->cacheManager,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->actionDispatcher,
			$this->reader,
		);

		$this->stubScopedRows(
			function (string $registerSlug, string $schema, array $filters = []): array {
				if ($schema === RuleEngineService::RULE_SET_SCHEMA) {
					return [['slug' => 'chain', 'version' => '1.0', 'ruleType' => 'condition-action']];
				}

				if ($schema === RuleEngineService::CONDITION_RULE_SCHEMA) {
					return [['name' => 'r1']];
				}

				return [];
			}
		);

		$calls = 0;
		$conditionExecutor->method('execute')->willReturnCallback(
			function () use ($service, &$calls): array {
				++$calls;
				// Distinct slug each level (not a cycle) — the depth guard stops it.
				$service->evaluate(ruleSetSlug: 'chain-' . $calls, payload: []);
				return ['result' => [], 'errors' => [], 'triggeredRules' => []];
			}
		);

		$service->evaluate(ruleSetSlug: 'chain-0', payload: []);
		$this->assertGreaterThan(1, $calls);
		$this->assertLessThanOrEqual(10, $calls);

	}//end testCallRuleSetDepthIsBounded()

	/**
	 * M1 and REQ-BRE-007: every rule-engine read is a numeric-id search with
	 * schema RBAC AND the organisation filter on. It never uses the unscoped
	 * `findAll`, nor the slug search whose filtered register lookup throws.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	public function testResolutionUsesAuthorizationScopedSearch(): void {
		$this->objectService->expects($this->never())->method('findAll');
		$this->objectService->expects($this->never())->method('searchObjectsBySlug');

		$flags = [];
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use (&$flags): array {
				$flags[] = [$_rbac, $_multitenancy, ($query['@self']['register'] ?? null)];
				return $this->loanFindAllResults($this->schemaSlugOf(query: $query));
			}
		);

		$outcome = $this->service->evaluate(
			'loan-eligibility',
			['applicant' => ['age' => 30, 'monthlyIncome' => 3000, 'creditScore' => 700]]
		);
		$this->assertSame('approve', $outcome['result']['decision']);
		$this->assertNotSame([], $flags);
		$this->assertSame([[true, true, 7]], array_values(array_unique($flags, SORT_REGULAR)));

	}//end testResolutionUsesAuthorizationScopedSearch()

	/**
	 * M1: a rule-set outside the caller's authorization scope (searchObjects
	 * returns nothing) resolves to a 404 — it is not evaluated by slug.
	 *
	 * @return void
	 */
	public function testOutOfScopeRuleSetResolvesNotFound(): void {
		$this->stubScopedRows(static fn (): array => []);
		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);
		$this->service->evaluate('foreign-rule-set', []);

	}//end testOutOfScopeRuleSetResolvesNotFound()

	/**
	 * REQ-BRE-007: a rule set held by another organisation is not found.
	 *
	 * The caller sits in organisation B; `loan-eligibility` and the `buildiq`
	 * register are held by organisation A. OpenRegister's organisation filter is
	 * modelled as it behaves: a read with `_multitenancy: true` sees none of
	 * organisation A's objects, and the filtered register lookup behind
	 * `searchObjectsBySlug` throws. Only a read with the filter OFF sees the rule
	 * set, which is exactly what must not happen (buildiq#988).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	public function testRuleSetHeldByAnotherOrganisationResolvesNotFound(): void {
		$this->modelOrganisationB();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);
		$this->service->evaluate(
			'loan-eligibility',
			['applicant' => ['age' => 30, 'monthlyIncome' => 3000, 'creditScore' => 700]]
		);

	}//end testRuleSetHeldByAnotherOrganisationResolvesNotFound()

	/**
	 * REQ-BRE-007: the shared bundle cache does not hand organisation A's rule
	 * set to a caller in organisation B.
	 *
	 * The cache is distributed and was keyed by slug alone, so the first
	 * caller's bundle answered every caller for 30 seconds.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/business-rules-engine/spec.md#requirement-req-bre-007-per-tenant-isolation-and-multitenancy
	 */
	public function testCachedBundleOfAnotherOrganisationIsNotServed(): void {
		$this->modelOrganisationB();

		$cache = $this->createMock(RuleSetCacheManager::class);
		$cache->method('get')->willReturn(
			[
				'ruleSet' => $this->loanFindAllResults('rule-set')[0],
				'ruleType' => 'decision-table',
				'decisionTables' => $this->loanFindAllResults('decision-table'),
				'conditionRules' => [],
			]
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionCode(404);
		$this->serviceWith(cacheManager: $cache)->evaluate(
			'loan-eligibility',
			['applicant' => ['age' => 30, 'monthlyIncome' => 3000, 'creditScore' => 700]]
		);

	}//end testCachedBundleOfAnotherOrganisationIsNotServed()

	/**
	 * OpenRegister's register lookup: the `buildiq` register is held by one
	 * organisation, so only the unfiltered lookup finds it for every caller.
	 *
	 * @return RegisterMapper&MockObject
	 */
	private function registerMapper(): RegisterMapper&MockObject {
		$register = new Register();
		$register->setId(7);
		$register->setSchemas(array_values(self::SCHEMA_IDS));

		$mapper = $this->createMock(RegisterMapper::class);
		$mapper->method('find')->willReturnCallback(
			static function (string|int $id, bool $_rbac = true, bool $_multitenancy = true) use ($register): Register {
				if ($_multitenancy === true) {
					throw new DoesNotExistException('Register not found in the caller organisation: ' . $id);
				}

				return $register;
			}
		);

		return $mapper;

	}//end registerMapper()

	/**
	 * OpenRegister's slug-to-id lookup, with `rule-test-case` also held by
	 * another app, so resolution has to pick the one in the register.
	 *
	 * @return SchemaMapper&MockObject
	 */
	private function schemaMapper(): SchemaMapper&MockObject {
		$mapper = $this->createMock(SchemaMapper::class);
		$mapper->method('findIdsBySlugs')->willReturnCallback(
			static function (array $slugs): array {
				$map = [];
				foreach ($slugs as $slug) {
					$ids = [];
					if (isset(self::SCHEMA_IDS[$slug]) === true) {
						$ids[] = (string)self::SCHEMA_IDS[$slug];
					}

					if ($slug === 'rule-test-case') {
						$ids[] = '9001';
					}

					$map[strtolower($slug)] = $ids;
				}

				return $map;
			}
		);

		return $mapper;

	}//end schemaMapper()

	/**
	 * Answer the organisation-scoped search with rows per schema slug.
	 *
	 * @param callable $rows Called as ($registerSlug, $schemaSlug, $filters).
	 *
	 * @return void
	 */
	private function stubScopedRows(callable $rows): void {
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true) use ($rows): array {
				$filters = $query;
				unset($filters['@self']);
				return $rows(RuleEngineService::REGISTER_SLUG, $this->schemaSlugOf(query: $query), $filters);
			}
		);

	}//end stubScopedRows()

	/**
	 * OpenRegister as a caller in organisation B meets it, with every rule-engine
	 * object held by organisation A.
	 *
	 * @return void
	 */
	private function modelOrganisationB(): void {
		$this->objectService->method('searchObjectsBySlug')->willReturnCallback(
			function (string $registerSlug, string $schema, array $filters = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($_multitenancy === true) {
					throw new DoesNotExistException('searchObjectsBySlug: register slug not found in caller organisation: ' . $registerSlug);
				}

				return $this->loanFindAllResults($schema);
			}
		);
		$this->objectService->method('searchObjects')->willReturnCallback(
			function (array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($_multitenancy === true) {
					return [];
				}

				return $this->loanFindAllResults($this->schemaSlugOf(query: $query));
			}
		);

	}//end modelOrganisationB()

	/**
	 * The schema slug a numeric-id search names, per {@see self::SCHEMA_IDS}.
	 *
	 * @param array<string,mixed> $query The searchObjects query.
	 *
	 * @return string
	 */
	private function schemaSlugOf(array $query): string {
		$slug = array_search((int)($query['@self']['schema'] ?? 0), self::SCHEMA_IDS, true);
		if ($slug === false) {
			return '';
		}

		return $slug;

	}//end schemaSlugOf()

	/**
	 * A service wired like setUp()'s, with another cache manager.
	 *
	 * @param RuleSetCacheManager $cacheManager The cache to use.
	 *
	 * @return RuleEngineService
	 */
	private function serviceWith(RuleSetCacheManager $cacheManager): RuleEngineService {
		$evaluator = new ExpressionEvaluator();
		return new RuleEngineService(
			$this->objectService,
			new DecisionTableEvaluator($evaluator, $this->sharedEvaluator()),
			new ConditionActionExecutor($evaluator),
			$cacheManager,
			$this->userSession,
			$this->createMock(LoggerInterface::class),
			$this->actionDispatcher,
			$this->reader,
		);

	}//end serviceWith()
}//end class
