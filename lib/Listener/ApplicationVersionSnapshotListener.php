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
 * On the same draft→published transition it also upserts the
 * Application's `BuiltAppRoute` (slug → applicationUuid) so the
 * manifest endpoint can resolve `{slug}` for user-created apps the
 * way SeedHelloWorld already does for the hello-world seed.
 *
 * ADR-031 §Exceptions(1) — declarative-first failure mode. OR's
 * lifecycle engine does NOT yet execute the declarative
 * `on_transition.create_relation` / `on_transition.upsert_relation`
 * actions documented on the Application schema; until it does we run
 * the same logic from a single PHP listener subscribed to
 * ObjectTransitionedEvent. The declarative metadata stays in
 * `openbuilt_register.json` so the intent is discoverable by
 * OR-engine maintainers (same pattern bootstrap-openbuilt's
 * SeedHelloWorld documented for the route upsert).
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
 * @template-implements IEventListener<Event>
 */
class ApplicationVersionSnapshotListener implements IEventListener
{
    private const REGISTER_SLUG        = 'openbuilt';
    private const APPLICATION_SCHEMA   = 'application';
    private const VERSION_SCHEMA       = 'application-version';
    private const ROUTE_SCHEMA         = 'built-app-route';
    private const PUBLISH_FROM         = 'draft';
    private const PUBLISH_TO           = 'published';
    private const DEFAULT_PUBLISHED_BY = 'system';
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
     * Filters on Application + draft→published, then delegates to
     * `snapshotPublish()`. Failures are logged but never thrown — a
     * snapshot failure must not block the underlying publish
     * transition (it already succeeded by the time this listener runs).
     *
     * @param Event $event Dispatched event
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($this->isPublishTransition(event: $event) === false) {
            return;
        }

