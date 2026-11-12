<?php

/**
 * Golden test for AutomationCompilerService::compile().
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\AutomationCompilerService;
use OCP\App\IAppManager;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Pins the compiled plan, or the refusal, for every trigger type crossed
 * with single and mixed action lists, enabled flags, conditions and identity
 * fields. Set GOLDEN_RECORD=1 to write a missing fixture.
 *
 * @covers \OCA\Buildiq\Service\AutomationCompilerService
 * @uses   \OCA\Buildiq\Service\Automation\CompileInput
 * @uses   \OCA\Buildiq\Exception\UnsupportedAutomationCombinationException
 * @uses   \OCA\Buildiq\Support\FleetAppId
 */
class AutomationCompileGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/automation-compile.golden.json';

	/**
	 * Trigger blocks by name.
	 *
	 * @return array<string,mixed>
	 */
	private function triggers(): array {
		return [
			'created' => ['type' => 'object-created', 'schema' => 'permit'],
			'updated' => ['type' => 'object-updated', 'schema' => 'permit'],
			'deleted' => ['type' => 'object-deleted'],
			'transition' => ['type' => 'lifecycle-transition', 'schema' => 'case', 'transition' => 'close'],
			'transition bare' => ['type' => 'lifecycle-transition'],
			'schedule cron' => ['type' => 'schedule', 'cron' => '0 * * * *', 'interval' => 5],
			'schedule interval' => ['type' => 'schedule', 'interval' => '3600'],
			'schedule none' => ['type' => 'schedule'],
			'manual' => ['type' => 'manual'],
			'unknown' => ['type' => 'webhook-in'],
			'not an array' => 'object-created',
		];
	}//end triggers()

	/**
	 * Action lists by name.
	 *
	 * @return array<string,mixed>
	 */
	private function actionLists(): array {
		$notify = ['type' => 'send-notification', 'channels' => 'email', 'recipients' => ['group:a'], 'subject' => 'Hi'];
		$notifyBare = ['type' => 'send-notification'];
		$approval = ['type' => 'approval', 'assigneeGroup' => 'leads'];
		$approval2 = ['type' => 'approval'];
		$objectOp = ['type' => 'object-op', 'operation' => 'update', 'schema' => 'task', 'fieldMapping' => ['a' => 'b']];
		$objectOpBare = ['type' => 'object-op'];
		$webhook = ['type' => 'webhook', 'url' => 'https://example.test/h', 'payloadTemplate' => ['x' => 1]];
		$webhookBare = ['type' => 'webhook'];
		$sync = ['type' => 'run-synchronization', 'synchronizationId' => 42];
		$syncBare = ['type' => 'run-synchronization'];
		$doc = ['type' => 'generateDocument', 'templateId' => 't-1', 'output' => ['attach', 'notify']];
		$docShort = ['type' => 'generateDocument', 'templateId' => 't-1', 'output' => 'download-link'];
		$docNotifyOnly = ['type' => 'generateDocument', 'templateId' => 't-1', 'output' => 'notify'];
		$docNoTemplate = ['type' => 'generateDocument', 'output' => ['attach']];

		return [
			'none' => [],
			'not a list' => 'send-notification',
			'keyed' => ['x' => $notify, 'y' => $notifyBare],
			'notify' => [$notify],
			'notify x3' => [$notify, $notifyBare, $notify],
			'approval' => [$approval],
			'approval x2' => [$approval, $approval2],
			'object-op' => [$objectOp, $objectOpBare],
			'webhook' => [$webhook, $webhookBare],
			'mixed lifecycle' => [$notify, $objectOp, $webhook, $approval, $notifyBare],
			'sync' => [$sync, $syncBare],
			'sync and other' => [$sync, $notify],
			'doc' => [$doc],
			'doc short' => [$docShort, $notify],
			'doc notify only' => [$docNotifyOnly],
			'doc no template' => [$docNoTemplate],
			'untyped' => [['channels' => ['x']]],
			'scalar action' => ['notify'],
		];
	}//end actionLists()

	/**
	 * Every case, by name.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function cases(): array {
		$identities = [
			'full' => ['id' => '0a1b2c3d-4e5f-6789-abcd-ef0123456789', 'slug' => 'close-case', 'name' => 'Close case', 'applicationSlug' => 'zaken'],
			'uuid only' => ['uuid' => 'ffeeddcc-0000-1111-2222-333344445555'],
			'no id' => ['slug' => 'nameless'],
		];
		$enabled = ['default' => null, 'off' => false];

		$cases = [];
		foreach ($this->triggers() as $tn => $trigger) {
			foreach ($this->actionLists() as $an => $actions) {
				foreach ($identities as $in => $identity) {
					foreach ($enabled as $en => $flag) {
						$automation = $identity + ['trigger' => $trigger, 'actions' => $actions];
						if ($flag !== null) {
							$automation['enabled'] = $flag;
						}

						$cases["$tn / $an / $in / enabled $en"] = $automation;
					}
				}
			}

			foreach (['feel' => ['feel' => 'amount > 10'], 'string' => 'amount > 10'] as $cn => $condition) {
				foreach (['none', 'notify', 'object-op', 'webhook'] as $an) {
					$cases["$tn / $an / condition $cn"] = $identities['full'] + [
						'trigger' => $trigger,
						'actions' => $this->actionLists()[$an],
						'condition' => $condition,
					];
				}
			}
		}//end foreach

		return $cases;
	}//end cases()

	/**
	 * Every case matches its recorded plan or refusal.
	 *
	 * @return void
	 */
	public function testCompileMatchesGolden(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(true);
		$compiler = new AutomationCompilerService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(SchemaMapper::class),
			$appManager,
			new NullLogger(),
		);

		$actual = [];
		foreach ($this->cases() as $name => $automation) {
			try {
				$actual[$name] = ['plan' => $compiler->compile($automation)];
			} catch (Throwable $e) {
				$actual[$name] = ['refused' => get_class($e) . ': ' . $e->getMessage()];
			}
		}

		if (file_exists(self::FIXTURE) === false && getenv('GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string)file_get_contents(self::FIXTURE), true);
		foreach ($actual as $name => $result) {
			self::assertSame($expected[$name] ?? null, json_decode((string)json_encode($result), true), $name);
		}

		self::assertSame(array_keys($expected), array_keys($actual));
	}//end testCompileMatchesGolden()
}//end class
