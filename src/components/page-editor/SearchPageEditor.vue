<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SearchPageEditor — structured editor for `type: "search"` pages
  - (REQ-PEC-005).
  -
  - Manifest contract verified against `CnSearchPage.vue`: `register` /
  - `schema` scope dropdowns (generic v2 carry-forward keys the
  - consumer-side `@search` wiring scopes its query against), text inputs
  - for `title` / `placeholder` / `searchLabel` / `idleLabel` /
  - `emptyLabel`, and a `facets[]` row-list builder — per row: `key`,
  - optional `label`, `multiple` checkbox, and a nested options row-list of
  - `{ value, label? }` pairs. Query execution itself is wired by the
  - consuming app via the page's `@search` contract — this editor never
  - claims otherwise and says so in a hint.
  -
  - Lossless round-trip: `update(key, value)` clones `config` and only
  - touches the one key (plus the `schema` partner-clear on a register
  - change, mirroring LogsPageEditor), so externally-authored keys this
  - editor doesn't surface survive every edit.
  -->
<template>
	<div class="search-page-editor">
		<h3 class="search-page-editor__title">
			{{ t('openbuild', 'Search page') }}
		</h3>

		<fieldset class="search-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Scope') }}</legend>
			<label class="search-page-editor__group-row">
				{{ t('openbuild', 'Register') }}
				<select
					:value="config.register || ''"
					:aria-invalid="isInvalid('register')"
					@change="updateRegister($event.target.value)">
					<option value="">
						{{ t('openbuild', '— select register —') }}
					</option>
					<option v-for="r in registers" :key="r.slug || r.id" :value="r.slug">
						{{ r.title || r.slug }}
					</option>
				</select>
				<InlineFieldMark :error="markFor('register')" />
			</label>
			<label class="search-page-editor__group-row">
				{{ t('openbuild', 'Schema') }}
				<select
					:value="config.schema || ''"
					:disabled="!config.register"
					:aria-invalid="isInvalid('schema')"
					@change="update('schema', $event.target.value)">
					<option value="">
						{{ t('openbuild', '— select schema —') }}
					</option>
					<option v-for="s in schemas" :key="s.slug || s.id" :value="s.slug">
						{{ s.title || s.slug }}
					</option>
				</select>
				<InlineFieldMark :error="markFor('schema')" />
			</label>
			<p class="search-page-editor__hint" role="note">
				{{ t('openbuild', 'Query execution is wired by the consuming app via the page\'s @search contract; a freshly built page renders the search UI without live results.') }}
			</p>
		</fieldset>

		<fieldset class="search-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Texts') }}</legend>
			<label class="search-page-editor__group-row">
				{{ t('openbuild', 'Title') }}
				<input
					type="text"
					:value="config.title || ''"
					:aria-invalid="isInvalid('title')"
					@input="update('title', $event.target.value)">
				<InlineFieldMark :error="markFor('title')" />
			</label>
			<label class="search-page-editor__group-row">
				{{ t('openbuild', 'Placeholder') }}
				<input
					type="text"
					:value="config.placeholder || ''"
					:aria-invalid="isInvalid('placeholder')"
					@input="update('placeholder', $event.target.value)">
				<InlineFieldMark :error="markFor('placeholder')" />
			</label>
			<label class="search-page-editor__group-row">
				{{ t('openbuild', 'Search button label') }}
				<input
					type="text"
					:value="config.searchLabel || ''"
					:aria-invalid="isInvalid('searchLabel')"
					@input="update('searchLabel', $event.target.value)">
				<InlineFieldMark :error="markFor('searchLabel')" />
			</label>
			<label class="search-page-editor__group-row">
				{{ t('openbuild', 'Idle label') }}
				<input
					type="text"
					:value="config.idleLabel || ''"
					:aria-invalid="isInvalid('idleLabel')"
					@input="update('idleLabel', $event.target.value)">
				<InlineFieldMark :error="markFor('idleLabel')" />
			</label>
			<label class="search-page-editor__group-row">
				{{ t('openbuild', 'Empty-results label') }}
				<input
					type="text"
					:value="config.emptyLabel || ''"
					:aria-invalid="isInvalid('emptyLabel')"
					@input="update('emptyLabel', $event.target.value)">
				<InlineFieldMark :error="markFor('emptyLabel')" />
			</label>
		</fieldset>

		<fieldset class="search-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Facets') }}</legend>
			<div v-for="(facet, index) in facets" :key="index" class="search-page-editor__facet">
				<div class="search-page-editor__row">
					<input
						type="text"
						:value="facet.key || ''"
						:placeholder="t('openbuild', 'Facet key')"
						:aria-label="t('openbuild', 'Facet key')"
						@input="updateFacetField(index, 'key', $event.target.value)">
					<input
						type="text"
						:value="facet.label || ''"
						:placeholder="t('openbuild', 'Label (optional)')"
						:aria-label="t('openbuild', 'Label (optional)')"
						@input="updateFacetField(index, 'label', $event.target.value)">
					<label class="search-page-editor__inline">
						<input
							type="checkbox"
							:checked="!!facet.multiple"
							@change="updateFacetField(index, 'multiple', $event.target.checked)">
						{{ t('openbuild', 'Multiple') }}
					</label>
					<button
						type="button"
						class="search-page-editor__row-remove"
						:title="t('openbuild', 'Remove facet')"
						@click="removeFacet(index)">
						✕
					</button>
				</div>
				<div class="search-page-editor__options">
					<div v-for="(option, optIndex) in facetOptions(facet)" :key="optIndex" class="search-page-editor__row">
						<input
							type="text"
							:value="option.value || ''"
							:placeholder="t('openbuild', 'Option value')"
							:aria-label="t('openbuild', 'Option value')"
							@input="updateFacetOptionField(index, optIndex, 'value', $event.target.value)">
						<input
							type="text"
							:value="option.label || ''"
							:placeholder="t('openbuild', 'Option label (optional)')"
							:aria-label="t('openbuild', 'Option label (optional)')"
							@input="updateFacetOptionField(index, optIndex, 'label', $event.target.value)">
						<button
							type="button"
							class="search-page-editor__row-remove"
							:title="t('openbuild', 'Remove option')"
							@click="removeFacetOption(index, optIndex)">
							✕
						</button>
					</div>
					<button type="button" class="search-page-editor__row-add" @click="addFacetOption(index)">
						+ {{ t('openbuild', 'Add option') }}
					</button>
				</div>
			</div>
			<button type="button" class="search-page-editor__row-add" @click="addFacet">
				+ {{ t('openbuild', 'Add facet') }}
			</button>
			<InlineFieldMark :error="markFor('facets')" />
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'SearchPageEditor',
	components: { InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		pageType: {
			type: String,
			default: 'search',
		},
		appSlug: {
			type: String,
			default: '',
		},
		dataRegisters: {
			type: Array,
			default: () => [],
		},
		parentRoute: {
			type: String,
			default: '',
		},
	},
	emits: ['update:config'],
	setup(props) {
		const picker = useRegisterPicker({ appSlug: props.appSlug, dataRegisters: props.dataRegisters })
		return { picker }
	},
	data() {
		return {
			registers: [],
			schemas: [],
		}
	},
	computed: {
		validatedConfigKeys() {
			return ['register', 'schema', 'title', 'placeholder', 'searchLabel', 'idleLabel', 'emptyLabel', 'facets']
		},
		facets() {
			return Array.isArray(this.config.facets) ? this.config.facets : []
		},
	},
	watch: {
		'config.register': {
			immediate: true,
			handler(val) {
				if (val) {
					this.fetchSchemas(val)
				} else {
					this.schemas = []
				}
			},
		},
	},
	async mounted() {
		await this.fetchRegisters()
	},
	methods: {
		/**
		 * Lossless top-level `config` key update: clone, delete-on-empty,
		 * touch only the edited key.
		 *
		 * @param {string} key - top-level config key.
		 * @param {*} value - new value.
		 */
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null || (Array.isArray(value) && value.length === 0)) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
		/**
		 * Register-picker change handler: writes `register` and resets the
		 * dependent `schema` dropdown (same partner-clear as LogsPageEditor).
		 *
		 * @param {string} value - register slug.
		 */
		updateRegister(value) {
			const next = { ...this.config }
			delete next.schema
			if (value === '' || value === null) {
				delete next.register
			} else {
				next.register = value
			}
			this.$emit('update:config', next)
		},
		/**
		 * The options list of one facet row (always an array).
		 *
		 * @param {object} facet - facet row.
		 * @return {Array} options list.
		 */
		facetOptions(facet) {
			return Array.isArray(facet && facet.options) ? facet.options : []
		},
		/**
		 * Append a blank facet row.
		 */
		addFacet() {
			this.update('facets', this.facets.concat([{ key: '', options: [] }]))
		},
		/**
		 * Remove a facet row by index.
		 *
		 * @param {number} index - row index.
		 */
		removeFacet(index) {
			const next = this.facets.slice()
			next.splice(index, 1)
			this.update('facets', next)
		},
		/**
		 * Update one field of one facet row.
		 *
		 * @param {number} index - facet row index.
		 * @param {string} key - facet field key.
		 * @param {*} value - new value.
		 */
		updateFacetField(index, key, value) {
			const next = this.facets.slice()
			const current = { ...next[index] }
			if (value === '' || value === null) {
				delete current[key]
			} else {
				current[key] = value
			}
			next[index] = current
			this.update('facets', next)
		},
		/**
		 * Append a blank `{ value }` option row to a facet.
		 *
		 * @param {number} facetIndex - facet row index.
		 */
		addFacetOption(facetIndex) {
			const next = this.facets.slice()
			const current = { ...next[facetIndex] }
			current.options = this.facetOptions(current).concat([{ value: '' }])
			next[facetIndex] = current
			this.update('facets', next)
		},
		/**
		 * Remove one option row from a facet.
		 *
		 * @param {number} facetIndex - facet row index.
		 * @param {number} optionIndex - option row index.
		 */
		removeFacetOption(facetIndex, optionIndex) {
			const next = this.facets.slice()
			const current = { ...next[facetIndex] }
			const options = this.facetOptions(current).slice()
			options.splice(optionIndex, 1)
			current.options = options
			next[facetIndex] = current
			this.update('facets', next)
		},
		/**
		 * Update one field of one option row within a facet.
		 *
		 * @param {number} facetIndex - facet row index.
		 * @param {number} optionIndex - option row index.
		 * @param {string} key - option field key.
		 * @param {string} value - new value.
		 */
		updateFacetOptionField(facetIndex, optionIndex, key, value) {
			const next = this.facets.slice()
			const current = { ...next[facetIndex] }
			const options = this.facetOptions(current).slice()
			const option = { ...options[optionIndex] }
			if (value === '' || value === null) {
				delete option[key]
			} else {
				option[key] = value
			}
			options[optionIndex] = option
			current.options = options
			next[facetIndex] = current
			this.update('facets', next)
		},
		/**
		 * Fetch the registers list for the picker dropdown.
		 */
		async fetchRegisters() {
			this.registers = await this.picker.fetchRegisters()
		},
		/**
		 * Fetch the schemas for a given register.
		 *
		 * @param {string} register - register slug.
		 */
		async fetchSchemas(register) {
			this.schemas = await this.picker.fetchSchemas(register)
		},
	},
}
</script>

<style scoped>
.search-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.search-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.search-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.search-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.search-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.search-page-editor__group-row input,
.search-page-editor__group-row select {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.search-page-editor__facet {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 6px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
}

.search-page-editor__row {
	display: flex;
	gap: 6px;
	align-items: center;
}

.search-page-editor__row input {
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.search-page-editor__options {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-left: 16px;
}

.search-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	font-size: 13px;
	white-space: nowrap;
}

.search-page-editor__row-remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.search-page-editor__row-add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.search-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
