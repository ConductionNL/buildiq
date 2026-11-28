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
use Psr\Container\ContainerInterface;
use ReflectionClass;

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
	 * OpenRegister's value classes for a settings section and panel.
	 *
	 * @var string
	 */
	private const PLACEMENT = 'OCA\\OpenRegister\\AppHost\\Settings\\SettingsPlacement';

	/**
	 * @var string
	 */
	private const APPEARANCE = 'OCA\\OpenRegister\\AppHost\\Settings\\SectionAppearance';

	/**
	 * @var string
	 */
	private const FORM_SERVICES = 'OCA\\OpenRegister\\AppHost\\Settings\\AdminFormServices';

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
		// whose constructors take values that are NOT autowireable (the scalars
		// sectionId, name, icon, priority, or on current OpenRegister the
		// SettingsPlacement / SectionAppearance / AdminFormServices values that
		// carry them) — they MUST be bound by a service factory.
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
			static fn (ContainerInterface $c): SettingsSection => self::section(urlGenerator: $c->get(IURLGenerator::class))
		);
		$context->registerService(
			AdminSettings::class,
			static fn (ContainerInterface $c): AdminSettings => self::panel(
				appManager: $c->get(IAppManager::class),
				initialState: $c->get(IInitialState::class),
				appConfig: $c->get(IAppConfig::class)
			)
		);
	}//end registerAdminSettings()
	/**
	 * The Buildiq settings section, built the way the installed OpenRegister's constructor takes it.
	 *
	 * OpenRegister moved GenericSettingsSection's scalar parameters into
	 * SettingsPlacement + SectionAppearance values. Building it with the old
	 * named parameters threw "Unknown named parameter $appId" and answered 500
	 * on /settings/admin/buildiq; older OpenRegister (and the unit-test stub)
	 * still take the scalars.
	 *
	 * @param IURLGenerator $urlGenerator The URL generator.
	 *
	 * @return SettingsSection
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	private static function section(IURLGenerator $urlGenerator): SettingsSection {
		if (self::takesPlacement(class: SettingsSection::class) === true) {
			$placement  = self::PLACEMENT;
			$appearance = self::APPEARANCE;
			// Named keys: the unit-test stub, which static analysis reads, still
			// declares the scalar constructor.
			return (new ReflectionClass(objectOrClass: SettingsSection::class))->newInstanceArgs(
				args: [
					'placement'    => new $placement(AppIdentity::APP_ID, AppIdentity::APP_ID, 75),
					'appearance'   => new $appearance('Buildiq', 'app-dark.svg'),
					'urlGenerator' => $urlGenerator,
				]
			);
		}

		return new SettingsSection(
			sectionId: AppIdentity::APP_ID,
			name: 'Buildiq',
			appId: AppIdentity::APP_ID,
			iconFile: 'app-dark.svg',
			priority: 75,
			urlGenerator: $urlGenerator
		);
	}//end section()

	/**
	 * The Buildiq admin panel, built the way the installed OpenRegister's constructor takes it.
	 *
	 * @param IAppManager   $appManager   The app manager.
	 * @param IInitialState $initialState The initial state.
	 * @param IAppConfig    $appConfig    The app config.
	 *
	 * @return AdminSettings
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	private static function panel(IAppManager $appManager, IInitialState $initialState, IAppConfig $appConfig): AdminSettings {
		if (self::takesPlacement(class: AdminSettings::class) === true) {
			$placement = self::PLACEMENT;
			$services  = self::FORM_SERVICES;
			return (new ReflectionClass(objectOrClass: AdminSettings::class))->newInstanceArgs(
				args: [
					'placement' => new $placement(AppIdentity::APP_ID, AppIdentity::APP_ID, 10),
					'services'  => new $services($appManager, $initialState, $appConfig),
				]
			);
		}

		return new AdminSettings(
			appId: AppIdentity::APP_ID,
			sectionId: AppIdentity::APP_ID,
			priority: 10,
			appManager: $appManager,
			initialState: $initialState,
			appConfig: $appConfig
		);
	}//end panel()

	/**
	 * Whether the class's constructor takes OpenRegister's SettingsPlacement first.
	 *
	 * @param class-string $class The settings class.
	 *
	 * @return bool
	 */
	private static function takesPlacement(string $class): bool {
		if (class_exists(self::PLACEMENT) === false) {
			return false;
		}

		$constructor = (new ReflectionClass(objectOrClass: $class))->getConstructor();
		$first       = ($constructor?->getParameters()[0] ?? null);

		return ($first !== null && ltrim((string) $first->getType(), characters: '?\\') === self::PLACEMENT);
	}//end takesPlacement()
}//end class
