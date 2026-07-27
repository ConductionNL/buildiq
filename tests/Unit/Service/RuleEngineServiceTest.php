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
 * @package  OCA\OpenBuild\Tests\Unit\Service
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

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\ConditionActionExecutor;
use OCA\OpenBuild\Service\DecisionTableEvaluator;
use OCA\OpenBuild\Service\ExpressionEvaluator;
use OCA\OpenBuild\Service\RuleActionDispatcher;
use OCA\OpenBuild\Service\RuleEngineService;
use OCA\OpenBuild\Service\RuleSetCacheManager;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see RuleEngineService}.
 */
final class RuleEngineServiceTest extends TestCase
{

    /**
     * Mock OpenRegister object service.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

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
    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->cacheManager  = $this->createMock(RuleSetCacheManager::class);
        $this->userSession   = $this->createMock(IUserSession::class);

        $this->cacheManager->method('get')->willReturn(null);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $this->userSession->method('getUser')->willReturn($user);

        $evaluator = new ExpressionEvaluator();
        $this->actionDispatcher = $this->createMock(RuleActionDispatcher::class);
        $this->service = new RuleEngineService(
            $this->objectService,
            new DecisionTableEvaluator($evaluator),
            new ConditionActionExecutor($evaluator),
            $this->cacheManager,
            $this->userSession,
            $this->createMock(LoggerInterface::class),
            $this->actionDispatcher
        );

    }//end setUp()

    /**
     * Build the loan RuleSet + DecisionTable rows returned by findAll.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loanFindAllResults(string $schema): array
    {
        if ($schema === 'rule-set') {
            return [['slug' => 'loan-eligibility', 'versie' => '1.0.0', 'ruleType' => 'decision-table']];
        }

        if ($schema === 'decision-table') {
            return [
                [
                    'ruleSetId'    => 'loan-eligibility',
                    'hitPolicy'    => 'first',
                    'inputColumns' => [
                        ['naam' => 'applicantAge', 'expressiePad' => 'applicant.age'],
                        ['naam' => 'monthlyIncome', 'expressiePad' => 'applicant.monthlyIncome'],
                        ['naam' => 'creditScore', 'expressiePad' => 'applicant.creditScore'],
                    ],
                    'outputColumns' => [['naam' => 'decision', 'defaultwaarde' => 'deny']],
                    'regels'        => [
                        [
                            'condities' => ['applicantAge' => '>=18', 'monthlyIncome' => '>=2000', 'creditScore' => '>=600'],
                            'waardes'   => ['decision' => 'approve'],
                            'label'     => 'approve',
                        ],
                        ['condities' => [], 'waardes' => ['decision' => 'deny'], 'label' => 'deny'],
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
    public function testEvaluateLoanApprove(): void
    {
        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
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
        $this->assertContains('approve', $outcome['geraaktRegels']);
        $this->assertSame([], $outcome['fouten']);

    }//end testEvaluateLoanApprove()

    /**
     * An unknown RuleSet slug raises a 404-coded exception.
     *
     * @return void
     */
    public function testEvaluateNotFound(): void
    {
        $this->objectService->method('searchObjectsBySlug')->willReturn([]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(404);
        $this->service->evaluate('does-not-exist', []);

    }//end testEvaluateNotFound()

    /**
     * PII fields are masked in the persisted RuleExecutionLog input.
     *
     * @return void
     */
    public function testPiiMasking(): void
    {
        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
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
    private function conditionActionFindAllResults(string $schema): array
    {
        if ($schema === 'rule-set') {
            return [['slug' => 'escalate', 'versie' => '1.0.0', 'ruleType' => 'condition-action']];
        }

        if ($schema === 'condition-action-rule') {
            return [
                [
                    'ruleSetId' => 'escalate',
                    'naam'      => 'always-notify',
                    'conditie'  => '',
                    'acties'    => [
                        ['type' => 'send-notification', 'parameters' => ['subject' => 'hello', 'recipientUid' => 'alice']],
                    ],
                    'actief'    => true,
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
    public function testWetEvaluationInvokesDispatcher(): void
    {
        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
            function (string $registerSlug, string $schema, array $filters = []): array {
                return $this->conditionActionFindAllResults($schema);
            }
        );

        $this->actionDispatcher->expects($this->once())
            ->method('__invoke')
            ->with('send-notification', $this->anything(), $this->anything());

        $outcome = $this->service->evaluate('escalate', [], null, false);

        $this->assertContains('always-notify', $outcome['geraaktRegels'] ?? [], 'sanity: rule fired');

    }//end testWetEvaluationInvokesDispatcher()

    /**
     * REQ-AUTD-010: a dry-run evaluation never invokes the dispatcher.
     *
     * @return void
     */
    public function testDryRunDoesNotInvokeDispatcher(): void
    {
        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
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
    public function testCallRuleSetSelfReferenceIsRefused(): void
    {
        $conditionExecutor = $this->createMock(ConditionActionExecutor::class);
        $service           = new RuleEngineService(
            $this->objectService,
            $this->createMock(DecisionTableEvaluator::class),
            $conditionExecutor,
            $this->cacheManager,
            $this->userSession,
            $this->createMock(LoggerInterface::class),
            $this->actionDispatcher
        );

        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
            function (string $registerSlug, string $schema, array $filters = []): array {
                if ($schema === RuleEngineService::RULE_SET_SCHEMA) {
                    return [['slug' => 'loop', 'versie' => '1.0', 'ruleType' => 'condition-action']];
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
        $this->assertStringContainsStringIgnoringCase('cycle', implode(' ', $outcome['fouten']));

    }//end testCallRuleSetSelfReferenceIsRefused()

    /**
     * DoS guard: a chain of distinct rule sets calling one another is bounded by
     * the maximum call depth — the executor fires at most MAX_CALL_DEPTH times.
     *
     * @return void
     */
    public function testCallRuleSetDepthIsBounded(): void
    {
        $conditionExecutor = $this->createMock(ConditionActionExecutor::class);
        $service           = new RuleEngineService(
            $this->objectService,
            $this->createMock(DecisionTableEvaluator::class),
            $conditionExecutor,
            $this->cacheManager,
            $this->userSession,
            $this->createMock(LoggerInterface::class),
            $this->actionDispatcher
        );

        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
            function (string $registerSlug, string $schema, array $filters = []): array {
                if ($schema === RuleEngineService::RULE_SET_SCHEMA) {
                    return [['slug' => 'chain', 'versie' => '1.0', 'ruleType' => 'condition-action']];
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
                $service->evaluate(ruleSetSlug: 'chain-'.$calls, payload: []);
                return ['result' => [], 'errors' => [], 'triggeredRules' => []];
            }
        );

        $service->evaluate(ruleSetSlug: 'chain-0', payload: []);
        $this->assertGreaterThan(1, $calls);
        $this->assertLessThanOrEqual(10, $calls);

    }//end testCallRuleSetDepthIsBounded()

    /**
     * M1: rule-set resolution is authorization-scoped — it uses
     * `searchObjectsBySlug` (RBAC + org) and never the unscoped `findAll`.
     *
     * @return void
     */
    public function testResolutionUsesAuthorizationScopedSearch(): void
    {
        $this->objectService->expects($this->never())->method('findAll');
        $this->objectService->method('searchObjectsBySlug')->willReturnCallback(
            function (string $registerSlug, string $schema, array $filters = []): array {
                return $this->loanFindAllResults($schema);
            }
        );

        $outcome = $this->service->evaluate(
            'loan-eligibility',
            ['applicant' => ['age' => 30, 'monthlyIncome' => 3000, 'creditScore' => 700]]
        );
        $this->assertSame('approve', $outcome['result']['decision']);

    }//end testResolutionUsesAuthorizationScopedSearch()

    /**
     * M1: a rule-set outside the caller's authorization scope (searchObjects
     * returns nothing) resolves to a 404 — it is not evaluated by slug.
     *
     * @return void
     */
    public function testOutOfScopeRuleSetResolvesNotFound(): void
    {
        $this->objectService->method('searchObjectsBySlug')->willReturn([]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(404);
        $this->service->evaluate('foreign-rule-set', []);

    }//end testOutOfScopeRuleSetResolvesNotFound()
}//end class
