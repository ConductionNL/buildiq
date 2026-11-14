<?php

/**
 * Buildiq Approval Form Declaration
 *
 * The fields an approver fills in, declared on an automation's approval
 * action as `form.fields[]`, checked against the trigger schema when the
 * automation is compiled.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service\Automation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;

/**
 * Checks the approval forms of an automation (design D2 and D3).
 *
 * A field is refused, by name, when the trigger schema does not declare it,
 * marks it `readOnly`, hides it (`visible: false`), or makes it a relation or
 * an array: the task form renders scalar fields only. A declaration that
 * passes is still refused while OpenRegister does not carry a chain
 * position's form into the tasks it opens, so a maker never saves a form that
 * silently does nothing. An empty or absent list is no form.
 *
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
 */
final class ApprovalFormDeclaration {

	/**
	 * The refusal while OpenRegister cannot carry a form into a task (REQ-BQTF-002).
	 */
	public const NOT_CARRIED = 'Approval forms need OpenRegister to carry a form into the task';

	/**
	 * The declared fields of an approval action, as `{field, required}`, in order; [] for no form.
	 *
	 * @param array<string,mixed> $action One automation action.
	 *
	 * @return list<array{field:string,required:bool}>
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
	 */
	public static function fieldsOf(array $action): array {
		$fields = ($action['form']['fields'] ?? []);
		if (is_array($fields) === false) {
			return [];
		}

		$declared = [];
		foreach ($fields as $entry) {
			if (is_array($entry) === false) {
				$entry = [];
			}

			$declared[] = ['field' => trim((string)($entry['field'] ?? '')), 'required' => (($entry['required'] ?? false) === true)];
		}

		return $declared;
	}//end fieldsOf()

	/**
	 * Constructor.
	 *
	 * @param string   $schemaSlug The trigger schema.
	 * @param \Closure $properties Reads the trigger schema's properties, or null when it cannot; called only when a form is declared.
	 */
	public function __construct(
		private readonly string $schemaSlug,
		private readonly \Closure $properties,
	) {
	}//end __construct()

	/**
	 * Refuse an approval form the trigger schema cannot render, naming the field; then refuse any form at all.
	 *
	 * @param array<int,mixed> $actions The automation's actions.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException For the first field refused, or for a form OpenRegister cannot carry.
	 *
	 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-the-form-is-compiled-into-the-approval-chain-or-refused-req-bqtf-002
	 */
	public function check(array $actions): void {
		$forms = [];
		foreach ($actions as $action) {
			if (is_array($action) === true && ($action['type'] ?? '') === 'approval' && self::fieldsOf(action: $action) !== []) {
				$forms[] = self::fieldsOf(action: $action);
			}
		}

		if ($forms === []) {
			return;
		}

		$declared = ($this->properties)();
		if (is_array($declared) === false) {
			throw new UnsupportedAutomationCombinationException(
				message: 'The fields of the approval form cannot be checked: schema "' . $this->schemaSlug . '" could not be read.'
			);
		}

		foreach ($forms as $fields) {
			$this->checkFields(fields: $fields, properties: $declared);
		}

		throw new UnsupportedAutomationCombinationException(message: self::NOT_CARRIED);
	}//end check()

	/**
	 * Refuse the first field of one form the schema cannot render.
	 *
	 * @param list<array{field:string,required:bool}> $fields     The declared fields.
	 * @param array<string,mixed>                     $properties The trigger schema's properties.
	 *
	 * @return void
	 *
	 * @throws UnsupportedAutomationCombinationException Naming the field.
	 */
	private function checkFields(array $fields, array $properties): void {
		$seen = [];
		foreach ($fields as $entry) {
			$name = $entry['field'];
			$reason = self::listingRefusal(name: $name, seen: $seen) ?? self::refusal(property: ($properties[$name] ?? null));
			if ($reason !== null) {
				throw new UnsupportedAutomationCombinationException(
					message: 'The approval form cannot ask for "' . $name . '": ' . $reason . ' in schema "' . $this->schemaSlug . '".'
				);
			}

			$seen[] = $name;
		}
	}//end checkFields()

	/**
	 * Why a field cannot be listed: it has no name, or is listed twice; null when it can.
	 *
	 * @param string       $name The field.
	 * @param list<string> $seen The fields earlier in the list.
	 *
	 * @return string|null
	 */
	private static function listingRefusal(string $name, array $seen): ?string {
		if ($name === '') {
			return 'a field without a name is listed';
		}

		if (in_array($name, $seen, true) === true) {
			return 'it is listed twice';
		}

		return null;
	}//end listingRefusal()

	/**
	 * Why a schema property cannot be on the form, or null when it can.
	 *
	 * @param mixed $property The schema property, or null when undeclared.
	 *
	 * @return string|null
	 */
	private static function refusal(mixed $property): ?string {
		if (is_array($property) === false) {
			return 'it is not a property';
		}

		if (($property['readOnly'] ?? false) === true) {
			return 'it is read-only';
		}

		if (($property['visible'] ?? true) === false) {
			return 'it is hidden';
		}

		$type = $property['type'] ?? null;
		if ($type === 'array' || (is_array($type) === true && in_array('array', $type, true) === true)) {
			return 'it is a list';
		}

		$relation = (isset($property['$ref']) === true || isset($property['inversedBy']) === true || isset($property['objectConfiguration']) === true);
		if ($type === 'object' || $relation === true) {
			return 'it is a relation';
		}

		return null;
	}//end refusal()
}//end class
