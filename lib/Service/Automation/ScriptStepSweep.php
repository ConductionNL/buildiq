<?php

/**
 * Buildiq ScriptStepSweep
 *
 * Disables every enabled automation that holds a script step, with the reason,
 * when an administrator turns "Allow script steps" off.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationWriteServiceInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * The disable sweep of logic-script-step D3.
 *
 * Each automation is handled on its own: its compiled artefacts are removed,
 * it is stored disabled with `disabledReason`, and a failure is logged and
 * counted without stopping the others. The author of its last script change
 * stays in its provenance.
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
 */
class ScriptStepSweep {

	/**
	 * The instance setting "Allow script steps", off by default.
	 *
	 * @var string
	 */
	public const SETTING = 'allow_script_steps';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface             $objectService Reads every automation.
	 * @param AutomationCompilerServiceInterface $compiler      Removes the compiled artefacts.
	 * @param AutomationWriteServiceInterface    $writeService  Stores the disabled automation.
	 * @param LoggerInterface                    $logger        PSR logger.
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AutomationCompilerServiceInterface $compiler,
		private readonly AutomationWriteServiceInterface $writeService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Disable every enabled automation that holds a script step.
	 *
	 * @return array{disabled: int, failed: int} How many were disabled, and how many could not be.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AutomationRecords and ScriptSteps are pure reads.
	 *
	 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-script-runs-only-in-the-code-runner-req-bqss-003
	 */
	public function disableAll(): array {
		$counts = ['disabled' => 0, 'failed' => 0];
		try {
			$automations = AutomationRecords::all(objectService: $this->objectService);
		} catch (\Throwable $e) {
			$this->logger->warning('ScriptStepSweep: automations could not be read: ' . $e->getMessage());
			return $counts;
		}

		foreach ($automations as $automation) {
			if (($automation['enabled'] ?? false) !== true || ScriptSteps::holds(automation: $automation) === false) {
				continue;
			}

			$key = 'failed';
			if ($this->disable(automation: $automation) === true) {
				$key = 'disabled';
			}

			$counts[$key]++;
		}

		return $counts;
	}//end disableAll()

	/**
	 * Remove one automation's artefacts and store it disabled with the reason.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return bool Whether it was disabled.
	 */
	private function disable(array $automation): bool {
		$uuid       = (string)($automation['id'] ?? ($automation['uuid'] ?? ''));
		$provenance = (array)($automation['provenance'] ?? []);
		try {
			$this->compiler->remove(automation: $automation, provenance: $provenance);
			$automation['enabled'] = false;
			$automation['disabledReason'] = ScriptSteps::DISABLED_REFUSAL;
			$automation['provenance'] = array_intersect_key($provenance, [ScriptSteps::AUTHOR_KEY => true])
				+ ['notificationKeys' => [], 'lifecycleActions' => [], 'scheduleIds' => [], 'openconnectorObjects' => []];
			$this->writeService->saveCompiled(automation: $automation, uuid: $uuid);
		} catch (\Throwable $e) {
			$this->logger->warning('ScriptStepSweep: could not disable "' . $uuid . '": ' . $e->getMessage());
			return false;
		}

		return true;
	}//end disable()
}//end class
