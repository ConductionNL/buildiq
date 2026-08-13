<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - CustomPageEditor — structured editor for `type: "custom"` pages (4.9).
  -
  - Manifest contract: "any shape the custom component expects" — in
  - practice `{ component, props?, ... }` where `component` is a key in the
  - consuming app's `customComponents` registry. This editor surfaces:
  -   - `component` — a free-text input (the canonical authoring affordance
  -     since the registry only exists at render time); when the live
  -     preview is active AND it exposes a registry list, the input also
  -     drives a `<datalist>` of known keys (graceful, free-text stays the
  -     fallback per task 4.9);
  -   - `props` — a raw-JSON textarea for the prop bag passed to the
  -     component, parsed on input (parse errors are surfaced inline and
  -     do NOT emit, so a half-typed object never blanks the page);
  -   - every other config key the manifest carries round-trips losslessly
  -     (a small read-only summary lists them).
  -->
<template>
	<div class="custom-page-editor">
		<h3 class="custom-page-editor__title">
			{{ t('openbuild', 'Custom page') }}
		</h3>

		<fieldset class="custom-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Component') }}</legend>
			<label class="custom-page-editor__group-row">
				{{ t('openbuild', 'customComponents registry key') }}
				<input
					type="text"
					:value="config.component || ''"
					list="custom-page-editor-component-suggestions"
					:placeholder="t('openbuild', 'e.g. LaunchPadboard')"
					:aria-invalid="isInvalid('component')"
					@input="update('component', $event.target.value)" />
				<datalist id="custom-page-editor-component-suggestions">
					<option v-for="key in registryKeys" :key="key" :value="key" />
				</datalist>
				<InlineFieldMark :error="markFor('component')" />
			</label>
			<p v-if="!registryKeys.length" class="custom-page-editor__hint">
				{{
					t(
						'openbuild',
						'The component must be registered in the consuming app’s customComponents map. The key is resolved at render time, so it is entered free-form here.',
					)
				}}
			</p>
		</fieldset>

		<fieldset class="custom-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Props (JSON, optional)') }}</legend>
			<textarea
				class="custom-page-editor__textarea"
				spellcheck="false"
				:value="propsDraft"
				:aria-label="t('openbuild', 'Props (JSON, optional)')"
				:aria-invalid="!!propsError || isInvalid('props')"
				@input="onPropsInput($event.target.value)" />
			<p v-if="propsError" class="custom-page-editor__error" role="alert">
				{{ propsError }}
			</p>
			<InlineFieldMark :error="markFor('props')" />
		</fieldset>

		<p v-if="otherKeys.length" class="custom-page-editor__other">
			{{ t('openbuild', 'Other config keys preserved on save:') }}
			{{ otherKeys.join(', ') }}
		</p>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'
import { useLivePreview } from '../../composables/useLivePreview.js'

export default {
	name: 'CustomPageEditor',
	components: { InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		pageType: {
			type: String,
			default: 'custom',
		},
		appSlug: {
			type: String,
			default: '',
		},
		parentRoute: {
			type: String,
			default: '',
		},
	},
	emits: ['update:config'],
	/**
	 * Observed behaviour of `setup` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-5
	 */
	setup() {
		// When chain spec #2's in-memory preview is wired AND it exposes a
		// registry list this surfaces it as <datalist> suggestions; until
		// then `available` is false and the free-text input stands alone.
		const preview = useLivePreview()
		return { preview }
	},
	data() {
		return {
			propsDraft: this.stringifyProps(this.config && this.config.props),
			propsError: '',
		}
	},
	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-5
		 */
		validatedConfigKeys() {
			return ['component', 'props']
		},
		/**
		 * Observed behaviour of `registryKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-5
		 */
		registryKeys() {
			const reg = this.preview && this.preview.componentRegistry
			if (Array.isArray(reg)) {
				return reg
			}
			if (reg && typeof reg === 'object') {
				return Object.keys(reg)
			}
			return []
		},
		/**
		 * Observed behaviour of `otherKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-5
		 */
		otherKeys() {
			return Object.keys(this.config || {}).filter(
				(k) => k !== 'component' && k !== 'props',
			)
		},
	},
	watch: {
		'config.props': {
			/**
			 * Re-seed the JSON textarea when `config.props` changes from the
			 * outside (page switch, manifest reload). The re-stringified text
			 * is compared against the current draft first, so the author's
			 * caret and half-typed JSON survive their own keystrokes.
			 *
			 * @param {?object} val - the new `config.props` prop bag; `null`/`undefined` empties the textarea.
			 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-5
			 */
			handler(val) {
				const fresh = this.stringifyProps(val)
				if (fresh !== this.propsDraft) {
					this.propsDraft = fresh
					this.propsError = ''
				}
			},
		},
	},
	methods: {
		/**
		 * Render a prop bag as the pretty-printed JSON shown in the textarea.
		 *
		 * @param {?object} value - the `config.props` prop bag; `null`/`undefined` (or anything JSON.stringify chokes on, e.g. a cyclic object) yields an empty textarea rather than throwing.
		 * @return {string} - the indented JSON, or `''`.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-5
		 */
		stringifyProps(value) {
			if (value === undefined || value === null) {
				return ''
			}
			try {
				return JSON.stringify(value, null, 2)
			} catch {
				return ''
			}
		},
		/**
		 * Write one key on the page's `config` block. Only the named key is
		 * touched, which is what lets the arbitrary extra keys a custom page
		 * carries (listed read-only under "Other config keys") survive.
		 *
		 * @param {string} key - the config key being written: `component` (the customComponents registry key) or `props`.
		 * @param {string|object|undefined} value - the new value: the registry key from the text input, or the parsed prop bag from the JSON textarea. `''`, `null` and `undefined` delete the key — that is how an emptied textarea removes `props`.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-5
		 */
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null || value === undefined) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
		/**
		 * Parse the props textarea on every keystroke. Invalid JSON only sets
		 * `propsError` and does NOT emit, so a half-typed object can never
		 * blank the live page; the last valid parse stays in the manifest.
		 *
		 * @param {string} value - the raw textarea contents. Blank/whitespace-only clears the error and removes `config.props` entirely.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-5
		 */
		onPropsInput(value) {
			this.propsDraft = value
			const trimmed = (value || '').trim()
			if (trimmed === '') {
				this.propsError = ''
				this.update('props', undefined)
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.propsError = ''
				this.update('props', parsed)
			} catch (e) {
				this.propsError = (e && e.message) || String(e)
			}
		},
	},
}
</script>

<style scoped>
.custom-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.custom-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.custom-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.custom-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.custom-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.custom-page-editor__group-row input {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.custom-page-editor__textarea {
	min-height: 160px;
	font-family: monospace;
	font-size: 13px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.custom-page-editor__error {
	margin: 0;
	color: var(--color-error);
	font-size: 12px;
}

.custom-page-editor__hint,
.custom-page-editor__other {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
