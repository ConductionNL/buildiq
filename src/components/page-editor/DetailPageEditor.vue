<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DetailPageEditor — register + schema picker, route-param derivation
  - from parent page route, sidebar config (boolean OR object shape both
  - supported), `sidebarProps.tabs` list. Implements REQ-OBPD-005.
  -->
<template>
	<div class="detail-page-editor">
		<h3 class="detail-page-editor__title">
			{{ t('buildiq', 'Detail page') }}
		</h3>
		<div class="detail-page-editor__group">
			<RegisterSchemaFields
				:config="config"
				:registers="registers"
				:schemas="schemas"
				@register="update('register', $event)"
				@schema="update('schema', $event)" />
		</div>

		<p v-if="!routeHasParam" class="detail-page-editor__warn" role="alert">
			{{
				t(
					'buildiq',
					'The parent page route has no :param segment — detail pages typically need one (e.g. /messages/:id).',
				)
			}}
		</p>
		<p v-else class="detail-page-editor__note">
			{{ t('buildiq', 'Route params detected:') }}
			{{ routeParams.join(', ') }}
		</p>

		<fieldset class="detail-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Sidebar') }}</legend>
			<div class="detail-page-editor__sidebar-shape">
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'object'"
						value="object"
						@change="setSidebarShape('object')" />
					{{ t('buildiq', 'Object form (preferred)') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'boolean'"
						value="boolean"
						@change="setSidebarShape('boolean')" />
					{{ t('buildiq', 'Boolean form (legacy)') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'none'"
						value="none"
						@change="setSidebarShape('none')" />
					{{ t('buildiq', 'Not set') }}
				</label>
			</div>
			<label
				v-if="sidebarShape === 'boolean'"
				class="detail-page-editor__inline">
				<input
					type="checkbox"
					:checked="config.sidebar === true"
					@change="update('sidebar', $event.target.checked)" />
				{{ t('buildiq', 'Sidebar enabled') }}
			</label>
			<div
				v-else-if="sidebarShape === 'object'"
				class="detail-page-editor__sidebar-object">
				<label class="detail-page-editor__inline">
					<input
						type="checkbox"
						:checked="(config.sidebar || {}).enabled !== false"
						@change="
							updateSidebarKey('enabled', $event.target.checked)
						" />
					{{ t('buildiq', 'Enabled') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="checkbox"
						:checked="(config.sidebar || {}).show !== false"
						@change="updateSidebarKey('show', $event.target.checked)" />
					{{ t('buildiq', 'Show') }}
				</label>
				<SidebarTabBuilder
					:modelValue="(config.sidebar && config.sidebar.tabs) || []"
					@update:modelValue="updateSidebarKey('tabs', $event)" />
			</div>
			<InlineFieldMark :error="markFor('sidebar')" />
		</fieldset>

		<fieldset class="detail-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Record history') }}</legend>
			<label class="detail-page-editor__inline">
				<input
					type="checkbox"
					data-testid="record-history-show"
					:checked="showHistory"
					@change="setShowHistory($event.target.checked)" />
				{{ t('buildiq', 'Show record history') }}
			</label>
			<label class="detail-page-editor__inline">
				<input
					type="checkbox"
					data-testid="record-history-restore"
					:checked="allowRestore"
					:disabled="!showHistory"
					@change="setAllowRestore($event.target.checked)" />
				{{ t('buildiq', 'Let users restore an earlier version') }}
			</label>
			<p class="detail-page-editor__note">
				{{
					t(
						'buildiq',
						'Only users who may edit a record can restore it. A restore is saved as a new version; records that point at it are not changed.',
					)
				}}
			</p>
		</fieldset>

		<fieldset class="detail-page-editor__fieldset">
			<legend>
				{{ t('buildiq', 'sidebarProps.tabs (alternate path)') }}
			</legend>
			<SidebarTabBuilder
				:modelValue="(config.sidebarProps && config.sidebarProps.tabs) || []"
				@update:modelValue="updateSidebarPropsTabs($event)" />
			<InlineFieldMark :error="markFor('sidebarProps')" />
		</fieldset>

		<AppliesToPanel
			:modelValue="config.pageLayout || {}"
			:register="config.register || ''"
			:schema="config.schema || ''"
			:targetApp="appSlug"
			@update:modelValue="update('pageLayout', $event)"
			@saved="$refs.overrideList && $refs.overrideList.reload()" />

		<ScreenOverrideList
			ref="overrideList"
			:register="config.register || ''"
			:schema="config.schema || ''" />

		<RegistrationFormList
			:register="config.register || ''"
			:schema="config.schema || ''"
			:typeProperty="(config.pageLayout || {}).typeProperty || ''"
			:typeValue="(config.pageLayout || {}).typeValue || ''"
			:targetApp="appSlug" />
	</div>
</template>

<script>
import AppliesToPanel from './fields/AppliesToPanel.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import RegisterSchemaFields from './fields/RegisterSchemaFields.vue'
import RegistrationFormList from './fields/RegistrationFormList.vue'
import ScreenOverrideList from './fields/ScreenOverrideList.vue'
import SidebarTabBuilder from './fields/SidebarTabBuilder.vue'
import { useEditorRegisterPicker } from '../../composables/useEditorRegisterPicker.js'
import {
	pageEditorBaseMixin,
	pageEditorPickerMixin,
	pageEditorRegisterPropsMixin,
	pageEditorRegisterWatchMixin,
} from '../../mixins/pageEditorBase.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

/**
 * The stable id of the record-history tab, so the section finds it again.
 */
const HISTORY_TAB_ID = 'history'

/**
 * A new record-history tab: one audit widget, restore as given.
 *
 * @param {boolean} allowRestore - whether app users may restore an earlier version.
 * @return {object} The tab.
 */
function historyTabFor(allowRestore) {
	return {
		id: HISTORY_TAB_ID,
		label: 'History',
		icon: 'History',
		widgets: [{ type: 'audit', props: { allowRestore } }],
	}
}

/**
 * The audit widget of a tab, or null.
 *
 * @param {object|null} tab - the tab.
 * @return {object|null} The widget.
 */
function auditWidget(tab) {
	if (!tab || !Array.isArray(tab.widgets)) {
		return null
	}
	return tab.widgets.find((w) => w && w.type === 'audit') || null
}

export default {
	name: 'DetailPageEditor',
	components: {
		AppliesToPanel,
		InlineFieldMark,
		RegisterSchemaFields,
		RegistrationFormList,
		ScreenOverrideList,
		SidebarTabBuilder,
	},

	mixins: [
		pageEditorBaseMixin,
		pageEditorRegisterPropsMixin,
		pageEditorValidationMixin,
		pageEditorPickerMixin,
		pageEditorRegisterWatchMixin,
	],

	props: {
		pageType: {
			type: String,
			default: 'detail',
		},
	},

	setup: useEditorRegisterPicker,

	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		validatedConfigKeys() {
			return ['register', 'schema', 'sidebar', 'sidebarProps']
		},

		/**
		 * Observed behaviour of `routeParams` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		routeParams() {
			const matches =
				this.parentRoute.match(/:([A-Za-z_][A-Za-z0-9_]*)/g) || []
			return matches.map((m) => m.slice(1))
		},

		/**
		 * Observed behaviour of `routeHasParam` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		routeHasParam() {
			return this.routeParams.length > 0
		},

		/**
		 * Observed behaviour of `sidebarShape` (retrofit annotation).
		 *
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		/**
		 * The page's record-history tab: the sidebar tab with the stable id
		 * `history`, or null when the page has none.
		 *
		 * @return {object|null} The tab.
		 * @spec openspec/changes/data-restore-record-version/specs/record-history-restore/spec.md
		 */
		historyTab() {
			const sidebar = this.config.sidebar
			const tabs = (sidebar && typeof sidebar === 'object' && Array.isArray(sidebar.tabs)) ? sidebar.tabs : []
			return tabs.find((tab) => tab && tab.id === HISTORY_TAB_ID) || null
		},

		/**
		 * Whether the page shows its record history.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/data-restore-record-version/specs/record-history-restore/spec.md
		 */
		showHistory() {
			return this.historyTab !== null
		},

		/**
		 * Whether app users may restore an earlier version: the `allowRestore`
		 * prop of the history tab's audit widget, off unless set to true.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/data-restore-record-version/specs/record-history-restore/spec.md
		 */
		allowRestore() {
			const widget = auditWidget(this.historyTab)
			return Boolean(widget && widget.props && widget.props.allowRestore === true)
		},

		sidebarShape() {
			const s = this.config.sidebar
			if (s === undefined) {
				return 'none'
			}
			if (typeof s === 'boolean') {
				return 'boolean'
			}
			return 'object'
		},
	},

	methods: {
		/**
		 * Write one key on the page's `config` block and emit the whole block
		 * back to PageDesigner. Only the named key is touched, so config keys
		 * this editor does not surface round-trip losslessly.
		 *
		 * @param {string} key - the config key being written: `register`, `schema`, or `sidebar` in its legacy boolean form. Writing `register` also drops `schema`, since a schema is only meaningful inside its register.
		 * @param {string|boolean} value - the new value: a slug from a `<select>`, or the checkbox state for the boolean-shaped sidebar. An empty string or `null` deletes the key; `false` is stored, so `sidebar: false` survives as an explicit "off".
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null) {
				delete next[key]
			} else {
				next[key] = value
			}
			if (key === 'register') {
				delete next.schema
			}
			this.$emit('update:config', next)
		},

		/**
		 * Switch `config.sidebar` between the three shapes the manifest
		 * accepts. Switching discards whatever the previous shape held — the
		 * object form's keys do not survive a trip through `boolean`/`none`.
		 *
		 * @param {'none'|'boolean'|'object'} shape - the radio's value: `none` deletes `sidebar`, `boolean` writes the legacy `true`, `object` seeds the preferred `{ enabled: true }`.
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		setSidebarShape(shape) {
			const next = { ...this.config }
			if (shape === 'none') {
				delete next.sidebar
			} else if (shape === 'boolean') {
				next.sidebar = true
			} else {
				next.sidebar = { enabled: true }
			}
			this.$emit('update:config', next)
		},

		/**
		 * Write one key inside the object-shaped sidebar, promoting a legacy
		 * boolean `sidebar: true` to `{ enabled: true }` on the way.
		 *
		 * @param {string} key - the sidebar key being written: `enabled`, `show` or `tabs`.
		 * @param {boolean|Array<object>} value - the checkbox state for `enabled`/`show`, or the rebuilt tab list from SidebarTabBuilder for `tabs`. Falsy values are stored, not deleted, so `enabled: false` is preserved as an explicit "off".
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		updateSidebarKey(key, value) {
			const next = { ...this.config }
			const current = (typeof next.sidebar === 'object' && next.sidebar) || {
				enabled: true,
			}
			next.sidebar = { ...current, [key]: value }
			this.$emit('update:config', next)
		},

		/**
		 * Add or remove the record-history tab. Adding it switches an unset or
		 * boolean sidebar to its object shape and starts with restore off;
		 * removing it leaves every other tab as it was.
		 *
		 * @param {boolean} on - whether the page shows its record history.
		 * @spec openspec/changes/data-restore-record-version/specs/record-history-restore/spec.md
		 */
		setShowHistory(on) {
			const sidebar = this.config.sidebar
			const tabs = (sidebar && typeof sidebar === 'object' && Array.isArray(sidebar.tabs)) ? sidebar.tabs : []
			const others = tabs.filter((tab) => !(tab && tab.id === HISTORY_TAB_ID))
			if (on === true) {
				if (this.showHistory) {
					return
				}
				this.updateSidebarKey('tabs', [...others, historyTabFor(false)])
				return
			}
			if (!this.showHistory) {
				return
			}
			this.updateSidebarKey('tabs', others)
		},

		/**
		 * Turn restoring on or off on the history tab's audit widget, keeping
		 * the tab's other keys and widgets.
		 *
		 * @param {boolean} on - whether app users may restore an earlier version.
		 * @spec openspec/changes/data-restore-record-version/specs/record-history-restore/spec.md
		 */
		setAllowRestore(on) {
			if (!this.showHistory) {
				return
			}
			const tabs = this.config.sidebar.tabs.map((tab) => {
				if (!(tab && tab.id === HISTORY_TAB_ID)) {
					return tab
				}
				const widgets = Array.isArray(tab.widgets) ? tab.widgets : []
				const hasAudit = widgets.some((w) => w && w.type === 'audit')
				const nextWidgets = hasAudit
					? widgets.map((w) => (w && w.type === 'audit')
						? { ...w, props: { ...(w.props || {}), allowRestore: on === true } }
						: w)
					: [...widgets, { type: 'audit', props: { allowRestore: on === true } }]
				return { ...tab, widgets: nextWidgets }
			})
			this.updateSidebarKey('tabs', tabs)
		},

		/**
		 * Write the alternate `config.sidebarProps.tabs` path. Emptying the
		 * list removes just the `tabs` key, and removes `sidebarProps`
		 * entirely when nothing else lived there — so a manifest that never
		 * used this path round-trips byte-identically.
		 *
		 * @param {Array<{id: string, label: string, icon?: string, component?: string}>} tabs - the rebuilt tab list from SidebarTabBuilder; empty or missing means "no tabs".
		 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		updateSidebarPropsTabs(tabs) {
			const next = { ...this.config }
			if (!tabs || !tabs.length) {
				if (next.sidebarProps) {
					const { tabs: _t, ...rest } = next.sidebarProps
					if (Object.keys(rest).length === 0) {
						delete next.sidebarProps
					} else {
						next.sidebarProps = rest
					}
				}
			} else {
				next.sidebarProps = { ...(next.sidebarProps || {}), tabs }
			}
			this.$emit('update:config', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

.detail-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.detail-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.detail-page-editor__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.detail-page-editor__group label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.detail-page-editor__group select {
	@include c.control;
}

.detail-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.detail-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.detail-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	margin-right: 12px;
}

.detail-page-editor__sidebar-shape {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 8px;
}

.detail-page-editor__sidebar-object {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.detail-page-editor__warn {
	margin: 0;
	font-size: 12px;
	color: var(--color-warning, var(--color-text-maxcontrast));
	font-style: italic;
}

.detail-page-editor__note {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
