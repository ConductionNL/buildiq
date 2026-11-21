<?php

/**
 * Unit tests for AutomationCompilerService.
 *
 * Covers REQ-AUTD-004 (one test per matrix ✅ cell, determinism, idempotent
 * recompile), REQ-AUTD-003 (unsupported cells throw naming the combination)
 * and REQ-AUTD-005 (delete removes only provenance-listed artifacts; drift
 * hash mismatch is detected).
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\Automation\AutomationFlowBackend;
use OCA\Buildiq\Service\Automation\AutomationFlowBuilder;
use OCA\Buildiq\Service\Automation\AutomationRuleSets;
use OCA\Buildiq\Service\Automation\AutomationSubFlowTargets;
use OCA\Buildiq\Service\Automation\FlowNodeAvailability;
use OCA\Buildiq\Service\AutomationCompilerService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TaskSequence;
use OCA\OpenRegister\Db\TaskSequenceMapper;
use OCA\OpenRegister\Service\ApprovalChainAnnotationInstaller;
use Psr\Container\ContainerInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use OCA\Buildiq\Tests\Support\LegacyObjectArgs;

/**
 * Tests for {@see AutomationCompilerService}.
 */
final class AutomationCompilerServiceTest extends TestCase {
	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private SchemaMapper&MockObject $schemaMapper;

	/**
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * @var ApprovalChainAnnotationInstaller&MockObject
	 */
	private ApprovalChainAnnotationInstaller&MockObject $annotationInstaller;

	/**
	 * @var TaskSequenceMapper&MockObject
	 */
	private TaskSequenceMapper&MockObject $sequenceMapper;

	/**
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * The flow backend (logic-automation-actions-that-run D2).
	 *
	 * @var AutomationFlowBackend&MockObject
	 */
	private AutomationFlowBackend&MockObject $flowBackend;

	/**
	 * The service under test.
	 *
	 * @var AutomationCompilerService
	 */
	private AutomationCompilerService $compiler;

	/**
	 * Wire the compiler with mocked OR boundaries.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->annotationInstaller = $this->createMock(ApprovalChainAnnotationInstaller::class);
		$this->sequenceMapper = $this->createMock(TaskSequenceMapper::class);
		$this->appManager = $this->createMock(IAppManager::class);
		// FleetAppId::resolve() asks isInstalled() first; without this the id
		// never resolves and every enabled check answers false.
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->appManager->method('isEnabledForUser')->willReturn(true);
		// The task-engine collaborators arrive through the container at USE
		// time, so the mock answers per class name.
		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			function (string $id): object {
				return match ($id) {
					'OCA\\OpenRegister\\Service\\ApprovalChainAnnotationInstaller' => $this->annotationInstaller,
					'OCA\\OpenRegister\\Db\\TaskSequenceMapper' => $this->sequenceMapper,
					default => $this->objectService,
				};
			}
		);

		$this->flowBackend = $this->createMock(AutomationFlowBackend::class);
		$this->flowBackend->method('scope')->willReturn(['register' => 'openbuild-claims-production', 'schemas' => ['claim', 'flag']]);

		$this->compiler = new AutomationCompilerService(
			$this->container,
			$this->objectService,
			$this->schemaMapper,
			$this->appManager,
			new NullLogger(),
			$this->flowBackend,
		);

	}//end setUp()

	/**
	 * Build a mock Schema entity carrying the given configuration, capturing
	 * every `setConfiguration()` call into `$captured` (last write wins).
	 *
	 * @param array<string,mixed> $initialConfig Initial `getConfiguration()` return.
	 * @param array<string,mixed> &$captured Reference populated on each setConfiguration() call.
	 *
	 * @return Schema&MockObject
	 */
	private function schemaWithConfig(array $initialConfig, array &$captured): Schema&MockObject {
		$schema = $this->createMock(Schema::class);
		$current = $initialConfig;
		$schema->method('getConfiguration')->willReturnCallback(static function () use (&$current) {
			return $current;
		});
		$schema->method('setConfiguration')->willReturnCallback(static function ($config) use (&$current, &$captured) {
			$current = $config;
			$captured = $config;
		});

		return $schema;
	}//end schemaWithConfig()

	/**
	 * REQ-AUTD-004 scenario 1: event notification compiles to the
	 * notifications dialect.
	 *
	 * @return void
	 */
	public function testObjectCreatedPlusNotificationCompilesToDialectEntry(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'notify-caseworkers',
			'name' => 'Notify case workers',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'condition' => null,
			'actions' => [
				[
					'type' => 'send-notification',
					'channels' => ['nc-notification'],
					'recipients' => [['kind' => 'object-acl', 'permission' => 'manage']],
					'subject' => ['en' => 'New permit', 'nl' => 'Nieuwe vergunning'],
				],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			[
				[
					'schema' => 'permit',
					'key' => 'aut-notify-caseworkers-1',
					'entry' => [
						'trigger' => ['type' => 'created'],
						'enabled' => true,
						'channels' => ['nc-notification'],
						'recipients' => [['kind' => 'object-acl', 'permission' => 'manage']],
						'subject' => ['en' => 'New permit', 'nl' => 'Nieuwe vergunning'],
					],
				],
			],
			$plan['notifications']
		);
		$this->assertSame([], $plan['lifecycleActions']);
		$this->assertSame([], $plan['schedules']);
		$this->assertNull($plan['ruleSet']);
		$this->assertStringStartsWith('sha256:', $plan['hash']);

	}//end testObjectCreatedPlusNotificationCompilesToDialectEntry()

