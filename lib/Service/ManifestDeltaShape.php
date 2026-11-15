<?php

/**
 * Buildiq ManifestDeltaShape
 *
 * How ManifestDeltaMerger reads JSON shapes in PHP arrays, so the merge matches
 * the JS util: an object is an array with string keys or an empty array, a
 * list is a JSON array, an entry's key is typed so `1` and `"1"` stay apart,
 * and a path segment prints a key the way a JS template string does.
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
 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * JSON-shape helpers for the manifest delta merge.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
 */
final class ManifestDeltaShape {

	/**
	 * Whether a value is a JSON object: an array with string keys, or an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
	 */
	public function isObject(mixed $value): bool {
		return is_array($value) === true && ($value === [] || array_is_list($value) === false);
	}//end isObject()

	/**
	 * Whether a value is a JSON array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
	 */
	public function isList(mixed $value): bool {
		return is_array($value) === true && array_is_list($value) === true;
	}//end isList()

	/**
	 * Whether a value can identify an entry.
	 *
	 * @param mixed $value The value.
	 *
	 * @return boolean
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
	 */
	public function keyable(mixed $value): bool {
		return is_string($value) === true || is_int($value) === true || is_float($value) === true || is_bool($value) === true;
	}//end keyable()

	/**
	 * The lookup key of an identifying value, typed so `1` and `"1"` stay apart as in JS.
	 *
	 * @param mixed $value The identifying value.
	 *
	 * @return string The lookup key.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
	 */
	public function keyOf(mixed $value): string {
		return get_debug_type($value) . ':' . json_encode($value);
	}//end keyOf()

	/**
	 * The lookup key of a value, or null when it cannot identify an entry.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The lookup key.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
	 */
	public function lookupOf(mixed $value): ?string {
		if ($this->keyable(value: $value) === false) {
			return null;
		}

		return $this->keyOf(value: $value);
	}//end lookupOf()

	/**
	 * The identifying field of an entry, or null when the entry is not an object.
	 *
	 * @param mixed  $entry    The entry.
	 * @param string $keyField The field that identifies an entry.
	 *
	 * @return mixed The field's value.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
	 */
	public function fieldOf(mixed $entry, string $keyField): mixed {
		if ($this->isObject(value: $entry) === false) {
			return null;
		}

		return ($entry[$keyField] ?? null);
	}//end fieldOf()

	/**
	 * The path segment of a key, as JS would print it in a template string.
	 *
	 * @param mixed $value The key.
	 *
	 * @return string The segment.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
	 */
	public function pathPart(mixed $value): string {
		if ($value === null) {
			return 'undefined';
		}

		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '[object Object]';
	}//end pathPart()

	/**
	 * The path of a child key.
	 *
	 * @param string $path The parent path.
	 * @param string $key  The child key.
	 *
	 * @return string The child path.
	 *
	 * @spec openspec/changes/app-delta-override/tasks.md#task-2.1
	 */
	public function childPath(string $path, string $key): string {
		if ($path === '') {
			return $key;
		}

		return $path . '/' . $key;
	}//end childPath()
}//end class