        try {
            $this->snapshotPublish(event: $event);
        } catch (\Throwable $e) {
            // Never bubble up — the publish itself already succeeded; a
            // failed snapshot must not roll the transition back.
            $this->logger->error(
                'OpenBuilt: ApplicationVersionSnapshotListener failed: '.$e->getMessage(),
                ['exception' => $e]
            );
        }
    }//end handle()

    /**
     * Filter the incoming event to only the Application draft→published transition.
     *
     * @param Event $event Dispatched event.
     *
     * @return bool True when the event matches the snapshot trigger.
     */
    private function isPublishTransition(Event $event): bool
    {
        if ($event instanceof ObjectTransitionedEvent === false) {
            return false;
        }

        if ($event->getSchema() !== self::APPLICATION_SCHEMA) {
            return false;
        }

        if ($event->getFrom() !== self::PUBLISH_FROM) {
            return false;
        }

        if ($event->getTo() !== self::PUBLISH_TO) {
            return false;
        }

        return true;
    }//end isPublishTransition()

    /**
     * Create the ApplicationVersion row and writeback Application.currentVersion.
     *
     * The caller must have passed the event through {@see isPublishTransition()}
     * so the cast to ObjectTransitionedEvent is safe.
     *
     * @param Event $event The (already filtered) publish event.
     *
     * @return void
     */
    private function snapshotPublish(Event $event): void
    {
        if ($event instanceof ObjectTransitionedEvent === false) {
            // Belt-and-braces guard — should never trip thanks to handle()'s filter.
            return;
        }

        $applicationData = $event->getObject()->jsonSerialize();
        $applicationUuid = $this->extractUuid(data: $applicationData);

        if ($applicationUuid === null) {
            $this->logger->warning(
                'OpenBuilt: ApplicationVersionSnapshotListener could not resolve Application UUID; skipping snapshot.'
            );
            return;
        }

        // Upsert the slug → applicationUuid route so GET /api/applications/{slug}/manifest
        // resolves for user-created apps (the on_transition.upsert_relation fallback).
        $this->upsertBuiltAppRoute(applicationData: $applicationData, applicationUuid: $applicationUuid);

        $snapshot     = $this->createSnapshot(applicationData: $applicationData, applicationUuid: $applicationUuid, event: $event);
        $snapshotUuid = $this->extractUuid(data: $this->normaliseSerialised(object: $snapshot));

        if ($snapshotUuid === null) {
            $this->logger->warning(
                'OpenBuilt: ApplicationVersionSnapshotListener created a snapshot but could not read its UUID;'
                .' currentVersion not updated.'
            );
            return;
        }

        $this->updateApplicationCurrentVersion(applicationData: $applicationData, snapshotUuid: $snapshotUuid);

        $this->logger->info(
            'OpenBuilt: snapshotted Application '.$applicationUuid.' as ApplicationVersion '.$snapshotUuid
            .' (version '.($applicationData['version'] ?? '0.0.0').').'
        );
    }//end snapshotPublish()

    /**
     * Find-or-create the Application's BuiltAppRoute (slug → applicationUuid).
     *
     * Mirrors SeedHelloWorld's explicit route creation — the
     * `on_transition.upsert_relation` action declared on the Application
     * schema's `publish` transition (ADR-031 §Exceptions(1) fallback). When
     * a route already exists for the slug it is updated to point at this
     * Application; otherwise a fresh one is created. Failures are logged but
     * not thrown (handled by the caller's try/catch).
     *
     * @param array<string, mixed> $applicationData Serialised Application data.
     * @param string               $applicationUuid Resolved Application UUID.
     *
     * @return void
     */
    private function upsertBuiltAppRoute(array $applicationData, string $applicationUuid): void
    {
        $slug = $this->extractSlug(data: $applicationData);
        if ($slug === null) {
            $this->logger->warning(
                'OpenBuilt: ApplicationVersionSnapshotListener could not resolve Application slug;'
                .' BuiltAppRoute not upserted.'
            );
            return;
        }

        $existing = [];
        try {
            $existing = $this->objectService->searchObjectsBySlug(
                registerSlug: self::REGISTER_SLUG,
                schemaSlug: self::ROUTE_SCHEMA,
                filters: ['slug' => $slug]
            );
        } catch (\Throwable $e) {
            // A missing register/schema slug makes searchObjectsBySlug() throw —
            // treat as "no route yet" and fall through to the create path.
            $this->logger->debug(
                'OpenBuilt: BuiltAppRoute lookup for slug '.$slug.' failed ('.$e->getMessage().'); will create.'
            );
        }

        if (is_array($existing) === true && empty($existing) === false) {
            $route = $this->normaliseSerialised(object: $existing[0]);
            if (($route['applicationUuid'] ?? null) === $applicationUuid) {
                // Already correct — nothing to do.
                return;
            }

            $route['slug']            = $slug;
            $route['applicationUuid'] = $applicationUuid;
            $this->objectService->saveObject(
                object: $route,
                register: self::REGISTER_SLUG,
                schema: self::ROUTE_SCHEMA
            );
            $this->logger->info('OpenBuilt: updated BuiltAppRoute '.$slug.' → Application '.$applicationUuid.'.');
            return;
        }

        $this->objectService->saveObject(
            object: [
                'slug'            => $slug,
                'applicationUuid' => $applicationUuid,
            ],
            register: self::REGISTER_SLUG,
            schema: self::ROUTE_SCHEMA
        );
        $this->logger->info('OpenBuilt: created BuiltAppRoute '.$slug.' → Application '.$applicationUuid.'.');
    }//end upsertBuiltAppRoute()

    /**
     * Read the Application slug out of an OR-serialised object array.
     *
     * Looks in top-level `slug`, then `@self.slug`.
     *
     * @param array<string, mixed> $data Serialised object array.
     *
     * @return string|null The slug or null if not present/blank.
     */
    private function extractSlug(array $data): ?string
    {
        $candidates = [];
        if (isset($data['slug']) === true) {
            $candidates[] = $data['slug'];
        }

        if (isset($data['@self']) === true && is_array($data['@self']) === true && isset($data['@self']['slug']) === true) {
            $candidates[] = $data['@self']['slug'];
        }

        foreach ($candidates as $candidate) {
            $slug = trim((string) $candidate);
            if ($slug !== '') {
                return $slug;
            }
        }

        return null;
    }//end extractSlug()

    /**
     * Save a new ApplicationVersion sibling row carrying a byte-equal manifest copy.
     *
     * @param array<string, mixed>    $applicationData Serialised Application data.
     * @param string                  $applicationUuid Resolved Application UUID.
     * @param ObjectTransitionedEvent $event           The publish event (for actor id).
     *
     * @return mixed The OR-returned snapshot entity/array.
     */
    private function createSnapshot(array $applicationData, string $applicationUuid, ObjectTransitionedEvent $event): mixed
    {
        $manifest = ($applicationData['manifest'] ?? []);
        $version  = ($applicationData['version'] ?? '0.0.0');
        $userId   = ($event->getUserId() ?? self::DEFAULT_PUBLISHED_BY);

        // OR's standard scoping (organisation + register + schema) applies on
        // saveObject so the snapshot inherits the Application's org context.
        return $this->objectService->saveObject(
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
    }//end createSnapshot()

    /**
     * Writeback — point Application.currentVersion at the new snapshot and reset status.
     *
     * Design.md Decision 3: persistent status is `draft` when editable;
     * "is published right now" is `currentVersion != null`.
     *
     * @param array<string, mixed> $applicationData Serialised Application data.
     * @param string               $snapshotUuid    UUID of the newly created snapshot.
     *
     * @return void
     */
    private function updateApplicationCurrentVersion(array $applicationData, string $snapshotUuid): void
    {
        $existing = $applicationData;
        unset($existing['@self']);
        $existing['currentVersion'] = $snapshotUuid;
        $existing['status']         = self::PUBLISH_FROM;

        $this->objectService->saveObject(
            object: $existing,
            register: self::REGISTER_SLUG,
            schema: self::APPLICATION_SCHEMA
        );
    }//end updateApplicationCurrentVersion()

    /**
     * Read the canonical UUID out of an OR-serialised object array.
     *
     * Looks in `@self.id`, `@self.uuid`, then top-level `uuid`.
     *
     * @param array<string, mixed> $data Serialised object array.
     *
     * @return string|null The UUID or null if not present.
     */
    private function extractUuid(array $data): ?string
    {
        $self = [];
        if (isset($data['@self']) === true && is_array($data['@self']) === true) {
            $self = $data['@self'];
        }

        if (isset($self['id']) === true) {
            return (string) $self['id'];
        }

        if (isset($self['uuid']) === true) {
            return (string) $self['uuid'];
        }

        if (isset($data['uuid']) === true) {
            return (string) $data['uuid'];
        }

        return null;
    }//end extractUuid()

    /**
     * Coerce an OR-returned entity/array to a plain associative array.
     *
     * @param mixed $object The OR object/result entry.
     *
     * @return array<string, mixed>
     */
    private function normaliseSerialised(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialised = $object->jsonSerialize();
            if (is_array($serialised) === true) {
                return $serialised;
            }
        }

        return [];
    }//end normaliseSerialised()
}//end class
