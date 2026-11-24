<?php

/**
 * Buildiq PublishedApplicationCacheListener
 *
 * Drops the cached published-Application read when an Application is
 * created, updated or deleted, so a publish or an unpublish shows in the
 * top bar and on the dashboard on the next request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-published-applications-are-read-once-per-request
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Kernel\Contracts\PublishedApplicationProviderInterface;
use OCA\Buildiq\Service\PublishedApplicationProvider;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Invalidates the published-Application cache on a write to an Application.
 *
 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-published-applications-are-read-once-per-request
 *
 * @template-implements IEventListener<Event>
 */
class PublishedApplicationCacheListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param PublishedApplicationProviderInterface $applications The shared published-Application read.
	 * @param ObjectSchemaSlugResolverInterface     $slugs        Resolves the written object's register and schema.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly PublishedApplicationProviderInterface $applications,
		private readonly ObjectSchemaSlugResolverInterface $slugs,
	) {
	}//end __construct()

	/**
	 * Drop the cache when the written object is a Buildiq Application.
	 *
	 * @param Event $event The OpenRegister object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/publish-widgets-to-nc-dashboard/specs/nc-dashboard-widgets/spec.md#requirement-published-applications-are-read-once-per-request
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false
			&& ($event instanceof ObjectUpdatedEvent) === false
			&& ($event instanceof ObjectDeletedEvent) === false
		) {
			return;
		}

		if ($this->slugs->isBuildiqSchema(
			entity: $event->getObject(),
			schemaSlug: PublishedApplicationProvider::APPLICATION_SCHEMA
		) === false
		) {
			return;
		}

		$this->applications->invalidate();
	}//end handle()
}//end class
