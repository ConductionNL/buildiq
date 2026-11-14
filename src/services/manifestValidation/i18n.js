// SPDX-License-Identifier: EUPL-1.2
/**
 * i18n — buildiq's own checks on a manifest's `i18n` block
 * (experience-multilingual-apps, REQ-BQML-001, task T07).
 *
 * nextcloud-vue's schema carries the block's shape, and `validateManifest`
 * already refuses labels for an undeclared language and a source language
 * that is also translated into. What it leaves open is the language code
 * itself: any string of two characters passes. Buildiq offers Nextcloud's
 * languages only, and a code Nextcloud does not know would never be chosen by
 * any user, so its translations could never show. That is checked here.
 *
 * Returned errors are `<pointer>: <code>` strings, like the sibling modules,
 * so the designer's side panel lists them.
 *
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
 */
import { isLanguageCode } from '../appLanguages.js'

/**
 * Validate the language codes of a manifest's `i18n` block.
 *
 * @param {object} manifest - the manifest.
 * @return {string[]} Errors; empty when valid or when there is no block.
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
 */
export function validateI18n(manifest) {
	const block = manifest && manifest.i18n
	if (!block || typeof block !== 'object') {
		return []
	}
	const errors = []
	if (block.sourceLanguage !== undefined && !isLanguageCode(block.sourceLanguage)) {
		errors.push('/i18n/sourceLanguage: buildiq.i18n.error.unknown-language')
	}
	if (Array.isArray(block.languages)) {
		block.languages.forEach((code, index) => {
			if (!isLanguageCode(code)) {
				errors.push(`/i18n/languages/${index}: buildiq.i18n.error.unknown-language`)
			}
		})
	}
	if (block.labels && typeof block.labels === 'object') {
		const declared = new Set(Array.isArray(block.languages) ? block.languages : [])
		for (const code of Object.keys(block.labels)) {
			// An undeclared language is the library's error already; only an
			// unknown code among the declared ones would be missed.
			if (declared.has(code)) {
				continue
			}
			if (!isLanguageCode(code)) {
				errors.push(`/i18n/labels/${code}: buildiq.i18n.error.unknown-language`)
			}
		}
	}
	return errors
}
