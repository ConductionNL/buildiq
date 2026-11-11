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

use OCA\Buildiq\Service\GitHub\TokenScrubber;

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
	 * Key names that hold a secret anywhere in a manifest, lower case without `-` and `_`.
	 *
	 * @var array<string,true>
	 */
	private const SECRET_KEYS = [
		'password' => true,
		'passwd' => true,
		'secret' => true,
		'clientsecret' => true,
		'sharedsecret' => true,
		'webhooksecret' => true,
		'signingsecret' => true,
		'apikey' => true,
		'apitoken' => true,
		'token' => true,
		'authtoken' => true,
		'accesstoken' => true,
		'refreshtoken' => true,
		'bearertoken' => true,
		'githubtoken' => true,
		'privatekey' => true,
		'authorization' => true,
	];

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
			static fn (mixed $entry): bool => is_array($entry) === true && is_scalar($entry['provider'] ?? null) === true
		);

		return array_values(array_map(static fn (array $entry): array => self::credential(entry: $entry), $declared));
	}//end credentials()

	/**
	 * One descriptor credential entry, with its scopes as a list of strings.
	 *
	 * Only scalar values are read: scopes come from a list, and a reason that
	 * is not scalar reads as empty, so a nested value never reaches the descriptor.
	 *
	 * @param array<string,mixed> $entry A manifest credential that names a scalar provider.
	 *
	 * @return array<string,mixed>
	 */
	private static function credential(array $entry): array {
		$reason = ($entry['reason'] ?? '');
		if (is_scalar($reason) === false) {
			$reason = '';
		}

		return [
			'provider' => (string)$entry['provider'],
			'reason' => (string)$reason,
			'scopes' => array_map(static fn (mixed $scope): string => (string)$scope, self::scalarScopes(entry: $entry)),
		];
	}//end credential()

	/**
	 * The scalar items of an entry's `scopes`, when it is a list; [] otherwise.
	 *
	 * @param array<string,mixed> $entry A manifest credential.
	 *
	 * @return array<int,mixed>
	 */
	private static function scalarScopes(array $entry): array {
		$scopes = ($entry['scopes'] ?? null);
		if (is_array($scopes) === false || array_is_list($scopes) === false) {
			return [];
		}

		return array_values(array_filter($scopes, static fn (mixed $scope): bool => is_scalar($scope)));
	}//end scalarScopes()

	/**
	 * A `credentials[]` entry with only its schema keys, each only in its schema
	 * type: provider and reason as strings, scopes as a list of strings.
	 *
	 * @param array<string,mixed> $entry A manifest credential.
	 *
	 * @return array<string,mixed>
	 */
	private static function declaration(array $entry): array {
		$declaration = [];
		foreach (array_intersect_key($entry, self::DECLARATION_KEYS) as $key => $value) {
			if ($key === 'scopes') {
				$value = array_values(array_filter(self::scalarScopes(entry: $entry), static fn (mixed $scope): bool => is_string($scope)));
			}

			if (is_string($value) === true || $key === 'scopes') {
				$declaration[$key] = $value;
			}
		}

		return $declaration;
	}//end declaration()

	/**
	 * The manifest as it is published to `manifest.json`.
	 *
	 * Each `credentials[]` entry keeps only the declaration keys the
	 * app-manifest schema allows, each only in its schema type (provider and
	 * reason as strings, scopes as a list of strings), and entries that are
	 * not objects are dropped, so a secret written next to a declaration
	 * never leaves the instance. Anywhere in the manifest, keys named like a
	 * secret (`password`, `token`, `apiKey`, ...) are dropped and token-shaped
	 * strings and private keys are redacted.
	 *
	 * @param array<string,mixed> $manifest The manifest blob.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec exclude shared descriptor field derivation for the repo serialisers, no behaviour of its own
	 */
	public static function publishableManifest(array $manifest): array {
		if (array_key_exists('credentials', $manifest) === true) {
			// An entry that keeps no declaration key is dropped rather than published as `{}`.
			$entries = array_filter((array)$manifest['credentials'], static fn (mixed $entry): bool => is_array($entry) === true);
			$declarations = array_map(static fn (array $entry): array => self::declaration(entry: $entry), $entries);
			$manifest['credentials'] = array_values(array_filter($declarations, static fn (array $declaration): bool => $declaration !== []));
		}

		return self::withoutSecrets(node: $manifest);
	}//end publishableManifest()

	/**
	 * A manifest node without secret-named keys, with token-shaped strings and private keys redacted.
	 *
	 * @param array<int|string,mixed> $node A manifest object or list.
	 *
	 * @return array<int|string,mixed>
	 */
	private static function withoutSecrets(array $node): array {
		$clean = [];
		foreach ($node as $key => $value) {
			$name = strtolower(str_replace(['-', '_'], '', (string)$key));
			if (is_string($key) === true && isset(self::SECRET_KEYS[$name]) === true) {
				continue;
			}

			$clean[$key] = self::publishableValue(value: $value);
		}

		return $clean;
	}//end withoutSecrets()

	/**
	 * One manifest value as it is published.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private static function publishableValue(mixed $value): mixed {
		if (is_array($value) === true) {
			return self::withoutSecrets(node: $value);
		}

		if (is_string($value) === false) {
			return $value;
		}

		if (str_contains($value, 'PRIVATE KEY-----') === true) {
			return '[redacted]';
		}

		return TokenScrubber::scrub(message: $value);
	}//end publishableValue()
}//end class
