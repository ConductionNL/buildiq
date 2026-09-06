<?php

namespace OCA\Buildiq\Tests\Unit\Controller;

use OCA\Buildiq\Controller\SetupController;
use OCA\Buildiq\Service\DemoDataService;
use OCA\Buildiq\Service\SettingsService;
use OCA\Buildiq\Service\TemplateSeedService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * ADR-042 / ADR-111 — the example-data steps.
 *
 * 🔴 THIS FILE EXISTS BECAUSE DECLINING WAS UNSAYABLE. The app implemented a
 * `skip-demo-data` action and no manifest step could reach it: the only step
 * was the run-action that INSTALLS. So an operator who did not want example
 * data had no way to record that, the step stayed `done: false`, and CnAppRoot
 * reopened the wizard over every page until they imported data they did not
 * want.
 */
class SetupControllerDemoDataTest extends TestCase {
	private array $written = [];
	private array $config = [];
	private DemoDataService $demoData;
	private TemplateSeedService $seedService;
	private SettingsService $settings;

	protected function setUp(): void {
		$this->written = [];
		$this->config = [];
		$this->demoData = $this->createMock(DemoDataService::class);
		$this->seedService = $this->createMock(TemplateSeedService::class);
		$this->seedService->method('countSeeded')->willReturn(0);
		$this->settings = $this->createMock(SettingsService::class);
	}

	private function controller(array $params = []): SetupController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				return ($this->config[$key] ?? $default);
			});
		$appConfig->method('setValueString')
			->willReturnCallback(function (string $app, string $key, string $value): bool {
				$this->written[$key] = $value;

				return true;
			});

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);

		return new SetupController(
			$request,
			new NullLogger(),
			$appConfig,
			$userSession,
			$groupManager,
			$this->demoData,
			$this->settings,
			$this->seedService
		);
	}

	public function testStatusReportsBothExampleDataSteps(): void {
		$this->demoData->method('listChoices')->willReturn([]);

		$steps = $this->controller()->status()->getData()['steps'];

		// Absence is the defect this guards: a step the wizard is never told
		// about cannot be offered and cannot be completed.
		$this->assertArrayHasKey('demo-data', $steps);
		$this->assertArrayHasKey('load-demo-data', $steps);
		$this->assertFalse($steps['demo-data']['done']);
		$this->assertFalse($steps['load-demo-data']['done']);
	}

	public function testStatusCarriesTheOptionListTheChoiceStepReads(): void {
		// 🔴 THIS RESPONSE *IS* THE OPTION LIST. The step declares
		// `optionsSource: datasets` and carries no options of its own, so a
		// dataset missing here is a dataset nobody can pick.
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => 'Nothing.', 'objectCount' => 0, 'icon' => 'CloseCircleOutline'],
			['id' => 'demo', 'label' => 'Example data', 'description' => 'Sample values.', 'objectCount' => 9, 'icon' => 'DatabaseOutline'],
		]);

		$data = $this->controller()->status()->getData();

		$this->assertSame(['none', 'demo'], array_column($data['datasets'], 'id'));
		$this->assertSame(9, $data['datasets'][1]['objectCount']);
	}

	public function testChoosingNoneClosesBothStepsWithoutRunningAnything(): void {
		$this->demoData->method('listChoices')->willReturn([]);
		$this->config['demo_dataset'] = 'none';

		$steps = $this->controller()->status()->getData()['steps'];

		$this->assertTrue($steps['demo-data']['done']);
		$this->assertTrue($steps['load-demo-data']['done']);
	}

	public function testTheChoiceIsStoredHereRatherThanHandedToTheSettingsService(): void {
		// The dataset is this controller's own key. Passing it through to
		// `updateSettings()` would make its acceptance somebody else's rule.
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'demo', 'label' => 'Example data', 'description' => '', 'objectCount' => 1, 'icon' => ''],
		]);
		$this->settings->expects($this->once())
			->method('updateSettings')
			->with($this->logicalNot($this->arrayHasKey('demo_dataset')));

		$data = $this->controller(['demo_dataset' => 'demo'])->saveConfig()->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('demo', $this->written['demo_dataset'] ?? null);
	}

	public function testAnUnknownDatasetIsRefusedRatherThanStored(): void {
		// Storing it would leave the load step pointing at nothing, so the
		// failure would surface one step later with no clue why.
		$this->demoData->method('listChoices')->willReturn([
			['id' => 'none', 'label' => 'None', 'description' => '', 'objectCount' => 0, 'icon' => ''],
		]);
		$this->settings->expects($this->never())->method('updateSettings');

		$response = $this->controller(['demo_dataset' => 'atlantis'])->saveConfig();

		$this->assertSame(400, $response->getStatus());
		$this->assertSame([], $this->written);
	}

	public function testSkippingClosesBOTHStepsOrTheWizardNeverCloses(): void {
		$response = $this->controller()->runAction('skip-demo-data');

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('skipped', $this->written['demo_data_decided'] ?? null);
		$this->assertSame('none', $this->written['demo_dataset'] ?? null, 'skipping IS choosing none');
	}

	public function testLoadingWithoutAChoiceRefusesRatherThanGuessing(): void {
		// 🔴 NO SILENT DEFAULT. Importing because the operator clicked Run one
		// step early would plant example objects nobody asked for.
		$this->demoData->expects($this->never())->method('install');

		$response = $this->controller()->runAction('load-demo-data');

		$this->assertSame(400, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testChoosingNoneAndThenRunningImportsNothing(): void {
		$this->config['demo_dataset'] = 'none';
		$this->demoData->expects($this->never())->method('install');

		$data = $this->controller()->runAction('load-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('No example data', $data['message']);
	}

	public function testTheLegacyActionStillImportsTheShippedDataset(): void {
		// `install-demo-data` was the id before the step asked WHICH dataset. A
		// runbook or script that still posts it must keep working, and it names
		// the shipped set by naming itself.
		$this->demoData->method('install')->willReturn(['objects' => 5, 'registers' => 1, 'schemas' => 2]);

		$data = $this->controller()->runAction('install-demo-data')->getData();

		$this->assertTrue($data['success']);
		$this->assertStringContainsString('5', $data['message']);
	}

	public function testAFailedLoadIsReportedAndLeavesTheStepUNDECIDED(): void {
		// Recording the decision here would close the step for an operator who
		// asked for example data and received none.
		$this->config['demo_dataset'] = 'demo';
		$this->demoData->method('install')->willThrowException(new RuntimeException('OpenRegister is not installed.'));

		$response = $this->controller()->runAction('load-demo-data');

		$this->assertFalse($response->getData()['success']);
		$this->assertArrayNotHasKey('demo_data_decided', $this->written);
	}
}
