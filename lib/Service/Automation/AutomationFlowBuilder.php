<?php

/**
 * Automation Flow Builder
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The OpenRegister flow one automation compiles to
 * (logic-automation-actions-that-run D1 to D6). Pure: it reads the automation
 * and the app version's register and schemas, and returns the flow document
 * `{name, nodes, edges}` that `FlowService::save()` takes, or refuses the shape
 * with a message that names what cannot run. Saving, binding and enabling the
 * flow is the compiler's apply step; this class does no I/O, so recompiling an
 * unchanged automation yields the same document and the same hash.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use InvalidArgumentException;
use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Service\FeelToJsonLogic;

/**
 * Selects, shapes and refuses the flow of an automation.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
 */
final class AutomationFlowBuilder {

	/**
	 * Action kinds that only a flow runs (D1).
	 *
	 * @var array<int, string>
	 */
	public const FLOW_ACTIONS = ['object-op', 'webhook', 'rule-decision', AiStepNodes::ACTION];

	/**
	 * Action kinds that keep their own backend and cannot share a flow (D1).
	 *
	 * @var array<int, string>
	 */
	public const OWN_BACKEND_ACTIONS = ['approval', 'generateDocument'];

	/**
	 * Object-event triggers and their `openregister.trigger-object` event (D3).
	 *
	 * @var array<string, string>
	 */
	private const OBJECT_EVENTS = [
		'object-created' => 'created',
		'object-updated' => 'updated',
		'object-deleted' => 'deleted',
	];

	/**
	 * The refusal for a flow action on a lifecycle transition (D3).
	 *
	 * @var string
	 */
	public const TRANSITION_REFUSAL = 'Record and webhook steps cannot run on a lifecycle transition yet.';

	/**
	 * The refusal for a webhook without a source (D5).
	 *
	 * @var string
	 */
	public const BARE_URL_REFUSAL = 'Pick a source for this webhook.';

	/**
	 * @param FeelToJsonLogic $feel Translates a FEEL condition for the filter node.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
	 */
	public function __construct(
		private readonly FeelToJsonLogic $feel,
	) {
	}//end __construct()

	/**
	 * Whether the automation compiles to a flow: a manual trigger, or any
	 * flow action (D1).
	 *
	 * @param CompileInput $input The automation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
	 */
	public function wantsFlow(CompileInput $input): bool {
		// Logic-email-and-webhook-triggers D1: the inbound triggers run only as a flow.
		if (in_array($input->triggerType, ['manual', ...InboundTriggerDeclarations::TRIGGERS], true) === true) {
			return true;
		}

		$types = array_map(static fn (mixed $action): string => (string)($action['type'] ?? ''), $input->actions);

		return array_intersect($types, self::FLOW_ACTIONS) !== [];
	}//end wantsFlow()

