<?php

/**
 * RepoDescriptor: descriptor fields shared by the app and template repo serialisers.
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
 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * Derives repo descriptor fields from a stored record and its manifest.
 *
 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
 */
final class RepoDescriptor {
	/**
	 * The keys the app-manifest schema allows on a `credentials[]` entry.
	 *
	 * @var array<string,true>
	 */
	private const DECLARATION_KEYS = ['provider' => true, 'reason' => true, 'scopes' => true];

	/**
	 * The descriptor category: the record's, else the manifest's, else `general`.
	 *
	 * @param array<string,mixed> $record The application or template object.
	 * @param array<string,mixed> $manifest Its manifest blob.
	 *
	 * @return string
	 *
	 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
	 */
	public static function category(array $record, array $manifest): string {
		foreach ([$record, $manifest] as $source) {
			$category = trim((string)($source['category'] ?? ''));
			if ($category !== '') {
				return $category;
			}
		}

		return 'general';
	}//end category()

	/**
	 * The descriptor `credentials[]`: one `{provider, reason, scopes}` entry per
	 * manifest credential that names a provider (REQ-GARF-009).
	 *
	 * @param array<string,mixed> $manifest The manifest blob.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
	 */
	public static function credentials(array $manifest): array {
		$raw = ($manifest['credentials'] ?? null);
		if (is_array($raw) === false) {
			return [];
		}

		$declared = array_filter(
			$raw,
			static fn (mixed $entry): bool => is_array($entry) === true && isset($entry['provider']) === true
		);

		return array_values(array_map(static fn (array $entry): array => self::credential(entry: $entry), $declared));
	}//end credentials()

	/**
	 * One descriptor credential entry, with its scopes as a list of strings.
	 *
	 * @param array<string,mixed> $entry A manifest credential that names a provider.
	 *
	 * @return array<string,mixed>
	 */
	private static function credential(array $entry): array {
		$scopes = [];
		if (is_array($entry['scopes'] ?? null) === true) {
			$scopes = array_values(array_map(static fn (mixed $scope): string => (string)$scope, $entry['scopes']));
		}

		return [
			'provider' => (string)$entry['provider'],
			'reason' => (string)($entry['reason'] ?? ''),
			'scopes' => $scopes,
		];
	}//end credential()

	/**
	 * The manifest as it is published to `manifest.json`.
	 *
	 * Each `credentials[]` entry keeps only the declaration keys the
	 * app-manifest schema allows (provider, reason, scopes), and entries that
	 * are not objects are dropped, so a secret written next to a declaration
	 * never leaves the instance. A manifest without `credentials` is returned
	 * as is.
	 *
	 * @param array<string,mixed> $manifest The manifest blob.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
	 */
	public static function publishableManifest(array $manifest): array {
		if (array_key_exists('credentials', $manifest) === false) {
			return $manifest;
		}

		$entries = array_filter((array)$manifest['credentials'], static fn (mixed $entry): bool => is_array($entry) === true);
		$manifest['credentials'] = array_values(
			array_map(static fn (array $entry): array => array_intersect_key($entry, self::DECLARATION_KEYS), $entries)
		);

		return $manifest;
	}//end publishableManifest()
}//end class
