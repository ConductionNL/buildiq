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
	public const FLOW_ACTIONS = ['object-op', 'webhook', 'rule-decision'];

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
		if ($input->triggerType === 'manual') {
			return true;
		}

		foreach ($input->actions as $action) {
			if (in_array((string)($action['type'] ?? ''), self::FLOW_ACTIONS, true) === true) {
				return true;
			}
		}

		return false;
	}//end wantsFlow()

	/**
	 * The flow document of an automation.
	 *
	 * @param CompileInput       $input    The automation.
	 * @param string             $register The app version's register slug.
	 * @param array<int, string> $schemas  The app version's schema slugs.
	 *
	 * @return array{name: string, nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
	 *
	 * @throws UnsupportedAutomationCombinationException When a trigger, action or condition cannot run in a flow.
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-automations-compile-deterministically-to-declarative-primitives-or-one-openregister-flow-req-bqar-001
	 */
	public function build(CompileInput $input, string $register, array $schemas): array {
		$this->assertNoOwnBackend(input: $input);
		$nodes = [$this->triggerNode(input: $input, register: $register)];

		$filter = $this->filterNode(condition: $input->condition);
		if ($filter !== null) {
			$nodes[] = $filter;
		}

		foreach ($input->actions as $index => $action) {
			$nodes[] = $this->actionNode(action: (array)$action, index: (int)$index, register: $register, schemas: $schemas);
		}

		$edges = [];
		$count = count($nodes);
		for ($i = 1; $i < $count; $i++) {
			$edges[] = ['id' => 'e' . $i, 'from' => [$nodes[($i - 1)]['id']], 'to' => [$nodes[$i]['id']]];
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

		if ($type === 'schedule') {
			return ['id' => 'trigger', 'type' => 'openregister.trigger-schedule', 'config' => ['cron' => (string)($input->trigger['cron'] ?? '')]];
		}

		if ($type === 'lifecycle-transition') {
			throw new UnsupportedAutomationCombinationException(message: self::TRANSITION_REFUSAL);
		}

		throw new UnsupportedAutomationCombinationException(message: 'Unknown or unsupported trigger type "' . $type . '".');
	}//end triggerNode()

	/**
	 * The filter node of a FEEL condition, or null without one (D6).
	 *
	 * A rule-set condition goes through a decision table first, which this
	 * builder does not compile yet, so it is refused rather than dropped.
	 *
	 * @param mixed $condition The stored condition.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws UnsupportedAutomationCombinationException When the condition cannot be translated.
	 */
	private function filterNode(mixed $condition): ?array {
		if (is_array($condition) === false || $condition === []) {
			return null;
		}

		$kind = (string)($condition['type'] ?? 'feel');
		if ($kind !== 'feel') {
			throw new UnsupportedAutomationCombinationException(message: 'A rule-set condition cannot gate a flow yet.');
		}

		$expression = trim((string)($condition['expression'] ?? ''));
		if ($expression === '') {
			return null;
		}

		try {
			$logic = $this->feel->translate(expression: $expression);
		} catch (InvalidArgumentException $e) {
			throw new UnsupportedAutomationCombinationException(message: 'The condition cannot run in a flow: ' . $e->getMessage(), previous: $e);
		}

		return ['id' => 'condition', 'type' => 'openregister.filter', 'config' => ['condition' => $logic]];
	}//end filterNode()

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
		$schema = (string)($action['schema'] ?? '');
		if (in_array($schema, $schemas, true) === false) {
			throw new UnsupportedAutomationCombinationException(
				message: 'The record step writes to "' . $schema . '", which is not a schema of this app.'
			);
		}

		$operation = (string)($action['operation'] ?? 'create');
		if (in_array($operation, ['create', 'update'], true) === false) {
			throw new UnsupportedAutomationCombinationException(message: 'A record step can create or update a record, not "' . $operation . '".');
		}

		$config = [
			'register' => $register,
			'schema' => $schema,
			'operation' => $operation,
			'fields' => self::templated(mapping: (array)($action['fieldMapping'] ?? [])),
		];
		if ($operation === 'update') {
			$match = (array)($action['match'] ?? []);
			if ($match === []) {
				throw new UnsupportedAutomationCombinationException(message: 'An update step must say which record it changes.');
			}

			$config['match'] = self::templated(mapping: $match);
		}

		return $config;
	}//end recordConfig()

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