	/**
	 * The flow document of an automation.
	 *
	 * @param CompileInput                        $input    The automation.
	 * @param string                              $register The app version's register slug.
	 * @param array<int, string>                  $schemas  The app version's schema slugs.
	 * @param array<string, array<string, mixed>> $ruleSets The rule sets the rule-decision steps name, by slug:
	 *                                                      `ruleType`, `tableRef`, `outputs` (D7).
	 * @param array<string, mixed>|null           $schemaProperties The trigger schema's properties, which an AI
	 *                                                              classify step checks its labels against.
	 *
	 * @return array{name: string, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
	 *
	 * @throws UnsupportedAutomationCombinationException When a trigger, action or condition cannot run in a flow.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
	 * @spec openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
	 */
	public function build(CompileInput $input, string $register, array $schemas, array $ruleSets = [], ?array $schemaProperties = null): array {
		$this->assertNoOwnBackend(input: $input);
		$schema = (string)($input->trigger['schema'] ?? '');
		$aiSteps = new AiStepNodes(register: $register, schema: $schema, properties: $schemaProperties);
		$trigger = $aiSteps->updateTriggerNode(triggerType: $input->triggerType, actions: $input->actions);
		$nodes = [$trigger ?? $this->triggerNode(input: $input, register: $register)];

		array_push($nodes, ...$this->conditionNodes(condition: $input->condition, ruleSets: $ruleSets));

		$decisions = new RuleDecisionNodes(register: $register, schema: $schema, ruleSets: $ruleSets);
		foreach ($input->actions as $index => $action) {
			$action = (array)$action;
			if ((string)($action['type'] ?? '') === 'rule-decision') {
				array_push($nodes, ...$decisions->build(action: $action, index: (int)$index));
				continue;
			}

			if ((string)($action['type'] ?? '') === AiStepNodes::ACTION) {
				array_push($nodes, ...$aiSteps->stepNodes(action: $action, index: (int)$index, triggerType: $input->triggerType));
				continue;
			}

			$nodes[] = $this->actionNode(action: $action, index: (int)$index, register: $register, schemas: $schemas);
		}

		$edges = [];
		$count = count($nodes);
		for ($i = 1; $i < $count; $i++) {
			$edges[] = ['id' => 'e' . $i, 'from' => [$nodes[($i - 1)]['id']], 'to' => [$nodes[$i]['id']]];
		}

		// An AI field also follows edits of its source fields: a second trigger
		// into the same steps (ai-llm-steps-and-computed-fields REQ-BQLS-004).
		$editTrigger = $aiSteps->editTriggerNode(
			automation: $input->automation,
			triggerType: $input->triggerType,
			actions: $input->actions,
			nodeCount: $count
		);
		if ($editTrigger !== null) {
			$nodes[] = $editTrigger;
			$edges[] = ['id' => 'e-edit', 'from' => ['trigger-edit'], 'to' => [$nodes[1]['id']]];
		}

		return ['name' => 'aut-' . $input->slug, 'nodes' => $nodes, 'edges' => $edges];
	}//end build()

	/**
	 * Refuse an approval or document action beside a flow (D1).
	 *
	 * @param CompileInput $input The automation.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException When one is present.
	 */
	private function assertNoOwnBackend(CompileInput $input): void {
		foreach ($input->actions as $action) {
			$type = (string)($action['type'] ?? '');
			if (in_array($type, self::OWN_BACKEND_ACTIONS, true) === true) {
				throw new UnsupportedAutomationCombinationException(
					message: 'A "' . $type . '" step cannot share an automation with record, webhook or rule steps in this version.'
				);
			}
		}
	}//end assertNoOwnBackend()

	/**
	 * The trigger node (D3).
	 *
	 * @param CompileInput $input    The automation.
	 * @param string       $register The register.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException For a lifecycle transition or an unknown trigger.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) InboundTriggerDeclarations::check() is a pure settings check with no collaborators.
	 */
	private function triggerNode(CompileInput $input, string $register): array {
		$type = $input->triggerType;
		if (isset(self::OBJECT_EVENTS[$type]) === true) {
			$config = ['event' => self::OBJECT_EVENTS[$type], 'register' => $register, 'schema' => (string)($input->trigger['schema'] ?? '')];
			return ['id' => 'trigger', 'type' => 'openregister.trigger-object', 'config' => $config];
		}

		if ($type === 'manual') {
			return ['id' => 'trigger', 'type' => 'openregister.trigger-manual', 'config' => []];
		}

		// Logic-email-and-webhook-triggers D1: integriq starts the run, with the
		// mail or the request body as input, so the flow itself is started manually.
		if (in_array($type, InboundTriggerDeclarations::TRIGGERS, true) === true) {
			InboundTriggerDeclarations::check(trigger: $input->trigger);
			return ['id' => 'trigger', 'type' => 'openregister.trigger-manual', 'config' => ['startedBy' => 'integriq', 'trigger' => $type]];
		}

		if ($type === 'schedule') {
			return ['id' => 'trigger', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => (string)($input->trigger['cron'] ?? '')]];
		}

		if ($type === 'lifecycle-transition') {
			throw new UnsupportedAutomationCombinationException(message: self::TRANSITION_REFUSAL);
		}

