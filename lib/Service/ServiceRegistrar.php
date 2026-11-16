<?php

/**
 * Buildiq service registrar
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
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

use OCA\Buildiq\Dashboard\VirtualAppWidgetContext;
use OCA\Buildiq\Service\Connection\ConnectionReporter;
use OCA\Buildiq\Service\Dashboard\AggregationGateway;
use OCA\Buildiq\Service\Dashboard\OpenRegisterAggregationGateway;
use OCA\Buildiq\Service\Dashboard\WidgetItemProjector;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Contract\RegisterSlugResolverInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IManager as IDashboardManager;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers the service bindings and hand-built service factories, and boots
 * the services that hook into Nextcloud's navigation and dashboard.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/app-composition-bindings/spec.md
 */
final class ServiceRegistrar {

	/**
	 * Bind OpenRegister's published contracts to their implementations.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerOpenRegisterContracts(IRegistrationContext $context): void {
		// ADR-084: services type-hint OpenRegister's PUBLISHED interface, never its
		// concrete class, so this app's unit tests can mock a type they are able to
		// load. Nextcloud autowires concrete classes across apps but not interfaces,
		// so the binding has to be stated — and the composition root is where this
		// app says how it is wired.
		//
		// An ALIAS, not a factory: it resolves when something actually asks for the
		// interface, so an instance without OpenRegister fails at the route that
		// needed the data rather than at registration. Both names are strings and
		// neither triggers an autoload, which is what keeps ADR-083 rule 3's promise
		// that the start screen still boots.
		$context->registerServiceAlias(
			ObjectServiceInterface::class,
			'OCA\OpenRegister\Service\ObjectService'
		);

		// The register-slug resolver, bound the same way and for the same reason.
		//
		// Register slugs live in `openregister_registers`, and nine fleet apps
		// ship a repair step that renames theirs. The step is per instance, so
		// both slugs are live across the estate at once and a literal is wrong
		// on half of it. The old-slug case is the quiet one: OpenRegister finds
		// no register, matches no rows, and returns an empty set that is
		// byte-for-byte what a healthy empty register returns. No exception, no
		// 404, no log line. This app read the connectors channel that way.
		//
		// Verified against this container, not assumed: OpenRegister registers
		// the resolver in its OWN container, so nothing of that registration
		// reaches here. What reaches here is the alias stated here plus autowiring
		// of the concrete class, whose only dependencies are `RegisterMapper`
		// and `LoggerInterface`. Both resolve from a leaf app's DIContainer, and
		// the interface then answers with a live resolution. The one thing lost
		// is OpenRegister's shared-instance registration: a leaf container
		// autowires a fresh resolver per injection point, so the request-scoped
		// memo is per consumer rather than per request. That costs one indexed
		// read per consumer and changes no answer.
		$context->registerServiceAlias(
			RegisterSlugResolverInterface::class,
			'OCA\OpenRegister\Service\RegisterSlugResolver'
		);
	}//end registerOpenRegisterContracts()

	/**
	 * Alias every Kernel contract to its Service implementation.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerContractBindings(IRegistrationContext $context): void {
		(new ContractBindings())->register(context: $context);
	}//end registerContractBindings()

	/**
	 * Bind the services behind the top bar and the dashboard widgets.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerDashboardServices(IRegistrationContext $context): void {
		// The published-Application read is SHARED on purpose. Autowiring
		// builds a fresh instance per injection point, and two instances mean
		// two per-request caches and two OpenRegister queries — the exact
		// thing this provider exists to prevent. Both boot-time consumers
		// (AppNavigationService for the top bar, DashboardWidgetRegistrar for
		// the dashboard widgets) resolve this one name.
		$context->registerService(
			PublishedApplicationProvider::class,
			static fn ($c): PublishedApplicationProvider => new PublishedApplicationProvider(
				objectService: $c->get(ObjectServiceInterface::class)
			)
		);

		// AppNavigationService takes the shared provider explicitly, because
		// its own constructor keeps the object service for the fallback path.
		$context->registerService(
			AppNavigationService::class,
			static fn ($c): AppNavigationService => new AppNavigationService(
				objectService: $c->get(ObjectServiceInterface::class),
				urlGenerator: $c->get('OCP\\IURLGenerator'),
				caller: $c->get(CallerContext::class),
				appConfig: $c->get('OCP\\IAppConfig'),
				logger: $c->get(LoggerInterface::class),
				published: new PublishedApps(
					applicationProvider: $c->get(PublishedApplicationProvider::class),
					visibilityResolver: $c->get(AppVisibilityResolver::class),
					organisationGuard: $c->get(AppOrganisationGuard::class)
				)
			)
		);

		// The registrar and the projector are bound explicitly rather than
		// autowired. Autowiring builds them as PHP 8.4 lazy ghosts, and when a
		// constructor argument fails to resolve, Nextcloud's ExceptionSerializer
		// runs get_object_vars() over the half-built ghost while serialising
		// that failure — which re-enters the initializer and throws a SECOND
		// time, this time out of the catch block that was handling the first.
		// A closure factory never becomes a ghost, so the guard in
		// registerDashboardWidgets() actually gets to do its job.
		$context->registerService(
			DashboardWidgetRegistrar::class,
			static fn ($c): DashboardWidgetRegistrar => new DashboardWidgetRegistrar(
				applications: $c->get(PublishedApplicationProvider::class),
				visibility: $c->get(AppVisibilityResolver::class),
				logger: $c->get(LoggerInterface::class)
			)
		);
		$context->registerService(
			WidgetItemProjector::class,
			static fn ($c): WidgetItemProjector => new WidgetItemProjector(
				objectService: $c->get(ObjectServiceInterface::class),
				aggregations: $c->get(AggregationGateway::class),
				urlGenerator: $c->get(IURLGenerator::class),
				l10n: $c->get('OCP\\IL10N'),
				logger: $c->get(LoggerInterface::class)
			)
		);

		// OpenRegister publishes no aggregation method on its contract, so the
		// gateway resolves the runner duck-typed and answers null when it is
		// absent. Bound here so the projector type-hints the interface.
		$context->registerService(
			AggregationGateway::class,
			static fn ($c): AggregationGateway => new OpenRegisterAggregationGateway(
				container: $c,
				logger: $c->get(LoggerInterface::class)
			)
		);
	}//end registerDashboardServices()

	/**
	 * Bind the concrete settings service.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-composition-bindings/spec.md
	 */
	public function registerSettingsService(IRegistrationContext $context): void {
		// SettingsService — bind the concrete Buildiq implementation so it wins
		// over the generic AppHost binding (last registration wins). When
		// Bootstrap::register ran (above), it aliases this leaf class name to
		// OpenRegister's generic AppHostSettingsService, whose loadConfiguration()
		// calls ConfigurationService::importFromApp() with a stale 2-argument
		// signature (OR `development` now requires 4) and skips the ADR-037
		// register.d/ fragment merge. Without this override the SettingsController
		// factory below also resolves `$c->get(SettingsService::class)` to the
		// generic AppHostSettingsService and fatals with a TypeError (constructor
		// arg #2 type mismatch), 500-ing `GET /api/settings` and leaving the app
		// detail page in its empty fallback ("Untitled application", `buildiq--`
		// register). On the app-enable/install path the InitializeSettings repair
		// step resolves THIS class directly, so under the generic binding the
		// register import fails with "importFromApp(): Argument #2 ($data) not
		// passed" — leaving the buildiq register uncreated until a manual
		// re-import. Registering the concrete class here (mirroring the controllers
		// above) guarantees every caller — the InitializeSettings repair step AND
		// SettingsController — uses the correct 4-arg importer + fragment merge on
		// all paths.
		$context->registerService(
			SettingsService::class,
			static fn ($c): SettingsService => new SettingsService(
				appConfig: $c->get('OCP\\IAppConfig'),
				appManager: $c->get('OCP\\App\\IAppManager'),
				container: $c,
				caller: $c->get(CallerContext::class),
				logger: $c->get('Psr\\Log\\LoggerInterface'),
				// Adopt-connection-registry: without it a store save would never
				// ask integriq to look again, and nothing would say so. The
				// argument is optional, so leaving it out here is a silent no-op.
				connectionReporter: $c->get(ConnectionReporter::class)
			)
		);
	}//end registerSettingsService()

