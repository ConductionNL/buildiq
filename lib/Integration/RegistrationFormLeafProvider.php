<?php

/**
 * Registration Form Leaf Provider
 *
 * `buildiq-registration-form`. A consuming app places this leaf on its own type
 * object and asks: which form should I show, for this audience and this
 * channel. Buildiq answers with the form, its presets and the person's own
 * drafts, and never touches the object the form will create (ADR-066).
 *
 * THE SERVING SHAPE IS THE POINT
 * ------------------------------
 * A hidden preset is REMOVED from `fields[]` and travels beside the form in
 * `presets[]`. Not marked hidden, not styled away: removed. A field that is
 * merely hidden in the markup is still in the payload and still readable by
 * anybody who opens the network tab, and "the citizen never sees the channel
 * field" has to mean the citizen is never sent it.
 *
 * The fields come back in the order the administrator arranged, grouped by the
 * sections the form declares. There is deliberately no fall-back to the target
 * schema's property order: a form that quietly reordered itself the day
 * somebody added a property is worse than one that shows nothing.
 *
 * @category Integration
 * @package  OCA\Buildiq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-006, REQ-OBRF-009)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Buildiq\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCP\IAppConfig;
use OCP\IUserSession;
use RuntimeException;

/**
 * Serves the registration forms bound to a type, and appends drafts.
 *
 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-006)
 */
final class RegistrationFormLeafProvider implements IntegrationProvider {
	/**
	 * The leaf id, equal on both halves so gate-24 can pair them.
	 *
	 * @var string
	 */
	public const LEAF_ID = 'buildiq-registration-form';

	/**
	 * The schema holding registration forms.
	 *
	 * @var string
	 */
	private const SCHEMA_FORM = 'registrationForm';

	/**
	 * The schema holding drafts.
	 *
	 * @var string
	 */
	private const SCHEMA_DRAFT = 'formDraft';

	/**
	 * Constructor.
	 *
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IAppConfig $appConfig App config, for the register slug.
	 * @param IUserSession $userSession The calling user, who owns the drafts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectServiceInterface $objectService,
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The leaf id.
	 *
	 * @return string The id.
	 */
	public function getId(): string {
		return self::LEAF_ID;
	}//end getId()

	/**
	 * The label shown on the leaf.
	 *
	 * @return string The label.
	 */
	public function getLabel(): string {
		return 'Registration forms';
	}//end getLabel()

	/**
	 * The MDI icon name.
	 *
	 * @return string The icon.
	 */
	public function getIcon(): string {
		return 'FormSelect';
	}//end getIcon()

	/**
	 * The group the leaf sorts under.
	 *
	 * @return string|null The group.
	 */
	public function getGroup(): ?string {
		return 'Forms';
	}//end getGroup()

	/**
	 * The app that must be installed for this leaf to answer.
	 *
	 * @return string|null The app id.
	 */
	public function getRequiredApp(): ?string {
		return 'buildiq';
	}//end getRequiredApp()

	/**
	 * Forms live in buildiq's own register.
	 *
	 * @return string The storage strategy.
	 */
	public function getStorageStrategy(): string {
		return 'app-local';
	}//end getStorageStrategy()

	/**
	 * No OpenConnector source.
	 *
	 * @return string|null The source.
	 */
	public function getOpenConnectorSource(): ?string {
		return null;
	}//end getOpenConnectorSource()

	/**
	 * The leaf answers whenever buildiq is installed.
	 *
	 * @return bool True.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * Reading a form needs no right of its own; a draft is scoped to its owner.
	 *
	 * @return string|null Null.
	 */
	public function requiresPermission(): ?string {
		return null;
	}//end requiresPermission()

	/**
	 * The leaf needs no credentials of its own.
	 *
	 * @return array<string, mixed> The requirements.
	 */
	public function authRequirements(): array {
		return [];
	}//end authRequirements()

