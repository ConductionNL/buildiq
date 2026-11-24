<?php

/**
 * The Automation schema accepts an automation with an AI step.
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
 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-the-composer-offers-an-ask-ai-step-req-bqls-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Validates automation records against `register.d/40-automations.json`.
 */
final class AutomationAiStepFragmentTest extends TestCase {

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
		$data = json_decode((string)file_get_contents($this->path));
		$this->assertSame(expected: JSON_ERROR_NONE, actual: json_last_error(), message: json_last_error_msg());

		return $data->components->schemas->Automation;
	}//end automationSchema()

	/**
	 * The validation errors of a record, '' when it is valid.
	 *
	 * @param array<string, mixed> $record The automation.
	 *
	 * @return string
	 */
	private function errors(array $record): string {
		$schema = $this->automationSchema();
		// The record-level relations (`versionUuid`) point at other schemas by
		// `$ref`; the keys this change adds are checked on their own.
		$properties = (object)[];
		foreach (['slug', 'name', 'enabled', 'origin', 'trigger', 'actions'] as $key) {
			$properties->$key = $schema->properties->$key;
		}

		$checked = (object)['type' => 'object', 'properties' => $properties];
		$result = (new Validator())->validate(json_decode((string)json_encode($record)), $checked);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode((new ErrorFormatter())->format($result->error()));
	}//end errors()

	/**
	 * The classify automation from REQ-BQLS-001.
	 *
	 * @return array<string, mixed>
	 */
	private static function classify(): array {
		return [
			'slug' => 'categorise-melding',
			'name' => 'Categorise reports',
			'enabled' => true,
			'origin' => 'composer',
			'trigger' => ['type' => 'object-created', 'schema' => 'melding'],
			'actions' => [
				[
					'type' => 'ai-step',
					'preset' => 'classify',
					'agent' => 'agent-uuid',
					'sourceFields' => ['omschrijving'],
					'targetField' => 'categorie',
					'labels' => ['afval', 'verlichting', 'overig'],
				],
			],
		];
	}//end classify()

	/**
	 * A classify step, and every other preset with its own keys, are accepted.
	 *
	 * @return void
	 */
	public function testTheImportAcceptsAnAutomationWithAnAiStep(): void {
		$this->assertSame(expected: '', actual: $this->errors(record: self::classify()));

		$record = self::classify();
		$record['origin'] = 'ai-field';
		$record['actions'] = [
			['type' => 'ai-step', 'preset' => 'extract', 'agent' => 'a', 'sourceFields' => ['brief'], 'fields' => ['naam', 'adres']],
			['type' => 'ai-step', 'preset' => 'translate', 'agent' => 'a', 'sourceFields' => ['toelichting'], 'targetField' => 'summary_en', 'language' => 'English'],
			['type' => 'ai-step', 'preset' => 'prompt', 'agent' => 'a', 'sourceFields' => ['tekst'], 'targetField' => 'antwoord', 'prompt' => 'Answer {{tekst}}'],
		];
		$this->assertSame(expected: '', actual: $this->errors(record: $record));
	}//end testTheImportAcceptsAnAutomationWithAnAiStep()

	/**
	 * An unknown preset, an empty source list and an unknown origin are refused.
	 *
	 * @return void
	 */
	public function testTheImportRefusesAMalformedAiStep(): void {
		$record = self::classify();
		$record['actions'][0]['preset'] = 'search-web';
		$this->assertNotSame(expected: '', actual: $this->errors(record: $record));

		$record = self::classify();
		$record['actions'][0]['sourceFields'] = [];
		$this->assertNotSame(expected: '', actual: $this->errors(record: $record));

		$record = self::classify();
		$record['origin'] = 'copilot';
		$this->assertNotSame(expected: '', actual: $this->errors(record: $record));
	}//end testTheImportRefusesAMalformedAiStep()

	/**
	 * The schema version moved with the new action kind.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionIsBumped(): void {
		$schema = $this->automationSchema();

		$this->assertTrue(condition: version_compare(version1: $schema->version, version2: '1.2.0', operator: '>='));
		$this->assertContains(needle: 'ai-step', haystack: $schema->properties->actions->items->properties->type->enum);
	}//end testTheSchemaVersionIsBumped()
}//end class
