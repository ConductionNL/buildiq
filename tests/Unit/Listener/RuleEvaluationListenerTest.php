<?php

/**
 * Unit tests for RuleEvaluationListener, buildiq's answer to another app's
 * RuleEvaluationRequestedEvent (rules-evaluation-for-other-apps design D2).
 *
 * The rule engine is a double: resolveForApp() and evaluateForApp() are
 * covered by RuleEngineServiceTest; here only the steps the listener takes
 * and what it answers matter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Listener
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

namespace OCA\Buildiq\Tests\Unit\Listener;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Event\RuleEvaluationRequestedEvent;
use OCA\Buildiq\Listener\RuleEvaluationListener;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\StoredDecisionTables;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Tests for RuleEvaluationListener.
 */
final class RuleEvaluationListenerTest extends TestCase {

	/**
	 * The parking rule set, as resolveForApp() answers it.
	 *
	 * @var array<string,mixed>
	 */
	private const PARKING = [
		'slug' => 'parkeren-soort-vergunning',
		'status' => 'active',
		'version' => '2',
		'callableBy' => ['portaliq'],
	];

	private RuleEngineService&MockObject $engine;

	private StoredDecisionTables&MockObject $tables;

	private LoggerInterface&MockObject $logger;

	private RuleEvaluationListener $listener;

