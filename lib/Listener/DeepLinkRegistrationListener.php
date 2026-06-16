<?php

/**
 * OpenBuild DeepLinkRegistrationListener
 *
 * Registers OpenBuild's deep link URL patterns with OpenRegister's search provider.
 *
 * @category Listener
 * @package  OCA\OpenBuild\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/retrofit-2026-05-24-deep-link-registration/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-deep-link-registration/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Listener;

use OCA\OpenRegister\Event\DeepLinkRegistrationEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers OpenBuild's deep link URL patterns with OpenRegister's search provider.
 *
 * When a user searches in Nextcloud's unified search, results for OpenBuild schemas
 * will link directly to the relevant detail views in the app.
 *
 * @implements IEventListener<Event>
 */
class DeepLinkRegistrationListener implements IEventListener
{
    /**
     * Handle the deep link registration event.
     *
     * @param Event $event The event to handle
     *
     * @return void
     *
     * @spec openspec/changes/archive/retrofit-2026-05-24-deep-link-registration/tasks.md#task-1
     */
    public function handle(Event $event): void
    {
        if ($event instanceof DeepLinkRegistrationEvent === false) {
            return;
        }

        // Register example object deep links.
        // Update the register slug, schema slug, and URL template to match
        // your app's actual schemas.
        $event->register(
            appId: 'openbuild',
            registerSlug: 'openbuild',
            schemaSlug: 'example',
            urlTemplate: '/apps/openbuild/#/examples/{uuid}'
        );

    }//end handle()
}//end class
