<?php

/**
 * OpenBuild Settings Service
 *
 * Service for managing OpenBuild application configuration and settings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuild\Service
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

namespace OCA\OpenBuild\Service;

use OCA\OpenBuild\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing OpenBuild application configuration and settings.
 */
class SettingsService
{

    /**
     * Configuration keys managed by this service.
     *
     * @var array<string>
     */
    private const CONFIG_KEYS = [
        'register',
    ];

    /**
     * Constructor for the SettingsService.
     *
     * @param IAppConfig         $appConfig    The app config interface
     * @param IAppManager        $appManager   The app manager
     * @param ContainerInterface $container    The container
     * @param IGroupManager      $groupManager The group manager
     * @param IUserSession       $userSession  The user session
     * @param LoggerInterface    $logger       The logger
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
     */
    public function isOpenRegisterAvailable(): bool
    {
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
    public function getSettings(): array
    {
        $settings = [];
        foreach (self::CONFIG_KEYS as $key) {
            $settings[$key] = $this->appConfig->getValueString(Application::APP_ID, $key, '');
        }

        $user    = $this->userSession->getUser();
        $isAdmin = ($user !== null && $this->groupManager->isAdmin($user->getUID()));

        return array_merge(
            $settings,
            [
                'openregisters' => $this->isOpenRegisterAvailable(),
                'isAdmin'       => $isAdmin,
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
    public function updateSettings(array $data): array
    {
        foreach (self::CONFIG_KEYS as $key) {
            if (isset($data[$key]) === true) {
                $this->appConfig->setValueString(Application::APP_ID, $key, (string) $data[$key]);
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
    public function loadConfiguration(): array
    {
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
    public function reloadConfiguration(): array
    {
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
    private function doLoadConfiguration(bool $force): array
    {
        if ($this->isOpenRegisterAvailable() === false) {
            $this->logger->warning('OpenBuild: OpenRegister not available, skipping register initialization');
            return [
                'success' => false,
                'message' => 'OpenRegister is not installed or enabled.',
            ];
        }

        $configPath = __DIR__.'/../Settings/openbuild_register.json';
        if (file_exists($configPath) === false) {
            $this->logger->error('OpenBuild: openbuild_register.json not found at '.$configPath);
            return [
                'success' => false,
                'message' => 'Configuration file openbuild_register.json not found.',
            ];
        }

        $configContent = file_get_contents($configPath);
        if ($configContent === false) {
            $this->logger->error('OpenBuild: failed to read openbuild_register.json');
            return [
                'success' => false,
                'message' => 'Failed to read configuration file.',
            ];
        }

        $configData = json_decode($configContent, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('OpenBuild: failed to parse openbuild_register.json: '.json_last_error_msg());
            return [
                'success' => false,
                'message' => 'Failed to parse configuration file: '.json_last_error_msg(),
            ];
        }

        // ADR-037: merge modular register fragments from Settings/register.d/*.json.
        // Each OpenSpec change drops its own fragment file instead of editing this
        // monolith, so concurrent builds touch disjoint files (no merge conflicts).
        // OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
        // fragments union cleanly by key.
        $fragmentDir = __DIR__.'/../Settings/register.d';
        $fragmentSig = '';
        if (is_dir($fragmentDir) === true) {
            $fragmentFiles = glob($fragmentDir.'/*.json');
            sort($fragmentFiles);
            foreach ($fragmentFiles as $fragmentFile) {
                $fragmentContent = file_get_contents($fragmentFile);
                if ($fragmentContent === false) {
                    continue;
                }

                $fragmentData = json_decode($fragmentContent, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->warning(
                        'OpenBuild: skipping malformed register fragment '.basename($fragmentFile)
                        .': '.json_last_error_msg()
                    );
                    continue;
                }

                $configData   = self::deepMergeConfig(base: $configData, overlay: $fragmentData);
                $fragmentSig .= basename($fragmentFile).':'.md5($fragmentContent).';';
            }
        }//end if

        // Fold the fragment signature into the version so OpenRegister's
        // version-gated importFromApp re-imports whenever fragments change.
        $configVersion = ($configData['info']['version'] ?? '0.0.0');
        if ($fragmentSig !== '') {
            $configVersion .= '+frag.'.substr(md5($fragmentSig), 0, 8);
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
                $this->logger->info('OpenBuild: register configuration imported successfully');
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
                'OpenBuild: configuration import failed',
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
     * @param array<mixed> $base    The accumulated config.
     * @param array<mixed> $overlay The fragment to merge in.
     *
     * @return array<mixed> The merged config.
     */
    private static function deepMergeConfig(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) === true
                && isset($base[$key]) === true
                && is_array($base[$key]) === true
            ) {
                $baseIsList    = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
                $overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
                if ($baseIsList === true && $overlayIsList === true) {
                    $base[$key] = array_merge($base[$key], $value);
                } else {
                    $base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value);
                }
            } else {
                $base[$key] = $value;
            }
        }

        return $base;

    }//end deepMergeConfig()
}//end class
