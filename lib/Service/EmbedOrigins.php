<?php

/**
 * Buildiq EmbedOrigins.
 *
 * The sites allowed to frame an embedded app (apps-embed-in-other-sites
 * D1): an https scheme and a host, optionally a port, and nothing else. No
 * path, no wildcard, so the framing policy names exactly the sites a maker
 * listed.
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
 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Checks embed origins and reads an app's embedding setting.
 *
 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001
 */
class EmbedOrigins {

	/**
	 * An https origin: scheme, host labels and an optional port. The same
	 * pattern the register declares on `Application.embed.origins`.
	 *
	 * @var string
	 */
	public const PATTERN = '/^https:\/\/[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?'
		. '(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*(?::[0-9]{1,5})?$/';

	/**
	 * Why an origin is refused, or null when it is a valid origin.
	 *
	 * @param string $origin The origin as the maker wrote it.
	 *
	 * @return string|null The reason, in English.
	 *
	 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-a-maker-switches-embedding-on-and-lists-the-allowed-sites-req-bqem-001
	 */
	public function reason(string $origin): ?string {
		$origin = trim($origin);
		if (str_contains($origin, '*') === true) {
			return 'A wildcard is not allowed: list each site separately.';
		}

		if (str_starts_with($origin, 'https://') === false) {
			return 'A site must start with https://.';
		}

		$rest = substr($origin, 8);
		if (strpbrk($rest, '/?#') !== false) {
			return 'A site is a scheme and a host only, without a path.';
		}

		if (preg_match(self::PATTERN, $origin) !== 1) {
			return 'This is not a valid site address.';
		}

		return null;
	}//end reason()

	/**
	 * The origins an app may be framed by: none unless embedding is on, and
	 * then only the valid ones, each once.
	 *
	 * An origin that does not pass is left out rather than trusted, whatever
	 * reached storage.
	 *
	 * @param array<string,mixed>|null $application The Application object.
	 *
	 * @return array<int,string> The origins.
	 *
	 * @spec openspec/changes/apps-embed-in-other-sites/specs/app-embedding/spec.md#requirement-only-listed-sites-can-frame-the-app-req-bqem-002
	 */
	public function allowedFor(?array $application): array {
		$embed = ($application['embed'] ?? null);
		if (is_array($embed) === false || ($embed['enabled'] ?? false) !== true) {
			return [];
		}

		$origins = ($embed['origins'] ?? []);
		if (is_array($origins) === false) {
			return [];
		}

		$allowed = [];
		foreach ($origins as $origin) {
			if (is_string($origin) === true && $this->reason(origin: $origin) === null) {
				$allowed[] = trim($origin);
			}
		}

		return array_values(array_unique($allowed));
	}//end allowedFor()
}//end class
