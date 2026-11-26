<?php

/**
 * Buildiq AutomationOutcomeKeys
 *
 * The keys an automation's compiled notifications and lifecycle actions carry,
 * so the outcomes OpenRegister records for them can be read back per automation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Builds and matches outcome keys (logic-automation-run-log, design D4).
 *
 * An automation's key is `aut-` and its uuid, so two apps whose automations
 * share a slug never read each other's outcomes. A notification adds its
 * position (`aut-<uuid>-1`); a lifecycle action carries the automation key
 * itself. Pure: no storage.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
 */
final class AutomationOutcomeKeys {

	/**
	 * The prefix of every key buildiq compiles.
	 */
	public const PREFIX = 'aut-';

	/**
	 * The key of an automation, or '' when it has no id yet.
	 *
	 * @param array<string, mixed> $automation The automation (id or uuid).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
	 */
	public static function forAutomation(array $automation): string {
		$id = trim((string)($automation['id'] ?? ($automation['uuid'] ?? '')));
		if ($id === '') {
			return '';
		}

		return self::PREFIX . $id;
	}//end forAutomation()

	/**
	 * The key of an automation's n-th notification (1-based), or '' without an automation key.
	 *
	 * @param string $automationKey The automation's key.
	 * @param int    $position      The notification's position among the automation's notifications.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
	 */
	public static function notification(string $automationKey, int $position): string {
		if ($automationKey === '') {
			return '';
		}

		return $automationKey . '-' . $position;
	}//end notification()

	/**
	 * Whether an outcome key is one of the automation's: its own key, or one of its notifications.
	 *
	 * A prefix alone is not enough: `aut-1234` is a prefix of `aut-12345-1`.
	 *
	 * @param string $key           The outcome's key.
	 * @param string $automationKey The automation's key.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004
	 */
	public static function belongsTo(string $key, string $automationKey): bool {
		if ($automationKey === '') {
			return false;
		}

		if ($key === $automationKey) {
			return true;
		}

		return preg_match('/^' . preg_quote($automationKey, '/') . '-\d+$/', $key) === 1;
	}//end belongsTo()
}//end class
