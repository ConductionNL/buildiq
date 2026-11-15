<?php

/**
 * ObjectRecord: reads an OpenRegister result entry as a plain array.
 *
 * OpenRegister hands back an entry as an array, a `JsonSerializable` entity, or
 * an object that exposes its data through `getObject()`. Callers differ in which
 * of those shapes they accept and in what they return for anything else, so each
 * reading is its own method.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Support
 * @package  OCA\Buildiq\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared coercion helper for OpenRegister result entries, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Coerces an OpenRegister result entry to an associative array.
 *
 * @spec exclude shared coercion helper for OpenRegister result entries, no behaviour of its own
 */
final class ObjectRecord {

	/**
	 * The entry as an array: itself when it is one, else its `jsonSerialize()`
	 * array, else null.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec exclude shared coercion helper for OpenRegister result entries, no behaviour of its own
	 */
	public static function arrayOrNull(mixed $object): ?array {
		if (is_array($object) === true) {
			return $object;
		}

		$serialised = null;
		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialised = $object->jsonSerialize();
		}

		if (is_array($serialised) === false) {
			return null;
		}

		return $serialised;
	}//end arrayOrNull()

	/**
	 * The entry as an array, or [] when it is neither an array nor serialises to one.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude shared coercion helper for OpenRegister result entries, no behaviour of its own
	 */
	public static function serialised(mixed $object): array {
		return self::arrayOrNull(object: $object) ?? [];
	}//end serialised()

	/**
	 * The entry as an array, falling back to the array its `getObject()` returns,
	 * or [] when neither applies.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude shared coercion helper for OpenRegister result entries, no behaviour of its own
	 */
	public static function toArray(mixed $object): array {
		$data = self::arrayOrNull(object: $object);
		if ($data !== null) {
			return $data;
		}

		$inner = null;
		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$inner = $object->getObject();
		}

		if (is_array($inner) === false) {
			return [];
		}

		return $inner;
	}//end toArray()

	/**
	 * The entry as an array, or the entry cast to an array when it neither is
	 * one nor serialises to one.
	 *
	 * @param mixed $object The OR object/result entry.
	 *
	 * @return array<array-key,mixed>
	 *
	 * @spec exclude shared coercion helper for OpenRegister result entries, no behaviour of its own
	 */
	public static function castArray(mixed $object): array {
		return self::arrayOrNull(object: $object) ?? (array)$object;
	}//end castArray()

	/**
	 * An `ObjectEntity`'s data: its `getObject()` array, else its
	 * `jsonSerialize()` array without the `@self` metadata block, else [].
	 *
	 * @param object $entity The `ObjectEntity` instance.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude shared coercion helper for OpenRegister result entries, no behaviour of its own
	 */
	public static function entityData(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			$object = $entity->getObject();
			if (is_array($object) === true) {
				return $object;
			}
		}

		$serialised = self::arrayOrNull(object: $entity);
		if ($serialised === null) {
			return [];
		}

		unset($serialised['@self']);
		return $serialised;
	}//end entityData()

	/**
	 * A normalised record's uuid: its `@self` id (or `@self` uuid), else its own
	 * `uuid`, else null when neither is a non-empty string.
	 *
	 * @param array<string,mixed> $record The normalised OR record.
	 *
	 * @return string|null
	 *
	 * @spec exclude shared coercion helper for OpenRegister result entries, no behaviour of its own
	 */
	public static function uuid(array $record): ?string {
		$self = ($record['@self'] ?? []);
		$candidates = [];
		if (is_array($self) === true) {
			$candidates[] = ($self['id'] ?? ($self['uuid'] ?? null));
		}

		$candidates[] = ($record['uuid'] ?? null);
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === true && $candidate !== '') {
				return $candidate;
			}
		}

		return null;
	}//end uuid()
}//end class
