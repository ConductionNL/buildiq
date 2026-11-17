<?php

/**
 * Unit tests for RuleEvaluationRequestedEvent, the typed command another app
 * dispatches to evaluate a rule set (REQ-BQRX-002).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Event;

use OCA\Buildiq\Event\RuleEvaluationRequestedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RuleEvaluationRequestedEvent.
 */
final class RuleEvaluationRequestedEventTest extends TestCase {

	/**
	 * The command carries what the caller sent and is unanswered until a listener answers.
	 *
	 * @return void
	 */
	public function testTheCommandCarriesTheRequestAndStartsUnanswered(): void {
		$command = new RuleEvaluationRequestedEvent(app: 'portaliq', ruleSet: 'parkeren-soort-vergunning', inputs: ['auto' => true]);

		$this->assertSame('portaliq', $command->getApp());
		$this->assertSame('parkeren-soort-vergunning', $command->getRuleSet());
		$this->assertSame(['auto' => true], $command->getInputs());
		$this->assertFalse($command->isAnswered());
		$this->assertNull($command->getAnswer());
	}//end testTheCommandCarriesTheRequestAndStartsUnanswered()

	/**
	 * An answer carries the result, the triggered rule ids as a list, and the version.
	 *
	 * @return void
	 */
	public function testAnAnswer(): void {
		$command = new RuleEvaluationRequestedEvent(app: 'portaliq', ruleSet: 'parkeren-soort-vergunning', inputs: []);
		$command->answer(result: ['soortVergunning' => 'bewoner'], triggeredRules: [3 => 'r1'], version: '2');

		$this->assertTrue($command->isAnswered());
		$this->assertSame(
			['ok' => true, 'result' => ['soortVergunning' => 'bewoner'], 'triggeredRules' => ['r1'], 'version' => '2'],
			$command->getAnswer()
		);
	}//end testAnAnswer()

	/**
	 * Each known refusal is kept; anything else is answered as evaluation-failed.
	 *
	 * @return void
	 */
	public function testARefusal(): void {
		foreach (RuleEvaluationRequestedEvent::REFUSALS as $reason) {
			$command = new RuleEvaluationRequestedEvent(app: 'portaliq', ruleSet: 'x', inputs: []);
			$command->refuse(reason: $reason);
			$this->assertSame(['ok' => false, 'reason' => $reason], $command->getAnswer());
		}

		$command = new RuleEvaluationRequestedEvent(app: 'portaliq', ruleSet: 'x', inputs: []);
		$command->refuse(reason: 'ok');
		$this->assertSame(['ok' => false, 'reason' => 'evaluation-failed'], $command->getAnswer());
		$this->assertSame(
			['unknown-rule-set', 'not-allowed', 'invalid-input', 'timeout', 'evaluation-failed'],
			RuleEvaluationRequestedEvent::REFUSALS
		);
	}//end testARefusal()
}//end class
