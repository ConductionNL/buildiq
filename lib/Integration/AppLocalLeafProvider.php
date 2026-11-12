<?php

/**
 * App-local Leaf Provider
 *
 * The descriptor every buildiq leaf gives OpenRegister: it is served from
 * buildiq's own register, answers whenever buildiq is installed, has no
 * OpenConnector source, and asks for no permission or credentials of its own.
 * Each leaf adds its own id, label, icon, group and data methods.
 *
 * @category Integration
 * @package  OCA\Buildiq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-006)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Integration;

use OCA\OpenRegister\Service\Integration\IntegrationProvider;

/**
 * The shared descriptor of a leaf whose objects live in buildiq's own register.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
 */
abstract class AppLocalLeafProvider implements IntegrationProvider {
	/**
	 * The app that must be installed for this leaf to answer.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
	 */
	public function getRequiredApp(): string {
		return 'buildiq';
	}//end getRequiredApp()

	/**
	 * Layouts and forms live in buildiq's own register.
	 *
	 * @return string The storage strategy.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
	 */
	public function getStorageStrategy(): string {
		return 'app-local';
	}//end getStorageStrategy()

	/**
	 * No OpenConnector source.
	 *
	 * @return string|null The source.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * The leaf answers whenever buildiq is installed.
	 *
	 * @return bool True.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * Reading a leaf needs no right of its own. A layout is page furniture, and
	 * the object's own permissions still decide what the page can show; a form
	 * draft is scoped to its owner.
	 *
	 * @return string|null Null.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
	 */
	public function requiresPermission(): ?string {
		return null;
	}//end requiresPermission()

	/**
	 * The leaf needs no credentials of its own.
	 *
	 * @return array<string, mixed> The requirements.
	 *
	 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md (REQ-OBPL-003)
	 */
	public function authRequirements(): array {
		return [];
	}//end authRequirements()
}//end class
