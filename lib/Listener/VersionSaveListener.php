<?php

/**
 * VersionSaveListener
 *
 * Keeps the manifest of every save of an app version (created or updated
 * through any path: the in-app editor, the versions API, a promotion or a
 * rollback), so the version history can roll a version back to an earlier
 * save (REQ-BQCV-015).
 *
 * @category  Listener
 * @package   OCA\Buildiq\Listener
 *
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version   GIT: <git_id>
 *
 * @link      https://github.com/ConductionNL/buildiq
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Service\VersionSaveHistory;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a version's manifest after each save.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author  Conduction b.v. <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/buildiq
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
class VersionSaveListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ObjectSchemaSlugResolverInterface $slugs       Tells a version record from any other record.
	 * @param VersionSaveHistory                $saves       Keeps the saved manifests.
	 * @param IUserSession                      $userSession Who saved.
	 * @param LoggerInterface                   $logger      Where a save that could not be kept is noted.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectSchemaSlugResolverInterface $slugs,
		private readonly VersionSaveHistory $saves,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Keep the manifest of a saved version; any other record, or a version without a manifest, is left alone.
	 *
	 * Never throws: a save that cannot be kept is logged, and the version's own save stands.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
	 */
	#[\Override]
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent || $event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		$entity = $event->getObject();
		if ($this->slugs->registerSlug(entity: $entity) !== ObjectSchemaSlugResolverInterface::REGISTER_SLUG
			|| $this->slugs->schemaSlug(entity: $entity) !== ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA
		) {
			return;
		}

		$manifest = ($entity->getObject()['manifest'] ?? null);
		$uuid     = (string)$entity->getUuid();
		if (is_array($manifest) === false || $uuid === '') {
			return;
		}

		try {
			$this->saves->record(
				versionUuid: $uuid,
				manifest: $manifest,
				user: (string)($this->userSession->getUser()?->getUID() ?? '')
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: the save of version {uuid} could not be kept for rollback: {message}',
				['uuid' => $uuid, 'message' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
