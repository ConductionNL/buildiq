<?php

/**
 * OpenBuild AutomationCleanupListener
 *
 * Listens for OpenRegister's `ObjectDeletedEvent` on `automation` rows and
 * removes exactly the provenance-listed compiled artifacts (design.md
 * Decision 4 / spec REQ-AUTD-005). Automation object CRUD — including
 * delete — stays on OR REST per ADR-022 (no `AutomationsController` delete
 * route; the redundant-controller gate would flag a pass-through), so the
 * post-delete artifact cleanup is realized as the imperative companion to
 * that declarative delete, exactly mirroring the established
 * `ProductionVersionGuardListener` / `HybridMetadataLockListener` pattern
 * (ADR-031 §Exceptions(1) — cross-cutting cleanup OR's per-row engine
 * cannot perform on its own).
 *
 * A cleanup failure is logged (never re-thrown — the automation object is
 * already gone by the time this event fires) so it surfaces as an
 * operational gap rather than silently leaking orphaned artifacts.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenBuild\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#2.2
 * @spec openspec/changes/automation-designer/specs/automation-designer/spec.md#req-autd-005
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Listener;

use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenBuild\Service\ListenerSlugContract;
use OCA\OpenBuild\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Post-delete artifact cleanup for `automation` rows.
 *
 * @template-implements IEventListener<Event>
 */
class AutomationCleanupListener implements IEventListener
{
    /**
     * Constructor.
     *
     * @param LoggerInterface           $logger   PSR logger for diagnostics.
     * @param AutomationCompilerService $compiler Owns the artifact-removal logic.
     * @param ObjectSchemaSlugResolver  $slugs    Resolves the event's schema id to a slug.
     * @param ListenerSlugContract      $contract Gates the corrected comparison.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AutomationCompilerService $compiler,
        private readonly ObjectSchemaSlugResolver $slugs,
        private readonly ListenerSlugContract $contract,
    ) {
    }//end __construct()

    /**
     * Handle a delete event, removing the automation's compiled artifacts
     * when the deleted row is an `automation` object.
     *
     * @param Event $event Dispatched event.
     *
     * @return void
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectDeletedEvent) === false) {
            return;
        }

        $entity = $event->getObject();

        // GATED ON PURPOSE — see ListenerSlugContract.
        //
        // extractSchemaSlug() returned the schema's numeric id and compared it
        // to the slug 'automation', so this cleanup has never once run and
        // every deleted automation has left its compiled artifacts behind. The
        // comparison below is correct; enabling it starts DELETING those
        // artifacts on automation delete, which is the desired behaviour but is
        // still a behaviour change on a path that has never executed.
        if ($this->contract->isEnabled() === false) {
            return;
        }

        // The register is checked as well as the schema: `automation` is not a
        // unique slug on this instance (two schemas carry it), so matching on
        // the schema slug alone would delete artifacts for another app's rows.
        if ($this->slugs->isOpenBuildSchema(
            entity: $entity,
            schemaSlug: AutomationCompilerService::AUTOMATION_SCHEMA
        ) === false
        ) {
            return;
        }

        $automation = $this->extractObjectData(entity: $entity);
        $provenance = $automation['provenance'] ?? [];
        if (is_array($provenance) === false) {
            $provenance = [];
        }

        try {
            $this->compiler->remove(automation: $automation, provenance: $provenance);
        } catch (Throwable $e) {
            $slug = (string) ($automation['slug'] ?? '');
            $this->logger->error(
                'OpenBuild: AutomationCleanupListener failed to remove compiled artifacts for deleted automation "'.$slug.'": '.$e->getMessage(),
                ['exception' => $e]
            );
        }
    }//end handle()

    /**
     * Read the object payload (post-`@self`) from the ObjectEntity.
     *
     * @param object $entity The ObjectEntity instance.
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
}//end class
