<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SettingsSectionBuilder — authors an array of settings `Section`s
  - (the `sidebarSection`-shaped sub-objects CnSettingsPage renders).
  - Used by SettingsPageEditor for both the top-level `sections[]` and the
  - per-tab `sections[]` (task 4.5).
  -
  - Each section carries a `title` (i18n key) and EXACTLY ONE body:
  -   - `fields` — a flat formField list (reuses FormFieldBuilder);
  -   - `component` (+ optional `props`) — a customComponents key mounted
  -     as the section body;
  -   - `widgets` — a list of `{ type, props?, componentName? }`. Built-in
  -     widget `type`s: `version-info`, `register-mapping`, and `component`
  -     (which then needs `componentName`).
  - A radio per section picks the body kind; switching kinds drops the
  - inactive body keys. Section objects keep any extra keys they came in
  - with (id, icon, order, …) so the round-trip stays lossless.
  -->
<template>
	<div class="settings-section-builder">
		<div v-for="(section, index) in localSections" :key="index" class="settings-section-builder__section">
			<div class="settings-section-builder__head">
				<input
					:value="section.title || ''"
					type="text"
					class="settings-section-builder__field"
					:placeholder="t('openbuilt', 'Section title (i18n key)')"
					@input="updateField(index, 'title', $event.target.value)">
				<input
					:value="section.id || ''"
					type="text"
					class="settings-section-builder__field settings-section-builder__field--narrow"
					:placeholder="t('openbuilt', 'id (optional)')"
					@input="updateField(index, 'id', $event.target.value)">
				<button
					type="button"
					class="settings-section-builder__remove"
					:title="t('openbuilt', 'Remove section')"
					@click="removeSection(index)">
					✕
				</button>
			</div>

			<div class="settings-section-builder__kind">
				<label class="settings-section-builder__inline">
					<input
						type="radio"
						:checked="bodyKind(section) === 'fields'"
						value="fields"
						@change="setBodyKind(index, 'fields')">
					{{ t('openbuilt', 'Fields') }}
				</label>
				<label class="settings-section-builder__inline">
					<input
						type="radio"
						:checked="bodyKind(section) === 'component'"
						value="component"
						@change="setBodyKind(index, 'component')">
					{{ t('openbuilt', 'Component') }}
				</label>
				<label class="settings-section-builder__inline">
					<input
						type="radio"
						:checked="bodyKind(section) === 'widgets'"
						value="widgets"
						@change="setBodyKind(index, 'widgets')">
					{{ t('openbuilt', 'Widgets') }}
				</label>
			</div>

			<div v-if="bodyKind(section) === 'fields'" class="settings-section-builder__body">
				<FormFieldBuilder
					:model-value="section.fields || []"
					@update:modelValue="updateField(index, 'fields', $event)" />
			</div>
			<div v-else-if="bodyKind(section) === 'component'" class="settings-section-builder__body">
				<label class="settings-section-builder__row">
					{{ t('openbuilt', 'customComponents key') }}
					<input
						:value="section.component || ''"
						type="text"
						:placeholder="t('openbuilt', 'e.g. AppSettingsPanel')"
						@input="updateField(index, 'component', $event.target.value)">
				</label>
				<label class="settings-section-builder__row">
					{{ t('openbuilt', 'props (JSON, optional)') }}
					<textarea
						class="settings-section-builder__textarea"
						spellcheck="false"
						:value="propsDraft[index] !== undefined ? propsDraft[index] : stringifyProps(section.props)"
						@input="onPropsInput(index, $event.target.value)" />
				</label>
				<p v-if="propsError[index]" class="settings-section-builder__error" role="alert">
					{{ propsError[index] }}
				</p>
			</div>
			<div v-else class="settings-section-builder__body">
				<div v-for="(widget, wIndex) in (section.widgets || [])" :key="wIndex" class="settings-section-builder__widget">
					<select
						:value="widget.type || 'version-info'"
						class="settings-section-builder__field settings-section-builder__field--narrow"
						@change="updateWidget(index, wIndex, 'type', $event.target.value)">
						<option v-for="wt in WIDGET_TYPES" :key="wt" :value="wt">
							{{ wt }}
						</option>
					</select>
					<input
						v-if="widget.type === 'component'"
						:value="widget.componentName || ''"
						type="text"
						class="settings-section-builder__field"
						:placeholder="t('openbuilt', 'componentName (customComponents key)')"
						@input="updateWidget(index, wIndex, 'componentName', $event.target.value)">
					<input
						:value="stringifyProps(widget.props)"
						type="text"
						class="settings-section-builder__field"
						:placeholder="t('openbuilt', 'props (JSON, optional)')"
						@input="onWidgetPropsInput(index, wIndex, $event.target.value)">
					<button
						type="button"
						class="settings-section-builder__remove"
						:title="t('openbuilt', 'Remove widget')"
						@click="removeWidget(index, wIndex)">
						✕
					</button>
				</div>
				<button type="button" class="settings-section-builder__add" @click="addWidget(index)">
					+ {{ t('openbuilt', 'Add widget') }}
				</button>
			</div>
		</div>
		<button type="button" class="settings-section-builder__add" @click="addSection">
			+ {{ t('openbuilt', 'Add section') }}
		</button>
	</div>
