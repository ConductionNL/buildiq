<?php

/**
 * PageRegisterBinding — point a template's pages at the new app's own register.
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
 * @spec openspec/specs/openbuild-template-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Buildiq\Support;

/**
 * The seeded templates write a page's register as `{{register}}`, or leave it
 * out on a page that names a schema; `{registerSlug}` is the older token. All
 * three mean "this app's own register". A clone that rewrote only the schema
 * left those pages with a schema and no register, so the app's index and
 * form pages listed and saved nothing. Pure rule: no state, no collaborators.
 *
 * @spec openspec/specs/openbuild-template-catalogue/spec.md
 */
final class PageRegisterBinding {

	/**
	 * The tokens a template writes for the app's own register.
	 *
	 * @var array<int, string>
	 */
	public const TOKENS = ['{registerSlug}', '{{register}}'];

	/**
	 * A page config pointed at the register: a token is replaced, and a page
	 * that names a schema but no register gets it. Anything else is returned
	 * as it is.
	 *
	 * @param array<string, mixed> $config       The page config.
	 * @param string               $registerSlug The app's own register slug.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/openbuild-template-catalogue/spec.md
	 */
	public static function forConfig(array $config, string $registerSlug): array {
		if ($registerSlug === '') {
			return $config;
		}

		$register = ($config['register'] ?? null);
		if (in_array($register, self::TOKENS, true) === true) {
			$config['register'] = $registerSlug;
			return $config;
		}

		$schema = ($config['schema'] ?? null);
		if (($register === null || $register === '') && is_string($schema) === true && $schema !== '') {
			$config['register'] = $registerSlug;
		}

		return $config;
	}//end forConfig()

	/**
	 * A manifest whose pages are pointed at the register (see forConfig()).
	 *
	 * @param array<string, mixed> $manifest     The manifest.
	 * @param string               $registerSlug The app's own register slug.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/openbuild-template-catalogue/spec.md
	 */
	public static function forManifest(array $manifest, string $registerSlug): array {
		if (is_array($manifest['pages'] ?? null) === false) {
			return $manifest;
		}

		foreach ($manifest['pages'] as $index => $page) {
			if (is_array($page) === true && is_array($page['config'] ?? null) === true) {
				$manifest['pages'][$index]['config'] = self::forConfig(config: $page['config'], registerSlug: $registerSlug);
			}
		}

		return $manifest;
	}//end forManifest()
}//end class
