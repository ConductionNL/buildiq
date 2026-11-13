<?php

/**
 * Buildiq admin settings registrar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Settings
 * @package  OCA\Buildiq\Settings
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

namespace OCA\Buildiq\Settings;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Sections\SettingsSection;
use OCP\App\IAppManager;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * Registers the admin settings section and panel.
 *
 * @category Settings
 * @package  OCA\Buildiq\Settings
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class SettingsRegistrar {

	/**
	 * Bind the admin settings section and panel.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerAdminSettings(IRegistrationContext $context): void {
		// 4. SettingsSection + AdminSettings — the admin settings section and
		// panel referenced by info.xml `<settings>`. Both are one-line leaf
		// subclasses of OpenRegister's GenericSettingsSection / GenericAdminSettings,
		// whose constructors take scalar params (sectionId, name, icon, priority)
		// that are NOT autowireable — they MUST be bound by a service factory.
		// Bootstrap::register() normally binds them, but when Bootstrap is not
		// autoloadable at register() time (it lives in OCA\OpenRegister and that
		// app's autoloader is not guaranteed to be registered before Buildiq
		// boots — `buildiq` sorts before `openregister`), the else branch above
		// skips it. NC's settings Manager instantiates EVERY registered section to
		// render ANY settings page, so an unbound `sectionId` throws
		// QueryNotFoundException that escapes NC's catch and blanks ALL admin AND
		// personal settings pages instance-wide (not just Buildiq's). Register
		// the leaf classes unconditionally here with Bootstrap's exact defaults so
		// they resolve regardless of app load order (last registration wins).
		$context->registerService(
			SettingsSection::class,
			static fn ($c): SettingsSection => new SettingsSection(
				sectionId: AppIdentity::APP_ID,
				name: 'Buildiq',
				appId: AppIdentity::APP_ID,
				iconFile: 'app-dark.svg',
				priority: 75,
				urlGenerator: $c->get(IURLGenerator::class)
			)
		);
		$context->registerService(
			AdminSettings::class,
			static fn ($c): AdminSettings => new AdminSettings(
				appId: AppIdentity::APP_ID,
				sectionId: AppIdentity::APP_ID,
				priority: 10,
				appManager: $c->get(IAppManager::class),
				initialState: $c->get(IInitialState::class),
				appConfig: $c->get(IAppConfig::class)
			)
		);
	}//end registerAdminSettings()
}//end class
