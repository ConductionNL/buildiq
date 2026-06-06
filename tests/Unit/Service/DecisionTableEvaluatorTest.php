<?php

/**
 * Unit tests for DecisionTableEvaluator.
 *
 * Covers REQ-BRE-002 / REQ-BRE-012: hit policies, overlap and unreachable
 * detection, default output, and FEEL cell-condition matching.
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

use OCA\OpenBuild\Service\DecisionTableEvaluator;
use OCA\OpenBuild\Service\ExpressionEvaluator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for {@see DecisionTableEvaluator}.
 */
final class DecisionTableEvaluatorTest extends TestCase
{

    /**
     * The evaluator under test.
     *
     * @var DecisionTableEvaluator
     */
    private DecisionTableEvaluator $evaluator;

    /**
     * Build a fresh evaluator before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->evaluator = new DecisionTableEvaluator(new ExpressionEvaluator());

    }//end setUp()

    /**
     * The canonical loan-eligibility table.
     *
     * @return array<string,mixed>
     */
    private function loanTable(): array
    {
        return [
            'hitPolicy'     => 'first',
            'inputColumns'  => [
                ['naam' => 'applicantAge', 'type' => 'integer', 'expressiePad' => 'applicant.age'],
                ['naam' => 'monthlyIncome', 'type' => 'number', 'expressiePad' => 'applicant.monthlyIncome'],
                ['naam' => 'creditScore', 'type' => 'integer', 'expressiePad' => 'applicant.creditScore'],
            ],
            'outputColumns' => [
                ['naam' => 'decision', 'type' => 'string', 'defaultwaarde' => 'deny'],
                ['naam' => 'reason', 'type' => 'string', 'defaultwaarde' => 'Eligibility criteria not met'],
            ],
            'regels'        => [
                [
                    'condities' => ['applicantAge' => '>=18', 'monthlyIncome' => '>=2000', 'creditScore' => '>=600'],
                    'waardes'   => ['decision' => 'approve', 'reason' => 'All criteria met'],
                    'label'     => 'Standard approval',
                ],
                [
                    'condities' => ['applicantAge' => '>=18', 'monthlyIncome' => '>=1500', 'creditScore' => '>=500'],
                    'waardes'   => ['decision' => 'review', 'reason' => 'Manual review required'],
                    'label'     => 'Marginal case',
                ],
                [
                    'condities' => [],
                    'waardes'   => ['decision' => 'deny', 'reason' => 'Eligibility criteria not met'],
                    'label'     => 'Default deny',
                ],
            ],
        ];

    }//end loanTable()

    /**
     * First-match returns the first matching rule's output.
     *
     * @return void
     */
    public function testFirstHitApproval(): void
    {
        $result = $this->evaluator->evaluate(
            $this->loanTable(),
            ['applicant' => ['age' => 30, 'monthlyIncome' => 3000, 'creditScore' => 650]]
        );
        $this->assertSame('approve', $result['outputColumns']['decision']);
        $this->assertSame('Standard approval', $result['triggeredRuleId']);

    }//end testFirstHitApproval()

    /**
     * A marginal applicant falls through to the review rule.
     *
     * @return void
     */
    public function testFirstHitFallthroughToReview(): void
    {
        $result = $this->evaluator->evaluate(
            $this->loanTable(),
            ['applicant' => ['age' => 30, 'monthlyIncome' => 1600, 'creditScore' => 520]]
        );
        $this->assertSame('review', $result['outputColumns']['decision']);

    }//end testFirstHitFallthroughToReview()

    /**
     * The empty default rule denies when nothing else matches.
     *
     * @return void
     */
    public function testDefaultDeny(): void
    {
        $result = $this->evaluator->evaluate(
            $this->loanTable(),
            ['applicant' => ['age' => 16, 'monthlyIncome' => 100, 'creditScore' => 300]]
        );
        $this->assertSame('deny', $result['outputColumns']['decision']);

    }//end testDefaultDeny()

    /**
     * The `unique` policy throws when more than one rule matches.
     *
     * @return void
     */
    public function testUniquePolicyThrowsOnOverlap(): void
    {
        $table = [
            'hitPolicy'    => 'unique',
            'inputColumns' => [['naam' => 'age', 'expressiePad' => 'age']],
            'regels'       => [
                ['condities' => ['age' => '>=18'], 'waardes' => ['x' => 1], 'label' => 'a'],
                ['condities' => ['age' => '>=21'], 'waardes' => ['x' => 2], 'label' => 'b'],
            ],
        ];
        $this->expectException(RuntimeException::class);
        $this->evaluator->evaluate($table, ['age' => 30]);

    }//end testUniquePolicyThrowsOnOverlap()

    /**
     * The `priority` policy returns the highest-priority matching rule.
     *
     * @return void
     */
    public function testPriorityPolicy(): void
    {
        $table = [
            'hitPolicy'    => 'priority',
            'inputColumns' => [['naam' => 'age', 'expressiePad' => 'age']],
            'regels'       => [
                ['condities' => ['age' => '>=18'], 'waardes' => ['band' => 'adult'], 'prioriteit' => 10, 'label' => 'low'],
                ['condities' => ['age' => '>=18'], 'waardes' => ['band' => 'senior'], 'prioriteit' => 50, 'label' => 'high'],
            ],
        ];
        $result = $this->evaluator->evaluate($table, ['age' => 40]);
        $this->assertSame('senior', $result['outputColumns']['band']);
        $this->assertSame('high', $result['triggeredRuleId']);

    }//end testPriorityPolicy()

    /**
     * The `collect` policy gathers every matching rule's output.
     *
     * @return void
     */
    public function testCollectPolicy(): void
    {
        $table = [
            'hitPolicy'    => 'collect',
            'inputColumns' => [['naam' => 'age', 'expressiePad' => 'age']],
            'regels'       => [
                ['condities' => ['age' => '>=18'], 'waardes' => ['tag' => 'adult'], 'label' => 'a'],
                ['condities' => ['age' => '>=65'], 'waardes' => ['tag' => 'senior'], 'label' => 'b'],
            ],
        ];
        $result = $this->evaluator->evaluate($table, ['age' => 70]);
        $this->assertCount(2, $result['outputColumns']['collected']);

    }//end testCollectPolicy()

    /**
     * detectIssues flags an unreachable rule shadowed by a don't-care rule.
     *
     * @return void
     */
    public function testDetectUnreachable(): void
    {
        $table = [
            'hitPolicy' => 'first',
            'regels'    => [
                ['condities' => [], 'waardes' => ['x' => 1], 'label' => 'catch-all'],
                ['condities' => ['age' => '>=18'], 'waardes' => ['x' => 2], 'label' => 'never'],
            ],
        ];
        $issues = $this->evaluator->detectIssues($table);
        $this->assertNotEmpty($issues['unreachable']);

    }//end testDetectUnreachable()

    /**
     * A well-formed table with disjoint literals reports no overlaps.
     *
     * @return void
     */
    public function testNoIssuesOnDisjointLiterals(): void
    {
        $table = [
            'hitPolicy' => 'first',
            'regels'    => [
                ['condities' => ['status' => 'open'], 'waardes' => ['x' => 1], 'label' => 'a'],
                ['condities' => ['status' => 'closed'], 'waardes' => ['x' => 2], 'label' => 'b'],
            ],
        ];
        $issues = $this->evaluator->detectIssues($table);
        $this->assertEmpty($issues['overlaps']);

    }//end testNoIssuesOnDisjointLiterals()
}//end class
