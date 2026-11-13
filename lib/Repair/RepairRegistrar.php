<?php

/**
 * Buildiq repair step registrar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Buildiq\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Repair;

use OCA\Buildiq\Service\SettingsService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the hand-built repair step factories.
 *
 * @category Repair
 * @package  OCA\Buildiq\Repair
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class RepairRegistrar {

	/**
	 * Bind the concrete settings repair step.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerSettingsRepairStep(IRegistrationContext $context): void {
		// InitializeSettings repair step — bind Buildiq's own class so it wins
		// over the generic AppHost binding (last registration wins). Bootstrap
		// above re-registers this exact class name to OpenRegister's
		// GenericInitializeSettings, which imports via the broken generic
		// AppHostSettingsService (2-arg importFromApp) and does NO register.d/
		// fragment merge. info.xml declares OCA\Buildiq\Repair\InitializeSettings
		// as an <install>/<post-migration> step; NC's repair runner resolves that
		// class name through the container, so without this the generic (failing)
		// step runs on install and the buildiq register is never created.
		// Re-registering the concrete step here makes install use the correct
		// 4-arg importer + fragment merge (via the concrete SettingsService above).
		$context->registerService(
			InitializeSettings::class,
			static fn ($c): InitializeSettings => new InitializeSettings(
				settingsService: $c->get(SettingsService::class),
				logger: $c->get('Psr\\Log\\LoggerInterface')
			)
		);
	}//end registerSettingsRepairStep()
}//end class
