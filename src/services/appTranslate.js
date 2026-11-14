// SPDX-License-Identifier: EUPL-1.2
/**
 * appTranslate — the host `translate` buildiq hands a built app's CnAppRoot
 * (experience-multilingual-apps, design.md D2, task T05).
 *
 * The renderer resolves a label from the manifest's own `i18n.labels` for the
 * user's language first and asks the host only when the app has no
 * translation. The host used to answer from buildiq's own catalogue, which
 * holds the builder's strings, not a maker's: a maker label that happened to
 * match one ("Delete", "Status") came back in buildiq's translation, so a page
 * in an app offered in English and Dutch could mix the maker's English with
 * buildiq's Dutch. The spec asks for the written text instead.
 *
 * So for an app that declares languages, a text that is one of its own labels
 * is answered as written; everything else (buildiq's strings) still goes to
 * buildiq's catalogue. An app without an `i18n` block keeps the old lookup.
 *
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-an-app-user-sees-the-app-in-their-language-req-bqml-003
 */
import { translatableTexts } from './manifestLabels.js'

/**
 * Fill `{name}` placeholders from `vars`, as Nextcloud's `t()` does.
 *
 * @param {string} text - the text.
 * @param {object} [vars] - values by placeholder name.
 * @return {string} The filled text.
 */
function fill(text, vars) {
	if (!vars || typeof vars !== 'object') {
		return text
	}
	return text.replace(/\{(\w+)\}/g, (match, name) => (
		Object.hasOwn(vars, name) ? String(vars[name]) : match
	))
}

/**
 * Build the host translate for a built app.
 *
 * The label set is cached so the per-label call stays cheap. It is rebuilt
 * when the manifest's menu or pages are replaced, and at most `maxAgeMs`
 * after it was built, since the in-app editor can change a label in place.
 *
 * @param {() => object} getManifest - the live manifest.
 * @param {(key: string, vars?: object) => string} translate - buildiq's own translate.
 * @param {object} [options] - cache options.
 * @param {number} [options.maxAgeMs] - how long a label set is reused.
 * @param {() => number} [options.now] - the clock.
 * @return {(key: string, vars?: object) => string} The host translate.
 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-an-app-user-sees-the-app-in-their-language-req-bqml-003
 */
export function createAppTranslate(getManifest, translate, { maxAgeMs = 500, now = Date.now } = {}) {
	let cache = { menu: null, pages: null, at: -Infinity, texts: new Set() }

	const ownLabels = (manifest) => {
		if (
			cache.menu !== manifest.menu
			|| cache.pages !== manifest.pages
			|| now() - cache.at > maxAgeMs
		) {
			cache = {
				menu: manifest.menu,
				pages: manifest.pages,
				at: now(),
				texts: translatableTexts(manifest),
			}
		}
		return cache.texts
	}

	return (key, vars) => {
		const manifest = getManifest()
		if (
			typeof key === 'string'
			&& manifest
			&& manifest.i18n
			&& typeof manifest.i18n === 'object'
			&& ownLabels(manifest).has(key)
		) {
			return fill(key, vars)
		}
		return translate(key, vars)
	}
}
