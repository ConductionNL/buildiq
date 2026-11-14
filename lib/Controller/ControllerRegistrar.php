<?php

/**
 * Buildiq controller registrar
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
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

namespace OCA\Buildiq\Controller;

use OCA\Buildiq\Kernel\AppIdentity;
use OCA\Buildiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Buildiq\Kernel\Contracts\TemplateInstallSeamInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the hand-built controller factories.
 *
 * @category Controller
 * @package  OCA\Buildiq\Controller
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class ControllerRegistrar {

	/**
	 * Bind the concrete dashboard and preferences controllers that override the AppHost generics.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerConcreteControllers(IRegistrationContext $context): void {
		// Buildiq keeps three concrete controllers + the settings service that
		// the AppHost generics cannot cover on OpenRegister `development` (see
		// openspec/changes/adopt-apphost/design.md "Engine reality"):
		//
		// 1. DashboardController — publishes `currentUserGroups` to
		// IInitialState (REQ-OBR-009); the generic dashboard controller
		// does not, which would break client-side per-Application role
		// derivation.
		// 2. PreferencesController — there is NO GenericPreferencesController
		// in OpenRegister `development` (Bootstrap aliases a class that does
		// not exist), so the preferences routes would 500 under the alias.
		// 3. SettingsController + SettingsService — the generic
		// AppHostSettingsService::loadConfiguration() calls OR's
		// ConfigurationService::importFromApp() with a 2-argument signature
		// that no longer matches `development` (4 required args) and performs
		// no ADR-037 `register.d/` fragment merge, which Buildiq relies on
		// (10-business-rules.json). The bespoke SettingsController also
		// enforces body-level admin guards on create()/load().
		//
		// Re-registering these concrete classes AFTER Bootstrap::register lets
		// NC's DI container resolve the leaf class names to the real Buildiq
		// controllers (last registration wins), so the canonical routes keep
		// working while everything else is generic.
		$context->registerService(
			DashboardController::class,
			static fn ($c): DashboardController => new DashboardController(
				request: $c->get('OCP\\IRequest'),
				initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState'),
				userSession: $c->get('OCP\\IUserSession'),
				groupManager: $c->get('OCP\\IGroupManager'),
				navigationManager: $c->get('OCP\\INavigationManager')
			)
		);
		$context->registerService(
			PreferencesController::class,
			static fn ($c): PreferencesController => new PreferencesController(
				request: $c->get('OCP\\IRequest'),
				config: $c->get('OCP\\IConfig'),
				userSession: $c->get('OCP\\IUserSession')
			)
		);
	}//end registerConcreteControllers()

	/**
	 * Bind the concrete settings controller.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerSettingsController(IRegistrationContext $context): void {
		$context->registerService(
			SettingsController::class,
			static fn ($c): SettingsController => new SettingsController(
				request: $c->get('OCP\\IRequest'),
				settingsService: $c->get(SettingsServiceInterface::class),
				userSession: $c->get('OCP\\IUserSession'),
				groupManager: $c->get('OCP\\IGroupManager')
			)
		);
	}//end registerSettingsController()

	/**
	 * Alias the contracts this component implements for other components.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerContractBindings(IRegistrationContext $context): void {
		$context->registerServiceAlias(TemplateInstallSeamInterface::class, ApplicationsController::class);
	}//end registerContractBindings()

	/**
	 * Bind the health and metrics controllers to the generic AppHost implementations.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerObservabilityControllers(IRegistrationContext $context): void {
		// 5. Observability probes (health + metrics) — same load-order hazard as
		// the settings section above, but with a worse symptom. `routes.php`
		// calls \OCA\OpenRegister\AppHost\Routes::standard(), which emits
		// `health#index` and `metrics#index` UNCONDITIONALLY. routes.php is
		// evaluated at request-routing time, by which point OpenRegister's
		// autoloader IS registered — so the routes always exist. The controllers
		// behind them, however, are only bound by Bootstrap::register(), which
		// the guard above skips because `buildiq` sorts before `openregister`
		// and OR is not autoloadable that early. The result is a routed endpoint
		// with no controller: GET /api/health and GET /api/metrics both 500.
		//
		// Buildiq has no concrete Health/MetricsController of its own (ADR-040
		// moved them to the AppHost engine), so bind the leaf class names to the
		// generic implementations here, mirroring Bootstrap::registerControllers.
		// The OpenRegister class names are referenced as STRINGS and every
		// dependency is resolved INSIDE the closure, so nothing touches the
		// OCA\OpenRegister namespace until the container resolves the controller
		// at request time — deferring the binding instead of guarding at boot.
		$context->registerService(
			'OCA\\Buildiq\\Controller\\HealthController',
			static function ($c) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericHealthController';
				return new $class(
					appName: AppIdentity::APP_ID,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					executor: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor')
				);
			}
		);
		$context->registerService(
			'OCA\\Buildiq\\Controller\\MetricsController',
			static function ($c) {
				$class = 'OCA\\OpenRegister\\AppHost\\Controller\\GenericMetricsController';
				return new $class(
					appName: AppIdentity::APP_ID,
					request: $c->get('OCP\\IRequest'),
					manifestLoader: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader'),
					engine: $c->get('OCA\\OpenRegister\\AppHost\\Observability\\MetricsEngine')
				);
			}
		);
	}//end registerObservabilityControllers()
}//end class
