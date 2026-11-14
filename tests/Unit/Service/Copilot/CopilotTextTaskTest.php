<?php

/**
 * CopilotTextTask unit test.
 *
 * The language model seam: a synchronous provider runs inline, a scheduled
 * task is waited for and its output read, a failed task and an unpicked task
 * become the 502 provider_error envelope, the unpicked one is cancelled, and
 * a missing TaskProcessing manager reads as none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Copilot
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Copilot;

use OCA\Buildiq\Exception\CopilotException;
use OCA\Buildiq\Service\Copilot\CopilotTextTask;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\Task;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Service\Copilot\CopilotTextTask
 * @uses   \OCA\Buildiq\Exception\CopilotException
 */
final class CopilotTextTaskTest extends TestCase {

	/**
	 * The TaskProcessing manager.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $manager;

	/**
	 * The seam under test.
	 *
	 * @var CopilotTextTask
	 */
	private CopilotTextTask $textTask;

	/**
	 * A seam over a mocked manager.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->manager = $this->createMock(IManager::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($this->manager);
		$this->textTask = new CopilotTextTask(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end setUp()

	/**
	 * A task in the given state, with the given output or error.
	 *
	 * @param int         $status The task status.
	 * @param string|null $output The output text, for a successful task.
	 * @param string      $error  The error message, for a failed task.
	 *
	 * @return Task
	 */
	private static function task(int $status, ?string $output=null, string $error=''): Task {
		$task = new Task('core:text2text', ['input' => 'x'], 'buildiq', 'alice');
		$task->setId(42);
		$task->setStatus($status);
		if ($output !== null) {
			$task->setOutput(['output' => $output]);
		}

		if ($error !== '') {
			$task->setErrorMessage($error);
		}

		return $task;
	}//end task()

	/**
	 * Assert a call fails with the provider_error envelope whose provider message contains the text.
	 *
	 * @param callable $call     The call.
	 * @param string   $contains What the provider message must contain.
	 *
	 * @return void
	 */
	private static function assertProviderError(callable $call, string $contains): void {
		try {
			$call();
			self::fail('no provider error was raised');
		} catch (CopilotException $e) {
			self::assertSame('provider_error', $e->getErrorCode());
			self::assertSame(502, $e->getHttpStatus());
			self::assertStringContainsString($contains, (string)($e->getContext()['providerMessage'] ?? ''));
		}
	}//end assertProviderError()

	/**
	 * The manager comes from the container; a container that cannot give it reads as none.
	 *
	 * @return void
	 */
	public function testTheManagerComesFromTheContainer(): void {
		$this->assertSame($this->manager, $this->textTask->manager());

		$broken = $this->createMock(ContainerInterface::class);
		$broken->method('get')->willThrowException(new RuntimeException('no TaskProcessing'));
		$this->assertNull((new CopilotTextTask(container: $broken, logger: $this->createMock(LoggerInterface::class)))->manager());
	}//end testTheManagerComesFromTheContainer()

	/**
	 * A synchronous provider runs the task inline and its output is returned.
	 *
	 * @return void
	 */
	public function testASynchronousProviderRunsInline(): void {
		$this->manager->method('getPreferredProvider')->with('core:text2text')->willReturn($this->createMock(ISynchronousProvider::class));
		$this->manager->expects($this->once())->method('runTask')->willReturnCallback(
			static function (Task $task): Task {
				self::assertSame(['input' => 'a prompt'], $task->getInput());
				self::assertSame('alice', $task->getUserId());
				self::assertSame('permits', $task->getCustomId());

				return self::task(status: Task::STATUS_SUCCESSFUL, output: 'answer');
			}
		);
		$this->manager->expects($this->never())->method('scheduleTask');

		$this->assertSame('answer', $this->textTask->run(manager: $this->manager, prompt: 'a prompt', userId: 'alice', appSlug: 'permits'));
	}//end testASynchronousProviderRunsInline()

	/**
	 * A scheduled task is waited for and its output read.
	 *
	 * @return void
	 */
	public function testAScheduledTaskIsWaitedFor(): void {
		$this->manager->method('getPreferredProvider')->willReturn(new \stdClass());
		$this->manager->method('scheduleTask')->willReturnCallback(static function (Task $task): void {
			$task->setId(42);
		});
		$this->manager->method('getTask')->with(42)->willReturn(self::task(status: Task::STATUS_SUCCESSFUL, output: 'scheduled answer'));

		$this->assertSame('scheduled answer', $this->textTask->run(manager: $this->manager, prompt: 'a prompt', userId: 'alice'));
	}//end testAScheduledTaskIsWaitedFor()

	/**
	 * A failed task carries the provider's own message.
	 *
	 * @return void
	 */
	public function testAFailedTaskCarriesTheProvidersMessage(): void {
		$this->manager->method('getPreferredProvider')->willThrowException(new RuntimeException('catalogue unavailable'));
		$this->manager->method('scheduleTask')->willReturnCallback(static function (Task $task): void {
			$task->setId(42);
		});
		$this->manager->method('getTask')->willReturn(self::task(status: Task::STATUS_FAILED, error: 'Chat provider is not configured'));

		self::assertProviderError(fn () => $this->textTask->run(manager: $this->manager, prompt: 'p', userId: 'alice'), 'Chat provider is not configured');
	}//end testAFailedTaskCarriesTheProvidersMessage()

	/**
	 * A task nobody picks up is given up on after the budget and cancelled.
	 *
	 * @return void
	 */
	public function testAnUnpickedTaskIsCancelled(): void {
		$this->manager->method('scheduleTask')->willReturnCallback(static function (Task $task): void {
			$task->setId(42);
		});
		$this->manager->method('getTask')->willReturn(self::task(status: Task::STATUS_SCHEDULED));
		$this->manager->expects($this->once())->method('cancelTask')->with(42)->willThrowException(new RuntimeException('cannot cancel'));

		self::assertProviderError(fn () => $this->textTask->run(manager: $this->manager, prompt: 'p', userId: 'alice', timeoutSeconds: 0.05), 'still waiting');
	}//end testAnUnpickedTaskIsCancelled()

	/**
	 * A scheduled task without an id is a provider error.
	 *
	 * @return void
	 */
	public function testATaskWithoutAnIdIsAProviderError(): void {
		$this->manager->method('scheduleTask');
		$this->manager->expects($this->never())->method('getTask');

		self::assertProviderError(fn () => $this->textTask->run(manager: $this->manager, prompt: 'p', userId: 'alice', timeoutSeconds: 0.05), 'did not assign a task id');
	}//end testATaskWithoutAnIdIsAProviderError()
}//end class
