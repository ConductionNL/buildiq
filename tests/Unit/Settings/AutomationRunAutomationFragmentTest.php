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
		$merged = json_decode((string)file_get_contents($settings . '/openbuild_register.json'), true);
		$this->assertIsArray($merged);
		$files = glob($settings . '/register.d/*.json');
		sort($files);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		foreach ($files as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($fragment, basename($file));
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
			json_decode((string)json_encode($action)),
			json_decode((string)json_encode($this->actionSchema()))
		);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode((new ErrorFormatter())->format($result->error()));
	}//end errors()

	/**
	 * A run-automation step naming an automation is accepted.
	 *
	 * @return void
	 */
	public function testTheImportAcceptsARunAutomationStep(): void {
		$this->assertSame('', $this->errors(['type' => 'run-automation', 'automation' => '0b6f3f5e-2a4c-4d8e-9f10-1a2b3c4d5e6f']));
	}//end testTheImportAcceptsARunAutomationStep()

	/**
	 * The merge appends the kind to the enum and keeps every earlier kind.
	 *
	 * @return void
	 */
	public function testTheKindIsAddedBesideTheOthers(): void {
		$enum = $this->actionSchema()['properties']['type']['enum'];

		$this->assertContains('run-automation', $enum);
		$this->assertContains('script-step', $enum);
		$this->assertContains('object-op', $enum);
		$this->assertSame(count($enum), count(array_unique($enum)));
	}//end testTheKindIsAddedBesideTheOthers()

	/**
	 * An unknown kind is still refused after the merge.
	 *
	 * @return void
	 */
	public function testAnUnknownKindIsStillRefused(): void {
		$this->assertNotSame('', $this->errors(['type' => 'run-anything']));
	}//end testAnUnknownKindIsStillRefused()
}//end class
