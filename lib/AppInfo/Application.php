<?php

/**
 * OpenBuilt Application
 *
 * Main application class for the OpenBuilt Nextcloud app.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category AppInfo
 * @package  OCA\OpenBuilt\AppInfo
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

namespace OCA\OpenBuilt\AppInfo;

use OCA\OpenBuilt\Listener\ProductionVersionGuardListener;
use OCA\OpenBuilt\Listener\DeepLinkRegistrationListener;
use OCA\OpenBuilt\Mcp\OpenBuiltToolProvider;
use OCA\OpenBuilt\Service\AppNavigationService;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;

/**
 * Main application class for the OpenBuilt Nextcloud app.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'openbuilt';

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
        // in openbuilt-versioning-model/specs/openbuilt-version-snapshots.
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

        // Register OpenBuiltToolProvider as the MCP tool provider for the AI Chat Companion.
        // The alias key 'OCA\OpenRegister\Mcp\IMcpToolProvider::openbuilt' is the format
        // that OR's McpToolsService enumerates to discover per-app providers (hydra ADR-035).
        // The interface ships in openregister PR #1466 (ai-chat-companion-orchestrator);
        // until then OpenBuilt implements the test stub at tests/Stubs/Mcp/IMcpToolProvider.php.
        $context->registerServiceAlias(
            'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::openbuilt',
            OpenBuiltToolProvider::class
        );

        // Repair steps (InitializeSettings + MigrateToVersionedModel + …) are declared in info.xml.
    }//end register()

    /**
     * Boot the application.
     *
     * Registers per-published-app top-bar navigation entries via
     * AppNavigationService (REQ-OBNAV-001 / openbuilt-nextcloud-nav).
     * Lazily resolved from the DI container to avoid instantiating the
     * service tree when OR is not installed.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
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
