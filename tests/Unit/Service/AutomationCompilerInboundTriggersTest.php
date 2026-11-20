<?php

/**
 * Unit tests for the email and webhook triggers in AutomationCompilerService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Both inbound triggers compile through the flow backend to a manually started
 * flow, allow flow steps only, write their integriq declarations on apply after
 * a fail-closed readiness check, and lose them on remove
 * (logic-email-and-webhook-triggers D1 to D6).
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md#requirement-the-composer-offers-email-and-webhook-triggers-req-bqtr-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\AutomationFlowBackend;
use OCA\Buildiq\Service\Automation\InboundTriggerDeclarations;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\SchemaMapper;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Compile, refuse, apply and remove of the inbound triggers.
 *
 * @covers \OCA\Buildiq\Service\AutomationCompilerService
 * @uses \OCA\Buildiq\Service\Automation\AutomationFlowBuilder
 * @uses \OCA\Buildiq\Service\Automation\CompileInput
 * @uses \OCA\Buildiq\Service\Automation\InboundTriggerDeclarations
 * @uses \OCA\Buildiq\Service\Automation\RuleDecisionNodes
 * @uses \OCA\Buildiq\Service\FeelToJsonLogic
 * @uses \OCA\Buildiq\Service\FeelParser
 */
final class AutomationCompilerInboundTriggersTest extends TestCase {

	/**
	 * @var AutomationFlowBackend&MockObject
	 */
	private AutomationFlowBackend&MockObject $flowBackend;

	/**
	 * @var InboundTriggerDeclarations&MockObject
	 */
	private InboundTriggerDeclarations&MockObject $inbound;

	/**
	 * @var AutomationCompilerService
	 */
	private AutomationCompilerService $compiler;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$this->flowBackend = $this->createMock(AutomationFlowBackend::class);
		$this->flowBackend->method('scope')->willReturn(['register' => 'meldingen-production', 'schemas' => ['melding']]);
		$this->inbound = $this->createMock(InboundTriggerDeclarations::class);

