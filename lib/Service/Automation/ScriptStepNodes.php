<?php

/**
 * Script Step Nodes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The flow node of a "Run a script" step (logic-script-step D2, D4): one
 * OpenRegister code step, shaped from the entry the node catalogue serves for
 * it. Pure: it reads the action and that entry and returns the node, or refuses
 * the step with a message naming what is missing. The code is copied into the
 * node as text and never evaluated here; the step declares no network egress.
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
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;

/**
 * Builds and refuses the node of a script step.
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */
final class ScriptStepNodes {

	/**
	 * The code step OpenRegister names in its change flow-code-step-in-a-sidecar.
	 *
	 * @var string
	 */
	public const CODE_NODE = 'openregister.code';

	/**
	 * The refusal when the node catalogue lists no code step (REQ-BQSS-001).
	 *
	 * @var string
	 */
	public const MISSING_NODE_REFUSAL = 'Script steps need OpenRegister\'s code runner (flow-code-runner), which is not installed.';

	/**
	 * The shortest and longest timeout a maker may set, in seconds (D1).
	 *
	 * @var array{0: int, 1: int}
	 */
	public const TIMEOUT_RANGE = [1, 30];

	/**
	 * The config the step writes, by the code step's key: the code runs on all
	 * items at once, as `(items) => items` (D1), and reaches no host (D4).
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED_KEYS = ['source'];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|null $catalogueEntry The catalogue's entry for the code step, or null when it has none.
	 */
	public function __construct(
		private readonly ?array $catalogueEntry,
	) {
	}//end __construct()

	/**
	 * Whether an automation's actions hold a script step.
	 *
	 * @param array<int|string, mixed> $actions The actions.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public static function holdsScriptStep(array $actions): bool {
		foreach ($actions as $action) {
			if (is_array($action) === true && ($action['type'] ?? '') === ScriptSteps::ACTION) {
				return true;
			}
		}

		return false;
	}//end holdsScriptStep()

	/**
	 * Whether a compiled flow runs a code step.
	 *
	 * @param array<string, mixed> $flow The flow document.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public static function flowRunsCode(array $flow): bool {
		foreach ((array)($flow['nodes'] ?? []) as $node) {
			if (is_array($node) === true && ($node['type'] ?? '') === self::CODE_NODE) {
				return true;
			}
		}

		return false;
	}//end flowRunsCode()

	/**
	 * The node of one script step.
	 *
	 * @param array<string, mixed> $action The action.
	 * @param int                  $index  Its position.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException Without a code step in the catalogue, or for an empty script.
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public function build(array $action, int $index): array {
		if ($this->catalogueEntry === null) {
			throw new UnsupportedAutomationCombinationException(message: self::MISSING_NODE_REFUSAL);
		}

		$code = (string)($action['code'] ?? '');
		if (trim($code) === '') {
			throw new UnsupportedAutomationCombinationException(message: 'Write the script this step runs.');
		}

		$config = [
			'language' => 'javascript',
			'mode' => 'allItems',
			'source' => $code,
			'timeoutMs' => (self::timeoutSeconds(action: $action) * 1000),
			'egress' => [],
		];

		return [
			'id' => 'step-' . ($index + 1),
			'type' => (string)($this->catalogueEntry['id'] ?? self::CODE_NODE),
			'config' => $this->declaredOnly(config: $config),
		];
	}//end build()

	/**
	 * The step's timeout in seconds, refused outside 1 to 30 (D1).
	 *
	 * @param array<string, mixed> $action The action.
	 *
	 * @return int
	 *
	 * @throws UnsupportedAutomationCombinationException For a timeout outside the range.
	 */
	private static function timeoutSeconds(array $action): int {
		$timeout = ($action['timeoutSeconds'] ?? ScriptSteps::DEFAULT_TIMEOUT);
		if (is_int($timeout) === false || $timeout < self::TIMEOUT_RANGE[0] || $timeout > self::TIMEOUT_RANGE[1]) {
			throw new UnsupportedAutomationCombinationException(
				message: 'A script step times out after 1 to 30 seconds.'
			);
		}

		return $timeout;
	}//end timeoutSeconds()

	/**
	 * The config reduced to the keys the catalogue says the code step reads.
	 *
	 * A catalogue entry without `configKeys` did not say, so every key is
	 * written. One that lists keys but not `source` cannot take a script, and
	 * the step is refused rather than compiled to a node that ignores its code.
	 *
	 * @param array<string, mixed> $config The full config.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException When the code step does not read a required key.
	 */
	private function declaredOnly(array $config): array {
		$declared = ($this->catalogueEntry['configKeys'] ?? null);
		if (is_array($declared) === false) {
			return $config;
		}

		$declared = array_map(static fn (mixed $key): string => (string)$key, $declared);
		foreach (self::REQUIRED_KEYS as $key) {
			if (in_array($key, $declared, true) === false) {
				throw new UnsupportedAutomationCombinationException(
					message: 'OpenRegister\'s code step does not take "' . $key . '", so this script cannot run. Update OpenRegister.'
				);
			}
		}

		return array_intersect_key($config, array_flip($declared));
	}//end declaredOnly()
}//end class
