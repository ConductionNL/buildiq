<?php

/**
 * Before a record is stored, recompute what a live form calculated and
 * enforce its blocking eligibility check (REQ-BQLV-005).
 *
 * Listens for OpenRegister's ObjectCreatingEvent and ObjectUpdatingEvent.
 * Two kinds of object matter:
 *
 * - a buildiq ApplicationVersion: its manifest is read into the live form
 *   index, so the forms it carries are known by the register and schema they
 *   write to;
 * - an object in a register and schema a live form writes to: its calculated
 *   fields are evaluated again and handed back through setModifiedData(),
 *   which OpenRegister merges before the write, and a failing blocking check
 *   stops the event with the explanation.
 *
 * Every other write returns after the index lookup. This is a pre-save
 * validation, so it runs synchronously by nature (hydra ADR-078: work that
 * decides whether the write may happen cannot be deferred).
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://buildiq.nl
 *
 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Kernel\Contracts\ApplicationVersionServiceInterface;
use OCA\Buildiq\Kernel\Contracts\FormLiveBindingIndexInterface;
use OCA\Buildiq\Kernel\Contracts\FormLiveValuesRecomputerInterface;
use OCA\Buildiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Buildiq\Service\DutchFormatValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recomputes live form values and enforces blocking checks before a save.
 *
 * @template-implements IEventListener<Event>
 *
 * @psalm-import-type LiveBinding from FormLiveBindingIndexInterface
 * @phpstan-import-type LiveBinding from FormLiveBindingIndexInterface
 */
class FormLiveValuesListener implements IEventListener {

	/**
	 * True while this listener evaluates, so the execution log entries the
	 * evaluation itself writes are not handled again.
	 *
	 * @var bool
	 */
	private bool $running = false;

	/**
	 * Constructor.
	 *
	 * @param ObjectSchemaSlugResolverInterface $slugs Resolves register and schema slugs
	 * @param FormLiveBindingIndexInterface $index The live form index
	 * @param FormLiveValuesRecomputerInterface $recomputer Evaluates on save
	 * @param LoggerInterface $logger PSR logger
	 * @param DutchFormatValidator|null $dutchFormats Checks the Dutch formats of form fields (form-dutch-field-formats T04)
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectSchemaSlugResolverInterface $slugs,
		private readonly FormLiveBindingIndexInterface $index,
		private readonly FormLiveValuesRecomputerInterface $recomputer,
		private readonly LoggerInterface $logger,
		private readonly ?DutchFormatValidator $dutchFormats=null,
	) {
	}//end __construct()

	/**
	 * Handle an object save.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-the-server-recomputes-before-a-save-req-bqlv-005
	 */
	public function handle(Event $event): void {
		if ($this->running === true
			|| ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) === false
		) {
			return;
		}

		$entity = $this->entityOf(event: $event);

		$schemaSlug = $this->slugs->schemaSlug(entity: $entity);
		$registerSlug = $this->slugs->registerSlug(entity: $entity);
		$data = $entity->getObject();
		if (is_array($data) === false) {
			$data = [];
		}

		if ($registerSlug === ObjectSchemaSlugResolverInterface::REGISTER_SLUG
			&& $schemaSlug === ApplicationVersionServiceInterface::APPLICATION_VERSION_SCHEMA
		) {
			$this->reindex(entity: $entity, version: $data);
			return;
		}

		if ($this->index->isEmpty() === true) {
			return;
		}

