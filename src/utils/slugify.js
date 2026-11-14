/**
 * A kebab-case slug of a human label: lower-cased, trimmed, every run of
 * other characters collapsed to one hyphen, and no hyphen at either end.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * @param {string} label - the human label.
 * @return {string} the slug.
 */
export function slugify(label) {
	return String(label || '')
		.toLowerCase()
		.trim()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
}
