<?php

/**
 * Unit tests for InboundTriggerDeclarations.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The integriq objects of the email and webhook triggers: the mail subscription
 * with its filters and flow action, paused while disabled; the webhook endpoint,
 * its flow rule and its rate-limited consumer; removal of exactly the recorded
 * objects; and the fail-closed refusals (logic-email-and-webhook-triggers D2 to D6).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-mail-starts-the-automations-flow-req-bqtr-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\InboundTriggerDeclarations;
use OCA\Buildiq\Service\Automation\IntegriqObjectStore;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Subscription, endpoint, rule, consumer, removal and refusals.
 *
 * @covers \OCA\Buildiq\Service\Automation\InboundTriggerDeclarations
 */
final class InboundTriggerDeclarationsTest extends TestCase {

	/**
	 * @var IntegriqObjectStore&MockObject
	 */
	private IntegriqObjectStore&MockObject $store;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Every save the store received, as [schema, object, uuid].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>, 2: ?string}>
	 */
	private array $saved = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = $this->createMock(IntegriqObjectStore::class);
		$this->store->method('available')->willReturn(true);
		$this->store->method('save')->willReturnCallback(
			function (string $schema, array $object, ?string $uuid): string {
				$this->saved[] = [$schema, $object, $uuid];
				return ($uuid ?? $schema . '-new');
			}
		);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('maker');
		$this->userSession->method('getUser')->willReturn($user);
	}//end setUp()

	/**
	 * @param bool $integriq Whether integriq is enabled.
	 *
	 * @return InboundTriggerDeclarations
	 */
	private function declarations(bool $integriq = true): InboundTriggerDeclarations {
		$this->appManager->method('isEnabledForUser')->with('integriq')->willReturn($integriq);
		return new InboundTriggerDeclarations(store: $this->store, appManager: $this->appManager, userSession: $this->userSession);
	}//end declarations()

	/**
	 * @param bool $enabled Whether the automation is on.
	 *
	 * @return array<string, mixed>
	 */
	private static function mailAutomation(bool $enabled = true): array {
		return [
			'slug' => 'meldingen-per-mail',
			'name' => 'Meldingen per mail',
			'applicationSlug' => 'meldingen',
			'enabled' => $enabled,
			'trigger' => ['type' => 'email-received', 'source' => 'mailbox-1', 'from' => 'burger@example.nl', 'subjectContains' => 'stoeptegel'],
			'actions' => [['type' => 'object-op', 'operation' => 'create', 'schema' => 'melding']],
		];
	}//end mailAutomation()

	/**
	 * @return array<string, mixed>
	 */
	private static function webhookAutomation(): array {
		return [
			'slug' => 'opdracht-gereed',
			'name' => 'Opdracht gereed',
			'applicationSlug' => 'planning',
			'trigger' => ['type' => 'webhook-called', 'authorization' => 'api-key'],
			'actions' => [['type' => 'object-op', 'operation' => 'update', 'schema' => 'opdracht']],
		];
	}//end webhookAutomation()

	/**
	 * REQ-BQTR-002: the mail subscription names the mailbox, the received type,
	 * the filters, the flow as action and the maker as owner.
	 *
	 * @return void
	 */
	public function testApplyWritesTheMailSubscription(): void {
		$provenance = $this->declarations()->apply(automation: self::mailAutomation(), flowUuid: 'flow-1', prior: []);

		$this->assertSame(['event_subscription' => 'event_subscription-new'], $provenance);
		$this->assertCount(1, $this->saved);
		[$schema, $object, $uuid] = $this->saved[0];
		$this->assertSame('event_subscription', $schema);
		$this->assertNull($uuid);
		$this->assertSame('mailbox-1', $object['source']);
		$this->assertSame([InboundTriggerDeclarations::MESSAGE_RECEIVED_TYPE], $object['types']);
		$this->assertSame(['kind' => 'flow', 'flowId' => 'flow-1'], $object['action']);
		$this->assertSame('maker', $object['userId']);
		$this->assertSame('active', $object['status']);
		$this->assertSame('aut-meldingen-per-mail', $object['reference']);
		$this->assertSame(
			[
				'from' => ['jsonlogic' => ['==' => [['var' => 'data.from'], 'burger@example.nl']]],
				'subject' => ['jsonlogic' => ['in' => ['stoeptegel', ['var' => 'data.subject']]]],
			],
			$object['filters']
		);
	}//end testApplyWritesTheMailSubscription()

	/**
	 * REQ-BQTR-002: without filters the subscription carries none.
	 *
	 * @return void
	 */
	public function testAMailboxWithoutFiltersWritesNoFilters(): void {
		$automation = self::mailAutomation();
		$automation['trigger'] = ['type' => 'email-received', 'source' => 'mailbox-1'];

		$this->declarations()->apply(automation: $automation, flowUuid: 'flow-1', prior: []);

		$this->assertArrayNotHasKey('filters', $this->saved[0][1]);
	}//end testAMailboxWithoutFiltersWritesNoFilters()

	/**
	 * REQ-BQTR-002: disabling pauses the same subscription, nothing is deleted.
	 *
	 * @return void
	 */
	public function testDisablePausesTheRecordedSubscription(): void {
		$this->store->expects($this->never())->method('delete');

		$provenance = $this->declarations()->apply(
			automation: self::mailAutomation(enabled: false),
			flowUuid: 'flow-1',
			prior: ['event_subscription' => 'sub-7']
		);

		$this->assertSame(['event_subscription' => 'sub-7'], $provenance);
		$this->assertSame('sub-7', $this->saved[0][2]);
		$this->assertSame('paused', $this->saved[0][1]['status']);
	}//end testDisablePausesTheRecordedSubscription()

	/**
	 * REQ-BQTR-002: remove deletes exactly the recorded objects, the endpoint first.
	 *
	 * @return void
	 */
	public function testRemoveDeletesTheRecordedObjects(): void {
		$deleted = [];
		$this->store->method('delete')->willReturnCallback(
			function (string $schema, string $uuid) use (&$deleted): bool {
				$deleted[] = $schema . ':' . $uuid;
				return true;
			}
		);

		$this->declarations()->remove(prior: ['consumer' => 'c-1', 'endpoint' => 'e-1', 'rule' => 'r-1', 'unknown' => 'x-1']);

		$this->assertSame(['endpoint:e-1', 'rule:r-1', 'consumer:c-1'], $deleted);
	}//end testRemoveDeletesTheRecordedObjects()

	/**
	 * REQ-BQTR-003: the webhook writes a rate-limited consumer, a flow rule and
	 * a POST endpoint at buildiq/<app>/<automation> running that rule. No secret is written.
	 *
	 * @return void
	 */
	public function testApplyWritesTheWebhookEndpointRuleAndConsumer(): void {
		$provenance = $this->declarations()->apply(automation: self::webhookAutomation(), flowUuid: 'flow-2', prior: []);

		$this->assertSame(['consumer' => 'consumer-new', 'rule' => 'rule-new', 'endpoint' => 'endpoint-new'], $provenance);
		$bySchema = array_column(array_map(static fn (array $s): array => ['k' => $s[0], 'v' => $s[1]], $this->saved), 'v', 'k');

		$this->assertSame('buildiq/planning/opdracht-gereed', $bySchema['endpoint']['endpoint']);
		$this->assertSame('POST', $bySchema['endpoint']['method']);
		$this->assertSame(['aut-opdracht-gereed'], $bySchema['endpoint']['rules']);
		$this->assertSame('flow', $bySchema['rule']['type']);
		$this->assertSame('aut-opdracht-gereed', $bySchema['rule']['slug']);
		$this->assertSame(['flowId' => 'flow-2'], $bySchema['rule']['configuration']);
		$this->assertSame('apikey', $bySchema['consumer']['authorizationType']);
		$this->assertSame('maker', $bySchema['consumer']['userId']);
		$this->assertSame(InboundTriggerDeclarations::RATE_LIMIT, $bySchema['consumer']['rateLimit']);
		$this->assertArrayNotHasKey('authorizationConfiguration', $bySchema['consumer']);
	}//end testApplyWritesTheWebhookEndpointRuleAndConsumer()

	/**
	 * Switching an automation from mail to webhook removes the old subscription.
	 *
	 * @return void
	 */
	public function testChangingTheTriggerRemovesTheOtherDeclarations(): void {
		$this->store->expects($this->once())->method('delete')->with('event_subscription', 'sub-7')->willReturn(true);

		$this->declarations()->apply(automation: self::webhookAutomation(), flowUuid: 'flow-2', prior: ['event_subscription' => 'sub-7']);
	}//end testChangingTheTriggerRemovesTheOtherDeclarations()

	/**
	 * An automation that is no longer inbound loses its declarations.
	 *
	 * @return void
	 */
	public function testANonInboundAutomationRemovesItsPriorDeclarations(): void {
		$this->store->expects($this->never())->method('save');
		$this->store->expects($this->once())->method('delete')->with('event_subscription', 'sub-7')->willReturn(true);

		$provenance = $this->declarations()->apply(
			automation: ['slug' => 'x', 'trigger' => ['type' => 'manual']],
			flowUuid: 'flow-1',
			prior: ['event_subscription' => 'sub-7']
		);

		$this->assertSame([], $provenance);
	}//end testANonInboundAutomationRemovesItsPriorDeclarations()

	/**
	 * D6: without integriq nothing is written and the reason is given.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsWritten(): void {
		$this->store->expects($this->never())->method('save');

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(InboundTriggerDeclarations::NO_INTEGRIQ_REFUSAL);

		$this->declarations(integriq: false)->apply(automation: self::mailAutomation(), flowUuid: 'flow-1', prior: []);
	}//end testWithoutIntegriqNothingIsWritten()

	/**
	 * D6: a write integriq refuses fails the apply.
	 *
	 * @return void
	 */
	public function testARefusedWriteFailsClosed(): void {
		$store = $this->createMock(IntegriqObjectStore::class);
		$store->method('available')->willReturn(true);
		$store->method('save')->willThrowException(new UnsupportedAutomationCombinationException(message: IntegriqObjectStore::UNAVAILABLE_REFUSAL));
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$declarations = new InboundTriggerDeclarations(store: $store, appManager: $this->appManager, userSession: $this->userSession);

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(IntegriqObjectStore::UNAVAILABLE_REFUSAL);

		$declarations->apply(automation: self::mailAutomation(), flowUuid: 'flow-1', prior: []);
	}//end testARefusedWriteFailsClosed()

	/**
	 * D5: without a signed-in maker a run would act as nobody, so nothing is written.
	 *
	 * @return void
	 */
	public function testWithoutAMakerNothingIsWritten(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		$this->store->expects($this->never())->method('save');
		$declarations = new InboundTriggerDeclarations(store: $this->store, appManager: $this->appManager, userSession: $session);

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(InboundTriggerDeclarations::NO_MAKER_REFUSAL);

		$declarations->assertReady(trigger: self::webhookAutomation()['trigger']);
	}//end testWithoutAMakerNothingIsWritten()

	/**
	 * REQ-BQTR-001: a mail trigger needs a mailbox; a webhook only an authorization integriq checks.
	 *
	 * @return void
	 */
	public function testCheckRefusesAMissingMailboxAndAnUncheckedAuthorization(): void {
		InboundTriggerDeclarations::check(trigger: ['type' => 'webhook-called']);
		InboundTriggerDeclarations::check(trigger: ['type' => 'email-received', 'source' => 'mailbox-1']);

		try {
			InboundTriggerDeclarations::check(trigger: ['type' => 'email-received', 'source' => ' ']);
			$this->fail('A mail trigger without a mailbox was accepted.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertSame(InboundTriggerDeclarations::NO_MAILBOX_REFUSAL, $e->getMessage());
		}

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(InboundTriggerDeclarations::AUTHORIZATION_REFUSAL);
		InboundTriggerDeclarations::check(trigger: ['type' => 'webhook-called', 'authorization' => 'none']);
	}//end testCheckRefusesAMissingMailboxAndAnUncheckedAuthorization()

	/**
	 * REQ-BQTR-003: the address needs both slugs.
	 *
	 * @return void
	 */
	public function testTheEndpointPathNeedsBothSlugs(): void {
		$this->assertSame('buildiq/planning/opdracht-gereed', InboundTriggerDeclarations::endpointPath(appSlug: 'planning', automationSlug: 'opdracht-gereed'));
		$this->assertTrue(InboundTriggerDeclarations::isInbound(triggerType: 'webhook-called'));
		$this->assertFalse(InboundTriggerDeclarations::isInbound(triggerType: 'manual'));

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(InboundTriggerDeclarations::NO_ADDRESS_REFUSAL);
		InboundTriggerDeclarations::endpointPath(appSlug: '', automationSlug: 'opdracht-gereed');
	}//end testTheEndpointPathNeedsBothSlugs()
}//end class
