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
			<legend>
				{{ t('buildiq', 'sidebarProps.tabs (alternate path)') }}
			</legend>
			<SidebarTabBuilder
				:modelValue="(config.sidebarProps && config.sidebarProps.tabs) || []"
				@update:modelValue="updateSidebarPropsTabs($event)" />
			<InlineFieldMark :error="markFor('sidebarProps')" />
		</fieldset>

		<fieldset class="detail-page-editor__fieldset detail-page-editor__buttons">
			<legend>{{ t('buildiq', 'Buttons') }}</legend>
			<p class="detail-page-editor__note">
				{{ t('buildiq', 'Buttons in the page header that act on the open record.') }}
			</p>
			<ActionBuilder
				:modelValue="config.headerActions || []"
				:pages="pages"
				:flows="flows"
				:fieldOptions="recordFields"
				:lifecycleFields="[statusField]"
				@update:modelValue="update('headerActions', $event.length > 0 ? $event : '')" />
			<InlineFieldMark :error="markFor('headerActions')" />
		</fieldset>

		<fieldset class="detail-page-editor__fieldset detail-page-editor__status-buttons">
			<legend>{{ t('buildiq', 'Status buttons') }}</legend>
			<label class="detail-page-editor__inline">
				<input
					type="checkbox"
					class="detail-page-editor__status-toggle"
					:checked="Boolean(config.lifecycleActions)"
					@change="setStatusButtons($event.target.checked)">
				{{ t('buildiq', 'Show the status changes the lifecycle allows') }}
			</label>
			<template v-if="config.lifecycleActions">
				<label class="detail-page-editor__inline">
					{{ t('buildiq', 'Status field') }}
					<input
						:value="statusField"
						type="text"
						class="detail-page-editor__status-field"
						:aria-label="t('buildiq', 'Status field')"
						@input="updateLifecycle('field', $event.target.value || 'status')">
				</label>
				<p class="detail-page-editor__note">
					{{ transitions.length === 0
						? t('buildiq', 'Every change the lifecycle allows from the record\'s status is offered, as OpenRegister reports it.')
						: t('buildiq', 'Only the changes listed here are offered, and only from the status they start from.') }}
				</p>
				<div
					v-for="(transition, index) in transitions"
					:key="index"
					class="detail-page-editor__transition">
					<input
						:value="transition.action || ''"
						type="text"
						:placeholder="t('buildiq', 'Transition (e.g. approve)')"
						:aria-label="t('buildiq', 'Transition (e.g. approve)')"
						@input="updateTransition(index, 'action', $event.target.value)">
					<input
						:value="transition.label || ''"
						type="text"
						:placeholder="t('buildiq', 'Button label')"
						:aria-label="t('buildiq', 'Button label')"
						@input="updateTransition(index, 'label', $event.target.value)">
					<input
						:value="transition.confirm || ''"
						type="text"
						:placeholder="t('buildiq', 'Confirm question (optional)')"
						:aria-label="t('buildiq', 'Confirm question (optional)')"
						@input="updateTransition(index, 'confirm', $event.target.value)">
					<button
						type="button"
						class="detail-page-editor__remove"
						:title="t('buildiq', 'Remove status button')"
						:aria-label="t('buildiq', 'Remove status button')"
						@click="removeTransition(index)">
						✕
					</button>
				</div>
				<button type="button" class="detail-page-editor__add-transition" @click="addTransition">
					+ {{ t('buildiq', 'List a status button') }}
				</button>
			</template>
			<InlineFieldMark :error="markFor('lifecycleActions')" />
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
import ActionBuilder from './fields/ActionBuilder.vue'
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

export default {
	name: 'DetailPageEditor',
	components: {
		ActionBuilder,
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

		/** This app's pages, for a "go to a page" button. */
		pages: {
			type: Array,
			default: () => [],
		},

		/** The app's declared flows (`{flow, label?}`), for a "start a flow" button. */
		flows: {
			type: Array,
			default: () => [],
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
			return ['register', 'schema', 'sidebar', 'sidebarProps', 'headerActions', 'lifecycleActions']
		},

		/**
		 * The field the status buttons move (`config.lifecycleActions.field`, `status` by default).
		 *
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-status-buttons-follow-the-lifecycle-req-bqab-003
		 */
		statusField() {
			const config = this.config.lifecycleActions
			return (config && typeof config === 'object' && config.field) || 'status'
		},

		/**
		 * The status buttons listed by hand; none means the live list.
		 *
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-status-buttons-follow-the-lifecycle-req-bqab-003
		 */
		transitions() {
			const config = this.config.lifecycleActions
			return (config && Array.isArray(config.transitions)) ? config.transitions : []
		},

		/**
		 * The bound schema's fields, for a button's "show only when".
		 *
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-a-button-can-depend-on-the-record-req-bqab-005
		 */
		recordFields() {
			const wanted = this.config.schema
			const match = (this.schemas || []).find((entry) => entry
				&& (entry.slug === wanted || String(entry.id) === String(wanted) || entry.uuid === wanted))
			const keys = Object.keys((match && match.properties) || {})
			return keys.length > 0 ? keys : [this.statusField]
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

		/**
		 * Switch status buttons on (`{field: 'status'}`, the live transitions)
		 * or off.
		 *
		 * @param {boolean} on - Whether status buttons show.
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-status-buttons-follow-the-lifecycle-req-bqab-003
		 */
		setStatusButtons(on) {
			this.update('lifecycleActions', on ? { field: 'status' } : '')
		},

		/**
		 * Write one key of `config.lifecycleActions`.
		 *
		 * @param {string} key - `field` or `transitions`.
		 * @param {*} value - The value; an empty transitions list is removed.
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-status-buttons-follow-the-lifecycle-req-bqab-003
		 */
		updateLifecycle(key, value) {
			const next = { ...(this.config.lifecycleActions || { field: 'status' }) }
			if (Array.isArray(value) && value.length === 0) {
				delete next[key]
			} else {
				next[key] = value
			}
			this.update('lifecycleActions', next)
		},

		/**
		 * Edit one listed status button.
		 *
		 * @param {number} index - Position of the transition.
		 * @param {string} key - `action`, `label` or `confirm`.
		 * @param {string} value - The new value; empty removes the key.
		 */
		updateTransition(index, key, value) {
			const list = this.transitions.slice()
			const current = { ...(list[index] || {}) }
			if (value === '') {
				delete current[key]
			} else {
				current[key] = value
			}
			list[index] = current
			this.updateLifecycle('transitions', list)
		},

		/**
		 * List one more status button.
		 */
		addTransition() {
			this.updateLifecycle('transitions', [...this.transitions, { action: '', label: '' }])
		},

		/**
		 * Drop one listed status button; with none left the live list applies again.
		 *
		 * @param {number} index - Position of the transition.
		 */
		removeTransition(index) {
			const list = this.transitions.slice()
			list.splice(index, 1)
			this.updateLifecycle('transitions', list)
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
