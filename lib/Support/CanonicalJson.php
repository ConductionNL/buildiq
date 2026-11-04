<?php

/**
 * CanonicalJson: a structure with its map keys sorted at every depth.
 *
 * Lists keep their order; maps are sorted by key. Serialising the result gives
 * the same bytes for the same content, whatever order it was stored in.
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
 * @spec exclude shared canonical ordering for serialised structures, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Sorts map keys recursively, keeping list order.
 *
 * @spec exclude shared canonical ordering for serialised structures, no behaviour of its own
 */
final class CanonicalJson {

	/**
	 * The value with every map's keys sorted, recursively; lists keep their order.
	 *
	 * @param mixed $value The value to canonicalise.
	 *
	 * @return mixed The canonicalised value.
	 *
	 * @spec exclude shared canonical ordering for serialised structures, no behaviour of its own
	 */
	public static function sortKeys(mixed $value): mixed {
		if (is_array($value) === false) {
			return $value;
		}

		if (array_is_list($value) === true) {
			return array_map(static fn (mixed $item): mixed => self::sortKeys(value: $item), $value);
		}

		ksort($value);
		$sorted = [];
		foreach ($value as $key => $item) {
			$sorted[$key] = self::sortKeys(value: $item);
		}

		return $sorted;
	}//end sortKeys()
}//end class
