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
        $this->service = new RuleEngineService(
            $this->objectService,
            new DecisionTableEvaluator($evaluator),
            new ConditionActionExecutor($evaluator),
            $this->cacheManager,
            $this->userSession,
            $this->createMock(LoggerInterface::class)
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
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config): array {
                return $this->loanFindAllResults((string) $config['filters']['schema']);
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
        $this->objectService->method('findAll')->willReturn([]);
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
        $this->objectService->method('findAll')->willReturnCallback(
            function (array $config): array {
                return $this->loanFindAllResults((string) $config['filters']['schema']);
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
}//end class
