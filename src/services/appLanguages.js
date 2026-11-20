// SPDX-License-Identifier: EUPL-1.2
/**
 * appLanguages — the languages a built app is offered in, and its label
 * translations, kept in the version manifest's `i18n` block
 * (experience-multilingual-apps, design.md D1).
 *
 * Shape, as the nextcloud-vue manifest schema defines it:
 *
 *     i18n: {
 *       sourceLanguage: 'nl',          // the language labels are written in
 *       languages: ['en', 'de'],       // translated into; never the source
 *       labels: { en: { 'Vergunningen': 'Permits' } },
 *     }
 *
 * Every function here is pure and returns a NEW manifest, so the designer's
 * single write path (and with it undo/redo and the version's save) treats a
 * language or translation edit like any other edit.
 *
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
 */

/** A Nextcloud language code: `nl`, `de_DE`, `pt_BR`, `zh_Hans`. */
export const LANGUAGE_CODE = /^[a-z]{2,3}(_[A-Za-z0-9]{2,8})?$/

/**
 * Nextcloud's language codes (core `resources/locale` and the Transifex
 * catalogues a server ships), the list a maker picks from.
 */
export const NEXTCLOUD_LANGUAGES = Object.freeze([
	'af', 'ar', 'ast', 'az', 'be', 'bg', 'bn_BD', 'br', 'bs', 'ca', 'cs', 'cy_GB',
	'da', 'de', 'de_DE', 'el', 'en', 'en_GB', 'eo', 'es', 'es_AR', 'es_CL', 'es_MX',
	'et_EE', 'eu', 'fa', 'fi', 'fr', 'ga', 'gd', 'gl', 'he', 'hi_IN', 'hr', 'hu', 'hy',
	'ia', 'id', 'is', 'it', 'ja', 'ka', 'kab', 'km', 'kn', 'ko', 'lb', 'lo', 'lt_LT',
	'lv', 'mk', 'mn', 'ms_MY', 'nb', 'nl', 'nn_NO', 'oc', 'pl', 'pt_BR', 'pt_PT',
	'ro', 'ru', 'sc', 'si', 'sk', 'sl', 'sq', 'sr', 'sr@latin', 'sv', 'sw', 'ta',
	'th', 'tk', 'tr', 'ug', 'uk', 'ur_PK', 'uz', 'vi', 'zh_CN', 'zh_HK', 'zh_TW',
])

/**
 * Whether a value is a Nextcloud language code.
 *
 * @param {unknown} code - the value.
 * @return {boolean}
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
 */
export function isLanguageCode(code) {
	return typeof code === 'string'
		&& (LANGUAGE_CODE.test(code) || NEXTCLOUD_LANGUAGES.includes(code))
}

/**
 * A language's name, in the given display language when the browser knows it.
 *
 * @param {string} code - a Nextcloud language code.
 * @param {string} [displayLanguage] - the language to name it in.
 * @return {string} The name, or the code itself.
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
 */
export function languageName(code, displayLanguage = 'en') {
	try {
		const names = new Intl.DisplayNames(
			[String(displayLanguage || 'en').replace('_', '-')],
			{ type: 'language' },
		)
		const name = names.of(String(code).replace('@latin', '-Latn').replace('_', '-'))
		return name && name !== code ? name : code
	} catch {
		return code
	}
}

/**
 * The manifest's `i18n` block, normalised: always all three keys.
 *
 * @param {object} manifest - a version's manifest.
 * @param {string} [fallbackSource] - the written language when none is set.
 * @return {{sourceLanguage: string, languages: string[], labels: object}}
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
 */
export function appI18n(manifest, fallbackSource = '') {
	const block = (manifest && manifest.i18n && typeof manifest.i18n === 'object')
		? manifest.i18n
		: {}
	return {
		sourceLanguage: typeof block.sourceLanguage === 'string' && block.sourceLanguage
			? block.sourceLanguage
			: fallbackSource,
		languages: Array.isArray(block.languages)
			? block.languages.filter((code) => typeof code === 'string')
			: [],
		labels: block.labels && typeof block.labels === 'object' ? block.labels : {},
	}
}

/**
 * A manifest with its languages set. A language no longer offered loses its
 * translations; the written language is never also a translated one.
 *
 * Setting no extra languages and no written language drops the block, so an
 * app that never used the feature saves byte-identically to before it.
 *
 * @param {object} manifest - a version's manifest.
 * @param {{sourceLanguage: string, languages: string[]}} next - the languages.
 * @return {object} The new manifest.
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
 */
export function withLanguages(manifest, { sourceLanguage, languages }) {
	const current = appI18n(manifest)
	const source = typeof sourceLanguage === 'string' ? sourceLanguage : ''
	const offered = Array.from(new Set((languages || []).filter(
		(code) => isLanguageCode(code) && code !== source,
	)))
	const next = { ...(manifest || {}) }
	if (!source && offered.length === 0) {
		delete next.i18n
		return next
	}
	const labels = {}
	for (const code of offered) {
		if (current.labels[code] && typeof current.labels[code] === 'object') {
			labels[code] = { ...current.labels[code] }
		}
	}
	next.i18n = { sourceLanguage: source, languages: offered, labels }
	return next
}

/**
 * The languages that would lose translations if `languages` replaced the
 * current ones: what the maker confirms before removing.
 *
 * @param {object} manifest - a version's manifest.
 * @param {string[]} languages - the languages to keep.
 * @return {string[]} Removed languages that hold at least one translation.
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-sets-the-app-s-languages-req-bqml-001
 */
export function languagesLosingTranslations(manifest, languages) {
	const { labels } = appI18n(manifest)
	const kept = new Set(languages || [])
	return Object.keys(labels).filter(
		(code) => !kept.has(code) && Object.keys(labels[code] || {}).length > 0,
	)
}

/**
 * A manifest with one label's translation set; an empty value removes it, so
 * the label shows as missing again.
 *
 * @param {object} manifest - a version's manifest.
 * @param {string} language - an offered language.
 * @param {string} text - the label as written.
 * @param {string} value - its translation.
 * @return {object} The new manifest (the same one when nothing changes).
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-translates-every-label-in-one-view-req-bqml-002
 */
export function withTranslation(manifest, language, text, value) {
	const i18n = appI18n(manifest)
	if (!i18n.languages.includes(language) || typeof text !== 'string' || text === '') {
		return manifest
	}
	const table = { ...(i18n.labels[language] || {}) }
	const trimmed = typeof value === 'string' ? value.trim() : ''
	if (trimmed === '') {
		if (!(text in table)) {
			return manifest
		}
		delete table[text]
	} else {
		if (table[text] === trimmed) {
			return manifest
		}
		table[text] = trimmed
	}
	const labels = { ...i18n.labels }
	if (Object.keys(table).length) {
		labels[language] = table
	} else {
		delete labels[language]
	}
	return { ...manifest, i18n: { ...i18n, labels } }
}

/**
 * How many of the given texts each offered language has no translation for.
 *
 * @param {object} manifest - a version's manifest.
 * @param {string[]} texts - the translatable texts.
 * @return {Record<string, number>} Missing count by language.
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-translates-every-label-in-one-view-req-bqml-002
 */
export function missingCounts(manifest, texts) {
	const { languages, labels } = appI18n(manifest)
	const out = {}
	for (const code of languages) {
		const table = labels[code] || {}
		out[code] = texts.filter(
			(text) => typeof table[text] !== 'string' || table[text] === '',
		).length
	}
	return out
}
