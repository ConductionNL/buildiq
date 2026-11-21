<?php

/**
 * The merged register accepts an Application listing its demo records.
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
 * @spec openspec/changes/capability-requirements-backfill/specs/application-creation-wizard/spec.md#requirement-a-maker-fills-a-new-app-with-demo-records-req-bqcv-030
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use OCA\Buildiq\Service\SettingsService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Validates `Application.demoRecords` against the merged register.
 *
 * @covers \OCA\Buildiq\Service\SettingsService
 */
final class ApplicationDemoRecordsFragmentTest extends TestCase {

	/**
	 * The merged `demoRecords` property, through the import's own merge.
	 *
	 * @return array<string,mixed>
	 */
	private function property(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode(json: (string)file_get_contents(filename: $settings . '/openbuild_register.json'), associative: true);
		$files = glob(pattern: $settings . '/register.d/*.json');
		sort(array: $files);
		$merge = new ReflectionMethod(SettingsService::class, 'deepMergeConfig');
		foreach ($files as $file) {
			$merged = $merge->invoke(null, $merged, json_decode(json: (string)file_get_contents(filename: $file), associative: true));
		}

		return $merged['components']['schemas']['Application']['properties']['demoRecords'];
	}//end property()

	/**
	 * Whether a value holds against the merged property.
	 *
	 * @param array<int, mixed> $value The value.
	 *
	 * @return bool
	 */
	private function holds(array $value): bool {
		$result = (new Validator())->validate(
			data: json_decode(json: (string)json_encode(value: $value)),
			schema: json_decode(json: (string)json_encode(value: $this->property()))
		);

		return $result->isValid();
	}//end holds()

	/**
	 * A list of demo records with register, schema and uuid is accepted; one without a uuid or with a stray key is not.
	 *
	 * @return void
	 */
	public function testTheImportAcceptsTheListOfDemoRecords(): void {
		$this->assertTrue(condition: $this->holds(value: [['register' => 'meldingen', 'schema' => 'report', 'uuid' => 'u-1']]));
		$this->assertTrue(condition: $this->holds(value: []));
		$this->assertFalse(condition: $this->holds(value: [['schema' => 'report']]));
		$this->assertFalse(condition: $this->holds(value: [['uuid' => 'u-1', 'note' => 'x']]));
	}//end testTheImportAcceptsTheListOfDemoRecords()
}//end class
