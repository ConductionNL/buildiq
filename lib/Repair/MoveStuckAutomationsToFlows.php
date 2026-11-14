<?php

/**
 * Buildiq Repair Step: move stuck automations to flows
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Before logic-automation-actions-that-run, a lifecycle transition with a
 * record or webhook step compiled to `related-object-upsert` and
 * `webhook-dispatch` lifecycle records OpenRegister cannot run, and a manual
 * automation compiled to an `aut-` rule set nothing evaluates. This step finds
 * those automations by their provenance, removes what the earlier compile
 * wrote, and recompiles each one: to a flow when it can run as one, and
 * otherwise disabled with the refusal recorded in `disabledReason`, which the
 * automations page shows (REQ-BQAR-007, D9).
 *
 * Idempotent: a moved or disabled automation no longer carries the old
 * artefacts in its provenance, so a second run finds nothing. Never throws.
 *
 * @category Repair
 * @package  OCA\Buildiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
 */

declare(strict_types=1);

namespace OCA\Buildiq\Repair;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Support\ObjectRecord;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Moves automations compiled to targets that never ran onto flows.
 *
 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
 */
class MoveStuckAutomationsToFlows implements IRepairStep {

	/**
	 * The shared buildiq register.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'buildiq';

	/**
	 * The Automation schema slug.
	 *
	 * @var string
	 */
	private const AUTOMATION_SCHEMA = 'automation';

	/**
	 * The most automations read in one run.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * The action kinds that compiled to lifecycle records nothing ran.
	 *
	 * @var array<int, string>
	 */
	private const STUCK_TRANSITION_ACTIONS = ['object-op', 'webhook'];

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface             $objectService Reads and writes the automations.
	 * @param AutomationCompilerServiceInterface $compiler      Removes the old artefacts and compiles the new flow.
	 * @param LoggerInterface                    $logger        PSR logger.
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly AutomationCompilerServiceInterface $compiler,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
	 */
	public function getName(): string {
		return 'Move buildiq automations that never ran onto OpenRegister flows';
	}//end getName()

	/**
	 * Move or disable every stuck automation.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectRecord is a pure coercion with no collaborators.
	 */
	public function run(IOutput $output): void {
		try {
			$rows = $this->objectService->findAll(
				config: ['filters' => ['register' => self::REGISTER_SLUG, 'schema' => self::AUTOMATION_SCHEMA], 'limit' => self::LIMIT],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('MoveStuckAutomationsToFlows: automations could not be read: ' . $e->getMessage());
			$output->warning('MoveStuckAutomationsToFlows: automations could not be read; nothing moved.');
			return;
		}

		$moved = 0;
		$disabled = 0;
		foreach ($rows as $row) {
			$automation = ObjectRecord::toArray(object: $row);
			if (self::isStuck(automation: $automation) === false) {
				continue;
			}

			$outcome = $this->move(automation: $automation);
			$moved += (int)($outcome === 'moved');
			$disabled += (int)($outcome === 'disabled');
		}

		$output->info('MoveStuckAutomationsToFlows: moved ' . $moved . ' automation(s) to flows, disabled ' . $disabled . ' with a reason.');
	}//end run()

	/**
	 * Whether an automation's provenance holds an artefact nothing runs.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/logic-automation-actions-that-run/specs/automation-designer/spec.md#requirement-stuck-automations-are-moved-or-disabled-with-a-reason-req-bqar-007
	 */
	public static function isStuck(array $automation): bool {
		$provenance = $automation['provenance'] ?? [];
		if (is_array($provenance) === false) {
			return false;
		}

		$trigger = (string)($automation['trigger']['type'] ?? '');
		if ($trigger === 'manual') {
			return trim((string)($provenance['ruleSetSlug'] ?? '')) !== '';
		}

		if ($trigger !== 'lifecycle-transition' || (array)($provenance['lifecycleActions'] ?? []) === []) {
			return false;
		}

		foreach ((array)($automation['actions'] ?? []) as $action) {
			if (is_array($action) === true && in_array((string)($action['type'] ?? ''), self::STUCK_TRANSITION_ACTIONS, true) === true) {
				return true;
			}
		}

		return false;
	}//end isStuck()

	/**
	 * Remove the old artefacts and recompile, or disable with the reason.
	 *
	 * @param array<string, mixed> $automation The automation.
	 *
	 * @return string `moved`, `disabled` or `failed`.
	 */
	private function move(array $automation): string {
		$uuid = (string)($automation['id'] ?? ($automation['uuid'] ?? ''));
		try {
			$this->compiler->remove(automation: $automation, provenance: (array)$automation['provenance']);
		} catch (Throwable $e) {
			$this->logger->warning('MoveStuckAutomationsToFlows: could not remove the old artefacts of "' . $uuid . '": ' . $e->getMessage());
			return 'failed';
		}

		$outcome = 'moved';
		try {
			$plan = $this->compiler->compile(automation: $automation);
			$automation['provenance'] = $this->compiler->apply(automation: $automation, plan: $plan, priorProvenance: []);
			unset($automation['disabledReason']);
		} catch (UnsupportedAutomationCombinationException $e) {
			$outcome = 'disabled';
			$automation['enabled'] = false;
			$automation['disabledReason'] = $e->getMessage();
			$automation['provenance'] = ['notificationKeys' => [], 'lifecycleActions' => [], 'scheduleIds' => [], 'openconnectorObjects' => []];
		} catch (Throwable $e) {
			$this->logger->warning('MoveStuckAutomationsToFlows: could not recompile "' . $uuid . '": ' . $e->getMessage());
			$outcome = 'disabled';
			$automation['enabled'] = false;
			$automation['disabledReason'] = 'This automation could not be moved to a flow; open it and save it again.';
			$automation['provenance'] = ['notificationKeys' => [], 'lifecycleActions' => [], 'scheduleIds' => [], 'openconnectorObjects' => []];
		}//end try

		try {
			$this->objectService->saveObject(
				object: $automation,
				register: self::REGISTER_SLUG,
				schema: self::AUTOMATION_SCHEMA,
				uuid: $uuid,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->error('MoveStuckAutomationsToFlows: could not save "' . $uuid . '": ' . $e->getMessage());
			return 'failed';
		}

		return $outcome;
	}//end move()
}//end class
