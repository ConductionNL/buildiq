<?php

/**
 * Contract of SettingsService
 *
 * The public surface of SettingsService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Buildiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Kernel\Contracts;

/**
 * Contract of SettingsService.
 *
 * @category Kernel
 * @package  OCA\Buildiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/buildiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SettingsServiceInterface {

	/**
	 * Retrieve all current settings.
	 *
	 * Returns a flat array containing all app config values plus metadata
	 * fields (openregisters, isAdmin) consumed by the frontend.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-settings-and-observability/tasks.md#task-1
	 */
	public function getSettings(): array;

	/**
	 * Check whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-settings-and-observability/tasks.md#task-1
	 */
	public function isOpenRegisterAvailable(): bool;

	/**
	 * Force a re-import of openbuild_register.json via OpenRegister, ignoring
	 * any cached or already-imported state.
	 *
	 * Used by the InitializeSettings repair step and the admin "Reload" action.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-settings-and-observability/tasks.md#task-3
	 */
	public function reloadConfiguration(): array;

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-25-settings-and-observability/tasks.md#task-2
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#requirement-req-biq-conn-002-a-store-settings-save-asks-integriq-to-look-again
	 */
	public function updateSettings(array $data): array;
}//end interface