	/**
	 * Register the per-published-app top-bar navigation entries.
	 *
	 * @param ContainerInterface $container The app container
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-nav-entries/spec.md
	 */
	public function registerNavEntries(ContainerInterface $container): void {
		$container->get(AppNavigationService::class)
			->registerNavEntries($container->get(INavigationManager::class));
	}//end registerNavEntries()

	/**
	 * Register the promoted virtual-app dashboard widgets.
	 *
	 * Never throws. An instance without OpenRegister, a failing object
	 * service, or a Nextcloud without the Dashboard app all land here as a
	 * warning and zero registered widgets, and the dashboard renders its other
	 * widgets normally.
	 *
	 * @param ContainerInterface $container The app container.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-promoted-widgets-are-registered-with-the-nextcloud-dashboard
	 */
	public function registerDashboardWidgets(ContainerInterface $container): void {
		$widgetContext = new VirtualAppWidgetContext(
			urlGenerator: $container->get(IURLGenerator::class),
			userSession: $container->get('OCP\\IUserSession'),
			groupManager: $container->get('OCP\\IGroupManager'),
			visibility: $container->get(AppVisibilityResolver::class),
			projector: $container->get(WidgetItemProjector::class),
			initialState: $container->get(IInitialState::class)
		);

		$container->get(DashboardWidgetRegistrar::class)->registerWidgets(
			container: $container,
			dashboardManager: $container->get(IDashboardManager::class),
			context: $widgetContext
		);
	}//end registerDashboardWidgets()
}//end class
