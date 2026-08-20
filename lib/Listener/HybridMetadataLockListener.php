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
class HybridMetadataLockListener implements IEventListener {
	/**
	 * The identity fields that are immutable on a HYBRID app.
	 *
	 * A hybrid app mirrors the installed Nextcloud app it customizes, so its
	 * IDENTITY — the `slug` and the `name` — is the fleet app's and may not be
	 * overwritten from the generic object editor. `appType` is handled
	 * separately because it is immutable for EVERY app, not just hybrids
	 * (layered-versioned-app-deltas).
	 *
	 * DELIBERATELY NOT LOCKED — both were here and both were wrong:
	 *
	 *  - `productionVersion`. The canonical spec requires the opposite: "The
	 *    Application's `productionVersion` SHALL point at that version"
	 *    (openspec/specs/unified-app-model/spec.md, "A hybrid app is an
	 *    Application plus a delta-only ApplicationVersion"). Locking it made
	 *    that pointer unwritable, so EVERY path that creates or republishes a
	 *    hybrid app was rejected at its final step:
	 *    `AppOverrideService::createHybridApp()` (the POST
	 *    /api/app-overrides/{appId} shim), `MigrateAppOverridesToHybrid`, and
	 *    `ApplicationVersionService::releaseVersion()` step 3. A hybrid app
	 *    that survived creation kept `productionVersion: null`, which is
	 *    exactly the value `findHybridProductionVersion()` bails on — the
	 *    stored delta was then never served to anyone.
	 *
	 *  - `description`. Never named as read-only by the spec, whose lock
	 *    requirement lists `slug`, `name`, `appType` and the `baseRef` linkage
	 *    and then says "All other content … SHALL remain editable". Worse, OR's
	 *    `saveObject()` is PUT-semantic: a partial update that simply does not
	 *    mention `description` arrives here as `description: null`, which the
	 *    lock read as a deliberate change and rejected. That is what actually
	 *    fired — an unrelated pointer update was refused with "A hybrid app's
	 *    description is read-only".
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
	public function handle(Event $event): void {
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

		// `appType` is immutable for EVERY Application (virtual or hybrid) once
		// created — flipping the discriminator would desync the entire
		// delta/baseRef model (a virtual app owns a full manifest; a hybrid app
		// layers a delta over a fleet app). This runs before the hybrid gate so
		// it also blocks a virtual→hybrid (or hybrid→virtual) flip.
		if (array_key_exists('appType', $new) === true
			&& ($new['appType'] ?? null) !== ($old['appType'] ?? null)
		) {
			$this->reject(
				event: $event,
				field: 'appType',
				message: 'An app\'s type is immutable once created.',
				old: $old
			);
			return;
		}

		// Identity / structural lock — HYBRID apps only, via the `appType`
		// discriminator (the only schema in the fleet that carries it; matching
		// on the schema slug is unreliable because OR exposes the schema as a
		// numeric id in @self.schema). Keyed on the STORED appType, so a virtual
		// app keeps full edit of slug/name; an absent appType reads as virtual
		// (legacy default).
		if (($old['appType'] ?? 'virtual') !== 'hybrid') {
			return;
		}

		$changed = $this->lockedFieldChanged(old: $old, new: $new);
		if ($changed === null) {
			return;
		}

		$this->reject(
			event: $event,
			field: $changed,
			message: sprintf(
				'A hybrid app\'s %s is read-only — it mirrors the installed Nextcloud app it customizes.',
				$changed
			),
			old: $old
		);
	}//end handle()

	/**
	 * Stop the save and attach a 422 error describing the locked field.
	 *
	 * @param Event $event The dispatched update event.
	 * @param string $field The locked field that was changed.
	 * @param string $message The human-readable rejection message.
	 * @param array<string, mixed> $old The stored object payload (for the log context).
	 *
	 * @return void
	 */
	private function reject(Event $event, string $field, string $message, array $old): void {
		$event->stopPropagation();
		if (method_exists($event, 'setErrors') === true) {
			$event->setErrors(
				[
					'status' => 422,
					'code' => 'openbuild.hybrid_metadata.locked',
					'message' => $message,
				]
			);
		}

		$this->logger->info(
			message: 'OpenBuild: blocked Application update — hybrid metadata-lock rejected the change.',
			context: [
				'field' => $field,
				'slug' => ($old['slug'] ?? null),
				'reason' => $message,
			]
		);
	}//end reject()

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
	private function lockedFieldChanged(array $old, array $new): ?string {
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
	private function extractObjectData(object $entity): array {
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
