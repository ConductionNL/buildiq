<?php

/**
 * OpenBuilt ApplicationVersionSnapshotListener
 *
 * Listens for OpenRegister's ObjectTransitionedEvent on the
 * Application schema and, on a draft→published transition, creates
 * an `ApplicationVersion` sibling row carrying a byte-equal copy of
 * the Application's `manifest`, the `version` string, the actor's NC
 * user id, and the transition timestamp. Then updates the
 * Application's `currentVersion` to point at the new snapshot and
 * resets `status` back to `draft` (design.md Decision 3).
 *
 * ADR-031 §Exceptions(1) — declarative-first failure mode. OR's
 * lifecycle engine does NOT yet execute the declarative
 * `on_transition.create_relation` action documented on the
 * Application schema; until it does we run the same logic from a
 * single PHP listener subscribed to ObjectTransitionedEvent. The
 * declarative metadata stays in `openbuilt_register.json` so the
 * intent is discoverable by OR-engine maintainers (same pattern
 * bootstrap-openbuilt's SeedHelloWorld documented for the route
 * upsert).
 *
 * Per ADR-031 + design.md Decision 6, NO `VersioningService`,
 * `SnapshotService`, or `ApplicationVersionManager` class exists.
 * This single listener is the only PHP-side surface for snapshot
 * creation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenBuilt\Listener
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

namespace OCA\OpenBuilt\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Snapshots an Application's manifest into ApplicationVersion on publish.
 *
 * @template-implements IEventListener<ObjectTransitionedEvent>
 */
class ApplicationVersionSnapshotListener implements IEventListener
{
    private const REGISTER_SLUG          = 'openbuilt';
    private const APPLICATION_SCHEMA     = 'application';
    private const VERSION_SCHEMA         = 'application-version';
    private const PUBLISH_FROM           = 'draft';
    private const PUBLISH_TO             = 'published';
    private const DEFAULT_PUBLISHED_BY   = 'system';
    private const DEFAULT_SNAPSHOT_NOTES = 'Published via lifecycle transition';

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger        PSR logger for diagnostics
     * @param ObjectService   $objectService OpenRegister object service (hard dep via info.xml)
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ObjectService $objectService,
    ) {
    }//end __construct()

    /**
     * Handle the ObjectTransitionedEvent.
     *
     * Filters on Application + draft→published, then creates the
     * ApplicationVersion snapshot and writes back currentVersion +
     * status reset on the Application. Failures are logged but
     * never thrown — a snapshot failure must not block the
     * underlying publish transition (it already succeeded by the
     * time this listener runs).
     *
     * @param Event $event Dispatched event
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectTransitionedEvent === false) {
            return;
        }

        if ($event->getSchema() !== self::APPLICATION_SCHEMA) {
            return;
        }

        if ($event->getFrom() !== self::PUBLISH_FROM || $event->getTo() !== self::PUBLISH_TO) {
            return;
        }

        try {
            $applicationData = $event->getObject()->jsonSerialize();
            $applicationSelf = (is_array($applicationData) === true ? ($applicationData['@self'] ?? []) : []);
            $applicationUuid = ($applicationSelf['id'] ?? ($applicationSelf['uuid'] ?? ($applicationData['uuid'] ?? null)));

            if ($applicationUuid === null) {
                $this->logger->warning('OpenBuilt: ApplicationVersionSnapshotListener could not resolve Application UUID; skipping snapshot.');
                return;
            }

            $manifest = ($applicationData['manifest'] ?? []);
            $version  = ($applicationData['version'] ?? '0.0.0');
            $userId   = ($event->getUserId() ?? self::DEFAULT_PUBLISHED_BY);

            // Create the ApplicationVersion sibling row. OR's standard scoping
            // (organisation + register + schema) applies on saveObject so the
            // snapshot inherits the Application's org context.
            $snapshot = $this->objectService->saveObject(
                object: [
                    'applicationUuid' => $applicationUuid,
                    'version'         => $version,
                    'manifest'        => $manifest,
                    'publishedAt'     => gmdate('Y-m-d\TH:i:s\Z'),
                    'publishedBy'     => $userId,
                    'notes'           => self::DEFAULT_SNAPSHOT_NOTES,
                ],
                register: self::REGISTER_SLUG,
                schema: self::VERSION_SCHEMA
            );

            $snapshotData = (method_exists($snapshot, 'jsonSerialize') === true ? $snapshot->jsonSerialize() : []);
            $snapshotSelf = (is_array($snapshotData) === true ? ($snapshotData['@self'] ?? []) : []);
            $snapshotUuid = ($snapshotSelf['id'] ?? ($snapshotSelf['uuid'] ?? ($snapshotData['uuid'] ?? null)));

            if ($snapshotUuid === null) {
                $this->logger->warning('OpenBuilt: ApplicationVersionSnapshotListener created a snapshot but could not read its UUID; currentVersion not updated.');
                return;
            }

            // Writeback — update Application.currentVersion AND reset status
            // back to draft (design.md Decision 3: persistent status is `draft`
            // when editable; "is published right now" is `currentVersion != null`).
            $existing = (is_array($applicationData) === true ? $applicationData : []);
            unset($existing['@self']);
            $existing['currentVersion'] = $snapshotUuid;
            $existing['status']         = self::PUBLISH_FROM;

            $this->objectService->saveObject(
                object: $existing,
                register: self::REGISTER_SLUG,
                schema: self::APPLICATION_SCHEMA
            );

            $this->logger->info(
                'OpenBuilt: snapshotted Application '.$applicationUuid.' as ApplicationVersion '.$snapshotUuid.' (version '.$version.').'
            );
        } catch (\Throwable $e) {
            // Never bubble up — the publish itself already succeeded; a
            // failed snapshot must not roll the transition back.
            $this->logger->error(
                'OpenBuilt: ApplicationVersionSnapshotListener failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }//end try
    }//end handle()
}//end class
