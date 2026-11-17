<?php

/**
 * Buildiq RuleSetColumns
 *
 * What a rule set takes in and gives out, read from its decision table, and
 * the check another app's inputs pass before the table sees them: undeclared
 * inputs are dropped, a declared input that is missing is passed as null, and
 * a declared input of the wrong type is refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\Buildiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * A rule set's declared inputs and outputs, and the check of a caller's inputs.
 *
 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
 */
final class RuleSetColumns {

	/**
	 * Column types whose values must be numbers.
	 *
	 * @var array<int,string>
	 */
	private const NUMBER_TYPES = ['number', 'integer', 'double', 'long'];

	/**
	 * Column types whose values must be text (a date travels as text).
	 *
	 * @var array<int,string>
	 */
	private const TEXT_TYPES = ['string', 'date', 'datetime', 'date-time', 'time'];

	/**
	 * The inputs and outputs of a decision table, as the rule set's schema answers them.
	 *
	 * @param array<string,mixed> $table The rule set's first decision table, or [] when it has none.
	 *
	 * @return array{inputs:list<array{name:string,path:string,type:string}>,outputs:list<array{name:string,type:string}>}
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-a-rule-set-names-the-apps-that-may-call-it-req-bqrx-001
	 */
	public static function fromTable(array $table): array {
		$inputs = [];
		foreach ((array)($table['inputColumns'] ?? []) as $column) {
			if (is_array($column) === true && is_string($column['name'] ?? null) === true) {
				$inputs[] = [
					'name' => $column['name'],
					'path' => (string)($column['expressionPath'] ?? $column['name']),
					'type' => (string)($column['type'] ?? ''),
				];
			}
		}

		$outputs = [];
		foreach ((array)($table['outputColumns'] ?? []) as $column) {
			if (is_array($column) === true && is_string($column['name'] ?? null) === true) {
				$outputs[] = ['name' => $column['name'], 'type' => (string)($column['type'] ?? '')];
			}
		}

		return ['inputs' => $inputs, 'outputs' => $outputs];

	}//end fromTable()

	/**
	 * Another app's inputs, checked against the declared input columns.
	 *
	 * An input is kept when its name is the first segment of a column's path.
	 * With no declared columns (a condition-action rule set) nothing is
	 * declared to drop against, so the inputs pass unchanged.
	 *
	 * @param list<array{name:string,path:string,type:string}> $columns The declared input columns.
	 * @param array<string,mixed>                              $inputs  What the caller sent.
	 *
	 * @return array<string,mixed>|null The inputs to evaluate, or null when one has the wrong type.
	 *
	 * @spec openspec/changes/rules-evaluation-for-other-apps/specs/rules-evaluation-for-other-apps/spec.md#requirement-another-app-evaluates-a-rule-set-through-a-typed-command-req-bqrx-002
	 */
	public static function checkInputs(array $columns, array $inputs): ?array {
		if ($columns === []) {
			return $inputs;
		}

		$checked = [];
		foreach ($columns as $column) {
			$segments = self::segments(path: $column['path']);
			$root = $segments[0];
			if ($root === '') {
				continue;
			}

			if (array_key_exists($root, $inputs) === false) {
				$checked[$root] = ($checked[$root] ?? null);
				continue;
			}

			$checked[$root] = $inputs[$root];
			if (self::fits(value: self::valueAt(inputs: $inputs, segments: $segments), type: $column['type']) === false) {
				return null;
			}
		}

		return $checked;

	}//end checkInputs()

	/**
	 * The dot-separated segments of a column path.
	 *
	 * @param string $path The column's expression path.
	 *
	 * @return non-empty-list<string>
	 */
	private static function segments(string $path): array {
		return explode('.', trim($path));

	}//end segments()

	/**
	 * The value a path points at in the inputs, or null when it is not there.
	 *
	 * @param array<string,mixed> $inputs   The caller's inputs.
	 * @param list<string>        $segments The path segments.
	 *
	 * @return mixed
	 */
	private static function valueAt(array $inputs, array $segments): mixed {
		$value = $inputs;
		foreach ($segments as $segment) {
			if (is_array($value) === false || array_key_exists($segment, $value) === false) {
				return null;
			}

			$value = $value[$segment];
		}

		return $value;

	}//end valueAt()

	/**
	 * Whether a value fits a column type. Null always fits (REQ-BRE-021 decides
	 * what a missing value means); an unknown type accepts any value.
	 *
	 * @param mixed  $value The value.
	 * @param string $type  The column type.
	 *
	 * @return bool
	 */
	private static function fits(mixed $value, string $type): bool {
		if ($value === null) {
			return true;
		}

		$type = strtolower($type);
		if (in_array($type, self::NUMBER_TYPES, true) === true) {
			return is_int($value) === true || is_float($value) === true;
		}

		if ($type === 'boolean') {
			return is_bool($value);
		}

		if (in_array($type, self::TEXT_TYPES, true) === true) {
			return is_string($value);
		}

		return true;

	}//end fits()
}//end class
