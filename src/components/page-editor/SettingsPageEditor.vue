<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SettingsPageEditor — structured editor for `type: "settings"` pages
  - (task 4.5).
  -
  - Manifest contract: `{ sections?: array<Section>, tabs?: array<Tab>,
  - saveEndpoint? }` where EXACTLY ONE of `sections` or `tabs` MUST be set
  - (XOR — `manifest-settings-orchestration` spec). UI:
  -   - a `saveEndpoint` text field;
  -   - a radio between "Flat sections" and "Tabbed sections";
  -   - flat mode: a `SettingsSectionBuilder` bound to `config.sections`;
  -   - tabbed mode: a tab list (id + label) each owning its own
  -     `SettingsSectionBuilder` bound to that tab's `sections`.
  - Each Section declares EXACTLY ONE body of `fields` / `component` (+
  - `props`) / `widgets` (built-in widget types `version-info`,
  - `register-mapping`, `component`) — handled inside SettingsSectionBuilder.
  -
  - `update(key, value)` clones `config` and only touches the one key
  - (plus the XOR partner on a mode switch), so externally-authored extra
  - keys round-trip losslessly.
  -->
<template>
	<div class="settings-page-editor">
		<h3 class="settings-page-editor__title">
			{{ t('buildiq', 'Settings page') }}
		</h3>

		<fieldset class="settings-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Save endpoint') }}</legend>
			<label class="settings-page-editor__group-row">
				{{ t('buildiq', 'saveEndpoint (optional)') }}
				<input
					type="text"
					:value="config.saveEndpoint || ''"
					:placeholder="t('buildiq', '/api/objects/:slug/settings')"
					:aria-invalid="isInvalid('saveEndpoint')"
					@input="update('saveEndpoint', $event.target.value)" />
				<InlineFieldMark :error="markFor('saveEndpoint')" />
			</label>
		</fieldset>

		<fieldset class="settings-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Layout') }}</legend>
			<div class="settings-page-editor__shape">
				<label class="settings-page-editor__inline">
					<input
						type="radio"
						:checked="layoutShape === 'sections'"
						value="sections"
						@change="setLayoutShape('sections')" />
					{{ t('buildiq', 'Flat sections') }}
				</label>
				<label class="settings-page-editor__inline">
					<input
						type="radio"
						:checked="layoutShape === 'tabs'"
						value="tabs"
						@change="setLayoutShape('tabs')" />
					{{ t('buildiq', 'Tabbed sections') }}
				</label>
			</div>
			<p class="settings-page-editor__hint">
				{{ t('buildiq', 'Exactly one of sections or tabs must be set.') }}
			</p>
		</fieldset>

		<fieldset
			v-if="layoutShape === 'sections'"
			class="settings-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Sections') }}</legend>
			<SettingsSectionBuilder
				:modelValue="config.sections || []"
				@update:modelValue="update('sections', $event)" />
			<InlineFieldMark :error="markFor('sections')" />
		</fieldset>

		<fieldset v-else class="settings-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Tabs') }}</legend>
			<div
				v-for="(tab, index) in tabs"
				:key="index"
				class="settings-page-editor__tab">
				<div class="settings-page-editor__tab-head">
					<input
						:value="tab.id || ''"
						type="text"
						class="settings-page-editor__field settings-page-editor__field--narrow"
						:placeholder="t('buildiq', 'Tab id')"
						:aria-label="t('buildiq', 'Tab id')"
						@input="updateTabField(index, 'id', $event.target.value)" />
					<input
						:value="tab.label || ''"
						type="text"
						class="settings-page-editor__field"
						:placeholder="t('buildiq', 'Tab label (i18n key)')"
						:aria-label="t('buildiq', 'Tab label (i18n key)')"
						@input="
							updateTabField(index, 'label', $event.target.value)
						" />
					<input
						:value="tab.icon || ''"
						type="text"
						class="settings-page-editor__field settings-page-editor__field--narrow"
						:placeholder="t('buildiq', 'Icon (optional)')"
						:aria-label="t('buildiq', 'Icon (optional)')"
						@input="
							updateTabField(index, 'icon', $event.target.value)
						" />
					<button
						type="button"
						class="settings-page-editor__remove"
						:title="t('buildiq', 'Remove tab')"
						@click="removeTab(index)">
						✕
					</button>
				</div>
				<SettingsSectionBuilder
					:modelValue="tab.sections || []"
					@update:modelValue="updateTabField(index, 'sections', $event)" />
			</div>
			<button type="button" class="settings-page-editor__add" @click="addTab">
				+ {{ t('buildiq', 'Add tab') }}
			</button>
			<InlineFieldMark :error="markFor('tabs')" />
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import SettingsSectionBuilder from './fields/SettingsSectionBuilder.vue'
import { pageEditorBaseMixin } from '../../mixins/pageEditorBase.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

/**
 * Whether a config value is blank: `''`, `null` or `undefined`.
 *
 * @param {string|Array<object>|null|undefined} value - the new value.
 * @return {boolean}
 */
function isBlank(value) {
	return value === '' || value === null || value === undefined
}

