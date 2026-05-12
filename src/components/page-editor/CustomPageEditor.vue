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
			{{ t('openbuilt', 'Custom page') }}
		</h3>

		<fieldset class="custom-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Component') }}</legend>
			<label class="custom-page-editor__group-row">
				{{ t('openbuilt', 'customComponents registry key') }}
				<input
					type="text"
					:value="config.component || ''"
					list="custom-page-editor-component-suggestions"
					:placeholder="t('openbuilt', 'e.g. MyDashboard')"
					:aria-invalid="isInvalid('component')"
					@input="update('component', $event.target.value)">
				<datalist id="custom-page-editor-component-suggestions">
					<option v-for="key in registryKeys" :key="key" :value="key" />
				</datalist>
				<InlineFieldMark :error="markFor('component')" />
			</label>
			<p v-if="!registryKeys.length" class="custom-page-editor__hint">
				{{ t('openbuilt', 'The component must be registered in the consuming app’s customComponents map. The key is resolved at render time, so it is entered free-form here.') }}
			</p>
		</fieldset>

		<fieldset class="custom-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Props (JSON, optional)') }}</legend>
			<textarea
				class="custom-page-editor__textarea"
				spellcheck="false"
				:value="propsDraft"
				:aria-invalid="!!propsError || isInvalid('props')"
				@input="onPropsInput($event.target.value)" />
			<p v-if="propsError" class="custom-page-editor__error" role="alert">
				{{ propsError }}
			</p>
			<InlineFieldMark :error="markFor('props')" />
		</fieldset>

		<p v-if="otherKeys.length" class="custom-page-editor__other">
			{{ t('openbuilt', 'Other config keys preserved on save:') }} {{ otherKeys.join(', ') }}
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
		validatedConfigKeys() {
			return ['component', 'props']
		},
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
		otherKeys() {
			return Object.keys(this.config || {}).filter((k) => k !== 'component' && k !== 'props')
		},
	},
	watch: {
		'config.props': {
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
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null || value === undefined) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},
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