		$bindings = $this->index->bindingsFor(
			registerKeys: [$registerSlug, (string)$entity->getRegister()],
			schemaKeys: [$schemaSlug, (string)$entity->getSchema()]
		);
		if ($bindings !== []) {
			$this->apply(event: $event, data: $data, bindings: $bindings);
		}
	}//end handle()

	/**
	 * The object an OpenRegister save event carries.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The save event
	 *
	 * @return ObjectEntity
	 */
	private function entityOf(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end entityOf()

	/**
	 * Recompute and either hand the values back or refuse the save.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The save event
	 * @param array<string,mixed> $data The object data
	 * @param list<LiveBinding> $bindings The live forms writing here
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $data, array $bindings): void {
		// Form-dutch-field-formats T04: a Dutch format is checked, and its
		// value normalised, before anything is calculated from it.
		$checked = $this->checkDutchFormats(data: $data, bindings: $bindings);
		if ($checked['refusal'] !== null) {
			$event->stopPropagation();
			$event->setErrors(['status' => 422, 'code' => 'invalid_format', 'message' => $checked['refusal'], 'field' => $checked['field']]);
			return;
		}

		if ($checked['changes'] !== []) {
			$data = array_merge($data, $checked['changes']);
			$event->setModifiedData(array_merge($event->getModifiedData(), $checked['changes']));
		}

		$this->running = true;
		try {
			$outcome = $this->recomputer->recompute(object: $data, bindings: $bindings);
		} finally {
			$this->running = false;
		}

		if ($outcome['refusal'] !== null) {
			$event->stopPropagation();
			$event->setErrors(
				[
					'status' => 422,
					'code' => $outcome['refusal']['code'],
					'message' => $outcome['refusal']['message'],
				]
			);
			return;
		}

		if ($outcome['changes'] !== []) {
			$event->setModifiedData(array_merge($event->getModifiedData(), $outcome['changes']));
		}
	}//end apply()

	/**
	 * Check every field with a Dutch format; the first failing one refuses the save.
	 *
	 * An empty value is not checked (whether it is required is another rule).
	 * A valid value is stored normalised, a licence plate without dashes in
	 * capitals and a postcode without its space.
	 *
	 * @param array<string,mixed> $data     The object about to be stored.
	 * @param list<LiveBinding>   $bindings The live forms that write to it.
	 *
	 * @return array{refusal: string|null, field: string|null, changes: array<string,string>}
	 *
	 * @spec openspec/changes/form-dutch-field-formats/specs/form-dutch-field-formats/spec.md#requirement-buildiqs-own-forms-check-the-dutch-formats-on-save-req-bqdf-002
	 */
	private function checkDutchFormats(array $data, array $bindings): array {
		$changes = [];
		foreach ($bindings as $binding) {
			foreach ((array)($binding['formats'] ?? []) as $field => $format) {
				$value = $data[$field] ?? null;
				if ($this->dutchFormats === null || $this->dutchFormats->knows(format: (string)$format) === false) {
					continue;
				}

				if (is_string($value) === false || trim($value) === '') {
					continue;
				}

				$normalised = $this->dutchFormats->normalise(format: (string)$format, value: $value);
				if ($normalised === null) {
					return ['refusal' => $this->dutchFormats->message(format: (string)$format), 'field' => (string)$field, 'changes' => []];
				}

				if ($normalised !== $value) {
					$changes[(string)$field] = $normalised;
				}
			}
		}

		return ['refusal' => null, 'field' => null, 'changes' => $changes];
	}//end checkDutchFormats()

	/**
	 * Read an app version's manifest into the index. A failure is logged and
	 * never blocks saving the version.
	 *
	 * @param ObjectEntity $entity The ApplicationVersion entity
	 * @param array<string,mixed> $version Its data
	 *
	 * @return void
	 */
	private function reindex(ObjectEntity $entity, array $version): void {
		$versionId = '';
		try {
			$versionId = (string)$entity->getUuid();
		} catch (Throwable) {
			$versionId = '';
		}

		if ($versionId === '') {
			$versionId = (string)($version['id'] ?? ($version['slug'] ?? ''));
		}

		if ($versionId === '') {
			return;
		}

		try {
			$this->index->reindexVersion(versionId: $versionId, version: $version);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Buildiq: the live forms of an app version could not be indexed.',
				['version' => $versionId, 'exception' => $e->getMessage()]
			);
		}
	}//end reindex()
}//end class
