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
	 * `registry_register` defaults to `buildiq` (the catalogue's register
	 * segment); `registry_url` defaults to '' so the store stays hidden until an
	 * admin configures it (the placeholder URL is only a UI hint, never stored).
	 *
	 * @var array<string, string>
	 */
	private const CONFIG_DEFAULTS = [
		'registry_register' => 'buildiq',
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
	 * Constructor for the SettingsService.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param IAppManager $appManager The app manager
	 * @param ContainerInterface $container The container
	 * @param IGroupManager $groupManager The group manager
	 * @param IUserSession $userSession The user session
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private IGroupManager $groupManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
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
			$settings[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
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
	 * @return array<string,mixed> The updated settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-settings-and-observability/tasks.md#task-2
	 */
	public function updateSettings(array $data): array {
		foreach (self::CONFIG_KEYS as $key) {
			if (isset($data[$key]) === true) {
				$this->appConfig->setValueString(Application::APP_ID, $key, (string)$data[$key]);
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
			}
		}

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
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		$configPath = __DIR__ . '/../Settings/openbuild_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error('Buildiq: openbuild_register.json not found at ' . $configPath);
			return [
				'success' => false,
				'message' => 'Configuration file openbuild_register.json not found.',
			];
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			$this->logger->error('Buildiq: failed to read openbuild_register.json');
			return [
				'success' => false,
				'message' => 'Failed to read configuration file.',
			];
		}

		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Buildiq: failed to parse openbuild_register.json: ' . json_last_error_msg());
			return [
				'success' => false,
				'message' => 'Failed to parse configuration file: ' . json_last_error_msg(),
			];
		}

		// ADR-037: merge modular register fragments from Settings/register.d/*.json.
		// Each OpenSpec change drops its own fragment file instead of editing this
		// monolith, so concurrent builds touch disjoint files (no merge conflicts).
		// OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
		// fragments union cleanly by key.
		$fragmentDir = __DIR__ . '/../Settings/register.d';
		$fragmentSig = '';
		if (is_dir($fragmentDir) === true) {
			$fragmentFiles = glob($fragmentDir . '/*.json');
			sort($fragmentFiles);
			foreach ($fragmentFiles as $fragmentFile) {
				$fragmentContent = file_get_contents($fragmentFile);
				if ($fragmentContent === false) {
					continue;
				}

				$fragmentData = json_decode($fragmentContent, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					$this->logger->warning(
						'Buildiq: skipping malformed register fragment ' . basename($fragmentFile)
						. ': ' . json_last_error_msg()
					);
					continue;
				}

				$configData = self::deepMergeConfig(base: $configData, overlay: $fragmentData);
				$fragmentSig .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';
			}
		}//end if

		// Fold the fragment signature into the version so OpenRegister's
		// version-gated importFromApp re-imports whenever fragments change.
		$configVersion = ($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$configVersion .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		try {
			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
			$result = $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $configData,
				version: $configVersion,
				force: $force
			);

			if (empty($result) === false) {
				$this->logger->info('Buildiq: register configuration imported successfully');
				return [
					'success' => true,
					'message' => 'Configuration imported successfully.',
					'version' => ($result['version'] ?? $configVersion),
				];
			}

			return [
				'success' => false,
				'message' => 'Import returned an empty result.',
			];
		} catch (\Throwable $e) {
			$this->logger->error(
				'Buildiq: configuration import failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try
	}//end doLoadConfiguration()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			if (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true
			) {
				$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
				$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
				if ($baseIsList === true && $overlayIsList === true) {
					$base[$key] = array_merge($base[$key], $value);
					continue;
				}

				$base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value);
				continue;
			}

			$base[$key] = $value;
		}

		return $base;
	}//end deepMergeConfig()
}//end class
