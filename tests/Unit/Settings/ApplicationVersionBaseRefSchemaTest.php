<?php

/**
 * The merged register accepts an application version's base reference and delta.
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
 * @spec openspec/changes/app-delta-override/specs/openbuild-application-register/spec.md#requirement-manifest-carrying-schema-declares-optional-baseref-and-manifestdelta
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use OCA\Buildiq\Service\SettingsService;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Validates `ApplicationVersion.baseRef`, `manifestDelta` and `manifest` against the merged register.
 *
 * @covers \OCA\Buildiq\Service\SettingsService
 */
final class ApplicationVersionBaseRefSchemaTest extends TestCase {

	/**
	 * The merged ApplicationVersion schema, through the import's own merge.
	 *
	 * @return array<string,mixed>
	 */
	private static function schema(): array {
		$settings = __DIR__ . '/../../../lib/Settings';
		$merged = json_decode(json: (string)file_get_contents(filename: $settings . '/openbuild_register.json'), associative: true);
		$files = glob(pattern: $settings . '/register.d/*.json');
		sort(array: $files);
		$merge = new ReflectionMethod(objectOrMethod: SettingsService::class, method: 'deepMergeConfig');
		foreach ($files as $file) {
			$merged = $merge->invoke(null, $merged, json_decode(json: (string)file_get_contents(filename: $file), associative: true));
		}

		return $merged['components']['schemas']['ApplicationVersion'];
	}//end schema()

	/**
	 * Whether a value holds against one merged property.
	 *
	 * @param string $property The property name.
	 * @param mixed  $value    The value.
	 *
	 * @return bool
	 */
	private static function holds(string $property, mixed $value): bool {
		$result = (new Validator())->validate(
			data: json_decode(json: (string)json_encode(value: $value)),
			schema: json_decode(json: (string)json_encode(value: self::schema()['properties'][$property]))
		);

		return $result->isValid();
	}//end holds()

	/**
	 * Every base kind is accepted with an id and an optional pin; another kind or a stray key is not.
	 *
	 * @return void
	 */
	public function testTheBaseReferenceNamesItsKind(): void {
		foreach (['template', 'buildiq-app', 'fleet-app', 'application-version'] as $kind) {
			$this->assertTrue(condition: self::holds(property: 'baseRef', value: ['kind' => $kind, 'id' => 'permits']), message: $kind);
		}

		$this->assertTrue(condition: self::holds(property: 'baseRef', value: ['kind' => 'template', 'id' => 'permits', 'manifestVersion' => '1.2.0']));
		$this->assertFalse(condition: self::holds(property: 'baseRef', value: ['kind' => 'fork', 'id' => 'permits']));
		$this->assertFalse(condition: self::holds(property: 'baseRef', value: ['kind' => 'template', 'id' => 'permits', 'note' => 'x']));
	}//end testTheBaseReferenceNamesItsKind()

	/**
	 * Both new fields are optional, so a legacy version with only a manifest blob is still valid.
	 *
	 * @return void
	 */
	public function testALegacyBlobVersionNeedsNeitherField(): void {
		$schema = self::schema();
		$required = (array)($schema['required'] ?? []);

		$this->assertNotContains(needle: 'baseRef', haystack: $required);
		$this->assertNotContains(needle: 'manifestDelta', haystack: $required);
		$this->assertTrue(condition: self::holds(property: 'manifest', value: ['version' => '1.0.0', 'pages' => []]));
		$this->assertStringContainsString(needle: 'no `baseRef`', haystack: (string)$schema['properties']['manifest']['description']);
	}//end testALegacyBlobVersionNeedsNeitherField()

	/**
	 * A keyed delta with a removal marker and an order key is accepted as an object.
	 *
	 * @return void
	 */
	public function testTheDeltaIsAKeyedObject(): void {
		$delta = ['pages' => ['home' => ['title' => 'Start'], 'old' => ['$op' => 'remove']], '__order' => ['home']];

		$this->assertTrue(condition: self::holds(property: 'manifestDelta', value: $delta));
		$this->assertFalse(condition: self::holds(property: 'manifestDelta', value: 'pages'));
	}//end testTheDeltaIsAKeyedObject()
}//end class
