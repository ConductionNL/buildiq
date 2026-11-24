<?php

/**
 * The merged register accepts an automation with a run-automation step.
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
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-an-automation-can-call-another-req-bqsf-004
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use OCA\Buildiq\Service\SettingsService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Validates run-automation steps against the merged Automation schema.
 *
 * @covers \OCA\Buildiq\Service\SettingsService
 */
final class AutomationRunAutomationFragmentTest extends TestCase {

	/**
	 * The merged Automation action item schema, through the import's own merge.
	 *
	 * @return array<string,mixed>
	 */
	private function actionSchema(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode(json: (string)file_get_contents(filename: $settings . '/openbuild_register.json'), associative: true);
		$this->assertIsArray(actual: $merged);
		$files = glob(pattern: $settings . '/register.d/*.json');
		sort(array: $files);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		foreach ($files as $file) {
			$fragment = json_decode(json: (string)file_get_contents(filename: $file), associative: true);
			$this->assertIsArray(actual: $fragment, message: basename(path: $file));
			$merged = $merge->invoke(null, $merged, $fragment);
		}

		return $merged['components']['schemas']['Automation']['properties']['actions']['items'];
	}//end actionSchema()

	/**
	 * Validation errors of an action, '' when it holds.
	 *
	 * @param array<string,mixed> $action The action.
	 *
	 * @return string
	 */
	private function errors(array $action): string {
		$result = (new Validator())->validate(
			data: json_decode(json: (string)json_encode(value: $action)),
			schema: json_decode(json: (string)json_encode(value: $this->actionSchema()))
		);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode(value: (new ErrorFormatter())->format(error: $result->error()));
	}//end errors()

	/**
	 * A run-automation step naming an automation is accepted.
	 *
	 * @return void
	 */
	public function testTheImportAcceptsARunAutomationStep(): void {
		$step = ['type' => 'run-automation', 'automation' => '0b6f3f5e-2a4c-4d8e-9f10-1a2b3c4d5e6f'];

		$this->assertSame(expected: '', actual: $this->errors(action: $step));
	}//end testTheImportAcceptsARunAutomationStep()

	/**
	 * The merge appends the kind to the enum and keeps every earlier kind.
	 *
	 * @return void
	 */
	public function testTheKindIsAddedBesideTheOthers(): void {
		$enum = $this->actionSchema()['properties']['type']['enum'];

		$this->assertContains(needle: 'run-automation', haystack: $enum);
		$this->assertContains(needle: 'script-step', haystack: $enum);
		$this->assertContains(needle: 'object-op', haystack: $enum);
		$this->assertSame(expected: count(value: $enum), actual: count(value: array_unique(array: $enum)));
	}//end testTheKindIsAddedBesideTheOthers()

	/**
	 * An unknown kind is still refused after the merge.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsStillRefused(): void {
		$this->assertNotSame(expected: '', actual: $this->errors(action: ['type' => 'run-anything']));
	}//end testAnUnknownKindIsStillRefused()
}//end class
