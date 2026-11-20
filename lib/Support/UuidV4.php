<?php

/**
 * UuidV4 — mint an RFC 4122 version 4 UUID.
 *
 * Used where buildiq needs an object's UUID before the object exists: an export
 * job's id, and the hybrid example Application the seed command creates in one
 * write. Kept free of any service dependency so a command can build one.
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
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Mints random version 4 UUIDs.
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-37
 */
final class UuidV4 {
	/**
	 * Generate a UUIDv4.
	 *
	 * Fixes #104: an earlier implementation split the 32 hex digits into
	 * eight 4-char groups (`str_split($hex, 4)`) but `vsprintf()` only
	 * consumes the first FIVE of a format string's `%s` placeholders,
	 * silently discarding the remaining three groups — the returned string
	 * was five 4-char groups (20 hex digits) instead of the canonical
	 * 8-4-4-4-12 (32 hex digits). The version/variant nibble-setting was
	 * already correct; only the grouping was malformed.
	 *
	 * @return string UUIDv4 in canonical 8-4-4-4-12 form.
	 *
	 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-openbuild/tasks.md#task-37
	 */
	public function generate(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0F) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3F) | 0x80);
		$hex = bin2hex($data);

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr($hex, 0, 8),
			substr($hex, 8, 4),
			substr($hex, 12, 4),
			substr($hex, 16, 4),
			substr($hex, 20, 12)
		);
	}//end generate()
}//end class
