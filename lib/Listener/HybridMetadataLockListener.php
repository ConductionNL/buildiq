<?php

/**
 * OpenBuild HybridMetadataLockListener
 *
 * Listens for OpenRegister's `ObjectUpdatingEvent` on Application rows and
 * rejects updates that change a HYBRID app's identity metadata (`slug` or
 * `name`). A hybrid app's identity mirrors the underlying installed Nextcloud
 * fleet app it customizes; renaming its slug would desync the `baseRef.id`
 * link and the `/api/app-overrides/{appId}` compatibility-shim key. Everything
 * else (the production version's pages/widgets/menus/schemas-as-delta) stays
 * editable, and a `virtual` app keeps full edit of slug/name.
 *
 * This is the cross-row companion to the same-row `hybrid-requires-baseRef`
 * `x-openregister-validation` rule declared on the Application schema: a plain
 * field rule cannot compare the proposed slug/name against the stored row, so
 * the immutability check is an imperative pre-save guard (ADR-031 §Exceptions(1)
 * — cross-row / temporal validation OR's per-row declarative engine cannot
 * perform), realized as an `ObjectUpdatingEvent` listener exactly like
 * {@see ProductionVersionGuardListener}. The event exposes both the old and
 * the new object, so no extra load is needed.
 *
 * On a locked-field change the listener stops propagation and attaches a
 * structured 422 error payload that OR's save handler surfaces to clients.
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
 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Pre-save metadata-lock guard for hybrid Applications.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
 */
class HybridMetadataLockListener implements IEventListener
{
    /**
     * The identity-metadata fields that are immutable on a hybrid app.
     *
     * @var array<int, string>
     */
    private const LOCKED_FIELDS = ['slug', 'name'];

    /**
     * Constructor.
     *
     * @param LoggerInterface $logger PSR logger for diagnostics.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an update event on an Application row.
     *
     * Only `ObjectUpdatingEvent` carries an old object to compare against —
     * creation is unaffected (a hybrid app is created with its locked identity,
     * which is allowed). Filters on the Application schema and a stored
     * `appType == "hybrid"`, then rejects any change to a locked field.
     *
     * @param Event $event Dispatched event.
     *
     * @return void
     *
     * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
     */
    public function handle(Event $event): void
    {
        if (($event instanceof ObjectUpdatingEvent) === false) {
            return;
        }

        $oldEntity = $event->getOldObject();
        $newEntity = $event->getNewObject();
        if ($oldEntity === null) {
            // No prior row to compare against — nothing to lock.
            return;
        }

        $old = $this->extractObjectData(entity: $oldEntity);
        $new = $this->extractObjectData(entity: $newEntity);

        // Scope to OpenBuild hybrid Applications via the `appType` discriminator
        // — the only schema in the fleet that carries it. (Matching on the
        // schema slug is unreliable: OR's ObjectEntity exposes the schema as a
        // numeric id in @self.schema, e.g. '28', not the 'application' slug.)
        // The lock is keyed on the STORED appType, so a virtual app keeps full
        // edit of slug/name; an absent appType reads as virtual (legacy default).
        if (($old['appType'] ?? 'virtual') !== 'hybrid') {
            return;
        }

        $changed = $this->lockedFieldChanged(old: $old, new: $new);
        if ($changed === null) {
            return;
        }

        $message = sprintf(
            'A hybrid app\'s %s is read-only — it mirrors the installed Nextcloud app it customizes.',
            $changed
        );

        $event->stopPropagation();
        if (method_exists($event, 'setErrors') === true) {
            $event->setErrors(
                [
                    'status'  => 422,
                    'code'    => 'openbuild.hybrid_metadata.locked',
                    'message' => $message,
                ]
            );
        }

        $this->logger->info(
            message: 'OpenBuild: blocked Application update — hybrid metadata-lock rejected the change.',
            context: [
                'field'  => $changed,
                'slug'   => ($old['slug'] ?? null),
                'reason' => $message,
            ]
        );
    }//end handle()

    /**
     * Return the first locked field whose value differs between old and new,
     * or null when no locked field changed.
     *
     * A locked field that is absent from the new payload is treated as
     * unchanged (a partial update that does not touch it), so content-only
     * edits to the version delta are never blocked.
     *
     * @param array<string, mixed> $old The stored object payload.
     * @param array<string, mixed> $new The proposed object payload.
     *
     * @return string|null The name of the changed locked field, or null.
     */
    private function lockedFieldChanged(array $old, array $new): ?string
    {
        foreach (self::LOCKED_FIELDS as $field) {
            if (array_key_exists($field, $new) === false) {
                continue;
            }

            if (($new[$field] ?? null) !== ($old[$field] ?? null)) {
                return $field;
            }
        }

        return null;
    }//end lockedFieldChanged()

    /**
     * Read the object payload (post-`@self`) from the ObjectEntity.
     *
     * @param object $entity The ObjectEntity instance.
     *
     * @return array<string, mixed>
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
