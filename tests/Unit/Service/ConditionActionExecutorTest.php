<?php

/**
 * Unit tests for ConditionActionExecutor.
 *
 * Covers REQ-BRE-003 / REQ-BRE-004: priority/salience ordering, action
 * execution, dry-run suppression of side effects, and continueOnError.
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
use OCA\OpenBuild\Service\ExpressionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see ConditionActionExecutor}.
 */
final class ConditionActionExecutorTest extends TestCase
{

    /**
     * The executor under test.
     *
     * @var ConditionActionExecutor
     */
    private ConditionActionExecutor $executor;

    /**
     * Build a fresh executor before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->executor = new ConditionActionExecutor(new ExpressionEvaluator());

    }//end setUp()

    /**
     * Rules fire in prioriteit DESC, then salience DESC, then order.
     *
     * @return void
     */
    public function testPriorityAndSalienceOrdering(): void
    {
        $rules = [
            ['naam' => 'C', 'prioriteit' => 100, 'salience' => 5, 'conditie' => '', 'acties' => []],
            ['naam' => 'A', 'prioriteit' => 200, 'salience' => 0, 'conditie' => '', 'acties' => []],
            ['naam' => 'B', 'prioriteit' => 100, 'salience' => 10, 'conditie' => '', 'acties' => []],
        ];
        $result = $this->executor->execute($rules, []);
        $order  = array_column($result['triggeredRules'], 'name');
        $this->assertSame(['A', 'B', 'C'], $order);

    }//end testPriorityAndSalienceOrdering()

    /**
     * A matching condition runs a set-veld action that mutates the payload.
     *
     * @return void
     */
    public function testSetFieldAction(): void
    {
        $rules = [
            [
                'naam'     => 'escalate',
                'conditie' => "severity == 'critical'",
                'acties'   => [['type' => 'set-veld', 'parameters' => ['veld' => 'escalated', 'waarde' => true]]],
            ],
        ];
        $result = $this->executor->execute($rules, ['severity' => 'critical']);
        $this->assertTrue($result['result']['escalated']);
        $this->assertCount(1, $result['triggeredRules']);

    }//end testSetFieldAction()

    /**
     * A non-matching condition fires no rule.
     *
     * @return void
     */
    public function testConditionNotMet(): void
    {
        $rules = [
            ['naam' => 'x', 'conditie' => 'amount > 5000', 'acties' => [['type' => 'set-veld', 'parameters' => ['veld' => 'big', 'waarde' => true]]]],
        ];
        $result = $this->executor->execute($rules, ['amount' => 100]);
        $this->assertEmpty($result['triggeredRules']);
        $this->assertArrayNotHasKey('big', $result['result']);

    }//end testConditionNotMet()

    /**
     * Dry-run records but does not dispatch side-effecting actions.
     *
     * @return void
     */
    public function testDryRunSkipsSideEffects(): void
    {
        $dispatched = 0;
        $dispatcher = static function () use (&$dispatched): void {
            ++$dispatched;
        };
        $rules = [
            [
                'naam'     => 'notify',
                'conditie' => '',
                'acties'   => [['type' => 'send-notification', 'parameters' => ['recipient' => 'x']]],
            ],
        ];
        $result = $this->executor->execute($rules, [], true, $dispatcher);
        $this->assertSame(0, $dispatched);
        $this->assertStringContainsString('dry-run', $result['triggeredRules'][0]['actions_executed'][0]);

    }//end testDryRunSkipsSideEffects()

    /**
     * automation-approval-steps REQ-AUTD-007: an `approval` action in a
     * dry-run synthetic rule is marked "dry-run, skipped" (no "unknown
     * action type" error, no ApprovalStep created — this executor never
     * touches OR's approval tables at all).
     *
     * @return void
     */
    public function testDryRunSkipsApprovalAction(): void
    {
        $rules = [
            [
                'naam'     => 'route-for-approval',
                'conditie' => '',
                'acties'   => [['type' => 'approval', 'parameters' => ['assigneeGroup' => 'permit-reviewers']]],
            ],
        ];

        $result = $this->executor->execute($rules, [], true, null);

        $this->assertEmpty($result['errors']);
        $this->assertStringContainsString('dry-run', $result['triggeredRules'][0]['actions_executed'][0]);
        $this->assertStringContainsString('approval', $result['triggeredRules'][0]['actions_executed'][0]);

    }//end testDryRunSkipsApprovalAction()

    /**
     * Live mode dispatches side-effecting actions through the dispatcher.
     *
     * @return void
     */
    public function testLiveModeDispatches(): void
    {
        $dispatched = [];
        $dispatcher = static function (string $type) use (&$dispatched): void {
            $dispatched[] = $type;
        };
        $rules = [
            ['naam' => 'notify', 'conditie' => '', 'acties' => [['type' => 'send-notification', 'parameters' => []]]],
        ];
        $this->executor->execute($rules, [], false, $dispatcher);
        $this->assertSame(['send-notification'], $dispatched);

    }//end testLiveModeDispatches()

    /**
     * An inactive rule is skipped.
     *
     * @return void
     */
    public function testInactiveRuleSkipped(): void
    {
        $rules = [
            ['naam' => 'off', 'actief' => false, 'conditie' => '', 'acties' => []],
        ];
        $result = $this->executor->execute($rules, []);
        $this->assertEmpty($result['triggeredRules']);

    }//end testInactiveRuleSkipped()
}//end class
