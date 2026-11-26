<?php

/**
 * Manual Run Reconciler
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Completes the automation runs of manual automations that OpenRegister
 * executed (logic-automation-run-log D2, the flow path). `POST
 * /api/automations/{uuid}/run` queues the automation's flow and records a
 * `waiting` run naming the flow run. Before the run log of an automation is
 * read, each such waiting run whose flow run has ended gets its outcome and
 * one step per action from that flow run.
 *
 * Done on read, not from OpenRegister's FlowRunTerminalEvent: that event is
 * dispatched inside the flow run's own transaction, so a buildiq write that
 * fails there (seen live on PostgreSQL) fails the flow run itself, and design
 * D2 says recording never stops an automation. A flow run that is still
 * running leaves its run waiting; one that no longer exists (retention) is
 * recorded failed with that reason. Never throws.
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
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Kernel\Contracts\AutomationRunRecorderInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes finished flow runs onto their waiting automation runs.
 *
 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
 */
class ManualRunReconciler {

	/**
	 * The flow run states after which a run does not change any more
	 * (FlowRun::TERMINAL on OpenRegister main).
	 *
	 * @var array<int, string>
	 */
	private const TERMINAL = ['completed', 'stopped', 'dead_letter', 'failed'];

	/**
	 * OpenRegister's flow run mapper, by its service id: resolved at use time
	 * so buildiq loads without OpenRegister.
	 *
	 * @var string
	 */
	public const FLOW_RUN_MAPPER = 'OCA\\OpenRegister\\Db\\FlowRunMapper';

	/**
	 * The reason of a run whose flow run is gone.
	 *
	 * @var string
	 */
	public const GONE = 'The flow run no longer exists.';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface             $container     Resolves OpenRegister's flow run mapper at use time.
	 * @param AutomationRunRecorderInterface $runs          Lists and completes the waiting runs.
	 * @param ObjectServiceInterface         $objectService Reads the automation's actions.
	 * @param LoggerInterface                $logger        Logs a run that cannot be completed.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly AutomationRunRecorderInterface $runs,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Complete every waiting run of one automation whose flow run has ended.
	 *
	 * @param string $automationUuid The automation.
	 *
	 * @return int How many runs were completed.
	 *
	 * @spec openspec/changes/logic-automation-run-log/specs/automation-designer/spec.md#requirement-every-run-of-an-automation-that-buildiq-dispatches-is-recorded-req-bqrl-001
	 */
	public function reconcile(string $automationUuid): int {
		try {
			$waiting = $this->runs->waitingOnFlowRuns(automationUuid: $automationUuid);
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: waiting automation runs could not be read', ['exception' => $e->getMessage()]);
			return 0;
		}

		if ($waiting === []) {
			return 0;
		}

		$actions   = null;
		$completed = 0;
		foreach ($waiting as $run) {
			$result = $this->resultOf(
				flowRunUuid: (string)$run['flowRun'],
				actions: $actions,
				automationUuid: $automationUuid
			);
			if ($result === null) {
				continue;
			}

			$done = $this->runs->completeFromFlowRun(runUuid: (string)$run['id'], result: $result);
			if ($done === true) {
				$completed++;
			}
		}

		return $completed;
	}//end reconcile()

	/**
	 * The outcome of one flow run, or null while it is still running.
	 *
	 * @param string                                $flowRunUuid    The flow run.
	 * @param array<int, array<string, mixed>>|null $actions        The automation's actions, read once on first need.
	 * @param string                                $automationUuid The automation.
	 *
	 * @return array{outcome: string, reason: string|null, durationMs: int|null, steps: list<array<string, string>>}|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowRunOutcome is a pure mapping with no collaborators.
	 */
	private function resultOf(string $flowRunUuid, ?array &$actions, string $automationUuid): ?array {
		try {
			$flowRun = $this->container->get(self::FLOW_RUN_MAPPER)->findByUuid($flowRunUuid);
		} catch (DoesNotExistException) {
			return ['outcome' => 'failed', 'reason' => self::GONE, 'durationMs' => null, 'steps' => []];
		} catch (Throwable $e) {
			$this->logger->error('Buildiq: flow run ' . $flowRunUuid . ' could not be read', ['exception' => $e->getMessage()]);
			return null;
		}

		$status = (string)$flowRun->getStatus();
		if (in_array($status, self::TERMINAL, true) === false) {
			return null;
		}

		if (method_exists($flowRun, 'getOutcome') === true) {
			$status = (string)$flowRun->getOutcome();
		}

		if ($actions === null) {
			$actions = $this->actionsOf(automationUuid: $automationUuid);
		}

		return FlowRunOutcome::fromFlowRun(
			status: $status,
			error: $flowRun->getError(),
			log: (array)($flowRun->getLog() ?? []),
			actions: $actions
		);
	}//end resultOf()

	/**
	 * The actions of the automation, in order.
	 *
	 * @param string $automationUuid The automation.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AutomationRecords is a pure coercion with no collaborators.
	 */
	private function actionsOf(string $automationUuid): array {
		try {
			$automation = $this->objectService->find(id: $automationUuid, _rbac: false, _multitenancy: false);
		} catch (Throwable) {
			return [];
		}

		if ($automation === null) {
			return [];
		}

		$actions = AutomationRecords::toArray(object: $automation)['actions'] ?? [];
		if (is_array($actions) === false) {
			return [];
		}

		return array_values(array_filter($actions, 'is_array'));
	}//end actionsOf()
}//end class
