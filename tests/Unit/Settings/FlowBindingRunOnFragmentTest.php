<?php

/**
 * The flow binding's `runOn` and the automation's `shared`, as the register
 * import sees them after every fragment is merged onto the base register.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/logic-shared-flows-and-automations/specs/app-flow-bindings/spec.md#requirement-a-bound-flow-declares-where-it-runs-req-bqsf-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use OCA\Buildiq\Service\SettingsService;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The merged register accepts a binding with `runOn` and an automation with `shared`.
 *
 * @covers \OCA\Buildiq\Service\SettingsService
 */
final class FlowBindingRunOnFragmentTest extends TestCase {

	private const UUID = '0b6f3f5e-2a4c-4d8e-9f10-1a2b3c4d5e6f';

	/**
	 * The base register with every register.d fragment merged in file-name
	 * order, through the import's own merge.
	 *
	 * @return array<string,mixed>
	 */
	private function merged(): array {
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

		return $merged;
	}//end merged()

	/**
	 * The merged flow binding item schema.
	 *
	 * @return array<string,mixed>
	 */
	private function bindingSchema(): array {
		return $this->merged()['components']['schemas']['Application']['properties']['flows']['items'];
	}//end bindingSchema()

	/**
	 * Validation errors of a value against a schema, empty when it holds.
	 *
	 * @param array<string,mixed> $value  The value.
	 * @param array<string,mixed> $schema The schema.
	 *
	 * @return string
	 */
	private function errors(array $value, array $schema): string {
		$result = (new Validator())->validate(
			json_decode((string)json_encode($value)),
			json_decode((string)json_encode($schema))
		);
		if ($result->isValid() === true) {
			return '';
		}

		return (string)json_encode((new ErrorFormatter())->format($result->error()));
	}//end errors()

	/**
	 * A binding that runs on updated `vergunning` records and from a record is accepted.
	 *
	 * @return void
	 */
	public function testTheImportAcceptsABindingThatSaysWhereItRuns(): void {
		$binding = [
			'flow' => self::UUID,
			'label' => 'Archiveer dossier',
			'runOn' => [
				['schema' => 'vergunning', 'event' => 'object.updated'],
				['schema' => 'vergunning', 'mode' => 'from-record'],
			],
		];

		$this->assertSame('', $this->errors(value: $binding, schema: $this->bindingSchema()));
	}//end testTheImportAcceptsABindingThatSaysWhereItRuns()

	/**
	 * A binding from before this change, without `runOn`, still holds, and
	 * the binding's other properties are untouched by the merge.
	 *
	 * @return void
	 */
	public function testABindingWithoutRunOnStillHolds(): void {
		$schema = $this->bindingSchema();

		$this->assertSame('', $this->errors(value: ['flow' => self::UUID], schema: $schema));
		$this->assertSame(['flow'], $schema['required']);
		$this->assertFalse($schema['additionalProperties']);
		$this->assertSame(['flow', 'label', 'sourceUuid', 'runOn'], array_keys($schema['properties']));
	}//end testABindingWithoutRunOnStillHolds()

	/**
	 * An entry needs a schema and exactly one of an event the engine fires or
	 * `from-record`; anything else is refused.
	 *
	 * @return void
	 */
	public function testTheImportRefusesAMalformedEntry(): void {
		$schema = $this->bindingSchema();
		$refused = [
			'no schema' => ['event' => 'object.updated'],
			'neither event nor mode' => ['schema' => 'vergunning'],
			'both event and mode' => ['schema' => 'vergunning', 'event' => 'object.updated', 'mode' => 'from-record'],
			'an event the engine does not fire' => ['schema' => 'vergunning', 'event' => 'object.archived'],
			'an unknown key' => ['schema' => 'vergunning', 'event' => 'object.created', 'wait' => true],
		];
		foreach ($refused as $why => $entry) {
			$this->assertNotSame('', $this->errors(value: ['flow' => self::UUID, 'runOn' => [$entry]], schema: $schema), $why);
		}
	}//end testTheImportRefusesAMalformedEntry()

	/**
	 * An automation carries `shared`, off by default, and the rest of the
	 * automation schema is unchanged.
	 *
	 * @return void
	 */
	public function testAnAutomationCarriesSharedOffByDefault(): void {
		$automation = $this->merged()['components']['schemas']['Automation'];

		$this->assertSame(['type' => 'boolean', 'default' => false], array_intersect_key($automation['properties']['shared'], ['type' => 1, 'default' => 1]));
		$this->assertSame(['slug', 'name', 'applicationSlug', 'versionUuid', 'trigger'], $automation['required']);
		$this->assertSame('', $this->errors(value: ['shared' => true], schema: ['type' => 'object', 'properties' => ['shared' => $automation['properties']['shared']]]));
		$this->assertNotSame('', $this->errors(value: ['shared' => 'yes'], schema: ['type' => 'object', 'properties' => ['shared' => $automation['properties']['shared']]]));
	}//end testAnAutomationCarriesSharedOffByDefault()
}//end class