	/**
	 * REQ-AUTD-004 scenario 2: scheduled sync compiles to a schedules entry.
	 *
	 * @return void
	 */
	public function testSchedulePlusRunSynchronizationCompilesToScheduleEntry(): void {
		$automation = [
			'id' => 'auto-2',
			'slug' => 'nightly-sync',
			'name' => 'Nightly sync',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'schedule', 'interval' => 86400],
			'condition' => null,
			'actions' => [['type' => 'run-synchronization', 'synchronizationId' => 'sync-1']],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			[
				[
					'id' => 'aut-nightly-sync-1',
					'enabled' => true,
					'action' => 'openconnector:synchronization',
					'arguments' => ['synchronizationId' => 'sync-1'],
					'interval' => 86400,
				],
			],
			$plan['schedules']
		);

	}//end testSchedulePlusRunSynchronizationCompilesToScheduleEntry()

	/**
	 * REQ-BQAR-001 scenario 3: a manual automation with a FEEL condition and a
	 * record step compiles to one flow: manual trigger, filter, object write.
	 *
	 * @return void
	 */
	public function testManualPlusConditionPlusObjectOpCompilesToFlow(): void {
		$automation = [
			'id' => '11112222-3333-4444-5555-666677778888',
			'slug' => 'flag-large-claims',
			'name' => 'Flag large claims',
			'applicationSlug' => 'claims',
			'versionUuid' => 'version-3',
			'enabled' => true,
			'trigger' => ['type' => 'manual'],
			'condition' => ['type' => 'feel', 'expression' => 'amount > 1000'],
			'actions' => [
				['type' => 'object-op', 'operation' => 'create', 'schema' => 'flag', 'fieldMapping' => ['reason' => 'large-claim']],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertNull($plan['ruleSet']);
		$this->assertNull($plan['conditionActionRule']);
		$this->assertSame('aut-flag-large-claims', $plan['flow']['name']);
		$this->assertSame(
			['openregister.trigger-manual', 'openregister.filter', 'openregister.object-write'],
			array_column($plan['flow']['nodes'], 'type')
		);
		$this->assertSame(['>' => [['var' => 'json.amount'], 1000]], $plan['flow']['nodes'][1]['config']['condition']);
		$this->assertSame(
			[
				'register' => 'openbuild-claims-production',
				'schema' => 'flag',
				'operation' => 'create',
				'fields' => ['reason' => 'large-claim'],
			],
			$plan['flow']['nodes'][2]['config']
		);

	}//end testManualPlusConditionPlusObjectOpCompilesToFlow()

	/**
	 * REQ-BQAR-001 scenario 4: a record step on a lifecycle transition is
	 * refused, and no lifecycle action is planned.
	 *
	 * @return void
	 */
	public function testLifecycleTransitionPlusObjectOpIsRefused(): void {
		$automation = [
			'id' => 'auto-4',
			'slug' => 'archive-related',
			'name' => 'Archive related',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'activate'],
			'condition' => null,
			'actions' => [
				['type' => 'object-op', 'operation' => 'update', 'schema' => 'audit-log', 'fieldMapping' => ['event' => 'activated']],
			],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(AutomationFlowBuilder::TRANSITION_REFUSAL);
		$this->compiler->compile($automation);

	}//end testLifecycleTransitionPlusObjectOpIsRefused()

	/**
	 * Compilation is deterministic: identical input compiles to an identical
	 * plan (including hash) across repeated calls.
	 *
	 * @return void
	 */
	public function testCompilationIsDeterministic(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'notify-caseworkers',
			'name' => 'Notify case workers',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'condition' => null,
			'actions' => [['type' => 'send-notification', 'subject' => ['en' => 'x']]],
		];

		$planA = $this->compiler->compile($automation);
		$planB = $this->compiler->compile($automation);

		$this->assertSame($planA, $planB);

	}//end testCompilationIsDeterministic()

	/**
	 * REQ-BQAR-003: a webhook that still carries a bare URL and no source is
	 * refused with the message the dialog shows.
	 *
	 * @return void
	 */
	public function testObjectCreatedPlusBareUrlWebhookIsRefused(): void {
		$automation = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'versionUuid' => 'version-1',
			'trigger' => ['type' => 'object-created', 'schema' => 'claim'],
			'actions' => [['type' => 'webhook', 'url' => 'https://example.test']],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage(AutomationFlowBuilder::BARE_URL_REFUSAL);
		$this->compiler->compile($automation);

	}//end testObjectCreatedPlusBareUrlWebhookIsRefused()

	/**
	 * REQ-AUTD-003: a condition on a schedule trigger is blocked.
	 *
	 * @return void
	 */
	public function testConditionOnScheduleTriggerIsBlocked(): void {
		$automation = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'schedule', 'interval' => 3600],
			'condition' => ['type' => 'feel', 'expression' => 'true'],
			'actions' => [['type' => 'run-synchronization', 'synchronizationId' => 's']],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($automation);

	}//end testConditionOnScheduleTriggerIsBlocked()

	/**
	 * automation-approval-steps REQ-AUTD-003: approval is blocked on
	 * `manual` and `schedule` triggers (D1 — only event/lifecycle-transition
	 * triggers bind to a concrete object instance).
	 *
	 * @return void
	 */
	public function testApprovalActionIsBlockedOnManualAndScheduleTriggers(): void {
		$manual = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'reviewers']],
		];

		try {
			$this->compiler->compile($manual);
			$this->fail('Expected UnsupportedAutomationCombinationException for manual + approval.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('approval', $e->getMessage());
		}

		$schedule = [
			'id' => 'auto-y',
			'slug' => 'bad2',
			'trigger' => ['type' => 'schedule', 'interval' => 3600],
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'reviewers']],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($schedule);

	}//end testApprovalActionIsBlockedOnManualAndScheduleTriggers()

	/**
	 * automation-approval-steps REQ-AUTD-004: an approval action on
	 * `object-created` compiles to a planned `ApprovalChain` (one step,
	 * `role` = the assignee group), named `aut-<slug>`.
	 *
	 * @return void
	 */
	public function testObjectCreatedPlusApprovalCompilesToApprovalChainPlan(): void {
		$automation = [
			'id' => 'auto-5',
			'slug' => 'route-permit-application-for-approval',
			'name' => 'Route permit application for approval',
			'applicationSlug' => 'vergunning-app',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'condition' => null,
			'actions' => [
				['type' => 'approval', 'assigneeGroup' => 'permit-reviewers'],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			[
				'name' => 'aut-route-permit-application-for-approval',
				'schema' => 'permit-application',
				'assigneeGroup' => 'permit-reviewers',
				'enabled' => true,
			],
			$plan['approvalChain']
		);
		$this->assertSame([], $plan['notifications']);

	}//end testObjectCreatedPlusApprovalCompilesToApprovalChainPlan()

	/**
	 * An automation on `klacht` asking the approver for these fields.
	 *
	 * @param array<int,array<string,mixed>> $fields The form fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function approvalWithForm(array $fields): array {
		return [
			'id' => 'auto-form',
			'slug' => 'klacht-ter-goedkeuring',
			'applicationSlug' => 'klachten',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-updated', 'schema' => 'klacht'],
			'condition' => null,
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'teamleiders', 'form' => ['fields' => $fields]]],
		];
	}//end approvalWithForm()

	/**
	 * Let the schema mapper answer `klacht` with these properties.
	 *
	 * @return void
	 */
	private function klachtSchema(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([
			'bedrag' => ['type' => 'number'],
			'motivering' => ['type' => 'string'],
			'zaaknummer' => ['type' => 'string', 'readOnly' => true],
			'intern' => ['type' => 'string', 'visible' => false],
			'bijlagen' => ['type' => 'array', 'items' => ['type' => 'string']],
			'melder' => ['type' => 'string', 'format' => 'uuid', '$ref' => 'persoon'],
			'adres' => ['type' => 'object'],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end klachtSchema()

	/**
	 * logic-approval-task-form REQ-BQTF-001: each field the task form cannot render is refused, naming it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
	 */
	public function testAnApprovalFormFieldTheFormCannotRenderIsRefusedByName(): void {
		$this->klachtSchema();
		$cases = [
			'onbekend' => 'it is not a property',
			'zaaknummer' => 'it is read-only',
			'intern' => 'it is hidden',
			'bijlagen' => 'it is a list',
			'melder' => 'it is a relation',
			'adres' => 'it is a relation',
		];

		foreach ($cases as $field => $reason) {
			try {
				$this->compiler->compile(self::approvalWithForm(fields: [['field' => 'bedrag', 'required' => true], ['field' => $field]]));
				$this->fail('Expected a refusal for ' . $field);
			} catch (UnsupportedAutomationCombinationException $e) {
				$this->assertSame('The approval form cannot ask for "' . $field . '": ' . $reason . ' in schema "klacht".', $e->getMessage());
			}
		}
	}//end testAnApprovalFormFieldTheFormCannotRenderIsRefusedByName()

	/**
	 * A field listed twice, or a row without a field, is refused too.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
	 */
	public function testADuplicateOrNamelessFormFieldIsRefused(): void {
		$this->klachtSchema();

		foreach ([[['field' => 'bedrag'], ['field' => 'bedrag']], [['field' => ' '], ['required' => true]]] as $fields) {
			try {
				$this->compiler->compile(self::approvalWithForm(fields: $fields));
				$this->fail('Expected a refusal');
			} catch (UnsupportedAutomationCombinationException $e) {
				$this->assertMatchesRegularExpression('/listed twice|without a name/', $e->getMessage());
			}
		}
	}//end testADuplicateOrNamelessFormFieldIsRefused()

	/**
	 * logic-approval-task-form REQ-BQTF-002: with OpenRegister carrying it, a valid form compiles onto the chain position, in order, and into the hash.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-the-form-is-compiled-into-the-approval-chain-or-refused-req-bqtf-002
	 */
	public function testAValidApprovalFormCompilesOntoTheChainPosition(): void {
		$this->assertTrue(class_exists('OCA\\OpenRegister\\Service\\Task\\ApprovalPositionForm'));
		$this->klachtSchema();

		$plan = $this->compiler->compile(self::approvalWithForm(fields: [['field' => 'bedrag', 'required' => true], ['field' => 'motivering', 'required' => false]]));
		$plain = $this->compiler->compile(self::approvalWithForm(fields: []));

		$this->assertSame(
			[
				'name' => 'aut-klacht-ter-goedkeuring',
				'schema' => 'klacht',
				'assigneeGroup' => 'teamleiders',
				'enabled' => true,
				'form' => ['kind' => 'fields', 'fields' => [['field' => 'bedrag', 'required' => true], ['field' => 'motivering', 'required' => false]]],
			],
			$plan['approvalChain']
		);
		$this->assertNotSame($plain['hash'], $plan['hash']);
	}//end testAValidApprovalFormCompilesOntoTheChainPosition()

	/**
	 * logic-approval-task-form REQ-BQTF-002: applying writes the form on the declared approver, and a form changed outside buildiq changes the live hash.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-the-form-is-compiled-into-the-approval-chain-or-refused-req-bqtf-002
	 */
	public function testTheAppliedChainCarriesTheFormAndAChangedFormShowsInTheLiveHash(): void {
		$captured = [];
		$schema = $this->schemaWithConfig([], $captured);
		$schema->method('getId')->willReturn(42);
		$schema->method('getProperties')->willReturn(['bedrag' => ['type' => 'number'], 'motivering' => ['type' => 'string']]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$automation = self::approvalWithForm(fields: [['field' => 'bedrag', 'required' => true], ['field' => 'motivering']]);

		$plan = $this->compiler->compile($automation);
		$provenance = $this->compiler->apply($automation, $plan, []);

		$this->assertSame(
			[
				'approvers' => [[
					'role' => 'teamleiders',
					'form' => ['kind' => 'fields', 'fields' => [['field' => 'bedrag', 'required' => true], ['field' => 'motivering', 'required' => false]]],
				]],
				'enabled' => true,
			],
			$captured['x-openregister-approval-chains']['aut-klacht-ter-goedkeuring']
		);

		$before = $this->compiler->status($automation, $provenance)['liveHash'];
		$this->assertSame($before, $this->compiler->status($automation, $provenance)['liveHash']);

		$edited = $captured;
		$edited['x-openregister-approval-chains']['aut-klacht-ter-goedkeuring']['approvers'][0]['form']['fields'] = [['field' => 'bedrag', 'required' => true]];
		$schema->setConfiguration($edited);

		$after = $this->compiler->status($automation, $provenance);
		$this->assertTrue($after['drift']);
		$this->assertNotSame($before, $after['liveHash']);
	}//end testTheAppliedChainCarriesTheFormAndAChangedFormShowsInTheLiveHash()

	/**
	 * An empty field list is no form: the approval compiles as before, without reading the schema.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
	 */
	public function testAnEmptyApprovalFormLeavesTheApprovalAsItWas(): void {
		$this->schemaMapper->expects($this->never())->method('find');

		$plan = $this->compiler->compile(self::approvalWithForm(fields: []));

		$this->assertSame(['name' => 'aut-klacht-ter-goedkeuring', 'schema' => 'klacht', 'assigneeGroup' => 'teamleiders', 'enabled' => true], $plan['approvalChain']);
	}//end testAnEmptyApprovalFormLeavesTheApprovalAsItWas()

	/**
	 * A trigger schema that cannot be read refuses the form rather than letting it through unchecked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
	 */
	public function testAnUnreadableSchemaRefusesTheForm(): void {
		$this->schemaMapper->method('find')->willThrowException(new \RuntimeException('gone'));

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('schema "klacht" could not be read');

		$this->compiler->compile(self::approvalWithForm(fields: [['field' => 'bedrag']]));
	}//end testAnUnreadableSchemaRefusesTheForm()

	/**
	 * automation-approval-steps REQ-AUTD-004: `lifecycle-transition` +
	 * `approval` is also supported (not just plain object events).
	 *
	 * @return void
	 */
	public function testLifecycleTransitionPlusApprovalCompilesToApprovalChainPlan(): void {
		$automation = [
			'id' => 'auto-6',
			'slug' => 'activate-needs-approval',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'activate'],
			'condition' => null,
			'actions' => [
				['type' => 'approval', 'assigneeGroup' => 'ops'],
			],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			['name' => 'aut-activate-needs-approval', 'schema' => 'permit', 'assigneeGroup' => 'ops', 'enabled' => true],
			$plan['approvalChain']
		);

	}//end testLifecycleTransitionPlusApprovalCompilesToApprovalChainPlan()

	/**
	 * automation-approval-steps task 1.2 acceptance: recompiling an
	 * unchanged automation produces a byte-identical `ApprovalChain` upsert
	 * (idempotent) — the mapper is asked to upsert with the SAME payload
	 * both times, and `provenance.approvalChainName` is stable.
	 *
	 * @return void
	 */
	public function testApplyIsIdempotentForApprovalChain(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);
		$this->schemaMapper->method('find')->willReturn($schema);

		// The chain is no longer a row: compiling writes the declaration onto the
		// schema's `x-openregister-approval-chains` configuration (openregister
		// #3302), so idempotence is now "the same configuration is written twice".
		$schema->method('getConfiguration')->willReturn([]);

		$captured = [];
		$schema->method('setConfiguration')
			->willReturnCallback(function (array $config) use (&$captured): void {
				$captured[] = ($config['x-openregister-approval-chains'] ?? []);
			});
		$this->schemaMapper->expects($this->exactly(2))->method('update');

		$automation = [
			'id' => 'auto-5',
			'slug' => 'route-permit-application-for-approval',
			'applicationSlug' => 'vergunning-app',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit-application'],
			'condition' => null,
			'actions' => [['type' => 'approval', 'assigneeGroup' => 'permit-reviewers']],
		];

		$plan = $this->compiler->compile($automation);
		$provenanceA = $this->compiler->apply($automation, $plan, []);
		$provenanceB = $this->compiler->apply($automation, $plan, $provenanceA);

		$this->assertSame('aut-route-permit-application-for-approval', $provenanceA['approvalChainName']);
		$this->assertSame($provenanceA['approvalChainName'], $provenanceB['approvalChainName']);
		$this->assertSame($captured[0], $captured[1]);

	}//end testApplyIsIdempotentForApprovalChain()

	/**
	 * automation-approval-steps REQ-AUTD-007: `approvalState()` returns
	 * `pending` for the most-recently-created step on the compiled chain.
	 *
	 * @return void
	 */
	public function testApprovalStateReportsTheNewestSequenceStatus(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);
		$schema->method('getConfiguration')->willReturn([
			'x-openregister-approval-chains' => [
				'aut-route-permit-application-for-approval' => [
					'approvers' => [['role' => 'permit-reviewers']],
				],
			],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);

		// Restored by openregister#3360, which added the template-wide finder
		// this aggregate needs — it reports a template's last run, so it has no
		// anchor object to ask about and the per-anchor finders could not serve it.
		$this->annotationInstaller->method('templateIdFor')->willReturn('tpl-permit-7');
		$sequence = new TaskSequence();
		$sequence->setStatus('running');
		$this->sequenceMapper->method('findNewestForTemplate')->with('tpl-permit-7')->willReturn($sequence);

		$automation = ['trigger' => ['type' => 'object-created', 'schema' => 'permit-application']];
		$provenance = ['approvalChainName' => 'aut-route-permit-application-for-approval'];

		self::assertSame('pending', $this->compiler->approvalState($automation, $provenance));

	}//end testApprovalStateReportsTheNewestSequenceStatus()

	/**
	 * A terminated sequence reads as `none`, not `rejected`.
	 *
	 * Terminated means the sequence was closed without anyone deciding. Folding
	 * it onto `rejected` would report an outcome nobody chose, on a panel whose
	 * whole job is to say what happened.
	 *
	 * @return void
	 */
	public function testApprovalStateReportsATerminatedSequenceAsNone(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getId')->willReturn(42);
		$schema->method('getConfiguration')->willReturn([
			'x-openregister-approval-chains' => [
				'aut-x' => ['approvers' => [['role' => 'reviewers']]],
			],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->annotationInstaller->method('templateIdFor')->willReturn('tpl-x');

		$sequence = new TaskSequence();
		$sequence->setStatus('terminated');
		$this->sequenceMapper->method('findNewestForTemplate')->willReturn($sequence);

		self::assertSame('none', $this->compiler->approvalState(
			['trigger' => ['type' => 'object-created', 'schema' => 'permit-application']],
			['approvalChainName' => 'aut-x']
		));

	}//end testApprovalStateReportsATerminatedSequenceAsNone()

	/**
	 * `approvalState()` returns `none` when no chain was ever compiled.
	 *
	 * @return void
	 */
	public function testApprovalStateReturnsNoneWhenNeverCompiled(): void {
		$this->assertSame('none', $this->compiler->approvalState(['trigger' => ['type' => 'object-created']], []));

	}//end testApprovalStateReturnsNoneWhenNeverCompiled()

	/**
	 * `mapActionToRuleAction()` maps an `approval` action to a typed
	 * `approval` rule action (used by `compileDryRunRule()` AND by
	 * `ApprovalOutcomeListener` to map on-approve/on-reject follow-ups).
	 *
	 * @return void
	 */
	public function testMapActionToRuleActionMapsApproval(): void {
		$mapped = $this->compiler->mapActionToRuleAction(['type' => 'approval', 'assigneeGroup' => 'reviewers']);

		$this->assertSame(
			['type' => 'approval', 'parameters' => ['assigneeGroup' => 'reviewers']],
			$mapped
		);

	}//end testMapActionToRuleActionMapsApproval()

	/**
	 * Documented deviation: manual + run-synchronization is blocked (no
	 * verified OpenConnector "run now" primitive exists — see class docblock).
	 *
	 * @return void
	 */
	public function testManualPlusRunSynchronizationIsBlocked(): void {
		$automation = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'run-synchronization', 'synchronizationId' => 's']],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($automation);

	}//end testManualPlusRunSynchronizationIsBlocked()

	/**
	 * Delete removes exactly the provenance-listed notification key; a
	 * hand-authored (non-`aut-`) key on the same schema survives.
	 *
	 * @return void
	 */
	public function testRemoveDeletesOnlyProvenanceListedNotificationKey(): void {
		$captured = [];
		$schema = $this->schemaWithConfig(
			[
				'x-openregister-notifications' => [
					'aut-notify-caseworkers-1' => ['trigger' => ['type' => 'created']],
					'hand-authored-alert' => ['trigger' => ['type' => 'created']],
				],
			],
			$captured
		);

		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->expects($this->once())->method('update');

		$automation = ['slug' => 'notify-caseworkers', 'versionUuid' => 'version-1'];
		$provenance = [
			'notificationKeys' => [['schema' => 'permit', 'key' => 'aut-notify-caseworkers-1']],
			'lifecycleActions' => [],
			'scheduleIds' => [],
			'ruleSetSlug' => null,
		];

		$this->compiler->remove($automation, $provenance);

		$this->assertArrayNotHasKey('aut-notify-caseworkers-1', $captured['x-openregister-notifications']);
		$this->assertArrayHasKey('hand-authored-alert', $captured['x-openregister-notifications']);

	}//end testRemoveDeletesOnlyProvenanceListedNotificationKey()

	/**
	 * Drift: a live artifact hash that no longer matches the stamped
	 * `provenance.compiledHash` is reported as drift.
	 *
	 * @return void
	 */
	public function testStatusDetectsDriftOnHashMismatch(): void {
		$captured = [];
		$schema = $this->schemaWithConfig(
			[
				'x-openregister-notifications' => [
					'aut-notify-caseworkers-1' => ['trigger' => ['type' => 'created'], 'enabled' => false],
				],
			],
			$captured
		);

		$this->schemaMapper->method('find')->willReturn($schema);

		$automation = ['slug' => 'notify-caseworkers', 'versionUuid' => 'version-1'];
		$provenance = [
			'notificationKeys' => [['schema' => 'permit', 'key' => 'aut-notify-caseworkers-1']],
			'lifecycleActions' => [],
			'scheduleIds' => [],
			'ruleSetSlug' => null,
			// A hash that does not match the live (hand-edited) artifact above.
			'compiledHash' => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
		];

		$status = $this->compiler->status($automation, $provenance);

		$this->assertTrue($status['drift']);

	}//end testStatusDetectsDriftOnHashMismatch()

	/**
	 * No drift when nothing was ever compiled (empty provenance).
	 *
	 * @return void
	 */
	public function testStatusNoDriftWhenNeverCompiled(): void {
		$status = $this->compiler->status(['slug' => 'x'], []);
		$this->assertFalse($status['drift']);

	}//end testStatusNoDriftWhenNeverCompiled()

	/**
	 * Idempotent recompile: applying the SAME plan twice against the SAME
	 * prior provenance produces the same resulting provenance (upsert, not
	 * duplicate).
	 *
	 * @return void
	 */
	public function testApplyIsIdempotentOnUnchangedPlan(): void {
		$captured = [];
		$schema = $this->schemaWithConfig([], $captured);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->schemaMapper->method('update')->willReturn($schema);

		$automation = [
			'id' => 'auto-1',
			'slug' => 'notify-caseworkers',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'condition' => null,
			'actions' => [['type' => 'send-notification', 'subject' => ['en' => 'x']]],
		];

		$plan = $this->compiler->compile($automation);

		$provenanceA = $this->compiler->apply($automation, $plan, []);
		$provenanceB = $this->compiler->apply($automation, $plan, $provenanceA);

		$this->assertSame($provenanceA['notificationKeys'], $provenanceB['notificationKeys']);
		$this->assertSame($provenanceA['compiledHash'], $provenanceB['compiledHash']);
		$this->assertCount(1, $captured['x-openregister-notifications']);

	}//end testApplyIsIdempotentOnUnchangedPlan()

	/**
	 * automation-document-action REQ-AUTD-004: `generateDocument` is
	 * supported on every event/lifecycle-transition trigger and compiles
	 * (no exception, empty plan side effects — no compile-time upsert).
	 *
	 * @return void
	 */
	public function testGenerateDocumentIsSupportedOnEventAndLifecycleTransitionTriggers(): void {
		foreach (['object-created', 'object-updated', 'object-deleted'] as $triggerType) {
			$automation = [
				'id' => 'auto-' . $triggerType,
				'slug' => 'gen-' . $triggerType,
				'trigger' => ['type' => $triggerType, 'schema' => 'permit'],
				'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
			];

			$plan = $this->compiler->compile($automation);
			$this->assertNull($plan['approvalChain']);
			$this->assertSame([], $plan['lifecycleActions']);
		}

		$lifecycle = [
			'id' => 'auto-lifecycle',
			'slug' => 'gen-lifecycle',
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'approve'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$plan = $this->compiler->compile($lifecycle);
		$this->assertSame([], $plan['lifecycleActions']);

	}//end testGenerateDocumentIsSupportedOnEventAndLifecycleTransitionTriggers()

	/**
	 * automation-document-action REQ-AUTD-003: `generateDocument` is
	 * blocked fail-closed on `manual` and `schedule` triggers.
	 *
	 * @return void
	 */
	public function testGenerateDocumentIsBlockedOnManualAndScheduleTriggers(): void {
		$manual = [
			'id' => 'auto-x',
			'slug' => 'bad',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		try {
			$this->compiler->compile($manual);
			$this->fail('Expected UnsupportedAutomationCombinationException for manual + generateDocument.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('generateDocument', $e->getMessage());
		}

		$schedule = [
			'id' => 'auto-y',
			'slug' => 'bad2',
			'trigger' => ['type' => 'schedule', 'interval' => 3600],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($schedule);

	}//end testGenerateDocumentIsBlockedOnManualAndScheduleTriggers()

	/**
	 * automation-document-action task 1.2: a `generateDocument` action
	 * missing `templateId` is rejected at compile time.
	 *
	 * @return void
	 */
	public function testGenerateDocumentMissingTemplateIdIsRejected(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'gen-missing-template',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'generateDocument', 'output' => ['attach']]],
		];

		try {
			$this->compiler->compile($automation);
			$this->fail('Expected UnsupportedAutomationCombinationException for a missing templateId.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('templateId', $e->getMessage());
		}

	}//end testGenerateDocumentMissingTemplateIdIsRejected()

	/**
	 * automation-document-action task 1.2 / design.md Decision 3:
	 * `notify`-only (no `attach`/`download-link`) is rejected as incomplete.
	 *
	 * @return void
	 */
	public function testGenerateDocumentNotifyOnlyIsRejected(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'gen-notify-only',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['notify']]],
		];

		try {
			$this->compiler->compile($automation);
			$this->fail('Expected UnsupportedAutomationCombinationException for notify-only output.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('notify', $e->getMessage());
		}

	}//end testGenerateDocumentNotifyOnlyIsRejected()

	/**
	 * automation-document-action task 1.2: an unknown/empty `output` is
	 * rejected.
	 *
	 * @return void
	 */
	public function testGenerateDocumentUnknownOutputIsRejected(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'gen-bad-output',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['bogus']]],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->compiler->compile($automation);

	}//end testGenerateDocumentUnknownOutputIsRejected()

	/**
	 * automation-document-action task 1.3 / D2 of design.md: missing
	 * Docudesk fails the COMPILE, not the runtime.
	 *
	 * @return void
	 */
	public function testGenerateDocumentMissingDocudeskFailsCompile(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$compiler = new AutomationCompilerService(
			$this->container,
			$this->objectService,
			$this->schemaMapper,
			$this->appManager,
			new NullLogger(),
		);

		$automation = [
			'id' => 'auto-1',
			'slug' => 'gen-no-docudesk',
			'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
			'actions' => [['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach']]],
		];

		try {
			$compiler->compile($automation);
			$this->fail('Expected UnsupportedAutomationCombinationException naming the missing docudesk dependency.');
		} catch (UnsupportedAutomationCombinationException $e) {
			$this->assertStringContainsString('filinq', $e->getMessage());
		}

	}//end testGenerateDocumentMissingDocudeskFailsCompile()

	/**
	 * `mapActionToRuleAction()` maps a `generateDocument` action to a typed
	 * rule action (dry-run panel traceability).
	 *
	 * @return void
	 */
	public function testMapActionToRuleActionMapsGenerateDocument(): void {
		$mapped = $this->compiler->mapActionToRuleAction(
			['type' => 'generateDocument', 'templateId' => 'tpl-1', 'output' => ['attach', 'notify']]
		);

		$this->assertSame(
			['type' => 'generateDocument', 'parameters' => ['templateId' => 'tpl-1', 'output' => ['attach', 'notify']]],
			$mapped
		);

	}//end testMapActionToRuleActionMapsGenerateDocument()

	/**
	 * Serve one ApplicationVersion and capture what is saved for it.
	 *
	 * @param array<string, mixed>          $version The stored version.
	 * @param array<int, array<string, mixed>> $saved   Receives the saved objects.
	 *
	 * @return void
	 */
	private function serveVersion(array $version, array &$saved): void {
		$entity = $this->createMock(\OCA\OpenRegister\Contract\ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($version);
		$entity->method('getObject')->willReturn($version);
		$this->objectService->method('find')->willReturnCallback(
			static fn (...$args) => (($args[0] ?? null) === 'version-1' ? $entity : null)
		);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (...$args) use (&$saved, $entity) {
				$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);
				$saved[] = $args[0];
				return $entity;
			}
		);
	}//end serveVersion()

	/**
	 * Applying a schedule plan replaces the entry with the same id, keeps
	 * unrelated entries, drops a prior-provenance id no longer planned, and
	 * records the planned ids as provenance.
	 *
	 * @return void
	 */
	public function testApplyUpsertsScheduleEntriesIntoTheVersionManifest(): void {
		$saved = [];
		$this->serveVersion(
			[
				'id' => 'version-1',
				'manifest' => [
					'pages' => ['p'],
					'schedules' => [
						['id' => 'other', 'interval' => 60],
						['id' => 'aut-nightly-sync-1', 'interval' => 1],
						['id' => 'aut-nightly-sync-old', 'interval' => 2],
						'not-a-row',
					],
				],
			],
			$saved
		);
		$automation = [
			'id' => 'auto-2',
			'slug' => 'nightly-sync',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'schedule', 'interval' => 86400],
			'condition' => null,
			'actions' => [['type' => 'run-synchronization', 'synchronizationId' => 'sync-1']],
		];
		$plan = $this->compiler->compile($automation);

		$provenance = $this->compiler->apply($automation, $plan, ['scheduleIds' => ['aut-nightly-sync-1', 'aut-nightly-sync-old']]);

		$this->assertSame(['aut-nightly-sync-1'], $provenance['scheduleIds']);
		$this->assertCount(1, $saved);
		$this->assertSame(['p'], $saved[0]['manifest']['pages']);
		$this->assertSame(
			[
				['id' => 'other', 'interval' => 60],
				$plan['schedules'][0],
				'not-a-row',
			],
			$saved[0]['manifest']['schedules']
		);
	}//end testApplyUpsertsScheduleEntriesIntoTheVersionManifest()

	/**
	 * Removing an automation drops its schedule entries and the schedules key
	 * when nothing is left; an unknown version is warned about and nothing is saved.
	 *
	 * @return void
	 */
	public function testRemoveDropsScheduleEntriesAndAnEmptySchedulesKey(): void {
		$saved = [];
		$this->serveVersion(['id' => 'version-1', 'manifest' => ['schedules' => [['id' => 'aut-x-1']]]], $saved);

		$this->compiler->remove(['slug' => 'x', 'versionUuid' => 'version-1'], ['scheduleIds' => ['aut-x-1']]);
		$this->compiler->remove(['slug' => 'x', 'versionUuid' => 'version-9'], ['scheduleIds' => ['aut-x-1']]);
		$this->compiler->remove(['slug' => 'x', 'versionUuid' => 'version-1'], []);

		$this->assertCount(1, $saved);
		$this->assertSame(['id' => 'version-1', 'manifest' => []], $saved[0]);
	}//end testRemoveDropsScheduleEntriesAndAnEmptySchedulesKey()
	/**
	 * A manual automation with a webhook through a source.
	 *
	 * @param bool $enabled Whether it is enabled.
	 *
	 * @return array<string,mixed>
	 */
	private function manualWebhookAutomation(bool $enabled = true): array {
		return [
			'id' => 'auto-flow',
			'slug' => 'send-claim',
			'name' => 'Send claim',
			'versionUuid' => 'version-3',
			'enabled' => $enabled,
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'webhook', 'source' => 'crm', 'path' => '/claims', 'payloadTemplate' => ['id' => '{{id}}']]],
		];
	}//end manualWebhookAutomation()

	/**
	 * REQ-BQAR-001 / D2: apply saves the flow through the backend with the
	 * automation's enabled state and names it in `provenance.flowUuid`.
	 *
	 * @return void
	 */
	public function testApplySavesTheFlowAndRecordsItsUuid(): void {
		$automation = $this->manualWebhookAutomation();
		$plan = $this->compiler->compile($automation);

		$this->flowBackend->expects($this->once())
			->method('apply')
			->with($plan['flow'], null, true, 'version-3')
			->willReturn('0b6a7c1e-1111-4222-8333-944445555666');
		$this->flowBackend->expects($this->never())->method('remove');

		$provenance = $this->compiler->apply($automation, $plan, []);

		$this->assertSame('0b6a7c1e-1111-4222-8333-944445555666', $provenance['flowUuid']);
		$this->assertSame($plan['hash'], $provenance['compiledHash']);
		$this->assertArrayNotHasKey('ruleSetSlug', $provenance);
		$this->assertSame('openconnector.source-call', $plan['flow']['nodes'][1]['type']);
		$this->assertSame(['source' => 'crm', 'endpoint' => '/claims', 'method' => 'POST', 'body' => ['id' => '{{id}}']], $plan['flow']['nodes'][1]['config']);

	}//end testApplySavesTheFlowAndRecordsItsUuid()

	/**
	 * REQ-BQAR-001: recompiling an unchanged automation gives the same flow and
	 * hash, and apply passes the prior flow so it is updated, not duplicated.
	 *
	 * @return void
	 */
	public function testRecompileIsIdempotentAndReusesThePriorFlow(): void {
		$automation = $this->manualWebhookAutomation();
		$first = $this->compiler->compile($automation);
		$second = $this->compiler->compile($automation);

		$this->assertSame($first, $second);

		$this->flowBackend->expects($this->once())
			->method('apply')
			->with($second['flow'], 'flow-1', true, 'version-3')
			->willReturn('flow-1');

		$provenance = $this->compiler->apply($automation, $second, ['flowUuid' => 'flow-1', 'compiledHash' => $first['hash']]);

		$this->assertSame('flow-1', $provenance['flowUuid']);
		$this->assertSame($first['hash'], $provenance['compiledHash']);

	}//end testRecompileIsIdempotentAndReusesThePriorFlow()

	/**
	 * D2: disabling the automation passes enabled=false to the flow, and the
	 * hash does not change with it.
	 *
	 * @return void
	 */
	public function testDisableSwitchesTheFlowOff(): void {
		$enabledPlan = $this->compiler->compile($this->manualWebhookAutomation());
		$automation = $this->manualWebhookAutomation(enabled: false);
		$plan = $this->compiler->compile($automation);

		$this->assertSame($enabledPlan['hash'], $plan['hash']);

		$this->flowBackend->expects($this->once())
			->method('apply')
			->with($plan['flow'], 'flow-1', false, 'version-3')
			->willReturn('flow-1');

		$this->compiler->apply($automation, $plan, ['flowUuid' => 'flow-1']);

	}//end testDisableSwitchesTheFlowOff()

	/**
	 * D2: remove() deletes and unbinds the provenance-listed flow.
	 *
	 * @return void
	 */
	public function testRemoveDeletesTheFlow(): void {
		$this->flowBackend->expects($this->once())->method('remove')->with('flow-1', 'version-3');

		$this->compiler->remove($this->manualWebhookAutomation(), ['flowUuid' => 'flow-1']);

	}//end testRemoveDeletesTheFlow()

	/**
	 * D2: an automation that no longer compiles to a flow loses its prior one.
	 *
	 * @return void
	 */
	public function testApplyWithoutAFlowRemovesThePriorFlow(): void {
		$automation = [
			'id' => 'auto-1',
			'slug' => 'notify-caseworkers',
			'versionUuid' => 'version-3',
			'trigger' => ['type' => 'schedule', 'interval' => 86400],
			'actions' => [],
		];
		$plan = ['notifications' => [], 'lifecycleActions' => [], 'schedules' => [], 'ruleSet' => null, 'conditionActionRule' => null, 'approvalChain' => null, 'hash' => 'sha256:x'];

		$this->flowBackend->expects($this->never())->method('apply');
		$this->flowBackend->expects($this->once())->method('remove')->with('flow-1', 'version-3');

		$provenance = $this->compiler->apply($automation, $plan, ['flowUuid' => 'flow-1']);

		$this->assertArrayNotHasKey('flowUuid', $provenance);

	}//end testApplyWithoutAFlowRemovesThePriorFlow()

	/**
	 * D1: an approval cannot share an automation with a flow step, and the
	 * refusal names the approval.
	 *
	 * @return void
	 */
	public function testApprovalBesideARecordStepIsRefused(): void {
		$automation = $this->manualWebhookAutomation();
		$automation['trigger'] = ['type' => 'object-created', 'schema' => 'claim'];
		$automation['actions'][] = ['type' => 'approval', 'assignee' => 'reviewers'];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessageMatches('/"approval" step cannot share an automation/');
		$this->compiler->compile($automation);

	}//end testApprovalBesideARecordStepIsRefused()

	/**
	 * REQ-BQAR-002: a record step writing to a schema outside the app version
	 * is refused at compile.
	 *
	 * @return void
	 */
	public function testRecordStepOnAForeignSchemaIsRefused(): void {
		$automation = [
			'id' => 'auto-2',
			'slug' => 'copy-out',
			'versionUuid' => 'version-3',
			'trigger' => ['type' => 'object-created', 'schema' => 'claim'],
			'actions' => [['type' => 'object-op', 'operation' => 'create', 'schema' => 'payroll', 'fieldMapping' => []]],
		];

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('The record step writes to "payroll", which is not a schema of this app.');
		$this->compiler->compile($automation);

	}//end testRecordStepOnAForeignSchemaIsRefused()

	/**
	 * REQ-BQAR-002: a record step on object created writes into the version's
	 * register, triggered by that schema's created event.
	 *
	 * @return void
	 */
	public function testObjectCreatedRecordStepCompilesToATriggeredFlow(): void {
		$automation = [
			'id' => 'auto-3',
			'slug' => 'flag-new',
			'versionUuid' => 'version-3',
			'trigger' => ['type' => 'object-created', 'schema' => 'claim'],
			'actions' => [['type' => 'object-op', 'operation' => 'update', 'schema' => 'claim', 'match' => ['id' => '{{id}}'], 'fieldMapping' => ['flagged' => true]]],
		];

		$plan = $this->compiler->compile($automation);

		$this->assertSame(
			['id' => 'trigger', 'type' => 'openregister.trigger-object', 'config' => ['event' => 'created', 'register' => 'openbuild-claims-production', 'schema' => 'claim']],
			$plan['flow']['nodes'][0]
		);
		$this->assertSame(['id' => '{{json.id}}'], $plan['flow']['nodes'][1]['config']['match']);
		$this->assertSame([], $plan['notifications']);

	}//end testObjectCreatedRecordStepCompilesToATriggeredFlow()

	/**
	 * Drift: status() reads the live flow when provenance names one, and a
	 * flow that is gone reads as drift.
	 *
	 * @return void
	 */
	public function testStatusReadsTheLiveFlow(): void {
		$automation = $this->manualWebhookAutomation();
		$plan = $this->compiler->compile($automation);

		$this->flowBackend->method('live')->willReturnOnConsecutiveCalls($plan['flow'], null);
		$provenance = ['flowUuid' => 'flow-1', 'compiledHash' => $plan['hash']];

		$this->assertFalse($this->compiler->status($automation, $provenance)['drift']);
		$this->assertTrue($this->compiler->status($automation, $provenance)['drift']);

	}//end testStatusReadsTheLiveFlow()
	/**
	 * REQ-BQAR-005: a rule decision compiles through the rule set the backend
	 * resolves, and provenance names the version the flow runs.
	 *
	 * @return void
	 */
	public function testRuleDecisionRecordsTheRuleSetVersion(): void {
		$ruleSets = $this->createMock(AutomationRuleSets::class);
		$ruleSets->expects($this->once())->method('ruleSets')->with(['leges-categorie'])->willReturn(
			['leges-categorie' => ['ruleType' => 'decision-table', 'version' => '1.0.1', 'tableRef' => 'buildiq.leges-categorie', 'outputs' => ['categorie']]]
		);
		$this->flowBackend->method('apply')->willReturn('flow-9');
		$compiler = new AutomationCompilerService(
			$this->container,
			$this->objectService,
			$this->schemaMapper,
			$this->appManager,
			new NullLogger(),
			$this->flowBackend,
			null,
			$ruleSets,
		);
		$automation = [
			'id' => 'auto-9',
			'slug' => 'leges',
			'versionUuid' => 'version-3',
			'trigger' => ['type' => 'object-created', 'schema' => 'claim'],
			'actions' => [['type' => 'rule-decision', 'ruleSetSlug' => 'leges-categorie', 'outputField' => 'categorie']],
		];

		$plan = $compiler->compile($automation);
		$provenance = $compiler->apply($automation, $plan, []);

		$this->assertSame('openregister.decision-table', $plan['flow']['nodes'][1]['type']);
		$this->assertSame([['slug' => 'leges-categorie', 'version' => '1.0.1']], $provenance['ruleSetVersions']);
		$this->assertSame('flow-9', $provenance['flowUuid']);

	}//end testRuleDecisionRecordsTheRuleSetVersion()

	/**
	 * Let the schema mapper answer `claim` with a `categorie` choice list.
	 *
	 * @return void
	 */
	private function claimWithCategories(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn([
			'omschrijving' => ['type' => 'string'],
			'toelichting' => ['type' => 'string'],
			'categorie' => ['type' => 'string', 'enum' => ['afval', 'verlichting', 'overig']],
			'samenvatting' => ['type' => 'string', 'readOnly' => true],
		]);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end claimWithCategories()

	/**
	 * An automation with one AI step.
	 *
	 * @param string               $trigger The trigger type.
	 * @param array<string, mixed> $step    What differs from the classify step.
	 *
	 * @return array<string, mixed>
	 */
	private static function aiAutomation(string $trigger, array $step = []): array {
		return [
			'id' => 'auto-ai',
			'slug' => 'categorise',
			'versionUuid' => 'version-3',
			'trigger' => ['type' => $trigger, 'schema' => 'claim'],
			'actions' => [
				array_merge(
					[
						'type' => 'ai-step',
						'preset' => 'classify',
						'agent' => 'agent-1',
						'sourceFields' => ['omschrijving'],
						'targetField' => 'categorie',
						'labels' => ['afval', 'verlichting', 'overig'],
					],
					$step
				),
			],
		];
	}//end aiAutomation()

	/**
	 * A compiler whose container serves OpenRegister's node registry with these node types.
	 *
	 * @param array<int, string> $types The registered node types.
	 *
	 * @return AutomationCompilerService
	 */
	private function compilerWithNodes(array $types): AutomationCompilerService {
		$registry = new class ($types) {
			/**
			 * @param array<int, string> $types The registered types.
			 */
			public function __construct(private readonly array $types) {
			}

			/**
			 * @param string $type The type.
			 *
			 * @return object
			 */
			public function get(string $type): object {
				if (in_array($type, $this->types, true) === false) {
					throw new \UnexpectedValueException('Unknown node type "' . $type . '".');
				}

				return new \stdClass();
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				FlowNodeAvailability::REGISTRY => $registry,
				default => $this->container->get($id),
			}
		);

		// The compiler builds its FlowNodeAvailability on this container.
		return new AutomationCompilerService(
			$container,
			$this->objectService,
			$this->schemaMapper,
			$this->appManager,
			new NullLogger(),
			$this->flowBackend,
		);
	}//end compilerWithNodes()

	/**
	 * REQ-BQLS-002: a classify step on object created compiles to the object
	 * trigger, Hermiq's agent step and a write of the label onto the record.
	 *
	 * @return void
	 */
	public function testAiClassifyCompilesToTriggerAgentAndWrite(): void {
		$this->claimWithCategories();

		$plan = $this->compiler->compile(self::aiAutomation(trigger: 'object-created'));
		$nodes = $plan['flow']['nodes'];

		$this->assertSame(['openregister.trigger-object', 'hermiq.agent-step', 'openregister.object-write'], array_column($nodes, 'type'));
		$this->assertSame('created', $nodes[0]['config']['event']);
		$this->assertSame('agent-1', $nodes[1]['config']['agentId']);
		$this->assertTrue($nodes[1]['config']['expectJson']);
		preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $nodes[1]['config']['prompt'], $placeholders);
		$this->assertSame(['omschrijving'], array_values(array_unique($placeholders[1])));
		$this->assertSame('update', $nodes[2]['config']['operation']);
		$this->assertSame(['categorie' => '{{json.aiAnswer.label}}'], $nodes[2]['config']['fields']);
		$this->assertSame(['uuid' => '{{json.uuid}}'], $nodes[2]['config']['match']);
		$this->assertSame(['id' => 'e2', 'from' => ['step-1'], 'to' => ['step-1-write']], $plan['flow']['edges'][1]);
		$this->assertSame($plan['hash'], $this->compiler->compile(self::aiAutomation(trigger: 'object-created'))['hash']);
	}//end testAiClassifyCompilesToTriggerAgentAndWrite()

	/**
	 * REQ-BQLS-002: each preset compiles with a prompt that reads its source fields only.
	 *
	 * @return void
	 */
	public function testEveryAiPresetCompilesWithItsSourcesOnly(): void {
		$this->claimWithCategories();
		$presets = [
			'summarise' => ['preset' => 'summarise', 'targetField' => 'samenvatting'],
			'translate' => ['preset' => 'translate', 'targetField' => 'samenvatting', 'language' => 'English'],
			'extract' => ['preset' => 'extract', 'fields' => ['samenvatting']],
			'prompt' => ['preset' => 'prompt', 'targetField' => 'samenvatting', 'prompt' => 'Explain {{toelichting}} simply.'],
		];

		foreach ($presets as $name => $step) {
			$step['sourceFields'] = ['omschrijving', 'toelichting'];
			$nodes = $this->compiler->compile(self::aiAutomation(trigger: 'manual', step: $step))['flow']['nodes'];

			$this->assertSame('openregister.trigger-manual', $nodes[0]['type'], $name);
			$this->assertSame('hermiq.agent-step', $nodes[1]['type'], $name);
			preg_match_all('/\{\{\s*([^}]*?)\s*\}\}/', $nodes[1]['config']['prompt'], $placeholders);
			$read = array_values(array_unique($placeholders[1]));
			sort($read);
			$this->assertSame(['omschrijving', 'toelichting'], $read, $name);
			$this->assertSame(['samenvatting'], array_keys($nodes[2]['config']['fields']), $name);
		}
	}//end testEveryAiPresetCompilesWithItsSourcesOnly()

	/**
	 * REQ-BQLS-003: a classify step onto a plain string field is refused at save.
	 *
	 * @return void
	 */
	public function testAiClassifyOntoAPlainFieldIsRefused(): void {
		$this->claimWithCategories();

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('Make samenvatting a choice list of your labels first.');

		$this->compiler->compile(self::aiAutomation(trigger: 'object-created', step: ['targetField' => 'samenvatting']));
	}//end testAiClassifyOntoAPlainFieldIsRefused()

	/**
	 * An AI step on a deletion is refused: there is no record left to write to.
	 *
	 * @return void
	 */
	public function testAiStepOnADeletionIsRefused(): void {
		$this->claimWithCategories();

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('not on "object-deleted"');

		$this->compiler->compile(self::aiAutomation(trigger: 'object-deleted'));
	}//end testAiStepOnADeletionIsRefused()

	/**
	 * REQ-BQLS-005: without Hermiq's node in the engine apply refuses and no flow is saved.
	 *
	 * @return void
	 */
	public function testApplyRefusesAnAiStepWithoutTheHermiqNode(): void {
		$this->claimWithCategories();
		$compiler = $this->compilerWithNodes(types: ['openregister.object-write']);
		$plan = $compiler->compile(self::aiAutomation(trigger: 'object-created'));

		$this->flowBackend->expects($this->never())->method('apply');
		$this->objectService->expects($this->never())->method('saveObject');
		$this->schemaMapper->expects($this->never())->method('update');

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('Install Hermiq to use AI steps.');

		$compiler->apply(self::aiAutomation(trigger: 'object-created'), $plan, []);
	}//end testApplyRefusesAnAiStepWithoutTheHermiqNode()

	/**
	 * REQ-BQLS-005: with the node, the AI flow is saved like any other flow; the
	 * default container (no registry) also refuses.
	 *
	 * @return void
	 */
	public function testApplySavesAnAiFlowWhenTheNodeExists(): void {
		$this->claimWithCategories();
		$compiler = $this->compilerWithNodes(types: ['hermiq.agent-step']);
		$plan = $compiler->compile(self::aiAutomation(trigger: 'object-created'));

		$this->flowBackend->expects($this->once())->method('apply')->with($plan['flow'], null, true, 'version-3')->willReturn('flow-ai');

		$this->assertSame('flow-ai', $compiler->apply(self::aiAutomation(trigger: 'object-created'), $plan, [])['flowUuid']);

		$this->expectExceptionMessage('Install Hermiq to use AI steps.');
		$this->compiler->apply(self::aiAutomation(trigger: 'object-created'), $plan, []);
	}//end testApplySavesAnAiFlowWhenTheNodeExists()

	/**
	 * T07: on object updated, the compiled trigger names the source fields, so
	 * writing the answer does not start the flow again.
	 *
	 * @return void
	 */
	public function testAiStepOnUpdateWatchesItsSourceFields(): void {
		$this->claimWithCategories();

		$nodes = $this->compiler->compile(self::aiAutomation(trigger: 'object-updated', step: ['sourceFields' => ['toelichting', 'omschrijving']]))['flow']['nodes'];

		$this->assertSame(
			[
				'id' => 'trigger',
				'type' => 'openregister.trigger-object',
				'config' => [
					'event' => 'object.updated',
					'register' => 'openbuild-claims-production',
					'schema' => 'claim',
					'changedFields' => ['omschrijving', 'toelichting'],
				],
			],
			$nodes[0]
		);
	}//end testAiStepOnUpdateWatchesItsSourceFields()

	/**
	 * REQ-BQLS-004 with T07: an AI field fills on create and follows edits of its sources.
	 *
	 * @return void
	 */
	public function testAiFieldAlsoFollowsEditsOfItsSources(): void {
		$this->claimWithCategories();
		$automation = self::aiAutomation(trigger: 'object-created');
		$automation['origin'] = 'ai-field';

		$flow = $this->compiler->compile($automation)['flow'];
		$last = $flow['nodes'][(count($flow['nodes']) - 1)];

		$this->assertSame('trigger-edit', $last['id']);
		$this->assertSame(['omschrijving'], $last['config']['changedFields']);
		$this->assertSame(['id' => 'e-edit', 'from' => ['trigger-edit'], 'to' => ['step-1']], $flow['edges'][(count($flow['edges']) - 1)]);
		$this->assertSame('created', $flow['nodes'][0]['config']['event']);
	}//end testAiFieldAlsoFollowsEditsOfItsSources()
	/**
	 * A script step compiles to nothing until it compiles to OpenRegister's
	 * code step: on a flow trigger the flow builder refuses it, on an object
	 * trigger the matrix does (logic-script-step D2).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public function testAScriptStepDoesNotCompileYet(): void {
		$automation = [
			'id' => 'auto-script',
			'slug' => 'join-names',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'manual'],
			'condition' => null,
			'actions' => [['type' => 'script-step', 'code' => 'return items;', 'timeoutSeconds' => 10]],
		];

		foreach (['manual', 'webhook-called', 'object-created'] as $trigger) {
			$automation['trigger'] = ['type' => $trigger, 'schema' => 'permit'];
			try {
				$this->compiler->compile(automation: $automation);
				$this->fail(message: 'A script step must not compile on ' . $trigger . '.');
			} catch (UnsupportedAutomationCombinationException $e) {
				$this->assertStringContainsString(needle: 'script-step', haystack: $e->getMessage());
			}
		}
	}//end testAScriptStepDoesNotCompileYet()
	/**
	 * REQ-BQCV-028: a status-change automation compiles to ONE notification
	 * bound to exactly the transition it listens for, addressed to the group;
	 * OpenRegister's notifications dialect fires it on that transition only,
	 * so another transition does not start it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/automation-designer/spec.md#requirement-a-status-change-starts-an-automation-req-bqcv-028
	 */
	public function testAStatusChangeAutomationListensToItsTransitionOnly(): void {
		$plan = $this->compiler->compile(automation: [
			'id' => 'auto-review',
			'slug' => 'notify-reviewers',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'lifecycle-transition', 'schema' => 'request', 'transition' => 'review'],
			'condition' => null,
			'actions' => [
				['type' => 'send-notification', 'recipients' => [['kind' => 'group', 'group' => 'reviewers']], 'subject' => ['en' => 'A request is in review']],
			],
		]);

		$this->assertCount(expectedCount: 1, haystack: $plan['notifications']);
		$entry = $plan['notifications'][0];
		$this->assertSame(expected: 'request', actual: $entry['schema']);
		$this->assertSame(expected: ['type' => 'transition', 'action' => 'review'], actual: $entry['entry']['trigger']);
		$this->assertSame(expected: [['kind' => 'group', 'group' => 'reviewers']], actual: $entry['entry']['recipients']);
		$this->assertSame(expected: [], actual: $plan['lifecycleActions'], message: 'Nothing else is bound to any transition.');
	}//end testAStatusChangeAutomationListensToItsTransitionOnly()

	/**
	 * REQ-BQCV-029: an approval step compiles to the approval chain for the
	 * group and to nothing that runs on the trigger, so the record keeps its
	 * status until a manager decides. The on-approve and on-reject paths are
	 * dispatched by ApprovalOutcomeListener on the decision (asserted in
	 * ApprovalOutcomeListenerTest::testApprovedDispatchesOnApproveFollowUps
	 * and ::testRejectedDispatchesOnRejectFollowUps).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/automation-approval-action/spec.md#requirement-an-approval-step-holds-the-run-until-it-is-approved-req-bqcv-029
	 */
	public function testAnApprovalStepHoldsTheRunUntilADecision(): void {
		$plan = $this->compiler->compile(automation: [
			'id' => 'auto-approve',
			'slug' => 'approve-request',
			'applicationSlug' => 'permit-tracker',
			'versionUuid' => 'version-1',
			'enabled' => true,
			'trigger' => ['type' => 'object-created', 'schema' => 'request'],
			'condition' => null,
			'actions' => [
				[
					'type' => 'approval',
					'assigneeGroup' => 'managers',
					'onApprove' => [['type' => 'object-op', 'operation' => 'update', 'schema' => 'request', 'fieldMapping' => ['status' => 'approved']]],
					'onReject' => [['type' => 'send-notification', 'subject' => ['en' => 'Rejected']]],
				],
			],
		]);

		$this->assertSame(expected: 'managers', actual: $plan['approvalChain']['assigneeGroup']);
		$this->assertSame(expected: 'request', actual: $plan['approvalChain']['schema']);
		$this->assertSame(expected: [], actual: $plan['notifications']);
		$this->assertSame(expected: [], actual: $plan['lifecycleActions']);
		$this->assertArrayNotHasKey(key: 'flow', array: $plan, message: 'No flow runs the on-approve step on the trigger.');
	}//end testAnApprovalStepHoldsTheRunUntilADecision()
	/**
	 * A compiler whose container answers the sub-flow targets with the given automations.
	 *
	 * @param array<string, array<string, mixed>> $targets The automations by uuid.
	 *
	 * @return AutomationCompilerService
	 */
	private function compilerWithSubFlowTargets(array $targets): AutomationCompilerService {
		$resolver = $this->createMock(AutomationSubFlowTargets::class);
		$resolver->method('targets')->willReturnCallback(
			static fn (array $uuids): array => array_intersect_key($targets, array_flip($uuids))
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				AutomationSubFlowTargets::class => $resolver,
				default => $this->objectService,
			}
		);

		return new AutomationCompilerService(
			$container,
			$this->objectService,
			$this->schemaMapper,
			$this->appManager,
			new NullLogger(),
			$this->flowBackend,
		);
	}//end compilerWithSubFlowTargets()

	/**
	 * REQ-BQSF-004: a step that reuses a shared check compiles to a sub-flow
	 * that waits, followed by the record step that reads its result.
	 *
	 * @return void
	 */
	public function testARunAutomationStepCompilesToAWaitingSubFlowBeforeTheRecordStep(): void {
		$compiler = $this->compilerWithSubFlowTargets(
			['check-uuid' => ['slug' => 'check-address', 'name' => 'Controleer adres', 'applicationSlug' => 'subsidies', 'shared' => true, 'provenance' => ['flowUuid' => 'flow-check']]]
		);
		$automation = [
			'id' => 'own-uuid',
			'slug' => 'intake',
			'applicationSlug' => 'claims',
			'versionUuid' => 'version-3',
			'trigger' => ['type' => 'object-created', 'schema' => 'claim'],
			'actions' => [
				['type' => 'run-automation', 'automation' => 'check-uuid'],
				['type' => 'object-op', 'operation' => 'create', 'schema' => 'flag', 'fieldMapping' => ['reason' => '{{adresGeldig}}']],
			],
		];

		$plan = $compiler->compile($automation);

		$this->assertSame(
			['openregister.trigger-object', 'openregister.sub-flow', 'openregister.object-write'],
			array_column($plan['flow']['nodes'], 'type')
		);
		$this->assertSame(['flowId' => 'flow-check', 'wait' => true], $plan['flow']['nodes'][1]['config']);
		$this->assertSame(['step-1'], $plan['flow']['edges'][1]['from']);
		$this->assertSame(['step-2'], $plan['flow']['edges'][1]['to']);
	}//end testARunAutomationStepCompilesToAWaitingSubFlowBeforeTheRecordStep()

	/**
	 * REQ-BQSF-004: a direct self reference is refused at compile, and nothing is applied.
	 *
	 * @return void
	 */
	public function testARunAutomationStepNamingItselfIsRefused(): void {
		$compiler = $this->compilerWithSubFlowTargets(
			['own-uuid' => ['slug' => 'intake', 'name' => 'Intake', 'applicationSlug' => 'claims', 'shared' => false, 'provenance' => ['flowUuid' => 'flow-own']]]
		);
		$this->flowBackend->expects($this->never())->method('apply');

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('An automation cannot run itself.');

		$compiler->compile([
			'id' => 'own-uuid',
			'slug' => 'intake',
			'applicationSlug' => 'claims',
			'versionUuid' => 'version-3',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'run-automation', 'automation' => 'own-uuid']],
		]);
	}//end testARunAutomationStepNamingItselfIsRefused()

	/**
	 * REQ-BQSF-003: another app's automation that is not shared is refused.
	 *
	 * @return void
	 */
	public function testARunAutomationStepNamingAnUnsharedAutomationOfAnotherAppIsRefused(): void {
		$compiler = $this->compilerWithSubFlowTargets(
			['other-uuid' => ['slug' => 'archive', 'name' => 'Archiveer', 'applicationSlug' => 'subsidies', 'shared' => false, 'provenance' => ['flowUuid' => 'flow-other']]]
		);

		$this->expectException(UnsupportedAutomationCombinationException::class);
		$this->expectExceptionMessage('Only a shared automation of another app can be run from this one.');

		$compiler->compile([
			'id' => 'own-uuid',
			'slug' => 'intake',
			'applicationSlug' => 'claims',
			'versionUuid' => 'version-3',
			'trigger' => ['type' => 'manual'],
			'actions' => [['type' => 'run-automation', 'automation' => 'other-uuid']],
		]);
	}//end testARunAutomationStepNamingAnUnsharedAutomationOfAnotherAppIsRefused()
}//end class

