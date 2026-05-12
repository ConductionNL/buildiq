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

use OCA\OpenBuilt\Listener\ApplicationVersionSnapshotListener;
use OCA\OpenBuilt\Listener\DeepLinkRegistrationListener;
use OCA\OpenBuilt\Mcp\OpenBuiltToolProvider;
use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

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

        // Snapshot the Application's manifest into ApplicationVersion on
        // draft→published transitions (chain spec #6 openbuilt-versioning,
        // ADR-031 §Exceptions(1) — declarative-first fallback because OR's
        // engine does not yet execute on_transition.create_relation).
        $context->registerEventListener(
            event: ObjectTransitionedEvent::class,
            listener: ApplicationVersionSnapshotListener::class
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

        // Repair steps (InitializeSettings + SeedHelloWorld) are declared in info.xml.
    }//end register()

    /**
     * Boot the application.
     *
     * @param IBootContext $context The boot context
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function boot(IBootContext $context): void
    {
    }//end boot()
}//end class