/**
 * Whether a write deletes its config key: a blank value, or an empty list
 * for `saveEndpoint`. An empty `sections` list is kept on purpose.
 *
 * @param {string} key - the config key being written.
 * @param {string|Array<object>|null|undefined} value - the new value.
 * @return {boolean}
 */
function deletesKey(key, value) {
	return (
		isBlank(value)
		|| (Array.isArray(value) && value.length === 0 && key === 'saveEndpoint')
	)
}

export default {
	name: 'SettingsPageEditor',
	components: { SettingsSectionBuilder, InlineFieldMark },
	mixins: [pageEditorBaseMixin, pageEditorValidationMixin],
	props: {
		pageType: {
			type: String,
			default: 'settings',
		},
	},

	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		validatedConfigKeys() {
			return ['saveEndpoint', 'sections', 'tabs']
		},

		/**
		 * Observed behaviour of `layoutShape` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		layoutShape() {
			// `tabs` wins only when there is no `sections` array so a
			// half-edited config never silently flips branches.
			if (
				Array.isArray(this.config.tabs)
				&& !Array.isArray(this.config.sections)
			) {
				return 'tabs'
			}
			return 'sections'
		},

		/**
		 * Observed behaviour of `tabs` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		tabs() {
			return Array.isArray(this.config.tabs) ? this.config.tabs : []
		},
	},

	methods: {
		/**
		 * Write one key on the page's `config` block. Only the named key is
		 * touched, so config keys this editor does not surface round-trip
		 * losslessly. An empty `sections` array is deliberately kept — the
		 * sections/tabs XOR needs the key present to say which branch is
		 * active, even before the first section is added.
		 *
		 * @param {string} key - the config key being written: `saveEndpoint` or `sections`.
		 * @param {string|Array<object>} value - the new value: the free-text save endpoint, or the rebuilt section list from SettingsSectionBuilder. `''`, `null` and `undefined` delete the key.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		update(key, value) {
			const next = { ...this.config }
			if (deletesKey(key, value)) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.$emit('update:config', next)
		},

		/**
		 * Switch between the two mutually exclusive layouts, deleting the key
		 * of the branch being left and seeding an empty array for the branch
		 * being entered — so the config always satisfies exactly one half of
		 * the sections/tabs XOR. Content authored under the abandoned branch
		 * is dropped.
		 *
		 * @param {'sections'|'tabs'} shape - the radio's value: `tabs` drops `sections` and seeds `tabs: []`, anything else does the reverse.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		setLayoutShape(shape) {
			const next = { ...this.config }
			if (shape === 'tabs') {
				delete next.sections
				if (!Array.isArray(next.tabs)) {
					next.tabs = []
				}
			} else {
				delete next.tabs
				if (!Array.isArray(next.sections)) {
					next.sections = []
				}
			}
			this.$emit('update:config', next)
		},

		/**
		 * Write one key on one tab of the tabbed layout, leaving that tab's
		 * other keys and the rest of the tab list alone.
		 *
		 * @param {number} index - position of the tab in `config.tabs`.
		 * @param {string} key - the tab key being written: `id`, `label`, `icon` or `sections`.
		 * @param {string|Array<object>} value - the new value: text from the tab's header inputs, or that tab's rebuilt section list from SettingsSectionBuilder. `''`, `null` and `undefined` delete the key.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		updateTabField(index, key, value) {
			const next = { ...this.config }
			const tabsArr = (next.tabs || []).slice()
			const tab = { ...(tabsArr[index] || {}) }
			if (value === '' || value === null || value === undefined) {
				delete tab[key]
			} else {
				tab[key] = value
			}
			tabsArr[index] = tab
			next.tabs = tabsArr
			this.$emit('update:config', next)
		},

		/**
		 * Observed behaviour of `addTab` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		addTab() {
			const next = { ...this.config }
			next.tabs = [...(next.tabs || []), { id: '', label: '', sections: [] }]
			delete next.sections
			this.$emit('update:config', next)
		},

		/**
		 * Drop one tab, along with the sections authored under it. `tabs` is
		 * kept even when the last tab goes, so the layout stays on the tabbed
		 * branch of the XOR rather than silently flipping to flat sections.
		 *
		 * @param {number} index - position of the tab to remove in `config.tabs`.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		removeTab(index) {
			const next = { ...this.config }
			const tabsArr = (next.tabs || []).slice()
			tabsArr.splice(index, 1)
			next.tabs = tabsArr
			this.$emit('update:config', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

.settings-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.settings-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.settings-page-editor__fieldset {
	@include c.fieldset;
}

.settings-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.settings-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.settings-page-editor__group-row input {
	@include c.control;
}

.settings-page-editor__shape {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.settings-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
}

.settings-page-editor__tab {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.settings-page-editor__tab-head {
	display: flex;
	gap: 6px;
	align-items: center;
}

.settings-page-editor__field {
	flex: 1 1 auto;
	@include c.control;
}

.settings-page-editor__field--narrow {
	flex: 0 0 130px;
}

.settings-page-editor__remove {
	@include c.danger-button;
}

.settings-page-editor__add {
	align-self: flex-start;
	@include c.add-button;
}

.settings-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
