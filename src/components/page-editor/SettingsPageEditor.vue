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
			{{ t('openbuild', 'Settings page') }}
		</h3>

		<fieldset class="settings-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Save endpoint') }}</legend>
			<label class="settings-page-editor__group-row">
				{{ t('openbuild', 'saveEndpoint (optional)') }}
				<input
					type="text"
					:value="config.saveEndpoint || ''"
					:placeholder="t('openbuild', '/api/objects/:slug/settings')"
					:aria-invalid="isInvalid('saveEndpoint')"
					@input="update('saveEndpoint', $event.target.value)" />
				<InlineFieldMark :error="markFor('saveEndpoint')" />
			</label>
		</fieldset>

		<fieldset class="settings-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Layout') }}</legend>
			<div class="settings-page-editor__shape">
				<label class="settings-page-editor__inline">
					<input
						type="radio"
						:checked="layoutShape === 'sections'"
						value="sections"
						@change="setLayoutShape('sections')" />
					{{ t('openbuild', 'Flat sections') }}
				</label>
				<label class="settings-page-editor__inline">
					<input
						type="radio"
						:checked="layoutShape === 'tabs'"
						value="tabs"
						@change="setLayoutShape('tabs')" />
					{{ t('openbuild', 'Tabbed sections') }}
				</label>
			</div>
			<p class="settings-page-editor__hint">
				{{ t('openbuild', 'Exactly one of sections or tabs must be set.') }}
			</p>
		</fieldset>

		<fieldset
			v-if="layoutShape === 'sections'"
			class="settings-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Sections') }}</legend>
			<SettingsSectionBuilder
				:modelValue="config.sections || []"
				@update:modelValue="update('sections', $event)" />
			<InlineFieldMark :error="markFor('sections')" />
		</fieldset>

		<fieldset v-else class="settings-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Tabs') }}</legend>
			<div
				v-for="(tab, index) in tabs"
				:key="index"
				class="settings-page-editor__tab">
				<div class="settings-page-editor__tab-head">
					<input
						:value="tab.id || ''"
						type="text"
						class="settings-page-editor__field settings-page-editor__field--narrow"
						:placeholder="t('openbuild', 'Tab id')"
						:aria-label="t('openbuild', 'Tab id')"
						@input="updateTabField(index, 'id', $event.target.value)" />
					<input
						:value="tab.label || ''"
						type="text"
						class="settings-page-editor__field"
						:placeholder="t('openbuild', 'Tab label (i18n key)')"
						:aria-label="t('openbuild', 'Tab label (i18n key)')"
						@input="
							updateTabField(index, 'label', $event.target.value)
						" />
					<input
						:value="tab.icon || ''"
						type="text"
						class="settings-page-editor__field settings-page-editor__field--narrow"
						:placeholder="t('openbuild', 'Icon (optional)')"
						:aria-label="t('openbuild', 'Icon (optional)')"
						@input="
							updateTabField(index, 'icon', $event.target.value)
						" />
					<button
						type="button"
						class="settings-page-editor__remove"
						:title="t('openbuild', 'Remove tab')"
						@click="removeTab(index)">
						✕
					</button>
				</div>
				<SettingsSectionBuilder
					:modelValue="tab.sections || []"
					@update:modelValue="updateTabField(index, 'sections', $event)" />
			</div>
			<button type="button" class="settings-page-editor__add" @click="addTab">
				+ {{ t('openbuild', 'Add tab') }}
			</button>
			<InlineFieldMark :error="markFor('tabs')" />
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import SettingsSectionBuilder from './fields/SettingsSectionBuilder.vue'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'SettingsPageEditor',
	components: { SettingsSectionBuilder, InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},

		pageType: {
			type: String,
			default: 'settings',
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
			if (
				value === ''
				|| value === null
				|| value === undefined
				|| (Array.isArray(value)
					&& value.length === 0
					&& key === 'saveEndpoint')
			) {
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

<style scoped>
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
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
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
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
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
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.settings-page-editor__field--narrow {
	flex: 0 0 130px;
}

.settings-page-editor__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.settings-page-editor__add {
	align-self: flex-start;
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.settings-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
