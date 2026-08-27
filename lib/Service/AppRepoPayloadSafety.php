<?php

/**
 * Buildiq AppRepoPayloadSafety
 *
 * Pure, stateless validation and redaction primitives shared by every v2
 * channel collector in `AppRepoSerializer`: is a string safe to use as a path
 * component (slug or UUID), and does a payload carry a secret-bearing value
 * that must never reach a published repository.
 *
 * Split out of `AppRepoSerializer` purely for size — that class had grown
 * past its length threshold once the flows/agents channels were added, and
 * these four methods have no dependency on the collaborators the rest of the
 * class carries (no `RegisterMapper`, `ObjectServiceInterface`, logger — every
 * method here is a pure function of its arguments). No behaviour changed by
 * the move.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use
 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-credential-values-never-leave-the-instance
 */

declare(strict_types=1);

namespace OCA\Buildiq\Service;

/**
 * Path-safety and secret-redaction primitives for the v2 repo channels.
 *
 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md
 */
class AppRepoPayloadSafety {

	/**
	 * Object keys whose VALUE is stripped before export.
	 *
	 * Defence in depth rather than the primary control: credentials live in
	 * OpenRegister's credential broker and configs reference them by UUID
	 * (`credential`/`credentialRef`), so a well-formed config carries no secret
	 * to begin with. This exists so a future config that DOES inline one cannot
	 * reach a repository, and every strip is recorded rather than silent.
	 *
	 * @var array<int,string>
	 */
	private const SECRET_KEYS = [
		'password',
		'secret',
		'apikey',
		'api_key',
		'token',
		'accesstoken',
		'refreshtoken',
		'authorization',
		'connectionstring',
		'privatekey',
		'clientsecret',
	];

	/**
	 * Whether a value is safe to use as a path component.
	 *
	 * Validated BEFORE any concatenation, so a crafted slug never reaches a path.
	 *
	 * @param string $slug The candidate slug.
	 *
	 * @return bool True when safe.
	 *
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use
	 */
	public function isSafeSlug(string $slug): bool {
		return (preg_match('/^[a-z0-9][a-z0-9-]{0,62}[a-z0-9]$/', $slug) === 1);
	}//end isSafeSlug()

	/**
	 * Whether a value is a well-formed UUID, and therefore safe as a path component.
	 *
	 * A UUID is a stricter path component than a free-form slug — the character set
	 * admits no separator, traversal segment or extension — so validating here is
	 * both the identity check and the path guard.
	 *
	 * @param string $uuid The candidate UUID.
	 *
	 * @return bool True when well-formed.
	 *
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use
	 */
	public function isSafeUuid(string $uuid): bool {
		return (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid) === 1);
	}//end isSafeUuid()

	/**
	 * The filename a connector is exported under.
	 *
	 * Prefers a human-readable slug — a diff of `connectors/source/ted-source.json`
	 * is reviewable in a way `connectors/source/9f1c….json` is not — but falls back
	 * to the UUID, which every object has. Resolution is always by UUID; this only
	 * decides the name.
	 *
	 * @param array<string,mixed> $binding The declared binding (may be empty for a resolved dependency).
	 * @param array<string,mixed> $object The resolved connector payload.
	 * @param string $uuid The connector UUID.
	 *
	 * @return string The safe filename stem.
	 *
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-every-channel-path-is-validated-before-use
	 */
	public function connectorFileName(array $binding, array $object, string $uuid): string {
		foreach ([(string)($binding['slug'] ?? ''), (string)($object['slug'] ?? '')] as $candidate) {
			if ($this->isSafeSlug(slug: $candidate) === true) {
				return $candidate;
			}
		}

		return $uuid;
	}//end connectorFileName()

	/**
	 * Recursively strip secret-bearing VALUES from an exported payload.
	 *
	 * Defence in depth, not the primary control: credentials live in
	 * OpenRegister's credential broker and configs reference them by UUID, so a
	 * well-formed config carries no secret. Credential REFERENCES are preserved —
	 * stripping them would break the installed app for no security gain.
	 *
	 * @param array<string,mixed> $data The payload.
	 * @param int $stripped Running strip counter (by reference).
	 *
	 * @return array<string,mixed> The sanitised payload.
	 *
	 * @spec openspec/changes/app-repo-format-v2/specs/github-app-repo-format/spec.md#requirement-credential-values-never-leave-the-instance
	 */
	public function stripSecrets(array $data, int &$stripped): array {
		$out = [];
		foreach ($data as $key => $value) {
			if (is_array($value) === true) {
				$out[$key] = $this->stripSecrets(data: $value, stripped: $stripped);
				continue;
			}

			$normalised = strtolower(str_replace(['-', '_'], '', (string)$key));
			if (in_array($normalised, array_map(static fn ($k): string => str_replace('_', '', $k), self::SECRET_KEYS), true) === true
				&& is_string($value) === true && $value !== ''
			) {
				$out[$key] = '';
				$stripped++;
				continue;
			}

			// An inline `scheme://user:pass@host` credential in any string value.
			if (is_string($value) === true && preg_match('#://[^:/@\s]+:[^@\s]+@#', $value) === 1) {
				$out[$key] = preg_replace('#://[^:/@\s]+:[^@\s]+@#', '://', $value);
				$stripped++;
				continue;
			}

			$out[$key] = $value;
		}//end foreach

		return $out;
	}//end stripSecrets()
}//end class
