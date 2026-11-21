<?php

/**
 * The Automation schema accepts an automation with a script step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validates script-step automations against `register.d/40-automations.json`.
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md#requirement-the-composer-offers-a-script-step-req-bqss-001
 */
final class AutomationScriptStepFragmentTest extends TestCase {

	/**
	 * The fragment file.
	 *
	 * @var string
	 */
	private string $path = __DIR__ . '/../../../lib/Settings/register.d/40-automations.json';

	/**
	 * The decoded Automation schema.
	 *
	 * @return object
	 */
	private function automationSchema(): object {
		$data = json_decode(json: (string)file_get_contents(filename: $this->path));
		$this->assertSame(expected: JSON_ERROR_NONE, actual: json_last_error(), message: json_last_error_msg());

		return $data->components->schemas->Automation;
	}//end automationSchema()

	/**
	 * The validation errors of a record's actions, '' when they are valid.
	 *
	 * @param array<int, array<string, mixed>> $actions The actions.
	 *
	 * @return string
	 */
	private function errors(array $actions): string {
		$checked = (object)[
			'type' => 'object',
			'properties' => (object)['actions' => $this->automationSchema()->properties->actions],
		];
		$record = json_decode(json: (string)json_encode(value: ['actions' => $actions]));
		$result = (new Validator())->validate(data: $record, schema: $checked);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode(value: (new ErrorFormatter())->format(error: $result->error()));
	}//end errors()

	/**
	 * A script step with its code, timeout and note is accepted.
	 *
	 * @return void
	 */
	public function testTheImportAcceptsAScriptStep(): void {
		$step = [
			'type' => 'script-step',
			'code' => "return items.map(i => ({...i, json: {...i.json, naam: i.json.voornaam + ' ' + i.json.achternaam}}));",
			'timeoutSeconds' => 10,
			'note' => 'Join first and last name',
		];

		$this->assertSame(expected: '', actual: $this->errors(actions: [$step]));
	}//end testTheImportAcceptsAScriptStep()

	/**
	 * A timeout outside 1 to 30 seconds is refused.
	 *
	 * @return void
	 */
	public function testTheImportRefusesATimeoutOutOfRange(): void {
		foreach ([0, 31] as $timeout) {
			$step = ['type' => 'script-step', 'code' => 'return items;', 'timeoutSeconds' => $timeout];
			$this->assertNotSame(expected: '', actual: $this->errors(actions: [$step]));
		}
	}//end testTheImportRefusesATimeoutOutOfRange()

	/**
	 * The schema version moved with the new action kind.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionIsBumped(): void {
		$schema = $this->automationSchema();

		$this->assertSame(expected: '1.3.0', actual: $schema->version);
		$this->assertContains(needle: 'script-step', haystack: $schema->properties->actions->items->properties->type->enum);
	}//end testTheSchemaVersionIsBumped()
}//end class