	/**
	 * The published forms bound to this type object, defaults first, with the
	 * calling user's own drafts beside them.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id, read as the type value.
	 * @param array<string, mixed> $filters Optional `audience`, `name` and `channel`; unknown keys are ignored.
	 *
	 * @return array<string, mixed> The `{items, total, nextCursor, drafts}` envelope.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-006, REQ-OBRF-009)
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		$forms = $this->publishedFormsFor($register, $schema, $objectId);

		$audience = (string)($filters['audience'] ?? '');
		if ($audience !== '') {
			$forms = array_values(
				array_filter($forms, static fn (array $form): bool => (string)($form['audience'] ?? '') === $audience)
			);
		}

		$name = (string)($filters['name'] ?? '');
		if ($name !== '') {
			$forms = array_values(
				array_filter($forms, static fn (array $form): bool => (string)($form['name'] ?? '') === $name)
			);
		}

		$channel = (string)($filters['channel'] ?? '');
		if ($channel !== '') {
			// The channel's own forms first, then the forms that declare no
			// channel. A channel with no form of its own must fall back rather
			// than answer nothing, or adding a channel silently removes the form
			// from every application that arrives through it.
			$onChannel = [];
			$channelless = [];
			foreach ($forms as $form) {
				$formChannel = (string)($form['channel'] ?? '');
				if ($formChannel === $channel) {
					$onChannel[] = $form;
				} elseif ($formChannel === '') {
					$channelless[] = $form;
				}
			}

			$forms = array_merge($onChannel, $channelless);
		}

		$forms = $this->defaultsFirst($forms);

		$items = [];
		foreach ($forms as $form) {
			$items[] = $this->serve($form);
		}

		return [
			'items' => $items,
			'total' => count($items),
			'nextCursor' => null,
			'drafts' => $this->draftsOfCaller($items),
		];
	}//end list()

	/**
	 * One form by id, scoped to the type object it is bound to.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The form id.
	 *
	 * @return array<string, mixed> The served form.
	 *
	 * @throws RuntimeException When no such form is bound to this type.
	 */
	public function get(string $register, string $schema, string $objectId, string $entityId): array {
		foreach ($this->publishedFormsFor($register, $schema, $objectId) as $form) {
			if ((string)($form['id'] ?? '') === $entityId) {
				return $this->serve($form);
			}
		}

		throw new RuntimeException('404 No published registration form with that id is bound to this type.');
	}//end get()

