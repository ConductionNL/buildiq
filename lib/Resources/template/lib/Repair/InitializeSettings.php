<?php

/**
 * AppTemplate Initialize Settings Repair Step
 *
 * Repair step that initializes AppTemplate register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\AppTemplate\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\AppTemplate\Repair;

use OCA\AppTemplate\Service\SettingsService;
use OCA\AppTemplate\Service\FlowSeedService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes AppTemplate configuration via SettingsService.
 */
class InitializeSettings implements IRepairStep {
	/**
	 * Constructor for InitializeSettings.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private FlowSeedService $flowSeedService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Initialize AppTemplate register and schemas via ConfigurationService';
	}//end getName()

	/**
	 * Run the repair step to initialize AppTemplate configuration.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 */
	public function run(IOutput $output): void {
		$output->info('Initializing AppTemplate configuration...');

		// Flows FIRST, and unconditionally relative to the settings load: an
		// app whose registers loaded but whose flows did not is an app that
		// looks installed and cannot act. Registered under <post-migration>,
		// so this runs on install AND on every upgrade — an app is installed
		// once and upgraded many times, and a changed flow ships in an upgrade.
		$this->seedShippedFlows(output: $output);

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning(
				'OpenRegister is not installed or enabled. Skipping auto-configuration.'
			);
			$this->logger->warning(
				'AppTemplate: OpenRegister not available, skipping register initialization'
			);
			return;
		}

		try {
			$result = $this->settingsService->loadConfiguration(force: true);

			if ($result['success'] === true) {
				$version = ($result['version'] ?? 'unknown');
				$output->info(
					'AppTemplate configuration imported successfully (version: ' . $version . ')'
				);
				return;
			}

			$message = ($result['message'] ?? 'unknown error');
			$output->warning(
				'AppTemplate configuration import issue: ' . $message
			);
		} catch (\Throwable $e) {
			$output->warning('Could not auto-configure AppTemplate: ' . $e->getMessage());
			$this->logger->error(
				'AppTemplate initialization failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * Put the flows this app ships into OpenRegister, and report what happened.
	 *
	 * Never throws: a flow that cannot be seeded must not stop the app from
	 * installing. But it must not be silent either — a flow that did not seed
	 * is a feature that will simply never fire, and the operator is the only
	 * one who can act on that.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function seedShippedFlows(IOutput $output): void {
		$mapperClass = '\\OCA\\OpenRegister\\Db\\FlowMapper';
		if (class_exists($mapperClass) === false) {
			// OpenRegister absent. Not an error — the settings step below
			// reports that condition once, and twice is noise.
			return;
		}

		try {
			$report = $this->flowSeedService->seed(flowMapper: \OC::$server->get($mapperClass));
		} catch (\Throwable $e) {
			$output->warning('Could not seed shipped flows: ' . $e->getMessage());
			$this->logger->warning('AppTemplate: flow seeding failed: ' . $e->getMessage());
			return;
		}

		if ($report['seeded'] > 0) {
			$output->info('Seeded ' . $report['seeded'] . ' flow(s).');
		}

		if ($report['kept'] > 0) {
			// The operator edited these here. Saying so is the whole point:
			// silently keeping a local version leaves them believing they run
			// the shipped one.
			$output->info(
				$report['kept'] . ' flow(s) were modified on this instance and were NOT overwritten by the shipped version.'
			);
		}

		if ($report['unknownNodeTypes'] !== []) {
			$output->warning(
				'Seeded flow(s) use node types this instance does not have: '
				. implode(', ', $report['unknownNodeTypes'])
				. '. They will not run until the app providing them is installed.'
			);
		}

		foreach ($report['failed'] as $failure) {
			$output->warning('Flow not seeded — ' . $failure);
		}
	}//end seedShippedFlows()
}//end class