		throw new UnsupportedAutomationCombinationException(message: 'Unknown or unsupported trigger type "' . $type . '".');
	}//end triggerNode()

	/**
	 * The nodes that gate the actions, none without a condition (D6).
	 *
	 * A FEEL condition is one filter; a rule-set condition runs its decision
	 * table first and filters on the outcome.
	 *
	 * @param mixed                               $condition The stored condition.
	 * @param array<string, array<string, mixed>> $ruleSets  The resolved rule sets by slug.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @throws UnsupportedAutomationCombinationException When the condition cannot be translated or its rule set cannot run.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-condition-gates-the-actions-req-bqar-004
	 */
	private function conditionNodes(mixed $condition, array $ruleSets): array {
		if (is_array($condition) === false || $condition === []) {
			return [];
		}

		$kind = (string)($condition['type'] ?? 'feel');
		if ($kind === 'rule-set') {
			return (new RuleSetConditionNodes(ruleSets: $ruleSets))->build(condition: $condition);
		}

		if ($kind !== 'feel') {
			throw new UnsupportedAutomationCombinationException(message: 'Unknown condition type "' . $kind . '".');
		}

		$expression = trim((string)($condition['expression'] ?? ''));
		if ($expression === '') {
			return [];
		}

		try {
			$logic = $this->feel->translate(expression: $expression);
		} catch (InvalidArgumentException $e) {
			throw new UnsupportedAutomationCombinationException(message: 'The condition cannot run in a flow: ' . $e->getMessage(), previous: $e);
		}

		return [['id' => 'condition', 'type' => 'openregister.filter', 'config' => ['condition' => $logic]]];
	}//end conditionNodes()

	/**
	 * The node of one action (D4, D5, D1 for notifications).
	 *
	 * @param array<string, mixed> $action   The action.
	 * @param int                  $index    Its position.
	 * @param string               $register The register.
	 * @param array<int, string>   $schemas  The app's schemas.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException For an action a flow cannot run.
	 */
	private function actionNode(array $action, int $index, string $register, array $schemas): array {
		$type = (string)($action['type'] ?? '');
		$id = 'step-' . ($index + 1);

		return match ($type) {
			'object-op' => [
				'id' => $id,
				'type' => 'openregister.object-write',
				'config' => $this->recordConfig(action: $action, register: $register, schemas: $schemas),
			],
			'webhook' => ['id' => $id, 'type' => 'openconnector.source-call', 'config' => self::sourceCallConfig(action: $action)],
			'send-notification' => ['id' => $id, 'type' => 'openregister.send-notification', 'config' => self::notificationConfig(action: $action)],
			default => throw new UnsupportedAutomationCombinationException(message: 'A "' . $type . '" step cannot run in a flow yet.'),
		};
	}//end actionNode()

	/**
	 * The rule set slugs an automation names: its rule-set condition first, then its rule-decision steps (D6, D7).
	 *
	 * @param CompileInput $input The automation.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-a-step-decides-with-a-rule-set-req-bqar-005
	 */
	public function ruleSetSlugs(CompileInput $input): array {
		$slugs     = [];
		$condition = $input->condition;
		if (is_array($condition) === true && ($condition['type'] ?? '') === 'rule-set') {
			$slug = trim((string)($condition['ruleSetSlug'] ?? ''));
			if ($slug !== '') {
				$slugs[] = $slug;
			}
		}

		foreach ($input->actions as $action) {
			if (is_array($action) === true && ($action['type'] ?? '') === 'rule-decision') {
				$slug = trim((string)($action['ruleSetSlug'] ?? ''));
				if ($slug !== '' && in_array($slug, $slugs, true) === false) {
					$slugs[] = $slug;
				}
			}
		}

		sort($slugs);

		return $slugs;
	}//end ruleSetSlugs()


	/**
	 * The `openregister.send-notification` config of a notification step.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 */
	private static function notificationConfig(array $action): array {
		return [
			'channels' => array_values((array)($action['channels'] ?? [])),
			'recipients' => array_values((array)($action['recipients'] ?? [])),
			'subject' => ($action['subject'] ?? ''),
			'message' => ($action['message'] ?? ''),
		];
	}//end notificationConfig()

	/**
	 * The `openregister.object-write` config of a record step, limited to the
	 * app version's schemas (D4). An update names the fields that find the
	 * record to change; without them it is refused rather than guessed.
	 *
	 * @param array<string, mixed> $action   The action.
	 * @param string               $register The register.
	 * @param array<int, string>   $schemas  The app's schemas.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException For a foreign schema, an unknown operation or an update without a match.
	 */
	private function recordConfig(array $action, string $register, array $schemas): array {
		$config = [
			'register' => $register,
			'schema' => self::recordSchema(action: $action, schemas: $schemas),
			'operation' => self::recordOperation(action: $action),
			'fields' => self::templated(mapping: (array)($action['fieldMapping'] ?? [])),
		];
		if ($config['operation'] === 'update') {
			$config['match'] = self::recordMatch(action: $action);
		}

		return $config;
	}//end recordConfig()

	/**
	 * The schema a record step writes to, when it is one of the app's.
	 *
	 * @param array<string, mixed> $action  The action.
	 * @param array<int, string>   $schemas The app's schemas.
	 *
	 * @return string
	 *
	 * @throws UnsupportedAutomationCombinationException For a foreign schema.
	 */
	private static function recordSchema(array $action, array $schemas): string {
		$schema = (string)($action['schema'] ?? '');
		if (in_array($schema, $schemas, true) === false) {
			throw new UnsupportedAutomationCombinationException(
				message: 'The record step writes to "' . $schema . '", which is not a schema of this app.'
			);
		}

		return $schema;
	}//end recordSchema()

	/**
	 * The operation of a record step: create (the default) or update.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return string
	 *
	 * @throws UnsupportedAutomationCombinationException For an unknown operation.
	 */
	private static function recordOperation(array $action): string {
		$operation = (string)($action['operation'] ?? 'create');
		if (in_array($operation, ['create', 'update'], true) === false) {
			throw new UnsupportedAutomationCombinationException(message: 'A record step can create or update a record, not "' . $operation . '".');
		}

		return $operation;
	}//end recordOperation()

	/**
	 * The templated fields that find the record an update step changes.
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException For an update without a match.
	 */
	private static function recordMatch(array $action): array {
		$match = (array)($action['match'] ?? []);
		if ($match === []) {
			throw new UnsupportedAutomationCombinationException(message: 'An update step must say which record it changes.');
		}

		return self::templated(mapping: $match);
	}//end recordMatch()

	/**
	 * The `openconnector.source-call` config of a webhook step (D5).
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException For a webhook without a source.
	 */
	private static function sourceCallConfig(array $action): array {
		$source = trim((string)($action['source'] ?? ''));
		if ($source === '') {
			throw new UnsupportedAutomationCombinationException(message: self::BARE_URL_REFUSAL);
		}

		return [
			'source' => $source,
			'endpoint' => (string)($action['path'] ?? '/'),
			'method' => 'POST',
			'body' => (array)($action['payloadTemplate'] ?? []),
		];
	}//end sourceCallConfig()

	/**
	 * A field mapping with `{{field}}` read from the triggering record, as the
	 * flow keeps it under `json` (D4). Values without a placeholder stay as they are.
	 *
	 * @param array<string, mixed> $mapping The mapping.
	 *
	 * @return array<string, mixed>
	 */
	private static function templated(array $mapping): array {
		$out = [];
		foreach ($mapping as $field => $value) {
			if (is_string($value) === true) {
				$value = preg_replace('/\{\{\s*(?!json\.)([A-Za-z0-9_.@-]+)\s*\}\}/', '{{json.$1}}', $value);
			}

			$out[(string)$field] = $value;
		}

		ksort($out);

		return $out;
	}//end templated()
}//end class
