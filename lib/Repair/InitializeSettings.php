<?php

/**
 * Buildiq Initialize Settings Repair Step
 *
 * Repair step that initializes Buildiq register and schemas on install/upgrade.
 *
 * @category Repair
 * @package  OCA\Buildiq\Repair
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

namespace OCA\Buildiq\Repair;

use OCA\Buildiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Repair step that initializes Buildiq configuration via SettingsService.
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
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Initialize Buildiq register and schemas via ConfigurationService';
	}//end getName()

	/**
	 * Run the repair step to initialize Buildiq configuration.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-4
	 */
	public function run(IOutput $output): void {
		$output->info('Initializing Buildiq configuration...');

		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning(
				'OpenRegister is not installed or enabled. Skipping auto-configuration.'
			);
			$this->logger->warning(
				'Buildiq: OpenRegister not available, skipping register initialization'
			);
			return;
		}

		try {
			$result = $this->settingsService->reloadConfiguration();

			if ($result['success'] === true) {
				$version = ($result['version'] ?? 'unknown');
				$output->info(
					'Buildiq configuration imported successfully (version: ' . $version . ')'
				);
				return;
			}

			$message = ($result['message'] ?? 'unknown error');
			$output->warning(
				'Buildiq configuration import issue: ' . $message
			);
		} catch (\Throwable $e) {
			$output->warning('Could not auto-configure Buildiq: ' . $e->getMessage());
			$this->logger->error(
				'Buildiq initialization failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end run()
}//end class
