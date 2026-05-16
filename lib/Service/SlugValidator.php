<?php

/**
 * OpenBuilt SlugValidator
 *
 * Single source of truth for the slug pattern used across Application and
 * ApplicationVersion records. The pattern constant is mirrored on the
 * client side in `src/utils/slugPattern.js`.
 *
 * Per spec `openbuilt-app-creation-wizard` (REQ-OBWIZ-005, REQ-OBWIZ-006):
 *   - Slugs must match `^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$` (min 2 chars).
 *   - Leading underscores are explicitly rejected (reserved for openbuilt
 *     system use by the `?_version=` URL convention from spec E).
 *   - Within a single app's chain, two ApplicationVersion rows may NOT share
 *     a slug.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenBuilt\Service
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

namespace OCA\OpenBuilt\Service;

/**
 * Validates slugs for Application and ApplicationVersion objects.
 *
 * All methods return an empty array on success or a structured error
 * array on failure, letting callers decide how to surface the problem.
 */
class SlugValidator
{
    /**
     * Canonical slug pattern. Used by the wizard endpoint and mirrored
     * in `src/utils/slugPattern.js` for client-side enforcement.
     *
     * Requirements:
     *   - Must NOT start with `_` (system-reserved for `?_version=` routing).
     *   - First char: `[a-z0-9]`
     *   - Middle chars: `[a-z0-9-]` (zero or more)
     *   - Last char: `[a-z0-9]`
     *   - Minimum length: 2 characters (first + last non-hyphen chars).
     */
    public const SLUG_PATTERN = '^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$';

    /**
     * Validate a proposed Application slug.
     *
     * Application slugs follow the same pattern as version slugs but do
     * not carry the leading-underscore rejection message (they share the
     * same regex which already blocks underscores as first char).
     *
     * @param string $slug The proposed slug
     *
     * @return array<string,mixed> Empty array on success; error array on failure
     */
    public function validateAppSlug(string $slug): array
    {
        if ($slug === '') {
            return [
                'code'    => 'slug_empty',
                'message' => 'Application slug must not be empty.',
            ];
        }

        if (preg_match('/'.self::SLUG_PATTERN.'/', $slug) !== 1) {
            if (str_starts_with($slug, '_') === true) {
                return [
                    'code'    => 'slug_leading_underscore',
                    'message' => 'Version slugs cannot start with `_` (reserved for openbuilt system use).',
                ];
            }

            return [
                'code'    => 'slug_invalid',
                'message' => sprintf(
                    'Slug must match `%s` — lowercase letters, digits, and hyphens only.',
                    self::SLUG_PATTERN
                ),
            ];
        }

        return [];
    }//end validateAppSlug()

    /**
     * Validate a proposed ApplicationVersion slug.
     *
     * Same rules as {@see validateAppSlug()}, but with an explicit
     * leading-underscore check and a more specific user-facing message.
     *
     * @param string $slug The proposed version slug
     *
     * @return array<string,mixed> Empty array on success; error array on failure
     */
    public function validateVersionSlug(string $slug): array
    {
        if ($slug === '') {
            return [
                'code'    => 'slug_empty',
                'message' => 'Version slug must not be empty.',
            ];
        }

        if (str_starts_with($slug, '_') === true) {
            return [
                'code'    => 'slug_leading_underscore',
                'message' => 'Version slugs cannot start with `_` (reserved for openbuilt system use).',
            ];
        }

        if (preg_match('/'.self::SLUG_PATTERN.'/', $slug) !== 1) {
            return [
                'code'    => 'slug_invalid',
                'message' => sprintf(
                    'Slug must match `%s` — lowercase letters, digits, and hyphens only.',
                    self::SLUG_PATTERN
                ),
            ];
        }

        return [];
    }//end validateVersionSlug()

    /**
     * Validate that no two slugs in the same chain are identical.
     *
     * Duplicate-rejection is scoped to one app's chain only — different apps
     * can each have a `production` version (REQ-OBWIZ-006, Decision 6).
     *
     * @param array<int,string> $slugs Ordered list of version slugs for one chain
     *
     * @return array<string,mixed> Empty array on success; error array identifying the collision
     */
    public function validateChainSlugs(array $slugs): array
    {
        $seen  = [];
        $dupes = [];

        foreach ($slugs as $idx => $slug) {
            $lower = strtolower((string) $slug);
            if (isset($seen[$lower]) === true) {
                $dupes[$lower][] = $seen[$lower];
                $dupes[$lower][] = $idx;
            } else {
                $seen[$lower] = $idx;
            }
        }

        if ($dupes === []) {
            return [];
        }

        // Report the first duplicate found.
        $firstSlug = (string) array_key_first($dupes);
        $rows      = array_values(array_unique($dupes[$firstSlug]));
        sort($rows);

        return [
            'code' => 'duplicate_version_slug',
            'slug' => $firstSlug,
            'rows' => $rows,
        ];
    }//end validateChainSlugs()
}//end class
