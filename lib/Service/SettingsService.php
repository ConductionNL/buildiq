<?php

/**
 * Buildiq Settings Service
 *
 * Service for managing Buildiq application configuration and settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
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

namespace OCA\Buildiq\Service;

use OCA\Buildiq\AppInfo\Application;
use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing Buildiq application configuration and settings.
 *
 * @spec openspec/changes/openbuild-remote-template-store/specs/openbuild-remote-template-store/spec.md
 */
class SettingsService {

	/**
	 * Configuration keys managed by this service (plain read/write).
	 *
	 * @var array<string>
	 */
	private const CONFIG_KEYS = [
		'register',
		'registry_url',
		'registry_register',
	];

	/**
	 * Per-key default values. Keys absent here default to ''.
	 * `register` defaults to the app's own register, the one the repair step
	 * imports, so a fresh install shows it instead of an empty field.
	 * `registry_register` defaults to `buildiq` (the catalogue's register
	 * segment); `registry_url` defaults to '' so the store stays hidden until an
	 * admin configures it (the placeholder URL is only a UI hint, never stored).
	 *
	 * @var array<string, string>
	 */
	private const CONFIG_DEFAULTS = [
		'register' => ApplicationVersionService::REGISTER_SLUG,
		'registry_register' => 'buildiq',
	];

	/**
	 * Keys whose stored empty value also falls back to the default.
	 *
	 * Saving the admin form with the field cleared writes '', and an empty
	 * register points Buildiq nowhere. Reading that back as the app's own
	 * register keeps the field and the app in agreement.
	 *
	 * @var array<string>
	 */
	private const EMPTY_MEANS_DEFAULT = [
		'register',
	];

	/**
	 * Write-only config keys — accepted on update but NEVER returned by
	 * getSettings(). The remote registry read token lives here.
	 *
	 * @var array<string>
	 */
	private const SECRET_KEYS = [
		'registry_token',
	];

	/**
	 * The user session
	 *
	 * @var IUserSession
	 */
	private IUserSession $userSession;

	/**
	 * The group manager
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * Constructor for the SettingsService.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The container
	 * @param CallerContext $caller The caller's session, group memberships and the shared permission matcher.
	 * @param LoggerInterface $logger The logger
	 * @param ConnectionReporter|null $connectionReporter Asks integriq to look again after a store save.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-002-a-store-settings-save-asks-integriq-to-look-again
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		CallerContext $caller,
		private LoggerInterface $logger,
		private ?ConnectionReporter $connectionReporter = null,
	) {
		$this->userSession = $caller->userSession;
		$this->groupManager = $caller->groupManager;
	}//end __construct()

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-1
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end isOpenRegisterAvailable()

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-1
	 */
	public function getSettings(): array {
		$settings = [];
		foreach (self::CONFIG_KEYS as $key) {
			$default = (self::CONFIG_DEFAULTS[$key] ?? '');
			$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
			if ($value === '' && in_array($key, self::EMPTY_MEANS_DEFAULT, true) === true) {
				$value = $default;
			}

			$settings[$key] = $value;
		}

		$user = $this->userSession->getUser();
		$isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

		// Remote template store (buildiq-remote-template-store): expose a
		// token-presence flag + a storeConfigured flag, but NEVER the token value.
		$registryToken = $this->appConfig->getValueString(Application::APP_ID, 'registry_token', '');

		return array_merge(
			$settings,
			[
				'openregisters' => $this->isOpenRegisterAvailable(),
				'isAdmin' => $isAdmin,
				'registry_token_set' => ($registryToken !== ''),
				// `registry_url` is one of self::CONFIG_KEYS, so the loop above
				// always set it — the `?? ''` this replaces was unreachable.
				'storeConfigured' => (trim($settings['registry_url']) !== ''),
			]
		);
	}//end getSettings()

