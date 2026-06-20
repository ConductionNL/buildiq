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
use OCA\OpenBuild\Lifecycle\ApplicationVersionOwnerGuard;
use OCA\OpenBuild\Listener\HybridMetadataLockListener;
use OCA\OpenBuild\Listener\ProductionVersionGuardListener;
use OCA\OpenBuild\Listener\DeepLinkRegistrationListener;
use OCA\OpenBuild\Mcp\OpenBuildToolProvider;
use OCA\OpenBuild\Service\AppNavigationService;
use OCA\OpenBuild\Service\PermissionResolver;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
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
        // Register deep link patterns with OpenRegister's unified search provider.
        // Only fires when OpenRegister is installed and dispatches the event.
        $context->registerEventListener(
            event: DeepLinkRegistrationEvent::class,
            listener: DeepLinkRegistrationListener::class
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
