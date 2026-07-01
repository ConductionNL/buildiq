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

use OCA\OpenBuild\Capabilities;
use OCA\OpenBuild\Controller\DashboardController;
use OCA\OpenBuild\Controller\PreferencesController;
use OCA\OpenBuild\Controller\SettingsController;
use OCA\OpenBuild\Lifecycle\ApplicationVersionOwnerGuard;
use OCA\OpenBuild\Listener\HybridMetadataLockListener;
use OCA\OpenBuild\Listener\ProductionVersionGuardListener;
use OCA\OpenBuild\Mcp\OpenBuildToolProvider;
use OCA\OpenBuild\Repair\InitializeSettings;
use OCA\OpenBuild\Sections\SettingsSection;
use OCA\OpenBuild\Service\AppNavigationService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenBuild\Service\SettingsService;
use OCA\OpenBuild\Settings\AdminSettings;
use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\INavigationManager;
use OCP\IURLGenerator;
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
        //
        // GUARDED: `Bootstrap::register` is an eager static call that fatals if
        // OpenRegister's AppHost engine class is not autoloadable on this
        // instance (e.g. a stale optimized/authoritative composer classmap that
        // predates AppHost, or a stub OCA\OpenRegister PSR-4 prefix). That fatal
        // would abort the WHOLE register() method BEFORE the domain listeners
        // below — silently disabling the per-Application RBAC guards and the
        // hybrid metadata-lock (so a hybrid app's immutable identity fields would
        // become writable). Skip the generic AppHost plumbing when it is absent
        // and fall through to OpenBuild's own concrete controllers + listeners,
        // which is what this app actually relies on (the comment that the lazy
        // closures "never fatal NC bootstrap" only held for the closures, not for
        // resolving the Bootstrap class itself).
        if (class_exists(Bootstrap::class) === true) {
            Bootstrap::register(
                $context,
                self::APP_ID,
                [
                    'namespace'     => 'OCA\\OpenBuild',
                    'sectionName'   => 'OpenBuild',
                    'observability' => true,
                ]
            );
        } else {
            // No PSR logger is wired this early in register(); error_log is the
            // safe channel and is captured by the NC log pipeline.
            // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- intentional: no PSR logger available this early in register().
            error_log(
                'OpenBuild: OpenRegister AppHost\\Bootstrap is not autoloadable — '
                .'skipping generic AppHost plumbing; concrete controllers + domain '
                .'listeners (RBAC + hybrid metadata-lock) still register.'
            );
        }

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
        // SettingsService — bind the concrete OpenBuild implementation so it wins
        // over the generic AppHost binding (last registration wins). When
        // Bootstrap::register ran (above), it aliases this leaf class name to
        // OpenRegister's generic AppHostSettingsService, whose loadConfiguration()
        // calls ConfigurationService::importFromApp() with a stale 2-argument
        // signature (OR `development` now requires 4) and skips the ADR-037
        // register.d/ fragment merge. On the app-enable/install path the
        // InitializeSettings repair step resolves THIS class directly, so under the
        // generic binding the register import fails with
        // "importFromApp(): Argument #2 ($data) not passed" — leaving the openbuild
        // register uncreated until a manual re-import. Registering the concrete
        // class here (mirroring the controllers above) guarantees every caller —
        // the InitializeSettings repair step AND SettingsController — uses the
        // correct 4-arg importer + fragment merge on all paths.
        $context->registerService(
            SettingsService::class,
            static fn ($c): SettingsService => new SettingsService(
                appConfig: $c->get('OCP\\IAppConfig'),
                appManager: $c->get('OCP\\App\\IAppManager'),
                container: $c,
                groupManager: $c->get('OCP\\IGroupManager'),
                userSession: $c->get('OCP\\IUserSession'),
                logger: $c->get('Psr\\Log\\LoggerInterface')
            )
        );
        // InitializeSettings repair step — bind OpenBuild's own class so it wins
        // over the generic AppHost binding (last registration wins). Bootstrap
        // above re-registers this exact class name to OpenRegister's
        // GenericInitializeSettings, which imports via the broken generic
        // AppHostSettingsService (2-arg importFromApp) and does NO register.d/
        // fragment merge. info.xml declares OCA\OpenBuild\Repair\InitializeSettings
        // as an <install>/<post-migration> step; NC's repair runner resolves that
        // class name through the container, so without this the generic (failing)
        // step runs on install and the openbuild register is never created.
        // Re-registering the concrete step here makes install use the correct
        // 4-arg importer + fragment merge (via the concrete SettingsService above).
        $context->registerService(
            InitializeSettings::class,
            static fn ($c): InitializeSettings => new InitializeSettings(
                settingsService: $c->get(SettingsService::class),
                logger: $c->get('Psr\\Log\\LoggerInterface')
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

        // 4. SettingsSection + AdminSettings — the admin settings section and
        // panel referenced by info.xml `<settings>`. Both are one-line leaf
        // subclasses of OpenRegister's GenericSettingsSection / GenericAdminSettings,
        // whose constructors take scalar params (sectionId, name, icon, priority)
        // that are NOT autowireable — they MUST be bound by a service factory.
        // Bootstrap::register() normally binds them, but when Bootstrap is not
        // autoloadable at register() time (it lives in OCA\OpenRegister and that
        // app's autoloader is not guaranteed to be registered before OpenBuild
        // boots — `openbuild` sorts before `openregister`), the else branch above
        // skips it. NC's settings Manager instantiates EVERY registered section to
        // render ANY settings page, so an unbound `sectionId` throws
        // QueryNotFoundException that escapes NC's catch and blanks ALL admin AND
        // personal settings pages instance-wide (not just OpenBuild's). Register
        // the leaf classes unconditionally here with Bootstrap's exact defaults so
        // they resolve regardless of app load order (last registration wins).
        $context->registerService(
            SettingsSection::class,
            static fn ($c): SettingsSection => new SettingsSection(
                sectionId: self::APP_ID,
                name: 'OpenBuild',
                appId: self::APP_ID,
                iconFile: 'app-dark.svg',
                priority: 75,
                urlGenerator: $c->get(IURLGenerator::class)
            )
        );
        $context->registerService(
            AdminSettings::class,
            static fn ($c): AdminSettings => new AdminSettings(
                appId: self::APP_ID,
                sectionId: self::APP_ID,
                priority: 10,
                appManager: $c->get(IAppManager::class),
                initialState: $c->get(IInitialState::class),
                appConfig: $c->get(IAppConfig::class)
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

        // Metadata-lock for hybrid apps (unify-apps-with-app-type). On every
        // Application UPDATE, reject a change to a hybrid app's identity
        // metadata (slug/name) — it mirrors the installed Nextcloud app it
        // customizes and renaming it would desync the baseRef.id link and the
        // /api/app-overrides/{appId} shim key. Cross-row check (compares the
        // proposed payload against the stored row) realized as a pre-save
        // listener, the imperative companion to the same-row
        // `hybrid-requires-baseRef` x-openregister-validation rule (ADR-031
        // §Exceptions(1)). Virtual apps keep full slug/name edit.
        $context->registerEventListener(
            event: ObjectUpdatingEvent::class,
            listener: HybridMetadataLockListener::class
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

        // Edit-availability capability (openbuild-inline-edit-persistence, spec
        // openbuild-capability). Advertises `{ openbuild: { enabled, canEdit } }`
        // so a fleet app's in-place edit button has a robust per-user signal
        // (IAppManager::isEnabledForUser respects the NC app group-restriction)
        // instead of inferring availability from OC.appswebroots. `canEdit` is a
        // UI hint only — the write/delete endpoints re-check access server-side.
        $context->registerCapability(Capabilities::class);

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
