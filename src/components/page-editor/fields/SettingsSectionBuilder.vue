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
		<div
			v-for="(section, index) in localSections"
			:key="index"
			class="settings-section-builder__section">
			<div class="settings-section-builder__head">
				<input
					:value="section.title || ''"
					type="text"
					class="settings-section-builder__field"
					:placeholder="t('openbuild', 'Section title (i18n key)')"
					:aria-label="t('openbuild', 'Section title (i18n key)')"
					@input="updateField(index, 'title', $event.target.value)" />
				<input
					:value="section.id || ''"
					type="text"
					class="settings-section-builder__field settings-section-builder__field--narrow"
					:placeholder="t('openbuild', 'id (optional)')"
					:aria-label="t('openbuild', 'id (optional)')"
					@input="updateField(index, 'id', $event.target.value)" />
				<button
					type="button"
					class="settings-section-builder__remove"
					:title="t('openbuild', 'Remove section')"
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
						@change="setBodyKind(index, 'fields')" />
					{{ t('openbuild', 'Fields') }}
				</label>
				<label class="settings-section-builder__inline">
					<input
						type="radio"
						:checked="bodyKind(section) === 'component'"
						value="component"
						@change="setBodyKind(index, 'component')" />
					{{ t('openbuild', 'Component') }}
				</label>
				<label class="settings-section-builder__inline">
					<input
						type="radio"
						:checked="bodyKind(section) === 'widgets'"
						value="widgets"
						@change="setBodyKind(index, 'widgets')" />
					{{ t('openbuild', 'Widgets') }}
				</label>
			</div>

			<div
				v-if="bodyKind(section) === 'fields'"
				class="settings-section-builder__body">
				<FormFieldBuilder
					:model-value="section.fields || []"
					@update:modelValue="updateField(index, 'fields', $event)" />
			</div>
			<div
				v-else-if="bodyKind(section) === 'component'"
				class="settings-section-builder__body">
				<label class="settings-section-builder__row">
					{{ t('openbuild', 'customComponents key') }}
					<input
						:value="section.component || ''"
						type="text"
						:placeholder="t('openbuild', 'e.g. AppSettingsPanel')"
						:aria-label="t('openbuild', 'e.g. AppSettingsPanel')"
						@input="
							updateField(index, 'component', $event.target.value)
						" />
				</label>
				<label class="settings-section-builder__row">
					{{ t('openbuild', 'props (JSON, optional)') }}
					<textarea
						class="settings-section-builder__textarea"
						spellcheck="false"
						:value="
							propsDraft[index] !== undefined
								? propsDraft[index]
								: stringifyProps(section.props)
						"
						@input="onPropsInput(index, $event.target.value)" />
				</label>
				<p
					v-if="propsError[index]"
					class="settings-section-builder__error"
					role="alert">
					{{ propsError[index] }}
				</p>
			</div>
			<div v-else class="settings-section-builder__body">
				<div
					v-for="(widget, wIndex) in section.widgets || []"
					:key="wIndex"
					class="settings-section-builder__widget">
					<select
						:value="widget.type || 'version-info'"
						class="settings-section-builder__field settings-section-builder__field--narrow"
						@change="
							updateWidget(index, wIndex, 'type', $event.target.value)
						">
						<option v-for="wt in WIDGET_TYPES" :key="wt" :value="wt">
							{{ wt }}
						</option>
					</select>
					<input
						v-if="widget.type === 'component'"
						:value="widget.componentName || ''"
						type="text"
						class="settings-section-builder__field"
						:placeholder="
							t('openbuild', 'componentName (customComponents key)')
						"
						:aria-label="
							t('openbuild', 'componentName (customComponents key)')
						"
						@input="
							updateWidget(
								index,
								wIndex,
								'componentName',
								$event.target.value,
							)
						" />
					<input
						:value="stringifyProps(widget.props)"
						type="text"
						class="settings-section-builder__field"
						:placeholder="t('openbuild', 'props (JSON, optional)')"
						:aria-label="t('openbuild', 'props (JSON, optional)')"
						@input="
							onWidgetPropsInput(index, wIndex, $event.target.value)
						" />
					<button
						type="button"
						class="settings-section-builder__remove"
						:title="t('openbuild', 'Remove widget')"
						@click="removeWidget(index, wIndex)">
						✕
					</button>
				</div>
				<button
					type="button"
					class="settings-section-builder__add"
					@click="addWidget(index)">
					+ {{ t('openbuild', 'Add widget') }}
				</button>
			</div>
		</div>
		<button
			type="button"
			class="settings-section-builder__add"
			@click="addSection">
			+ {{ t('openbuild', 'Add section') }}
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
		/**
		 * Observed behaviour of `localSections` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		localSections() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},
	methods: {
		/**
		 * Observed behaviour of `bodyKind` (retrofit annotation).
		 *
		 * @param {?{fields?: object[], component?: string, widgets?: object[]}} section - one
		 *   entry of the sections list; only `widgets` (must be an array) and `component`
		 *   (must be a string) are probed, in that precedence order.
		 * @return {'fields'|'component'|'widgets'} which of the three mutually-exclusive
		 *   bodies the section declares — `'fields'` is also the fallback for a section
		 *   that declares no body at all.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		bodyKind(section) {
			if (section && Array.isArray(section.widgets)) {
				return 'widgets'
			}
			if (section && typeof section.component === 'string') {
				return 'component'
			}
			return 'fields'
		},
		/**
		 * Observed behaviour of `stringifyProps` (retrofit annotation).
		 *
		 * @param {?object} value - a section's or a widget's `props` bag as it sits in the
		 *   manifest; absent props arrive as `undefined`/`null`.
		 * @return {string} the JSON text to seed the props textarea/input with — `''` when
		 *   there are no props, or when they cannot be serialised (e.g. a cycle).
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
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
		/**
		 * Observed behaviour of `emit` (retrofit annotation).
		 *
		 * @param {object[]} sections - the COMPLETE next sections list. Every mutator here
		 *   clones the list plus the one section it touches and hands the whole array up,
		 *   so extra keys the editor does not surface (icon, order, …) round-trip intact.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		emit(sections) {
			this.$emit('update:modelValue', sections)
		},
		/**
		 * Observed behaviour of `updateField` (retrofit annotation).
		 *
		 * @param {number} index - position of the section in the sections list.
		 * @param {'title'|'id'|'component'|'fields'|'props'} key - the section property to
		 *   write: `title`/`id` from the head inputs, `component` from the component input,
		 *   `fields` from the nested FormFieldBuilder, `props` from `onPropsInput`.
		 * @param {string|object|object[]|undefined} value - the new value: input text for
		 *   `title`/`id`/`component`, the emitted formField list for `fields`, or the parsed
		 *   JSON object for `props`. An empty string, `null`, `undefined` or an empty array
		 *   DELETES the key so the manifest never carries an empty body.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateField(index, key, value) {
			const next = this.localSections.slice()
			const current = { ...(next[index] || {}) }
			if (
				value === ''
				|| value === null
				|| value === undefined
				|| (Array.isArray(value) && value.length === 0)
			) {
				delete current[key]
			} else {
				current[key] = value
			}
			next[index] = current
			this.emit(next)
		},
		/**
		 * Observed behaviour of `setBodyKind` (retrofit annotation).
		 *
		 * @param {number} index - position of the section whose body kind is switched.
		 * @param {'fields'|'component'|'widgets'} kind - the body picked by the radio group.
		 *   All four BODY_KEYS (`fields`, `component`, `props`, `widgets`) are dropped first
		 *   and only the chosen body is seeded empty, so the XOR invariant holds; any value
		 *   other than `'fields'`/`'component'` falls through to `widgets`. The section's
		 *   non-body keys (title, id, …) survive.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
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
			this.propsDraft[index] = undefined
			this.propsError[index] = ''
			this.emit(next)
		},
		/**
		 * Observed behaviour of `onPropsInput` (retrofit annotation).
		 *
		 * @param {number} index - position of the section whose props textarea changed; also
		 *   the key into the `propsDraft` / `propsError` maps.
		 * @param {string} value - the raw textarea text. Emptying it clears `props`; valid
		 *   JSON is parsed and written; malformed JSON is only kept as a draft and reported
		 *   through `propsError[index]`, leaving the manifest untouched mid-keystroke.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		onPropsInput(index, value) {
			this.propsDraft[index] = value
			const trimmed = (value || '').trim()
			if (trimmed === '') {
				this.propsError[index] = ''
				this.updateField(index, 'props', undefined)
				return
			}
			try {
				const parsed = JSON.parse(trimmed)
				this.propsError[index] = ''
				this.updateField(index, 'props', parsed)
			} catch (e) {
				this.propsError[index] = (e && e.message) || String(e)
			}
		},
		/**
		 * Observed behaviour of `addWidget` (retrofit annotation).
		 *
		 * @param {number} index - position of the section to append a default
		 *   `{ type: 'version-info' }` widget to.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addWidget(index) {
			const next = this.localSections.slice()
			const current = { ...(next[index] || {}) }
			current.widgets = [...(current.widgets || []), { type: 'version-info' }]
			next[index] = current
			this.emit(next)
		},
		/**
		 * Observed behaviour of `updateWidget` (retrofit annotation).
		 *
		 * @param {number} index - position of the owning section in the sections list.
		 * @param {number} wIndex - position of the widget within that section's `widgets`.
		 * @param {'type'|'componentName'|'props'} key - the widget property to write;
		 *   `componentName` only applies to the `component` widget type.
		 * @param {string|object|undefined} value - the new value: the selected widget
		 *   `type`, the componentName text, or the parsed props object from
		 *   `onWidgetPropsInput`. `''`, `null` and `undefined` DELETE the key.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
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
		/**
		 * Observed behaviour of `onWidgetPropsInput` (retrofit annotation).
		 *
		 * @param {number} index - position of the owning section in the sections list.
		 * @param {number} wIndex - position of the widget within that section's `widgets`.
		 * @param {string} value - the raw props text. Emptying it clears the widget's
		 *   `props`; valid JSON is written; malformed JSON is silently ignored (unlike a
		 *   section's props there is no per-widget error slot), so the last valid object
		 *   stays in the manifest.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
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
		/**
		 * Observed behaviour of `removeWidget` (retrofit annotation).
		 *
		 * @param {number} index - position of the owning section in the sections list.
		 * @param {number} wIndex - position of the widget to drop from that section's
		 *   `widgets`; the (possibly empty) array is kept so the section stays a
		 *   widgets-bodied section.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removeWidget(index, wIndex) {
			const next = this.localSections.slice()
			const current = { ...(next[index] || {}) }
			const widgets = (current.widgets || []).slice()
			widgets.splice(wIndex, 1)
			current.widgets = widgets
			next[index] = current
			this.emit(next)
		},
		/**
		 * Observed behaviour of `addSection` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addSection() {
			const next = this.localSections.slice()
			next.push({ title: '', fields: [] })
			this.emit(next)
		},
		/**
		 * Observed behaviour of `removeSection` (retrofit annotation).
		 *
		 * @param {number} index - position of the section to drop from the sections list.
		 *   The index-keyed `propsDraft` / `propsError` maps are NOT re-based, so a
		 *   surviving section can inherit the removed one's draft slot.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
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