	protected function setUp(): void {
		$this->engine = $this->createMock(RuleEngineService::class);
		$this->tables = $this->createMock(StoredDecisionTables::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->tables->method('forRuleSet')->willReturn(
			[
				[
					'inputColumns' => [
						['name' => 'woonplaats', 'type' => 'string'],
						['name' => 'auto', 'type' => 'boolean'],
					],
					'outputColumns' => [['name' => 'soortVergunning', 'type' => 'string']],
				],
			]
		);
		$this->listener = new RuleEvaluationListener(engine: $this->engine, tables: $this->tables, logger: $this->logger);
	}//end setUp()

	/**
	 * The command as portaliq dispatches it for the parking rule.
	 *
	 * @param array<string,mixed> $inputs The inputs.
	 * @param string              $app    The calling app.
	 *
	 * @return RuleEvaluationRequestedEvent
	 */
	private function command(array $inputs = ['woonplaats' => 'Zuiddrecht', 'auto' => true], string $app = 'portaliq'): RuleEvaluationRequestedEvent {
		return new RuleEvaluationRequestedEvent(app: $app, ruleSet: 'parkeren-soort-vergunning', inputs: $inputs);
	}//end command()

	/**
	 * The engine resolves the parking rule set for the caller.
	 *
	 * @return void
	 */
	private function resolves(): void {
		$this->engine->method('resolveForApp')->willReturn(['status' => 'ok', 'ruleSet' => self::PARKING]);
	}//end resolves()

	/**
	 * The portal decides which permit step comes next: the outcome, the version
	 * and the triggered rules, from the declared inputs only.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function testThePortalGetsTheDecision(): void {
		$this->engine->expects($this->once())->method('resolveForApp')
			->with('portaliq', 'parkeren-soort-vergunning')
			->willReturn(['status' => 'ok', 'ruleSet' => self::PARKING]);
		$this->engine->expects($this->once())->method('evaluateForApp')
			->with('portaliq', self::PARKING, ['woonplaats' => 'Zuiddrecht', 'auto' => true])
			->willReturn(['result' => ['soortVergunning' => 'bewoner'], 'triggeredRules' => [7], 'executionTime' => 3, 'errors' => [], 'version' => '2']);

		$command = $this->command(inputs: ['woonplaats' => 'Zuiddrecht', 'auto' => true, 'bsn' => '999993653']);
		$this->listener->handle($command);

		$this->assertSame(
			['ok' => true, 'result' => ['soortVergunning' => 'bewoner'], 'triggeredRules' => ['7'], 'version' => '2'],
			$command->getAnswer()
		);
	}//end testThePortalGetsTheDecision()

	/**
	 * An unknown rule set or an app the rule set does not list is refused, and nothing is evaluated.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function testAnUnknownRuleSetOrAnUnlistedAppIsRefusedBeforeEvaluating(): void {
		$this->engine->method('resolveForApp')->willReturnOnConsecutiveCalls(
			['status' => 'unknown-rule-set', 'ruleSet' => null],
			['status' => 'not-allowed', 'ruleSet' => null]
		);
		$this->engine->expects($this->never())->method('evaluateForApp');

		$unknown = $this->command();
		$this->listener->handle($unknown);
		$notAllowed = $this->command(app: 'decidiq');
		$this->listener->handle($notAllowed);

		$this->assertSame(['ok' => false, 'reason' => 'unknown-rule-set'], $unknown->getAnswer());
		$this->assertSame(['ok' => false, 'reason' => 'not-allowed'], $notAllowed->getAnswer());
	}//end testAnUnknownRuleSetOrAnUnlistedAppIsRefusedBeforeEvaluating()

	/**
	 * An input of the wrong type, or inputs over the payload bound, are invalid-input.
	 *
	 * @return void
	 */
	public function testInvalidInputIsRefusedBeforeEvaluating(): void {
		$this->resolves();
		$this->engine->expects($this->never())->method('evaluateForApp');

		$wrongType = $this->command(inputs: ['woonplaats' => 'Zuiddrecht', 'auto' => 'ja']);
		$this->listener->handle($wrongType);
		$tooLarge = $this->command(inputs: ['woonplaats' => str_repeat('x', RuleEvaluationListener::MAX_INPUT_BYTES)]);
		$this->listener->handle($tooLarge);

		$this->assertSame(['ok' => false, 'reason' => 'invalid-input'], $wrongType->getAnswer());
		$this->assertSame(['ok' => false, 'reason' => 'invalid-input'], $tooLarge->getAnswer());
	}//end testInvalidInputIsRefusedBeforeEvaluating()

	/**
	 * An evaluation past the 500 ms bound is a timeout; one with errors is evaluation-failed.
	 *
	 * @return void
	 */
	public function testATimeoutAndAFailedEvaluationAreRefused(): void {
		$this->resolves();
		$this->engine->method('evaluateForApp')->willReturnOnConsecutiveCalls(
			['result' => ['soortVergunning' => 'bewoner'], 'triggeredRules' => [], 'executionTime' => (RuleEngineService::TIMEOUT_MS + 1), 'errors' => ['slow'], 'version' => '2'],
			['result' => [], 'triggeredRules' => [], 'executionTime' => 2, 'errors' => ['Hit policy violated'], 'version' => '2']
		);

		$slow = $this->command();
		$this->listener->handle($slow);
		$failed = $this->command();
		$this->listener->handle($failed);

		$this->assertSame(['ok' => false, 'reason' => 'timeout'], $slow->getAnswer());
		$this->assertSame(['ok' => false, 'reason' => 'evaluation-failed'], $failed->getAnswer());
	}//end testATimeoutAndAFailedEvaluationAreRefused()

	/**
	 * A throwing engine never reaches the caller: it is logged and answered as evaluation-failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public function testAThrowingEngineIsAnsweredNotThrown(): void {
		$this->resolves();
		$this->engine->method('evaluateForApp')->willThrowException(new RuntimeException('Rule-set cycle detected', 508));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not be evaluated'), $this->callback(static fn (array $context): bool => $context['app'] === 'portaliq' && $context['exception'] === 'Rule-set cycle detected'));

		$command = $this->command();
		$this->listener->handle($command);

		$this->assertSame(['ok' => false, 'reason' => 'evaluation-failed'], $command->getAnswer());
	}//end testAThrowingEngineIsAnsweredNotThrown()

	/**
	 * Another event, or a command another listener already answered, is left alone.
	 *
	 * @return void
	 */
	public function testOtherEventsAndAnsweredCommandsAreLeftAlone(): void {
		$this->engine->expects($this->never())->method('resolveForApp');

		$this->listener->handle(new Event());
		$answered = $this->command();
		$answered->refuse(reason: 'not-allowed');
		$this->listener->handle($answered);

		$this->assertSame(['ok' => false, 'reason' => 'not-allowed'], $answered->getAnswer());
	}//end testOtherEventsAndAnsweredCommandsAreLeftAlone()

	/**
	 * Application::register() wires the listener to the command.
	 *
	 * @return void
	 */
	public function testTheAppRegistersTheListenerForTheCommand(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		$app = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$this->assertContains([RuleEvaluationRequestedEvent::class, RuleEvaluationListener::class], $registered);
	}//end testTheAppRegistersTheListenerForTheCommand()
}//end class
