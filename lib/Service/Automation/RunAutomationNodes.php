<?php

/**
 * Run Automation Nodes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The flow node of a "Run another automation" step
 * (logic-shared-flows-and-automations D4): `openregister.sub-flow` on the
 * compiled flow of the named automation, waiting for its result so the later
 * steps see its output items. Pure: the named automations are resolved by the
 * caller and handed in.
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
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;

/**
 * Builds and refuses the node of a step that runs another automation.
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */
final class RunAutomationNodes {

	/**
	 * The action kind.
	 *
	 * @var string
	 */
	public const ACTION = 'run-automation';

	/**
	 * OpenRegister's sub-flow node.
	 *
	 * @var string
	 */
	public const NODE = 'openregister.sub-flow';

	/**
	 * The refusal of a step that names no automation.
	 *
	 * @var string
	 */
	public const MISSING_REFUSAL = 'Pick the automation this step runs.';

	/**
	 * The refusal of a step that names its own automation (D4).
	 *
	 * @var string
	 */
	public const SELF_REFUSAL = 'An automation cannot run itself.';

	/**
	 * The refusal of a step whose automation cannot be read.
	 *
	 * @var string
	 */
	public const UNKNOWN_REFUSAL = 'The automation this step runs could not be found.';

	/**
	 * The refusal of a step naming another app's automation that is not shared (D3).
	 *
	 * @var string
	 */
	public const NOT_SHARED_REFUSAL = 'Only a shared automation of another app can be run from this one.';

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                $automation The automation that holds the steps.
	 * @param array<string, array<string, mixed>> $targets    The automations the steps name, by uuid.
	 */
	public function __construct(
		private readonly array $automation,
		private readonly array $targets,
	) {
	}//end __construct()

	/**
	 * The uuids of the automations a list of actions runs, in order and without repeats.
	 *
	 * @param array<int|string, mixed> $actions The automation's actions.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
	 */
	public static function targetUuids(array $actions): array {
		$uuids = [];
		foreach ($actions as $action) {
			if (is_array($action) === false || ($action['type'] ?? null) !== self::ACTION) {
				continue;
			}

			$uuid = trim((string)($action['automation'] ?? ''));
			if ($uuid !== '' && in_array($uuid, $uuids, true) === false) {
				$uuids[] = $uuid;
			}
		}

		return $uuids;
	}//end targetUuids()

	/**
	 * The sub-flow node of one step: the named automation's compiled flow, waited on (D4).
	 *
	 * @param array<string, mixed> $action The action.
	 * @param int                  $index  Its position.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnsupportedAutomationCombinationException For a missing, self, unknown, unshared or uncompiled automation.
	 *
	 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
	 */
	public function build(array $action, int $index): array {
		$uuid = trim((string)($action['automation'] ?? ''));
		if ($uuid === '') {
			throw new UnsupportedAutomationCombinationException(message: self::MISSING_REFUSAL);
		}

		if (in_array($uuid, self::identities(automation: $this->automation), true) === true) {
			throw new UnsupportedAutomationCombinationException(message: self::SELF_REFUSAL);
		}

		$target = ($this->targets[$uuid] ?? null);
		if (is_array($target) === false) {
			throw new UnsupportedAutomationCombinationException(message: self::UNKNOWN_REFUSAL);
		}

		if ($this->sameApp(target: $target) === false && ($target['shared'] ?? false) !== true) {
			throw new UnsupportedAutomationCombinationException(message: self::NOT_SHARED_REFUSAL);
		}

		$flowUuid = self::flowUuidOf(target: $target);
		if ($flowUuid === '') {
			$name = (string)($target['name'] ?? ($target['slug'] ?? $uuid));
			throw new UnsupportedAutomationCombinationException(
				message: 'The automation "' . $name . '" has no flow yet; compile it before another automation runs it.'
			);
		}

		return [
			'id' => 'step-' . ($index + 1),
			'type' => self::NODE,
			'config' => ['flowId' => $flowUuid, 'wait' => true],
		];
	}//end build()

	/**
	 * Whether a named automation belongs to the same app as the one that runs it.
	 *
	 * @param array<string, mixed> $target The named automation.
	 *
	 * @return bool
	 */
	private function sameApp(array $target): bool {
		$own = (string)($this->automation['applicationSlug'] ?? '');

		return $own !== '' && $own === (string)($target['applicationSlug'] ?? '');
	}//end sameApp()

	/**
	 * The ids an automation is known by.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return list<string>
	 */
	private static function identities(array $automation): array {
		$ids = [];
		foreach (['uuid', 'id'] as $key) {
			$id = trim((string)($automation[$key] ?? ''));
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end identities()

	/**
	 * The compiled flow of a named automation, '' when it has none.
	 *
	 * @param array<string, mixed> $target The named automation.
	 *
	 * @return string
	 */
	private static function flowUuidOf(array $target): string {
		$provenance = ($target['provenance'] ?? null);
		if (is_array($provenance) === false) {
			return '';
		}

		return trim((string)($provenance['flowUuid'] ?? ''));
	}//end flowUuidOf()
}//end class
