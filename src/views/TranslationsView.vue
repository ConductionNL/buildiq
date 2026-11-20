<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - TranslationsView — every label of the version being designed, one row per
  - distinct text, one column per language the app is translated into
  - (experience-multilingual-apps, REQ-BQML-002, design.md D3).
  -
  - Reached from the page designer's toolbar. A controlled component: each
  - committed edit emits the whole replacement manifest, which the designer
  - writes through its single save path, so undo/redo and the version's save
  - treat a translation like any other designer edit.
  -->
<template>
	<section class="translations-view" :aria-label="t('buildiq', 'Translations')">
		<header class="translations-view__head">
			<h3 class="translations-view__title">
				{{ t('buildiq', 'Translations') }}
			</h3>
			<button
				type="button"
				class="translations-view__close"
				data-test="translations-close"
				@click="$emit('close')">
				{{ t('buildiq', 'Close') }}
			</button>
		</header>

		<p v-if="!languages.length" class="translations-view__hint" data-test="translations-no-languages">
			{{ t('buildiq', 'This app is offered in one language only. Add languages in the app settings to translate its labels.') }}
		</p>

		<template v-else>
			<p class="translations-view__hint">
				{{ t('buildiq', 'Labels are written in {language}. A label without a translation is shown as written.', { language: languageLabel(sourceLanguage) }) }}
			</p>
			<ul class="translations-view__counts" data-test="translations-missing-counts">
				<li v-for="code in languages" :key="code">
					{{ t('buildiq', '{language}: {count} missing', { language: languageLabel(code), count: missing[code] }) }}
				</li>
			</ul>

			<div class="translations-view__filters">
				<label class="translations-view__filter">
					<input
						v-model="missingOnly"
						type="checkbox"
						data-test="translations-missing-only">
					{{ t('buildiq', 'Missing only') }}
				</label>
				<label class="translations-view__filter">
					{{ t('buildiq', 'Page') }}
					<select v-model="pageFilter" data-test="translations-page-filter">
						<option value="">
							{{ t('buildiq', 'All pages and the menu') }}
						</option>
						<option
							v-for="page in pageOptions"
							:key="page.value"
							:value="page.value">
							{{ page.label }}
						</option>
					</select>
				</label>
			</div>

			<p v-if="!rows.length" class="translations-view__hint" data-test="translations-empty">
				{{ missingOnly ? t('buildiq', 'Every label is translated.') : t('buildiq', 'This version has no labels yet.') }}
			</p>

			<table v-else class="translations-view__table">
				<thead>
					<tr>
						<th scope="col">
							{{ languageLabel(sourceLanguage) }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Used at') }}
						</th>
						<th v-for="code in languages" :key="code" scope="col">
							{{ languageLabel(code) }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="row in rows"
						:key="(row.fromSchema ? 'schema:' : 'app:') + row.text"
						data-test="translations-row">
						<th scope="row" class="translations-view__text">
							{{ row.text }}
						</th>
						<td class="translations-view__places">
							{{ placesLabel(row) }}
						</td>
						<template v-if="row.fromSchema">
							<td
								:colspan="languages.length"
								class="translations-view__schema"
								data-test="translations-from-schema">
								{{ t('buildiq', 'From the schema: not translatable here') }}
							</td>
						</template>
						<template v-else>
							<td v-for="code in languages" :key="code">
								<input
									type="text"
									class="translations-view__input"
									:lang="code"
									:value="translationOf(code, row.text)"
									:aria-label="t('buildiq', '{language} translation of {text}', { language: languageLabel(code), text: row.text })"
									:data-test="`translation-${code}`"
									@change="onEdit(code, row.text, $event.target.value)">
							</td>
						</template>
					</tr>
				</tbody>
			</table>
		</template>
	</section>
</template>

<script>
import { getLanguage, translate as t } from '@nextcloud/l10n'
import {
	appI18n,
	languageName,
	missingCounts,
	withTranslation,
} from '../services/appLanguages.js'
import { collectManifestLabels } from '../services/manifestLabels.js'

export default {
	name: 'TranslationsView',

	props: {
		/** The version's manifest under edit. */
		manifest: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:manifest', 'close'],

	data() {
		return {
			missingOnly: false,
			// '' = everything; else `/pages/<index>` or `/menu`.
			pageFilter: '',
		}
	},

	computed: {
		/**
		 * The manifest's normalised `i18n` block.
		 *
		 * @return {{sourceLanguage: string, languages: string[], labels: object}}
		 */
		i18n() {
			return appI18n(this.manifest)
		},

		/**
		 * The written language.
		 *
		 * @return {string} A language code, '' when unset.
		 */
		sourceLanguage() {
			return this.i18n.sourceLanguage
		},

		/**
		 * The languages labels are translated into.
		 *
		 * @return {string[]} Language codes.
		 */
		languages() {
			return this.i18n.languages
		},

		/**
		 * Every label of the version.
		 *
		 * @return {Array<{text: string, places: string[], fromSchema: boolean}>}
		 */
		labels() {
			return collectManifestLabels(this.manifest)
		},

		/**
		 * Missing translations per language, over the app's own labels.
		 *
		 * @return {Record<string, number>}
		 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-translates-every-label-in-one-view-req-bqml-002
		 */
		missing() {
			const texts = this.labels.filter((l) => !l.fromSchema).map((l) => l.text)
			return missingCounts(this.manifest, texts)
		},

		/**
		 * The pages to filter on, plus the menu.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		pageOptions() {
			const pages = Array.isArray(this.manifest && this.manifest.pages) ? this.manifest.pages : []
			return [{ value: '/menu', label: t('buildiq', 'Menu') }].concat(
				pages.map((page, index) => ({
					value: `/pages/${index}`,
					label: (page && (page.title || page.id)) || t('buildiq', 'Page {number}', { number: index + 1 }),
				})),
			)
		},

		/**
		 * The rows shown: filtered to a page, and to labels missing in any
		 * language when "Missing only" is on (schema labels then drop out,
		 * since they cannot be translated here).
		 *
		 * @return {Array<{text: string, places: string[], fromSchema: boolean}>}
		 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-translates-every-label-in-one-view-req-bqml-002
		 */
		rows() {
			return this.labels.filter((label) => {
				if (this.pageFilter && !label.places.some(
					(place) => place === this.pageFilter || place.startsWith(this.pageFilter + '/'),
				)) {
					return false
				}
				if (this.missingOnly) {
					return !label.fromSchema
						&& this.languages.some((code) => this.translationOf(code, label.text) === '')
				}
				return true
			})
		},
	},

	methods: {
		t,

		/**
		 * The stored translation of a text, '' when there is none.
		 *
		 * @param {string} code - a language.
		 * @param {string} text - the label as written.
		 * @return {string}
		 */
		translationOf(code, text) {
			const table = this.i18n.labels[code] || {}
			return typeof table[text] === 'string' ? table[text] : ''
		},

		/**
		 * Commit one translation as a whole replacement manifest.
		 *
		 * @param {string} code - the language.
		 * @param {string} text - the label as written.
		 * @param {string} value - the translation; '' removes it.
		 * @return {void}
		 * @spec openspec/changes/experience-multilingual-apps/specs/app-interface-languages/spec.md#requirement-a-maker-translates-every-label-in-one-view-req-bqml-002
		 */
		onEdit(code, text, value) {
			const next = withTranslation(this.manifest, code, text, value)
			if (next !== this.manifest) {
				this.$emit('update:manifest', next)
			}
		},

		/**
		 * A language's name in the maker's language, with its code.
		 *
		 * @param {string} code - a language code.
		 * @return {string}
		 */
		languageLabel(code) {
			if (!code) {
				return t('buildiq', 'the written language')
			}
			const name = languageName(code, getLanguage())
			return name === code ? code : `${name} (${code})`
		},

		/**
		 * Where a label is used, by page title or the menu.
		 *
		 * @param {{places: string[]}} row - a label row.
		 * @return {string}
		 */
		placesLabel(row) {
			const names = []
			for (const place of row.places) {
				const match = /^\/pages\/(\d+)/.exec(place)
				const option = match
					? this.pageOptions.find((o) => o.value === `/pages/${match[1]}`)
					: this.pageOptions[0]
				if (option && !names.includes(option.label)) {
					names.push(option.label)
				}
			}
			return names.join(', ')
		},
	},
}
</script>

<style scoped>
.translations-view {
	margin: 8px 0;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	color: var(--color-main-text);
	max-height: 60vh;
	overflow: auto;
}

.translations-view__head {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.translations-view__title {
	margin: 0;
}

.translations-view__hint {
	color: var(--color-text-maxcontrast);
	margin: 8px 0;
}

.translations-view__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 8px 0;
	padding: 0;
	list-style: none;
	font-weight: bold;
}

.translations-view__filters {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 8px 0;
}

.translations-view__filter {
	display: flex;
	align-items: center;
	gap: 6px;
}

.translations-view__table {
	width: 100%;
	border-collapse: collapse;
}

.translations-view__table th,
.translations-view__table td {
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: start;
	vertical-align: middle;
}

.translations-view__places,
.translations-view__schema {
	color: var(--color-text-maxcontrast);
}

.translations-view__input {
	width: 100%;
	min-width: 160px;
}
</style>
