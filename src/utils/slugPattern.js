/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Client-side slug utilities for the app-creation wizard.
 *
 * The SLUG_PATTERN string constant is the single source of truth, mirrored
 * server-side in `lib/Service/SlugValidator.php::SLUG_PATTERN`.
 *
 * Rules (spec REQ-OBWIZ-005, Decision 4 + 5):
 *   - Pattern: `^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$`
 *   - Leading underscores are rejected (reserved for `?_version=` system use).
 *   - Minimum 2 characters (first + last non-hyphen).
 *   - Lowercase letters, digits, and hyphens only.
 */

/**
 * The canonical slug pattern string.
 * Construct a regex via `new RegExp(SLUG_PATTERN)` rather than using a
 * regex literal here so PHP and JS always share the same string constant.
 *
 * @type {string}
 */
export const SLUG_PATTERN = '^(?!_)[a-z0-9][a-z0-9-]*[a-z0-9]$'

// Pre-built RegExp for internal use — avoids constructing a new object on
// every validation call.
const _slugRegex = new RegExp(SLUG_PATTERN)

/**
 * Derive a kebab-case slug from a free-text name.
 *
 * Steps:
 *   1. Lowercase.
 *   2. Normalize unicode (NFD) and strip combining marks (accents etc.).
 *   3. Replace spaces with `-`.
 *   4. Strip any character that is not `[a-z0-9-]`.
 *   5. Collapse consecutive hyphens (`--` → `-`).
 *   6. Trim leading and trailing hyphens.
 *
 * @param {string} input - The raw name string
 * @returns {string} Derived slug (may be empty when input contains only
 *   characters that are stripped by step 4)
 */
export function toKebabCase(input) {
	if (typeof input !== 'string') return ''

	return input
		.toLowerCase()
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '') // strip combining marks
		.replace(/\s+/g, '-')
		.replace(/[^a-z0-9-]/g, '')
		.replace(/-{2,}/g, '-')
		.replace(/^-+|-+$/g, '')
}

/**
 * Validate a slug string against the canonical pattern.
 *
 * @param {string} slug - The slug to validate (may be derived or user-typed)
 * @returns {{ valid: boolean, message?: string }} Validation result.
 *   When valid is false, message contains a user-facing error string.
 */
export function validateSlug(slug) {
	if (typeof slug !== 'string' || slug === '') {
		return { valid: false, message: 'Slug must not be empty.' }
	}

	if (slug.startsWith('_')) {
		return {
			valid: false,
			message: 'Version slugs cannot start with `_` (reserved for openbuilt system use).',
		}
	}

	if (!_slugRegex.test(slug)) {
		return {
			valid: false,
			message: `Slug must match \`${SLUG_PATTERN}\` — lowercase letters, digits, and hyphens only.`,
		}
	}

	return { valid: true }
}
