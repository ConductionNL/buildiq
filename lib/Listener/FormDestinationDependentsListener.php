<?php

/**
 * Form Destination Dependents Listener
 *
 * Tells OpenRegister which of buildiq's published forms submit into a schema
 * that is about to change. OpenRegister re-checks each one against the
 * proposed schema; a form the change breaks is unpublished after the save and
 * its author notified, unless buildiq's `formDestinationBreak` setting asks
 * for the schema save to be refused (decision 179 Q7, decision 181).
 *
 * WHAT BUILDIQ ANNOUNCES
 * ----------------------
 * Its published registration forms: those are the stored, served forms that
 * name a destination register and schema. Unpublishing one sets it back to
 * `draft`, so its author fixes and republishes it in the same builder.
 *
 * @category Listener
 * @package  OCA\Buildiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Listener;

use OCA\Buildiq\Service\RegistrationFormTargetWarnings;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Announces buildiq's published registration forms to a schema save.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
 */
final class FormDestinationDependentsListener implements IEventListener {
	/**
	 * The schema holding registration forms.
	 *
	 * @var string
	 */
	private const SCHEMA = 'registrationForm';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param RegisterMapper $registerMapper Resolves a form's destination register.
	 * @param IAppConfig $appConfig App config, for buildiq's own register slug.
	 * @param RegistrationFormTargetWarnings $forms Maps a form the way the save-time check does.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly RegisterMapper $registerMapper,
		private readonly IAppConfig $appConfig,
		private readonly RegistrationFormTargetWarnings $forms,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Add every published registration form that writes into the event's schema.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/forms-are-valid-against-their-destination/specs/form-destination-authoring/spec.md#requirement-every-buildiq-form-must-be-valid-against-its-destination-before-it-is-published
	 */
	public function handle(Event $event): void {
		if ($event instanceof FormDestinationDependentsEvent === false) {
			return;
		}

		$schema = $event->getSchema();
		$slug = strtolower((string)$schema->getSlug());
		$schemaId = (string)$schema->getId();
		if ($slug === '') {
			return;
		}

		foreach ($this->publishedForms(schemaSlug: $slug) as $form) {
			if ($this->registerHolds(register: (string)($form['register'] ?? ''), schemaId: $schemaId) === false) {
				continue;
			}

			$event->addForm(
				new FormDependent(
					id: (string)($form['id'] ?? ''),
					app: 'buildiq',
					title: (string)($form['name'] ?? ''),
					author: $this->authorOf(form: $form),
					mapping: $this->forms->mappingOf(form: $form),
					unpublish: function () use ($form): void {
						$this->unpublish(form: $form);
					},
					audience: ($form['isPublic'] ?? false) === true ? 'public' : null,
				)
			);
		}
	}//end handle()

	/**
	 * Set a form back to draft, so its author fixes it before it is served again.
	 *
	 * @param array<string, mixed> $form The form.
	 *
	 * @return void
	 */
	private function unpublish(array $form): void {
		$form['status'] = 'draft';
		unset($form['@self']);
		$this->objectService->saveObject(
			object: $form,
			register: $this->registerSlug(),
			schema: self::SCHEMA,
			uuid: (string)($form['id'] ?? ''),
			_rbac: false,
			_multitenancy: false,
		);
	}//end unpublish()

	/**
	 * The published registration forms that name this schema.
	 *
	 * @param string $schemaSlug The schema slug, lower case.
	 *
	 * @return array<int, array<string, mixed>> The forms.
	 */
	private function publishedForms(string $schemaSlug): array {
		try {
			$rows = $this->objectService
				->setRegister($this->registerSlug())
				->setSchema(self::SCHEMA)
				->findAll(['filters' => ['status' => 'published'], 'limit' => 1000], _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// A schema save is not blocked by buildiq failing to list its forms,
			// but the miss is logged: an unannounced form is a form nobody re-checks.
			$this->logger->error('Buildiq: could not list registration forms for a schema change: ' . $e->getMessage(), ['exception' => $e]);
			return [];
		}

		$forms = [];
		foreach ((array)$rows as $row) {
			$form = $this->asArray(row: $row);
			if ((string)($form['status'] ?? '') !== 'published'
				|| strtolower((string)($form['schema'] ?? '')) !== $schemaSlug
			) {
				continue;
			}

			$forms[] = $form;
		}

		return $forms;
	}//end publishedForms()

	/**
	 * Whether a register holds the schema with this id.
	 *
	 * A slug alone is not enough: two registers may each hold a schema called
	 * `case`, and only the forms on the changed one are its dependents.
	 *
	 * @param string $register The form's destination register slug.
	 * @param string $schemaId The changed schema's id.
	 *
	 * @return bool True when it does.
	 */
	private function registerHolds(string $register, string $schemaId): bool {
		if ($register === '' || $schemaId === '') {
			return false;
		}

		try {
			$schemas = (array)($this->registerMapper->find($register, _multitenancy: false)->getSchemas() ?? []);
		} catch (Throwable) {
			return false;
		}

		return in_array($schemaId, array_map('strval', $schemas), true);
	}//end registerHolds()

	/**
	 * A stored row as a plain array, with its id and owner.
	 *
	 * @param mixed $row The row as findAll returned it.
	 *
	 * @return array<string, mixed> The form.
	 */
	private function asArray(mixed $row): array {
		if ($row instanceof ObjectEntityInterface) {
			$form = $row->getObject();
			$form['id'] = ($form['id'] ?? $row->getUuid());
			$form['@self'] = ['owner' => $row->getOwner()];
			return $form;
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end asArray()

	/**
	 * The user to tell when the form is unpublished.
	 *
	 * @param array<string, mixed> $form The form.
	 *
	 * @return string|null The owner's user id, when known.
	 */
	private function authorOf(array $form): ?string {
		$owner = ($form['@self']['owner'] ?? null);
		if (is_string($owner) === true && $owner !== '') {
			return $owner;
		}

		return null;
	}//end authorOf()

	/**
	 * The register slug holding buildiq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		$slug = $this->appConfig->getValueString('buildiq', 'register', 'buildiq');
		if ($slug === '') {
			return 'buildiq';
		}

		return $slug;
	}//end registerSlug()
}//end class
