<?php

/**
 * OpenBuilt ProductionVersionGuardListener
 *
 * Listens for OpenRegister's `ObjectSavingEvent` on Application rows and
 * rejects saves whose `productionVersion` relation points at an
 * ApplicationVersion that does not back-reference the Application being
 * saved. The check is the imperative cross-row companion to the same-row
 * `x-openregister-validation` block declared on ApplicationVersion
 * (ADR-031 §Exceptions(1) — cross-row validation that OR's per-row
 * declarative engine cannot perform).
 *
 * Implementation: delegates to
 * {@see ApplicationVersionService::guardProductionVersionOwnership()}.
 * On mismatch the listener stops propagation and attaches a structured
 * error payload — the OR save handler surfaces it as a 422 to clients
 * (spec REQ-OBV-105 / REQ-OBA-008).
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

use OCA\OpenBuilt\Service\ApplicationVersionService;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Pre-save integrity guard for `Application.productionVersion`.
 *
 * @template-implements IEventListener<Event>
 */
class ProductionVersionGuardListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param LoggerInterface           $logger  PSR logger for diagnostics
     * @param ApplicationVersionService $service The cross-row guard owner
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ApplicationVersionService $service,
    ) {
    }//end __construct()

    /**
     * Handle a save event on an Application row.
     *
     * Filters on Application schema + presence of `productionVersion`,
     * then calls the cross-row guard. On guard failure the event is
     * stopped and an error payload attached.
     *
     * @param Event $event Dispatched event
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if ($event instanceof ObjectCreatingEvent) {
            $entity = $event->getObject();
        } else if ($event instanceof ObjectUpdatingEvent) {
            // OR's ObjectUpdatingEvent exposes the new object via getNewObject()
            // (not getObject() — the two events have different APIs).
            $entity = $event->getNewObject();
        } else {
            return;
        }

        $schema = $this->extractSchemaSlug(entity: $entity);
        if ($schema !== ApplicationVersionService::APPLICATION_SCHEMA) {
            return;
        }

        $object          = $this->extractObjectData(entity: $entity);
        $proposedVersion = (string) ($object['productionVersion'] ?? '');
        if ($proposedVersion === '') {
            // Unset productionVersion is always allowed (REQ-OBA-008 makes it optional).
            return;
        }

        $applicationUuid = $this->extractUuid(entity: $entity, object: $object);
        if ($applicationUuid === '') {
            // No UUID yet — let OR finish its initial CREATE path; the guard
            // re-runs on the subsequent update once OR has stamped a UUID.
            return;
        }

        try {
            $this->service->guardProductionVersionOwnership(
                applicationUuid: $applicationUuid,
                proposedVersionUuid: $proposedVersion
            );
        } catch (Throwable $e) {
            $event->stopPropagation();
            if (method_exists($event, 'setErrors') === true) {
                $event->setErrors(
                        [
                            'status'  => 422,
                            'code'    => 'openbuilt.production_version.back_reference_mismatch',
                            'message' => $e->getMessage(),
                        ]
                        );
            }

            $this->logger->info(
                message: 'OpenBuilt: blocked Application save — productionVersion guard rejected the change.',
                context: [
                    'applicationUuid'   => $applicationUuid,
                    'productionVersion' => $proposedVersion,
                    'reason'            => $e->getMessage(),
                ]
            );
        }//end try
    }//end handle()

    /**
     * Read the schema slug from the ObjectEntity (defensive — supports
     * both direct `getSchemaSlug()` and the `@self.schema` projection).
     *
     * @param object $entity The ObjectEntity instance
     *
     * @return string Schema slug or empty string when unresolved
     */
    private function extractSchemaSlug(object $entity): string
    {
        if (method_exists($entity, 'getSchemaSlug') === true) {
            $slug = $entity->getSchemaSlug();
            if (is_string($slug) === true && $slug !== '') {
                return $slug;
            }
        }

        if (method_exists($entity, 'jsonSerialize') === true) {
            $serialised = $entity->jsonSerialize();
            if (is_array($serialised) === true && isset($serialised['@self']['schema']) === true) {
                return (string) $serialised['@self']['schema'];
            }
        }

        return '';
    }//end extractSchemaSlug()

    /**
     * Read the object payload (post-`@self`) from the ObjectEntity.
     *
     * @param object $entity The ObjectEntity instance
     *
     * @return array<string,mixed>
     */
    private function extractObjectData(object $entity): array
    {
        if (method_exists($entity, 'getObject') === true) {
            $object = $entity->getObject();
            if (is_array($object) === true) {
                return $object;
            }
        }

        if (method_exists($entity, 'jsonSerialize') === true) {
            $serialised = $entity->jsonSerialize();
            if (is_array($serialised) === true) {
                unset($serialised['@self']);
                return $serialised;
            }
        }

        return [];
    }//end extractObjectData()

    /**
     * Read the canonical UUID from the entity / object payload.
     *
     * @param object              $entity The ObjectEntity instance
     * @param array<string,mixed> $object The plain object data
     *
     * @return string UUID or empty string when not yet assigned
     */
    private function extractUuid(object $entity, array $object): string
    {
        if (method_exists($entity, 'getUuid') === true) {
            $uuid = $entity->getUuid();
            if (is_string($uuid) === true && $uuid !== '') {
                return $uuid;
            }
        }

        if (isset($object['id']) === true && is_string($object['id']) === true) {
            return $object['id'];
        }

        if (isset($object['uuid']) === true && is_string($object['uuid']) === true) {
            return $object['uuid'];
        }

        return '';
    }//end extractUuid()
}//end class
