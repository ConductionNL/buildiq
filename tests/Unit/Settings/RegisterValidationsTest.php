<?php

/**
 * Unit test for the same-row validations declared in
 * `lib/Settings/openbuild_register.json`.
 *
 * OpenRegister reads validations from `x-openregister-validations` only: a
 * map of named declarations, each with a JSONLogic `condition` that names the
 * violation, a `severity`, a `message` and the `properties` it is about. The
 * declarations are checked here the way OpenRegister checks them on import,
 * and their conditions are evaluated over sample objects with a small
 * JSONLogic subset (`and`, loose `==` and `!=`, `var`), so a condition that inverts or
 * misses its case fails here rather than on a live save.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the register's administered validations.
 */
class RegisterValidationsTest extends TestCase {
	/**
	 * The annotation OpenRegister reads.
	 *
	 * @var string
	 */
	private const ANNOTATION = 'x-openregister-validations';

	/**
	 * The schemas of the register seed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function schemas(): array {
		$raw = (string)file_get_contents(__DIR__ . '/../../../lib/Settings/openbuild_register.json');
		$seed = json_decode($raw, true);
		self::assertIsArray($seed);

		return $seed['components']['schemas'];
	}//end schemas()

	/**
	 * No schema carries an annotation OpenRegister drops on import.
	 *
	 * @return void
	 */
	public function testNoSchemaCarriesAnUnreadAnnotation(): void {
		foreach (self::schemas() as $name => $schema) {
			foreach (['x-openregister-validation', 'x-openregister-rbac', 'x-rbac-role'] as $unread) {
				$this->assertArrayNotHasKey($unread, $schema, $name . ' carries ' . $unread);
			}
		}
	}//end testNoSchemaCarriesAnUnreadAnnotation()

	/**
	 * Every declaration is complete in the way OpenRegister requires.
	 *
	 * @return void
	 */
	public function testEveryDeclarationIsComplete(): void {
		$expected = [
			'Application' => ['hybrid-requires-baseRef'],
			'ApplicationVersion' => ['promotesTo-no-self-loop', 'user-scope-requires-owner'],
		];
		$found = [];
		foreach (self::schemas() as $name => $schema) {
			if (isset($schema[self::ANNOTATION]) === false) {
				continue;
			}

			$found[$name] = array_keys($schema[self::ANNOTATION]);
			foreach ($schema[self::ANNOTATION] as $validation => $declaration) {
				$this->assertArrayHasKey('condition', $declaration, $validation);
				$this->assertSame('refuse', $declaration['severity'] ?? null, $validation . ': the specs say the save fails');
				$this->assertNotSame('', trim((string)($declaration['message'] ?? '')), $validation);
				$this->assertIsArray($declaration['properties'], $validation);
				foreach ($declaration['properties'] as $property) {
					$this->assertArrayHasKey($property, $schema['properties'], $validation . ' names ' . $property);
				}
			}
		}

		$this->assertSame($expected, $found);
	}//end testEveryDeclarationIsComplete()

	/**
	 * Which validations a sample object violates.
	 *
	 * @param string $schema The schema the object belongs to.
	 * @param array<string,mixed> $object The object as OpenRegister hands it over (`id` is the UUID).
	 * @param array<int,string> $violated The validations expected to refuse it.
	 *
	 * @return void
	 */
	#[DataProvider('samples')]
	public function testConditionsNameTheViolation(string $schema, array $object, array $violated): void {
		$actual = [];
		foreach (self::schemas()[$schema][self::ANNOTATION] as $validation => $declaration) {
			if ($this->holds(node: $declaration['condition'], document: $object) === true) {
				$actual[] = $validation;
			}
		}

		$this->assertSame($violated, $actual);
	}//end testConditionsNameTheViolation()

	/**
	 * Sample objects and the validations they violate.
	 *
	 * @return array<string, array{0: string, 1: array<string,mixed>, 2: array<int,string>}>
	 */
	public static function samples(): array {
		return [
			'hybrid without baseRef' => ['Application', ['id' => 'a1', 'appType' => 'hybrid'], ['hybrid-requires-baseRef']],
			'hybrid with empty baseRef' => ['Application', ['id' => 'a1', 'appType' => 'hybrid', 'baseRef' => []], ['hybrid-requires-baseRef']],
			'hybrid with baseRef' => ['Application', ['id' => 'a1', 'appType' => 'hybrid', 'baseRef' => ['id' => 'opencatalogi']], []],
			'virtual without baseRef' => ['Application', ['id' => 'a1', 'appType' => 'virtual'], []],
			'legacy without appType' => ['Application', ['id' => 'a1'], []],
			'version promoting to itself' => ['ApplicationVersion', ['id' => 'v1', 'promotesTo' => 'v1'], ['promotesTo-no-self-loop']],
			'version promoting onward' => ['ApplicationVersion', ['id' => 'v1', 'promotesTo' => 'v2'], []],
			'version promoting nowhere' => ['ApplicationVersion', ['id' => 'v1', 'promotesTo' => null], []],
			'unsaved version promoting nowhere' => ['ApplicationVersion', ['id' => null, 'promotesTo' => null], []],
			'user scope without owner' => ['ApplicationVersion', ['id' => 'v1', 'scope' => 'user'], ['user-scope-requires-owner']],
			'user scope with owner' => ['ApplicationVersion', ['id' => 'v1', 'scope' => 'user', 'owner' => 'alice'], []],
			'admin scope without owner' => ['ApplicationVersion', ['id' => 'v1', 'scope' => 'admin'], []],
			'both broken' => ['ApplicationVersion', ['id' => 'v1', 'scope' => 'user', 'promotesTo' => 'v1'], ['promotesTo-no-self-loop', 'user-scope-requires-owner']],
		];
	}//end samples()

	/**
	 * Evaluate the JSONLogic subset the declarations use.
	 *
	 * @param mixed $node The condition node.
	 * @param array<string,mixed> $document The object.
	 *
	 * @return mixed
	 */
	private function holds(mixed $node, array $document): mixed {
		if (is_array($node) === false) {
			return $node;
		}

		$operator = (string)array_key_first($node);
		$args = $node[$operator];
		if ($operator === 'var') {
			$value = $document;
			foreach (explode('.', (string)$args) as $key) {
				$value = (is_array($value) === true ? ($value[$key] ?? null) : null);
			}

			return $value;
		}

		$values = array_map(fn (mixed $arg): mixed => $this->holds(node: $arg, document: $document), $args);

		return match ($operator) {
			'and' => in_array(false, array_map(static fn (mixed $v): bool => (bool)$v, $values), true) === false,
			'==' => $values[0] == $values[1],
			'!=' => $values[0] != $values[1],
		};
	}//end holds()
}//end class