		$this->compiler = new AutomationCompilerService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(SchemaMapper::class),
			$appManager,
			new NullLogger(),
			$this->flowBackend,
			null,
			null,
			$this->inbound,
		);
	}//end setUp()

	/**
	 * @param string               $type    The trigger type.
	 * @param array<string, mixed> $trigger Extra trigger settings.
	 * @param array<int, mixed>    $actions The actions.
	 *
	 * @return array<string, mixed>
	 */
	private static function automation(string $type, array $trigger = [], ?array $actions = null): array {
		return [
			'id' => 'auto-1',
			'slug' => 'meldingen-per-mail',
			'applicationSlug' => 'meldingen',
			'versionUuid' => 'version-1',
			'trigger' => array_merge(['type' => $type], $trigger),
			'actions' => ($actions ?? [['type' => 'object-op', 'operation' => 'create', 'schema' => 'melding', 'mapping' => ['title' => '{{subject}}']]]),
		];
	}//end automation()

	/**
	 * REQ-BQTR-001: both triggers compile to one flow started manually, by integriq.
	 *
	 * @return void
	 */
	public function testBothTriggersCompileToAManuallyStartedFlow(): void {
		foreach (['email-received' => ['source' => 'mailbox-1'], 'webhook-called' => ['authorization' => 'api-key']] as $type => $settings) {
			$plan = $this->compiler->compile(self::automation(type: $type, trigger: $settings));

			$this->assertSame('aut-meldingen-per-mail', $plan['flow']['name']);
			$this->assertSame(
				['id' => 'trigger', 'type' => 'openregister.trigger-manual', 'config' => ['startedBy' => 'integriq', 'trigger' => $type]],
				$plan['flow']['nodes'][0]
			);
			$this->assertSame('openregister.object-write', $plan['flow']['nodes'][1]['type']);
			$this->assertSame([], $plan['notifications']);
			$this->assertSame([], $plan['schedules']);
		}
	}//end testBothTriggersCompileToAManuallyStartedFlow()

	/**
	 * REQ-BQTR-001: a step that is not a flow step is refused on an inbound trigger.
	 *
	 * @return void
	 */
	public function testANonFlowActionIsRefused(): void {
		foreach ([['type' => 'approval', 'role' => 'admin'], ['type' => 'run-synchronization', 'synchronization' => 's-1']] as $action) {
			try {
				$this->compiler->compile(self::automation(type: 'webhook-called', actions: [$action]));
				$this->fail('A "' . $action['type'] . '" step was accepted on a webhook trigger.');
			} catch (UnsupportedAutomationCombinationException $e) {
				$this->assertStringContainsString('"' . $action['type'] . '"', $e->getMessage());
			}
		}
	}//end testANonFlowActionIsRefused()

	/**
	 * REQ-BQTR-001: a mail trigger without a mailbox does not compile.
	 *
	 * @return void
	 */
	public function testAMailTriggerWithoutAMailboxIsRefused(): void {
		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(InboundTriggerDeclarations::NO_MAILBOX_REFUSAL);

		$this->compiler->compile(self::automation(type: 'email-received'));
	}//end testAMailTriggerWithoutAMailboxIsRefused()

	/**
	 * REQ-BQTR-002: apply checks readiness first, saves the flow, then writes the
	 * declarations naming it, and records them in provenance.
	 *
	 * @return void
	 */
	public function testApplyWritesTheDeclarationsAndRecordsThem(): void {
		$automation = self::automation(type: 'email-received', trigger: ['source' => 'mailbox-1']);
		$plan = $this->compiler->compile($automation);
		$order = [];

		$this->inbound->expects($this->once())->method('assertReady')->with($automation['trigger'])
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'ready';
			});
		$this->flowBackend->expects($this->once())->method('apply')
			->willReturnCallback(static function () use (&$order): string {
				$order[] = 'flow';
				return 'flow-1';
			});
		$this->inbound->expects($this->once())->method('apply')->with($automation, 'flow-1', ['event_subscription' => 'sub-7'])
			->willReturnCallback(static function () use (&$order): array {
				$order[] = 'declarations';
				return ['event_subscription' => 'sub-7'];
			});

		$provenance = $this->compiler->apply($automation, $plan, ['flowUuid' => 'flow-1', 'inbound' => ['event_subscription' => 'sub-7']]);

		$this->assertSame(['ready', 'flow', 'declarations'], $order);
		$this->assertSame('flow-1', $provenance['flowUuid']);
		$this->assertSame(['event_subscription' => 'sub-7'], $provenance['inbound']);
	}//end testApplyWritesTheDeclarationsAndRecordsThem()

	/**
	 * D6: when integriq cannot take the trigger nothing is written, not even the flow.
	 *
	 * @return void
	 */
	public function testApplyFailsClosedBeforeAnythingIsWritten(): void {
		$automation = self::automation(type: 'webhook-called', trigger: ['authorization' => 'api-key']);
		$plan = $this->compiler->compile($automation);
		$this->inbound->method('assertReady')->willThrowException(
			new UnsupportedAutomationCombinationException(message: InboundTriggerDeclarations::NO_INTEGRIQ_REFUSAL)
		);
		$this->flowBackend->expects($this->never())->method('apply');
		$this->inbound->expects($this->never())->method('apply');

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(InboundTriggerDeclarations::NO_INTEGRIQ_REFUSAL);

		$this->compiler->apply($automation, $plan, []);
	}//end testApplyFailsClosedBeforeAnythingIsWritten()

	/**
	 * An automation that was inbound and no longer is has its declarations removed through apply.
	 *
	 * @return void
	 */
	public function testAFormerInboundAutomationClearsItsDeclarations(): void {
		$automation = self::automation(type: 'manual');
		$plan = $this->compiler->compile($automation);
		$this->flowBackend->method('apply')->willReturn('flow-1');
		$this->inbound->expects($this->never())->method('assertReady');
		$this->inbound->expects($this->once())->method('apply')->with($automation, 'flow-1', ['event_subscription' => 'sub-7'])->willReturn([]);

		$provenance = $this->compiler->apply($automation, $plan, ['inbound' => ['event_subscription' => 'sub-7']]);

		$this->assertArrayNotHasKey('inbound', $provenance);
	}//end testAFormerInboundAutomationClearsItsDeclarations()

	/**
	 * A plain automation never touches integriq.
	 *
	 * @return void
	 */
	public function testAPlainAutomationLeavesIntegriqAlone(): void {
		$automation = self::automation(type: 'manual');
		$plan = $this->compiler->compile($automation);
		$this->flowBackend->method('apply')->willReturn('flow-1');
		$this->inbound->expects($this->never())->method('apply');
		$this->inbound->expects($this->never())->method('remove');

		$this->compiler->apply($automation, $plan, []);
		$this->compiler->remove($automation, ['flowUuid' => 'flow-1']);
	}//end testAPlainAutomationLeavesIntegriqAlone()

	/**
	 * REQ-BQTR-002: remove deletes the recorded declarations with the flow.
	 *
	 * @return void
	 */
	public function testRemoveDeletesTheDeclarationsWithTheFlow(): void {
		$this->inbound->expects($this->once())->method('remove')->with(['endpoint' => 'e-1', 'rule' => 'r-1', 'consumer' => 'c-1']);
		$this->flowBackend->expects($this->once())->method('remove')->with('flow-1', 'version-1');

		$this->compiler->remove(
			self::automation(type: 'webhook-called'),
			['flowUuid' => 'flow-1', 'inbound' => ['endpoint' => 'e-1', 'rule' => 'r-1', 'consumer' => 'c-1']]
		);
	}//end testRemoveDeletesTheDeclarationsWithTheFlow()
}//end class