	/**
	 * Append a draft for the calling user.
	 *
	 * This is the one append ADR-066 allows here: it writes buildiq's own data
	 * and nothing else's. The owner is taken from the SESSION and never from the
	 * payload, because a draft is the one object whose whole protection is whose
	 * name is on it.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param array<string, mixed> $payload `registrationFormId`, `values`, `step`.
	 *
	 * @return array<string, mixed> The created draft.
	 *
	 * @throws RuntimeException When there is no session, or no form is named.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-006)
	 */
	public function create(string $register, string $schema, string $objectId, array $payload): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('403 A draft belongs to somebody; there is no signed-in user to own this one.');
		}

		$formId = (string)($payload['registrationFormId'] ?? '');
		if ($formId === '') {
			throw new RuntimeException('A draft has to say which form it is an answer to.');
		}

		$draft = [
			'registrationFormId' => $formId,
			'userId' => $user->getUID(),
			'values' => (is_array($payload['values'] ?? null) === true ? $payload['values'] : []),
			'step' => (int)($payload['step'] ?? 0),
			'updatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];

		$saved = $this->objectService->saveObject(
			object: $draft,
			register: $this->registerSlug(),
			schema: self::SCHEMA_DRAFT,
		);

		$created = $saved->getObject();
		if ($saved->getUuid() !== null) {
			$created['id'] = $saved->getUuid();
		}

		return $created;
	}//end create()

	/**
	 * A form is edited in buildiq's builder, where its validation is.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The entity id.
	 * @param array<string, mixed> $payload Ignored.
	 *
	 * @return array<string, mixed> Never returns.
	 *
	 * @throws RuntimeException Always.
	 */
	public function update(string $register, string $schema, string $objectId, string $entityId, array $payload): array {
		throw new RuntimeException('A registration form is edited in buildiq, where the rules that validate it live.');
	}//end update()

	/**
	 * The same for a delete.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id.
	 * @param string $entityId The entity id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException Always.
	 */
	public function delete(string $register, string $schema, string $objectId, string $entityId): void {
		throw new RuntimeException('A registration form is retired in buildiq, not deleted from a consuming app.');
	}//end delete()

	/**
	 * Health of the leaf.
	 *
	 * @return array<string, mixed> The health report.
	 */
	public function health(): array {
		return ['status' => 'ok', 'leaf' => self::LEAF_ID];
	}//end health()

	/**
	 * Turn a stored form into what a consumer renders.
	 *
	 * @param array<string, mixed> $form The stored form.
	 *
	 * @return array<string, mixed> The served form.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-008)
	 */
	private function serve(array $form): array {
		$presets = [];
		$hidden = [];
		$visible = [];
		foreach (($form['presets'] ?? []) as $preset) {
			if (is_array($preset) === false || (string)($preset['field'] ?? '') === '') {
				continue;
			}

			$field = (string)$preset['field'];
			$presets[] = ['field' => $field, 'value' => ($preset['value'] ?? null), 'hidden' => (($preset['hidden'] ?? false) === true)];

			if (($preset['hidden'] ?? false) === true) {
				$hidden[] = $field;
				continue;
			}

			$visible[$field] = ($preset['value'] ?? null);
		}

		$fields = [];
		foreach (($form['fields'] ?? []) as $field) {
			if (is_array($field) === false) {
				continue;
			}

			$name = (string)($field['name'] ?? '');
			if ($name === '' || in_array($name, $hidden, true) === true) {
				// Removed, not marked hidden. A field still in the payload is
				// still readable by anybody who opens the network tab.
				continue;
			}

			if (array_key_exists($name, $visible) === true) {
				$field['default'] = $visible[$name];
			}

			$fields[] = $field;
		}

		return [
			'id' => (string)($form['id'] ?? ''),
			'name' => (string)($form['name'] ?? ''),
			'audience' => (string)($form['audience'] ?? ''),
			'channel' => (string)($form['channel'] ?? ''),
			'isDefault' => (($form['isDefault'] ?? false) === true),
			'isPublic' => (($form['isPublic'] ?? false) === true),
			'confirmationText' => (string)($form['confirmationText'] ?? ''),
			'allowSaveForLater' => (($form['allowSaveForLater'] ?? false) === true),
			'sections' => $this->orderedSections($form),
			'fields' => $this->orderFields($fields, $this->orderedSections($form)),
			'steps' => (is_array($form['steps'] ?? null) === true ? $form['steps'] : []),
			'formLogic' => (is_array($form['formLogic'] ?? null) === true ? $form['formLogic'] : []),
			'presets' => $presets,
		];
	}//end serve()

	/**
	 * The form's sections, in the order the administrator set.
	 *
	 * @param array<string, mixed> $form The stored form.
	 *
	 * @return array<int, array<string, mixed>> The sections.
	 */
	private function orderedSections(array $form): array {
		$sections = [];
		foreach (($form['sections'] ?? []) as $section) {
			if (is_array($section) === true && (string)($section['name'] ?? '') !== '') {
				$sections[] = $section;
			}
		}

		usort(
			$sections,
			static fn (array $a, array $b): int => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0))
		);

		return $sections;
	}//end orderedSections()

	/**
	 * The fields grouped into their sections and ordered inside each, sections
	 * in their own order first and the section-less fields last.
	 *
	 * @param array<int, array<string, mixed>> $fields The served fields.
	 * @param array<int, array<string, mixed>> $sections The ordered sections.
	 *
	 * @return array<int, array<string, mixed>> The ordered fields.
	 *
	 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
	 */
	private function orderFields(array $fields, array $sections): array {
		$order = [];
		foreach ($sections as $index => $section) {
			$order[(string)$section['name']] = $index;
		}

		usort(
			$fields,
			static function (array $a, array $b) use ($order): int {
				$sectionA = ($order[(string)($a['section'] ?? '')] ?? PHP_INT_MAX);
				$sectionB = ($order[(string)($b['section'] ?? '')] ?? PHP_INT_MAX);

				if ($sectionA !== $sectionB) {
					return ($sectionA <=> $sectionB);
				}

				return ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0));
			}
		);

		return array_values($fields);
	}//end orderFields()

	/**
	 * Put the defaults at the front, keeping the rest in their stored order.
	 *
	 * @param array<int, array<string, mixed>> $forms The forms.
	 *
	 * @return array<int, array<string, mixed>> The forms, defaults first.
	 */
	private function defaultsFirst(array $forms): array {
		$defaults = [];
		$rest = [];
		foreach ($forms as $form) {
			if (($form['isDefault'] ?? false) === true) {
				$defaults[] = $form;
				continue;
			}

			$rest[] = $form;
		}

		return array_merge($defaults, $rest);
	}//end defaultsFirst()

	/**
	 * The calling user's own drafts for the listed forms.
	 *
	 * @param array<int, array<string, mixed>> $forms The served forms.
	 *
	 * @return array<int, array<string, mixed>> The drafts.
	 */
	private function draftsOfCaller(array $forms): array {
		$user = $this->userSession->getUser();
		if ($user === null || $forms === []) {
			return [];
		}

		$uid = $user->getUID();
		$formIds = array_map(static fn (array $form): string => (string)$form['id'], $forms);

		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA_DRAFT)
			->findAll(['filters' => ['userId' => $uid], 'limit' => 100]);

		if (is_array($rows) === false) {
			return [];
		}

		$mine = [];
		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			// The owner check is repeated here rather than trusted to the filter.
			// A filter the store quietly ignores would hand one person's
			// half-written answers to the next caller, and nothing would say so.
			if ((string)($row['userId'] ?? '') !== $uid) {
				continue;
			}

			if (in_array((string)($row['registrationFormId'] ?? ''), $formIds, true) === true) {
				$mine[] = $row;
			}
		}

		return $mine;
	}//end draftsOfCaller()

	/**
	 * Every published form bound to the type this host object represents.
	 *
	 * @param string $register The host object's register.
	 * @param string $schema The host object's schema.
	 * @param string $objectId The host object's id, read as the type value.
	 *
	 * @return array<int, array<string, mixed>> The forms.
	 */
	private function publishedFormsFor(string $register, string $schema, string $objectId): array {
		$rows = $this->objectService
			->setRegister($this->registerSlug())
			->setSchema(self::SCHEMA_FORM)
			->findAll(['filters' => ['register' => $register], 'limit' => 500]);

		if (is_array($rows) === false) {
			return [];
		}

		$mine = [];
		foreach ($rows as $row) {
			if (is_array($row) === false
				|| (string)($row['status'] ?? '') !== 'published'
				|| (string)($row['register'] ?? '') !== $register
				|| (string)($row['schema'] ?? '') !== $schema
				|| (string)($row['typeValue'] ?? '') !== $objectId
			) {
				continue;
			}

			$mine[] = $row;
		}

		return $mine;
	}//end publishedFormsFor()

	/**
	 * The register slug holding buildiq's own objects.
	 *
	 * @return string The slug.
	 */
	private function registerSlug(): string {
		return $this->appConfig->getValueString('buildiq', 'register', 'buildiq');
	}//end registerSlug()
}//end class
