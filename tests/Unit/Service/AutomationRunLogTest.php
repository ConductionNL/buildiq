<?php

/**
 * Unit tests for AutomationRunLog.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AutomationRunLog;
use OCA\Buildiq\Service\AutomationRunPage;
use OCA\Buildiq\Service\AutomationRunRecorder;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What each dispatch point writes as a run: the manual trigger's steps and
 * outcome, a waiting approval and its decision, a generated document, and a
 * recorder failure that never escapes.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(AutomationRunLog::class)]
#[UsesClass(AutomationRunRecorder::class)]
#[UsesClass(AutomationRunPage::class)]
#[UsesClass(ObjectRecord::class)]
final class AutomationRunLogTest extends TestCase {

	/**
	 * The automation the rule set `aut-a1b2c3d4` belongs to.
	 */
	private const AUTOMATION = [
		'id' => 'a1b2c3d4-0000-4000-8000-000000000001',
		'slug' => 'nieuw-ticket-melden',
		'versionUuid' => 'v-1',
		'trigger' => ['type' => 'manual', 'schema' => 'ticket'],
		'provenance' => ['ruleSetSlug' => 'aut-a1b2c3d4', 'compiledHash' => 'h-1'],
	];

	/**
	 * OpenRegister's object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface $objectService;

	/**
	 * Every record written, as [record, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $saved = [];

	/**
	 * Errors logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * The run log under test.
	 *
	 * @var AutomationRunLog
	 */
	private AutomationRunLog $log;

	/**
	 * Build the run log over a real recorder and doubled boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->saved  = [];
		$this->errors = [];

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$arguments): ObjectEntity {
				// The arguments are saveObject(object, extend, register, schema, uuid, ...).
				$this->saved[] = [$arguments[0], ($arguments[4] ?? null)];
				return self::entity(payload: ['@self' => ['id' => ($arguments[4] ?? 'run-new')]] + $arguments[0]);
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1793952000);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message): void {
				$this->errors[] = $message;
			}
		);

		$this->log = new AutomationRunLog(
			recorder: new AutomationRunRecorder(objectService: $this->objectService, time: $time, logger: $logger),
			objectService: $this->objectService,
			logger: $logger
		);
	}//end setUp()

	/**
	 * An object entity that serialises to this payload.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return ObjectEntity
	 */
	private static function entity(array $payload): ObjectEntity {
		$entity = new class() extends ObjectEntity {
			/**
			 * @var array<string, mixed>
			 */
			public array $payload = [];

			/**
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->payload;
			}
		};
		$entity->payload = $payload;
		return $entity;
	}//end entity()

	/**
	 * Make findAll answer with these automations, and these runs for the run schema.
	 *
	 * @param array<int, array<string, mixed>> $automations The automations.
	 * @param array<int, array<string, mixed>> $runs        The stored runs.
	 *
	 * @return void
	 */
	private function stored(array $automations, array $runs = []): void {
		$this->objectService->method('findAll')->willReturnCallback(
			static function (array $config) use ($automations, $runs): array {
				if (($config['filters']['schema'] ?? '') === 'automation-run') {
					return $runs;
				}

				return $automations;
			}
		);
	}//end stored()

	/**
	 * Two compiled actions, as the rules backend stores them.
	 *
	 * @param bool $continueOnError Whether a failure stops the next action.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function rules(bool $continueOnError = false): array {
		return [
			[
				'name' => 'Nieuw ticket melden',
				'condition' => 'prioriteit == "hoog"',
				'continueOnError' => $continueOnError,
				'actions' => [
					['type' => 'send-notification', 'parameters' => []],
					['type' => 'call-webhook', 'parameters' => []],
					['type' => 'object-op', 'parameters' => []],
				],
			],
		];
	}//end rules()

	/**
	 * A manual run whose second action failed: the first succeeded, the second failed with its message, the third did not run.
	 *
	 * @return void
	 */
	public function testAManualRunWithAFailingActionIsRecordedWithTheFailure(): void {
		$this->stored(automations: [self::AUTOMATION]);

		$this->log->ruleSetRan(
			ruleSetSlug: 'aut-a1b2c3d4',
			payload: ['@self' => ['id' => 'ticket-T-1'], 'secret' => 'not stored'],
			outcome: [
				'triggeredRules' => ['Nieuw ticket melden'],
				'errors' => ['Action "call-webhook" failed in rule "Nieuw ticket melden": Webhook answered 500'],
			],
			rules: self::rules(),
			actor: 'maker'
		);

		$this->assertCount(1, $this->saved);
		[$record, $uuid] = $this->saved[0];
		$this->assertNull($uuid);
		$this->assertSame('a1b2c3d4-0000-4000-8000-000000000001', $record['automation']);
		$this->assertSame('v-1', $record['versionUuid']);
		$this->assertSame('h-1', $record['compiledHash']);
		$this->assertSame('manual', $record['trigger']);
		$this->assertSame(['schema' => 'ticket', 'uuid' => 'ticket-T-1'], $record['subject']);
		$this->assertSame('maker', $record['actor']);
		$this->assertSame('failed', $record['outcome']);
		$this->assertSame('Action "call-webhook" failed in rule "Nieuw ticket melden": Webhook answered 500', $record['reason']);
		$this->assertSame(
			[
				['action' => 'send-notification', 'outcome' => 'succeeded', 'message' => ''],
				['action' => 'call-webhook', 'outcome' => 'failed', 'message' => 'Webhook answered 500'],
				['action' => 'object-op', 'outcome' => 'skipped', 'message' => 'Not run: an earlier action failed.'],
			],
			$record['steps']
		);
		$this->assertStringNotContainsString('not stored', json_encode($record));
	}//end testAManualRunWithAFailingActionIsRecordedWithTheFailure()

	/**
	 * With continueOnError the actions after a failure still ran.
	 *
	 * @return void
	 */
	public function testContinueOnErrorKeepsTheLaterActionsSucceeded(): void {
		$this->stored(automations: [self::AUTOMATION]);

		$this->log->ruleSetRan(
			ruleSetSlug: 'aut-a1b2c3d4',
			payload: ['id' => 'ticket-T-1'],
			outcome: ['triggeredRules' => ['x'], 'errors' => ['Action "send-notification" failed in rule "Nieuw ticket melden": no recipient']],
			rules: self::rules(continueOnError: true),
			actor: ''
		);

		$record = $this->saved[0][0];
		$this->assertSame(['failed', 'succeeded', 'succeeded'], array_column($record['steps'], 'outcome'));
		$this->assertSame('system', $record['actor']);
	}//end testContinueOnErrorKeepsTheLaterActionsSucceeded()

	/**
	 * A condition that did not hold is a skipped run whose reason names the condition.
	 *
	 * @return void
	 */
	public function testAFalseConditionIsASkippedRunWithItsReason(): void {
		$this->stored(automations: [self::AUTOMATION]);

		$this->log->ruleSetRan(
			ruleSetSlug: 'aut-a1b2c3d4',
			payload: ['uuid' => 'ticket-T-2', 'prioriteit' => 'laag'],
			outcome: ['triggeredRules' => [], 'errors' => []],
			rules: self::rules(),
			actor: 'maker'
		);

		$record = $this->saved[0][0];
		$this->assertSame('skipped', $record['outcome']);
		$this->assertSame('The condition was false: prioriteit == "hoog".', $record['reason']);
		$this->assertSame([], $record['steps']);
	}//end testAFalseConditionIsASkippedRunWithItsReason()

	/**
	 * A run in which every action ran succeeded, with one succeeded step per action.
	 *
	 * @return void
	 */
	public function testARunWithoutErrorsSucceeded(): void {
		$this->stored(automations: [self::AUTOMATION]);

		$this->log->ruleSetRan(
			ruleSetSlug: 'aut-a1b2c3d4',
			payload: [],
			outcome: ['triggeredRules' => ['x'], 'errors' => []],
			rules: self::rules(),
			actor: 'maker'
		);

		$record = $this->saved[0][0];
		$this->assertSame('succeeded', $record['outcome']);
		$this->assertArrayNotHasKey('reason', $record);
		$this->assertNull($record['subject']);
		$this->assertSame(['succeeded', 'succeeded', 'succeeded'], array_column($record['steps'], 'outcome'));
	}//end testARunWithoutErrorsSucceeded()

	/**
	 * A rule set that is not an automation's, or whose automation is gone, writes nothing and looks nothing up.
	 *
	 * @return void
	 */
	public function testARuleSetNoAutomationOwnsIsNotRecorded(): void {
		$this->stored(automations: [self::AUTOMATION]);

		$this->log->ruleSetRan(ruleSetSlug: 'pricing', payload: [], outcome: ['triggeredRules' => ['x'], 'errors' => []], rules: [], actor: '');
		$this->log->ruleSetRan(ruleSetSlug: 'aut-ffffffff', payload: [], outcome: ['triggeredRules' => ['x'], 'errors' => []], rules: [], actor: '');

		$this->assertSame([], $this->saved);
		$this->assertSame([], $this->errors);
	}//end testARuleSetNoAutomationOwnsIsNotRecorded()

	/**
	 * A failing lookup is logged with the rule set and never thrown.
	 *
	 * @return void
	 */
	public function testALookupFailureIsLoggedNotThrown(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('database gone'));

		$this->log->ruleSetRan(ruleSetSlug: 'aut-a1b2c3d4', payload: [], outcome: [], rules: [], actor: '');

		$this->assertSame(['Buildiq: the run of automation aut-a1b2c3d4 could not be recorded: database gone'], $this->errors);
	}//end testALookupFailureIsLoggedNotThrown()

	/**
	 * An approval asked for is a waiting run with the step "approval requested".
	 *
	 * @return void
	 */
	public function testAnApprovalRequestedIsAWaitingRun(): void {
		$this->log->approvalRequested(
			automation: self::AUTOMATION,
			trigger: 'lifecycle-transition',
			schemaSlug: 'klacht',
			objectUuid: 'C-2026-0412',
			actor: 'indiener'
		);

		$record = $this->saved[0][0];
		$this->assertSame('waiting', $record['outcome']);
		$this->assertSame('lifecycle-transition', $record['trigger']);
		$this->assertSame(['schema' => 'klacht', 'uuid' => 'C-2026-0412'], $record['subject']);
		$this->assertSame([['action' => 'approval requested', 'outcome' => 'waiting', 'message' => '']], $record['steps']);
	}//end testAnApprovalRequestedIsAWaitingRun()

	/**
	 * The decision completes the same waiting run with the decision and the follow-up, and it succeeds.
	 *
	 * @return void
	 */
	public function testTheDecisionCompletesTheSameWaitingRun(): void {
		$waiting = [
			'id' => 'run-7',
			'automation' => self::AUTOMATION['id'],
			'outcome' => 'waiting',
			'startedAt' => '2026-11-06T08:00:00+00:00',
			'subject' => ['schema' => 'klacht', 'uuid' => 'C-2026-0412'],
			'steps' => [['action' => 'approval requested', 'outcome' => 'waiting', 'message' => '']],
		];
		$this->stored(automations: [], runs: [$waiting]);
		$this->objectService->method('find')->willReturn(self::entity(payload: ['@self' => ['id' => 'run-7']] + $waiting));

		$this->log->approvalDecided(
			automation: self::AUTOMATION,
			objectUuid: 'C-2026-0412',
			approved: true,
			followUps: [['action' => 'generateDocument', 'outcome' => 'succeeded', 'message' => '']]
		);

		$this->assertCount(1, $this->saved);
		[$record, $uuid] = $this->saved[0];
		$this->assertSame('run-7', $uuid);
		$this->assertSame('succeeded', $record['outcome']);
		$this->assertSame(
			['approval requested', 'approval', 'generateDocument'],
			array_column($record['steps'], 'action')
		);
		$this->assertSame('Approved', $record['steps'][1]['message']);
	}//end testTheDecisionCompletesTheSameWaitingRun()

	/**
	 * A decision without a waiting run on that object writes a new run, failed when a follow-up failed.
	 *
	 * @return void
	 */
	public function testADecisionWithoutAWaitingRunWritesANewOne(): void {
		$other = ['id' => 'run-8', 'automation' => self::AUTOMATION['id'], 'outcome' => 'waiting', 'subject' => ['uuid' => 'other']];
		$this->stored(automations: [], runs: [$other]);

		$this->log->approvalDecided(
			automation: self::AUTOMATION,
			objectUuid: 'C-2026-0412',
			approved: false,
			followUps: [['action' => 'object-op', 'outcome' => 'failed', 'message' => 'Object is locked']]
		);

		[$record, $uuid] = $this->saved[0];
		$this->assertNull($uuid);
		$this->assertSame('failed', $record['outcome']);
		$this->assertSame('Object is locked', $record['reason']);
		$this->assertSame('Rejected', $record['steps'][0]['message']);
		$this->assertSame(['schema' => 'ticket', 'uuid' => 'C-2026-0412'], $record['subject']);
	}//end testADecisionWithoutAWaitingRunWritesANewOne()

	/**
	 * A generated document succeeded; a failed one failed with the reason.
	 *
	 * @return void
	 */
	public function testAGeneratedDocumentIsOneRun(): void {
		$this->log->documentGenerated(automation: self::AUTOMATION, trigger: 'object-created', schemaSlug: 'permit', objectUuid: 'p-1', error: null);
		$this->log->documentGenerated(
			automation: self::AUTOMATION,
			trigger: 'object-created',
			schemaSlug: 'permit',
			objectUuid: 'p-1',
			error: 'Docudesk answered 500'
		);

		$this->assertSame('succeeded', $this->saved[0][0]['outcome']);
		$this->assertSame([['action' => 'generateDocument', 'outcome' => 'succeeded', 'message' => 'Document generated']], $this->saved[0][0]['steps']);
		$this->assertSame('failed', $this->saved[1][0]['outcome']);
		$this->assertSame('Docudesk answered 500', $this->saved[1][0]['reason']);
	}//end testAGeneratedDocumentIsOneRun()

	/**
	 * The automation is found by the rule set its provenance names, with its id set.
	 *
	 * @return void
	 */
	public function testTheAutomationIsFoundByItsRuleSet(): void {
		$other = ['id' => 'b', 'provenance' => ['ruleSetSlug' => 'aut-bbbbbbbb']];
		$this->stored(automations: [$other, ['provenance' => 'broken'], self::AUTOMATION]);

		$this->assertSame(self::AUTOMATION['id'], $this->log->automationForRuleSet(ruleSetSlug: 'aut-a1b2c3d4')['id'] ?? null);
		$this->assertNull($this->log->automationForRuleSet(ruleSetSlug: 'aut-cccccccc'));
	}//end testTheAutomationIsFoundByItsRuleSet()
}//end class