</template>

<script>
import FormFieldBuilder from './FormFieldBuilder.vue'

const WIDGET_TYPES = ['version-info', 'register-mapping', 'component']
const BODY_KEYS = ['fields', 'component', 'props', 'widgets']

export default {
	name: 'SettingsSectionBuilder',
	components: { FormFieldBuilder },
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:modelValue'],
	data() {
		return {
			WIDGET_TYPES,
			// Keyed by section index — the in-progress raw-JSON text for a
			// section's `props` textarea (so a half-typed object doesn't
			// blank the manifest mid-keystroke).
			propsDraft: {},
			propsError: {},
		}
	},
	computed: {
		localSections() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
	methods: {
		bodyKind(section) {
			if (section && Array.isArray(section.widgets)) {
				return 'widgets'
			}
			if (section && typeof section.component === 'string') {
				return 'component'
			}
			return 'fields'
		},
		stringifyProps(value) {
			if (value === undefined || value === null) {
				return ''
			}
			try {
				return JSON.stringify(value)
			} catch {
				return ''
			}
		},
		emit(sections) {
			this.$emit('update:modelValue', sections)
		},
		updateField(index, key, value) {
			const next = this.localSections.slice()
			const current = { ...(next[index] || {}) }
			if (value === '' || value === null || value === undefined
				|| (Array.isArray(value) && value.length === 0)) {
				delete current[key]
			} else {
				current[key] = value
			}
			next[index] = current
			this.emit(next)
		},
		setBodyKind(index, kind) {
			const next = this.localSections.slice()
			const current = { ...(next[index] || {}) }
			for (const k of BODY_KEYS) {
				delete current[k]
			}
			if (kind === 'fields') {
				current.fields = []
			} else if (kind === 'component') {
				current.component = ''
			} else {
				current.widgets = []
			}
			next[index] = current
			this.$set(this.propsDraft, index, undefined)
			this.$set(this.propsError, index, '')
			this.emit(next)
		},
		onPropsInput(index, value) {
			this.$set(this.propsDraft, index, value)
			const trimmed = (value || '').trim()
			if (trimmed === '') {
				this.$set(this.propsError, index, '')
				this.updateField(index, 'props', undefined)
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.$set(this.propsError, index, '')
				this.updateField(index, 'props', parsed)
			} catch (e) {
				this.$set(this.propsError, index, (e && e.message) || String(e))
			}
		},
		addWidget(index) {
			const next = this.localSections.slice()
			const current = { ...(next[index] || {}) }
			current.widgets = [...(current.widgets || []), { type: 'version-info' }]
			next[index] = current
			this.emit(next)
		},
		updateWidget(index, wIndex, key, value) {
			const next = this.localSections.slice()
			const current = { ...(next[index] || {}) }
			const widgets = (current.widgets || []).slice()
			const widget = { ...(widgets[wIndex] || {}) }
			if (value === '' || value === null || value === undefined) {
				delete widget[key]
			} else {
				widget[key] = value
			}
			widgets[wIndex] = widget
			current.widgets = widgets
			next[index] = current
			this.emit(next)
		},
		onWidgetPropsInput(index, wIndex, value) {
			const trimmed = (value || '').trim()
			if (trimmed === '') {
				this.updateWidget(index, wIndex, 'props', undefined)
				return
			}
			try {
				this.updateWidget(index, wIndex, 'props', JSON.parse(trimmed))
			} catch {
				// Keep the last valid value until the JSON parses; the
				// settings validator surfaces the malformed state.
			}
		},
		removeWidget(index, wIndex) {
			const next = this.localSections.slice()
			const current = { ...(next[index] || {}) }
			const widgets = (current.widgets || []).slice()
			widgets.splice(wIndex, 1)
			current.widgets = widgets
			next[index] = current
			this.emit(next)
		},
		addSection() {
			const next = this.localSections.slice()
			next.push({ title: '', fields: [] })
			this.emit(next)
		},
		removeSection(index) {
			const next = this.localSections.slice()
			next.splice(index, 1)
			this.emit(next)
		},
	},
}
</script>

<style scoped>
.settings-section-builder {
	display: flex;
	flex-direction: column;
	gap: 10px;
}
.settings-section-builder__section {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}
.settings-section-builder__head {
	display: flex;
	gap: 6px;
	align-items: center;
}
.settings-section-builder__kind {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
.settings-section-builder__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	font-size: 13px;
}
.settings-section-builder__body {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-left: 8px;
	border-left: 2px solid var(--color-border);
}
.settings-section-builder__row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}
.settings-section-builder__widget {
	display: flex;
	gap: 6px;
	align-items: center;
}
.settings-section-builder__field {
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.settings-section-builder__field--narrow {
	flex: 0 0 140px;
}
.settings-section-builder__textarea {
	min-height: 90px;
	font-family: monospace;
	font-size: 12px;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.settings-section-builder__error {
	margin: 0;
	color: var(--color-error);
	font-size: 12px;
}
.settings-section-builder__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
.settings-section-builder__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}
</style>
