<?php

/**
 * OpenBuild Application
 *
 * Main application class for the OpenBuild Nextcloud app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\OpenBuild\AppInfo
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

namespace OCA\OpenBuild\AppInfo;

use OCA\OpenBuild\Controller\DashboardController;
use OCA\OpenBuild\Controller\PreferencesController;
use OCA\OpenBuild\Controller\SettingsController;
use OCA\OpenBuild\Lifecycle\ApplicationVersionOwnerGuard;
use OCA\OpenBuild\Listener\ProductionVersionGuardListener;
use OCA\OpenBuild\Mcp\OpenBuildToolProvider;
use OCA\OpenBuild\Service\AppNavigationService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenBuild\Service\SettingsService;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Main application class for the OpenBuild Nextcloud app.
 *
 * @spec openspec/changes/archive/2026-05-12-openbuild-rbac/tasks.md
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'openbuild';

    /**
     * Constructor for the Application class.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct(appName: self::APP_ID);
    }//end __construct()

    /**
     * Register event listeners and services.
     *
     * @param IRegistrationContext $context The registration context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     *
     * @spec openspec/changes/archive/2026-05-12-openbuild-rbac/tasks.md
     */
    public function register(IRegistrationContext $context): void
    {
        // ADR-040 AppHost adoption: one call wires the standard plumbing —
        // the generic dashboard/settings/preferences controllers, the
        // observability (health + metrics) controllers, the install repair
        // steps, the admin settings panel + section, and the manifest-driven
        // deep-link listener (reads src/manifest.json `deepLinks`, replacing
        // the bespoke DeepLinkRegistrationListener that was deleted).
        //
        // Every Bootstrap registration is a lazy closure, so a disabled/absent
        // OpenRegister never fatals NC bootstrap (see Bootstrap docblock).
        //
        // `mcpProvider` is wired below explicitly (not via Bootstrap) because
        // the domain registrations that follow override the relevant aliases.
        Bootstrap::register(
            $context,
            self::APP_ID,
            [
                'namespace'     => 'OCA\\OpenBuild',
                'sectionName'   => 'OpenBuild',
                'observability' => true,
            ]
        );

        // OpenBuild keeps three concrete controllers + the settings service that
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
        // no ADR-037 `register.d/` fragment merge, which OpenBuild relies on
        // (10-business-rules.json). The bespoke SettingsController also
        // enforces body-level admin guards on create()/load().
        //
        // Re-registering these concrete classes AFTER Bootstrap::register lets
        // NC's DI container resolve the leaf class names to the real OpenBuild
        // controllers (last registration wins), so the canonical routes keep
        // working while everything else is generic.
        $context->registerService(
            DashboardController::class,
            static fn ($c): DashboardController => new DashboardController(
                request: $c->get('OCP\\IRequest'),
                initialState: $c->get('OCP\\AppFramework\\Services\\IInitialState'),
                userSession: $c->get('OCP\\IUserSession'),
                groupManager: $c->get('OCP\\IGroupManager')
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
        $context->registerService(
            SettingsController::class,
            static fn ($c): SettingsController => new SettingsController(
                request: $c->get('OCP\\IRequest'),
                settingsService: $c->get(SettingsService::class),
                userSession: $c->get('OCP\\IUserSession'),
                groupManager: $c->get('OCP\\IGroupManager')
            )
        );

        // Per ADR-002 the snapshot-on-publish writeback listener has been
        // retired. ApplicationVersion is now a first-class long-lived row,
        // not an append-only snapshot, and `Application.currentVersion` has
        // been removed in favour of an explicit `productionVersion` relation
        // set by the admin. Object time-travel on the ApplicationVersion row
        // captures audit history. The corresponding spec retirement lives
        // in openbuild-versioning-model/specs/openbuild-version-snapshots.
        // Cross-row integrity guard: on every Application save (create or
        // update), verify that `productionVersion` (when set) points at an
        // ApplicationVersion whose `application` relation refers back to
        // this Application (ADR-031 §Exceptions(1) — cross-row validation
        // that OR's per-row x-openregister-validation cannot perform).
        $context->registerEventListener(
            event: ObjectCreatingEvent::class,
            listener: ProductionVersionGuardListener::class
        );
        $context->registerEventListener(
            event: ObjectUpdatingEvent::class,
            listener: ProductionVersionGuardListener::class
        );

        // Register OpenBuildToolProvider as the MCP tool provider for the AI Chat Companion.
        // The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::openbuild' is the format
        // that OR's McpToolsService enumerates to discover per-app providers (hydra ADR-035).
        // The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator);
        // until then OpenBuild implements the test stub at tests/Stubs/Mcp/IMcpToolProvider.php.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::openbuild',
            OpenBuildToolProvider::class
        );

        // Per-Application RBAC lifecycle guard (ADR-022/ADR-023; openbuild-rbac).
        // OpenRegister's LifecycleGuardRegistry resolves the destructive
        // ApplicationVersion transitions' `requires` tag — keyed by this guard's
        // FQCN in the schema's x-openregister-lifecycle.transitions[*].requires —
        // to this concrete instance. It is the real, default-secure, fail-closed
        // ownership rule the descriptive `authorization` block cannot express
        // (issue #1). Registered explicitly because the guard's dependencies
        // (PermissionResolver, ObjectService) need wiring through the container.
        $context->registerService(
            ApplicationVersionOwnerGuard::class,
            static function ($c): ApplicationVersionOwnerGuard {
                return new ApplicationVersionOwnerGuard(
                    objectService: $c->get(ObjectService::class),
                    permissionResolver: $c->get(PermissionResolver::class),
                    userManager: $c->get(IUserManager::class),
                    logger: $c->get(LoggerInterface::class)
                );
            }
        );

        // Repair steps (InitializeSettings + MigrateToVersionedModel + …) are declared in info.xml.
    }//end register()

    /**
     * Boot the application.
     *
     * Registers per-published-app top-bar navigation entries via
     * AppNavigationService (REQ-OBNAV-001 / openbuild-nextcloud-nav).
     * Lazily resolved from the DI container to avoid instantiating the
     * service tree when OR is not installed.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @spec openspec/changes/archive/2026-05-17-openbuild-nextcloud-nav/tasks.md
     */
    public function boot(IBootContext $context): void
    {
        try {
            $container = $context->getAppContainer();
            $container->get(AppNavigationService::class)
                ->registerNavEntries($container->get(INavigationManager::class));
        } catch (\Throwable $e) {
            // Boot must never throw — log and continue.
            // OpenRegister may not be installed on this instance.
        }//end try
    }//end boot()
}//end class
