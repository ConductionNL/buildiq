<?php

/**
 * Buildiq CopilotTextTask
 *
 * The one seam between the copilot and the language model: resolve
 * Nextcloud's TaskProcessing manager and run a `core:text2text` task, inline
 * when the preferred provider is synchronous and otherwise scheduled and
 * waited for, with a timeout after which the task is cancelled. Every failure
 * comes back as the 502 `provider_error` envelope carrying the provider's own
 * message. Moved here unchanged from CopilotService so the plan and the
 * builder assist share it (ai-copilot-documents-and-code-help D3).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Copilot;

use OCA\Buildiq\Exception\CopilotException;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToText;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

// OCP\TaskProcessing\Task and TextToText ship only on NC 30+. A `use` import only
// aliases the name, so it is safe on older servers; every call into this class
// comes after the copilot has confirmed `OCP\TaskProcessing\IManager` exists.

/**
 * Runs one text-to-text task for the copilot.
 *
 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
 */
class CopilotTextTask {

	/**
	 * The TaskProcessing task type the copilot runs.
	 *
	 * @var string
	 */
	public const TASK_TYPE_ID = 'core:text2text';

	/**
	 * How long to wait for a worker to finish a scheduled task.
	 *
	 * @var float
	 */
	public const TIMEOUT_SECONDS = 120.0;

	/**
	 * How long to sleep between two looks at a scheduled task.
	 *
	 * @var integer
	 */
	private const POLL_INTERVAL_MICROSECONDS = 500000;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Where the TaskProcessing manager comes from.
	 * @param LoggerInterface    $logger    Logger for provider errors.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve `OCP\TaskProcessing\IManager` from the container, or null when unavailable.
	 *
	 * @return object|null
	 *
	 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
	 */
	public function manager(): ?object {
		try {
			return $this->container->get('OCP\\TaskProcessing\\IManager');
		} catch (Throwable $e) {
			return null;
		}
	}//end manager()

	/**
	 * Run a `core:text2text` task and return its output.
	 *
	 * Runs inline when the preferred provider is synchronous; otherwise
	 * schedules the task and polls it until it finishes or the wait runs out,
	 * in which case the task is cancelled.
	 *
	 * @param object      $manager        `OCP\TaskProcessing\IManager` instance.
	 * @param string      $prompt         The prompt to send as task input.
	 * @param string      $userId         Acting user's UID.
	 * @param string|null $appSlug        Optional target app slug, carried as the task's customId.
	 * @param float|null  $timeoutSeconds How long to wait for a worker, defaulting to {@see self::TIMEOUT_SECONDS}.
	 *
	 * @return string The task's `output` text.
	 *
	 * @throws CopilotException (502) On provider failure, cancellation, or timeout.
	 *
	 * @spec openspec/changes/ai-copilot-documents-and-code-help/specs/ai-builder-assist/spec.md#requirement-the-assist-proposes-a-checked-value-req-bqch-002
	 */
	public function run(object $manager, string $prompt, string $userId, ?string $appSlug=null, ?float $timeoutSeconds=null): string {
		$task = new Task(
			TextToText::ID,
			['input' => $prompt],
			'buildiq',
			$userId,
			$appSlug,
		);

		if ($this->preferredProviderRunsInline(manager: $manager) === true) {
			return $this->readTaskOutput(task: $manager->runTask($task));
		}

		$manager->scheduleTask($task);
		$taskId = $task->getId();
		if ($taskId === null) {
			throw $this->providerError(message: 'The AI provider did not accept the request.', detail: 'TaskProcessing did not assign a task id.');
		}

		$budget = ($timeoutSeconds ?? self::TIMEOUT_SECONDS);
		$deadline = microtime(as_float: true) + $budget;

		while (true) {
			$current = $manager->getTask($taskId);
			$status = $current->getStatus();

			if ($status === Task::STATUS_SUCCESSFUL || $status === Task::STATUS_FAILED || $status === Task::STATUS_CANCELLED) {
				return $this->readTaskOutput(task: $current);
			}

			if (microtime(as_float: true) > $deadline) {
				$this->cancelAbandonedTask(manager: $manager, taskId: $taskId);
				throw $this->providerError(
					message: 'No AI worker picked up the request in time. Ask an administrator to check the AI settings.',
					detail: 'TaskProcessing task ' . $taskId . ' was still waiting after ' . ((int)$budget) . 's.'
				);
			}

			usleep(self::POLL_INTERVAL_MICROSECONDS);
		}//end while
	}//end run()

	/**
	 * Whether the preferred provider for `core:text2text` can run in this
	 * request (`ISynchronousProvider`), rather than needing a worker.
	 *
	 * @param object $manager `OCP\TaskProcessing\IManager` instance.
	 *
	 * @return boolean
	 */
	private function preferredProviderRunsInline(object $manager): bool {
		if (interface_exists('OCP\\TaskProcessing\\ISynchronousProvider') === false || method_exists($manager, 'runTask') === false) {
			return false;
		}

		try {
			$provider = $manager->getPreferredProvider(self::TASK_TYPE_ID);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq Copilot: could not resolve the preferred text2text provider: ' . $e->getMessage());
			return false;
		}

		return $provider instanceof \OCP\TaskProcessing\ISynchronousProvider;
	}//end preferredProviderRunsInline()

	/**
	 * Read a finished task's output, turning a failed one into a provider error
	 * that carries the provider's own message.
	 *
	 * @param Task $task The finished task.
	 *
	 * @return string The task's `output` text.
	 *
	 * @throws CopilotException (502) When the task did not succeed.
	 */
	private function readTaskOutput(Task $task): string {
		if ($task->getStatus() === Task::STATUS_SUCCESSFUL) {
			$output = $task->getOutput();
			return (string)($output['output'] ?? '');
		}

		throw $this->providerError(
			message: 'The AI provider could not answer. Ask an administrator to check the AI settings.',
			detail: (string)$task->getErrorMessage()
		);
	}//end readTaskOutput()

	/**
	 * Cancel a task this request has stopped waiting for. Best effort: a failed
	 * cancel must never replace the timeout the caller is about to report.
	 *
	 * @param object  $manager `OCP\TaskProcessing\IManager` instance.
	 * @param integer $taskId  The abandoned task's id.
	 *
	 * @return void
	 */
	private function cancelAbandonedTask(object $manager, int $taskId): void {
		try {
			$manager->cancelTask($taskId);
		} catch (Throwable $e) {
			$this->logger->warning('Buildiq Copilot: could not cancel abandoned task ' . $taskId . ': ' . $e->getMessage());
		}
	}//end cancelAbandonedTask()

	/**
	 * Build the 502 envelope for a provider-side failure.
	 *
	 * @param string $message User-facing message.
	 * @param string $detail  The provider's own message, carried for the panel's detail line.
	 *
	 * @return CopilotException
	 */
	private function providerError(string $message, string $detail): CopilotException {
		$this->logger->warning('Buildiq Copilot: provider error: ' . $detail);

		return new CopilotException(
			errorCode: 'provider_error',
			message: $message,
			httpStatus: 502,
			context: ['providerMessage' => $detail]
		);
	}//end providerError()
}//end class
