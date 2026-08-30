<?php

/**
 * Buildiq ProductionVersionGuardListener
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-31
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-32
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Service\ApplicationVersionService;
use OCA\Buildiq\Service\ListenerSlugContract;
use OCA\Buildiq\Service\ObjectSchemaSlugResolver;
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
class ProductionVersionGuardListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger PSR logger for diagnostics
	 * @param ApplicationVersionService $service The cross-row guard owner
	 * @param ObjectSchemaSlugResolver $slugs Resolves the event's schema id to a slug
	 * @param ListenerSlugContract $contract Gates the corrected comparison
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly ApplicationVersionService $service,
		private readonly ObjectSchemaSlugResolver $slugs,
		private readonly ListenerSlugContract $contract,
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
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-31
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-32
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$entity = $event->getObject();
		} elseif ($event instanceof ObjectUpdatingEvent) {
			// OR's ObjectUpdatingEvent exposes the new object via getNewObject()
			// (not getObject() — the two events have different APIs).
			$entity = $event->getNewObject();
		}

		if (isset($entity) === false) {
			return;
		}

		// GATED ON PURPOSE — read before flipping the flag.
		//
		// This guard has never once executed: extractSchemaSlug() returned the
		// schema's numeric id and compared it to the slug 'application', so the
		// `!==` was always true and this method always returned here. The
		// comparison below is now correct, but making it correct CHANGES
		// BEHAVIOUR: this is a fail-closed validation guard, so waking it
		// starts REJECTING production-version writes that succeed today.
		//
		// Enabling it is therefore a rollout decision, not a bug fix, and it is
		// deliberately off by default. Enable with:
		// occ config:app:set buildiq listener_slug_contract --value=yes.
		if ($this->contract->isEnabled() === false) {
			return;
		}

		if ($this->slugs->isBuildiqSchema(
			entity: $entity,
			schemaSlug: ApplicationVersionService::APPLICATION_SCHEMA
		) === false
		) {
			return;
		}

		$object = $this->extractObjectData(entity: $entity);
		$proposedVersion = (string)($object['productionVersion'] ?? '');
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
						'status' => 422,
						'code' => 'buildiq.production_version.back_reference_mismatch',
						'message' => $e->getMessage(),
					]
				);
			}

			$this->logger->info(
				message: 'Buildiq: blocked Application save — productionVersion guard rejected the change.',
				context: [
					'applicationUuid' => $applicationUuid,
					'productionVersion' => $proposedVersion,
					'reason' => $e->getMessage(),
				]
			);
		}//end try
	}//end handle()

	/**
	 * Read the object payload (post-`@self`) from the ObjectEntity.
	 *
	 * @param object $entity The ObjectEntity instance
	 *
	 * @return array<string,mixed>
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

	/**
	 * Read the canonical UUID from the entity / object payload.
	 *
	 * @param object $entity The ObjectEntity instance
	 * @param array<string,mixed> $object The plain object data
	 *
	 * @return string UUID or empty string when not yet assigned
	 */
	private function extractUuid(object $entity, array $object): string {
		// NOT method_exists(): getUuid() is an `@method` docblock on ObjectEntity,
		// served by Entity::__call, so method_exists() is false and this branch
		// never ran. is_callable() is true for any name on a __call class, so the
		// call itself must be exception-safe.
		if (is_callable([$entity, 'getUuid']) === true) {
			try {
				$uuid = $entity->getUuid();
				if (is_string($uuid) === true && $uuid !== '') {
					return $uuid;
				}
			} catch (Throwable $e) {
				$this->logger->debug('Buildiq: getUuid() unavailable on ' . $entity::class . ': ' . $e->getMessage());
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