	/**
	 * Update settings with the provided data.
	 *
	 * @param array<string,mixed> $data The data to update
	 *
	 * After the write it asks integriq to resolve every connection whose keys
	 * the save wrote (adopt-connection-registry). That never throws, does
	 * nothing without integriq, and never changes the result.
	 *
	 * @return array<string,mixed> The updated settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-2
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-002-a-store-settings-save-asks-integriq-to-look-again
	 */
	public function updateSettings(array $data): array {
		$written = [];
		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
				$written[] = $key;
			}
		}

		// Write-only secrets (registry_token): an empty submitted value means
		// "leave the stored token unchanged" so re-saving the form does not wipe
		// a previously-stored token (the form never receives the value back).
		//
		// `sensitive: true` is the half that was missing. The token was already never
		// returned to the browser, but it was written as an ordinary appconfig string —
		// so it sat in cleartext in `occ config:app:get buildiq registry_token`, in
		// `occ config:list`, and in every support/status dump those feed. The flag makes
		// Nextcloud encrypt it at rest and redact it from that output.
		foreach (self::SECRET_KEYS as $key) {
			if (isset($data[$key]) === true && (string)$data[$key] !== '') {
				$this->appConfig->setValueString(
					Application::APP_ID,
					$key,
					(string)$data[$key],
					sensitive: true
				);
				$written[] = $key;
			}
		}

		$this->connectionReporter?->refreshFromSave(savedKeys: $written);

		return $this->getSettings();
	}//end updateSettings()

	/**
	 * Load configuration from openbuild_register.json via OpenRegister.
	 *
	 * Idempotent — relies on OR's ConfigurationService::importFromApp to
	 * detect already-imported state and short-circuit. Call
	 * reloadConfiguration() to force a re-import.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-3
	 */
	public function loadConfiguration(): array {
		return $this->doLoadConfiguration(force: false);
	}//end loadConfiguration()

	/**
	 * Force a re-import of openbuild_register.json via OpenRegister, ignoring
	 * any cached or already-imported state.
	 *
	 * Used by the InitializeSettings repair step and the admin "Reload" action.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-3
	 */
	public function reloadConfiguration(): array {
		return $this->doLoadConfiguration(force: true);
	}//end reloadConfiguration()

	/**
	 * Shared implementation of the configuration import — private so the
	 * boolean flag never reaches the public API.
	 *
	 * @param bool $force Whether to force re-import.
	 *
	 * @return array<string,mixed>
	 */
	private function doLoadConfiguration(bool $force): array {
		if ($this->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Buildiq: OpenRegister not available, skipping register initialization');
			return self::failure(message: 'OpenRegister is not installed or enabled.');
		}

		$config = new RegisterConfigAssembler(logger: $this->logger);
		$failure = $config->assemble();
		if ($failure !== null) {
			return self::failure(message: $failure);
		}

		return $this->importRegisterConfig(config: $config, force: $force);
	}//end doLoadConfiguration()

	/**
	 * Hand the assembled configuration to OpenRegister's ConfigurationService.
	 *
	 * @param RegisterConfigAssembler $config The assembled configuration.
	 * @param bool                 $force  Whether to force re-import.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 */
	private function importRegisterConfig(RegisterConfigAssembler $config, bool $force): array {
		try {
			$result = $this->callImportFromApp(config: $config, force: $force);
			return $this->importOutcome(result: $result, configVersion: $config->version());
		} catch (\Throwable $e) {
			$this->logger->error(
				'Buildiq: configuration import failed',
				['exception' => $e->getMessage()]
			);
			return self::failure(message: $e->getMessage());
		}
	}//end importRegisterConfig()

	/**
	 * Call OpenRegister's ConfigurationService::importFromApp.
	 *
	 * @param RegisterConfigAssembler $config The assembled configuration.
	 * @param bool                 $force  Whether to force re-import.
	 *
	 * @return mixed What importFromApp returned.
	 */
	private function callImportFromApp(RegisterConfigAssembler $config, bool $force): mixed {
		$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
		return $configurationService->importFromApp(
			appId: Application::APP_ID,
			data: $config->data(),
			version: $config->version(),
			force: $force
		);
	}//end callImportFromApp()

	/**
	 * Map an importFromApp result to the load result.
	 *
	 * @param mixed  $result        What importFromApp returned.
	 * @param string $configVersion The version handed to the import.
	 *
	 * @return array<string,mixed>
	 */
	private function importOutcome(mixed $result, string $configVersion): array {
		if (empty($result) === false) {
			$this->logger->info('Buildiq: register configuration imported successfully');
			return [
				'success' => true,
				'message' => 'Configuration imported successfully.',
				'version' => ($result['version'] ?? $configVersion),
			];
		}

		return self::failure(message: 'Import returned an empty result.');
	}//end importOutcome()

	/**
	 * A failed configuration-load result.
	 *
	 * @param string $message The failure message.
	 *
	 * @return array{success:false,message:string}
	 */
	private static function failure(string $message): array {
		return [
			'success' => false,
			'message' => $message,
		];
	}//end failure()
}//end class
