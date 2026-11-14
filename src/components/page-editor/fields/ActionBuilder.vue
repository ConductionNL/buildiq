<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ActionBuilder — authors typed page actions (the manifest `action` $def):
  - a maker picks what a button does (change fields on this record, start a
  - flow, open a form, go to a page, open a link, export) and fills in only
  - what that type needs (pages-action-buttons D1, REQ-BQAB-001). Used for an
  - index page's `actions` and a detail page's `headerActions`.
  -
  - Types that need code or another app (handler, agent, api-call, …) are
  - shown read-only and never rewritten. Old `{target: navigate|emit|none}`
  - actions are shown in their typed form and written that way on the next
  - edit (D3, REQ-BQAB-004). A "change fields" action that patches a
  - lifecycle field is refused: status moves go through status buttons (D2,
  - REQ-BQAB-003). Each action may carry a `visibleWhen` condition (D4).
  -->
<template>
	<div class="action-builder">
		<p v-if="migration.migrated > 0" class="action-builder__note">
			{{ t('buildiq', 'Older actions are shown in their new form and are saved that way when you save the page.') }}
			<template v-if="migration.dropped > 0">
				{{ n('buildiq', '%n action that did nothing was removed.', '%n actions that did nothing were removed.', migration.dropped) }}
			</template>
		</p>
		<div
			v-for="(act, index) in localActions"
			:key="index"
			class="action-builder__action">
			<div class="action-builder__row">
				<input
					:value="act.id || ''"
					type="text"
					class="action-builder__field"
					:placeholder="t('buildiq', 'Action id (e.g. edit)')"
					:aria-label="t('buildiq', 'Action id (e.g. edit)')"
					@input="updateField(index, 'id', $event.target.value)">
				<input
					:value="act.label || ''"
					type="text"
					class="action-builder__field"
					:placeholder="t('buildiq', 'Label (i18n key)')"
					:aria-label="t('buildiq', 'Label (i18n key)')"
					@input="updateField(index, 'label', $event.target.value)">
				<input
					:value="act.icon || ''"
					type="text"
					class="action-builder__field action-builder__field--narrow"
					:placeholder="t('buildiq', 'Icon')"
					:aria-label="t('buildiq', 'Icon')"
					@input="updateField(index, 'icon', $event.target.value)">
				<select
					v-if="editable(act)"
					:value="act.type || ''"
					class="action-builder__field action-builder__type"
					:aria-label="t('buildiq', 'What the button does')"
					@change="setType(index, $event.target.value)">
					<option value="">
						{{ t('buildiq', '— what the button does —') }}
					</option>
					<option v-for="option in typeOptions" :key="option.value" :value="option.value">
						{{ option.label }}
					</option>
				</select>
				<span v-else class="action-builder__readonly">
					{{ t('buildiq', 'Runs {type}; edit in Raw JSON', { type: act.type }) }}
				</span>
				<button
					type="button"
					class="action-builder__remove"
					:title="t('buildiq', 'Remove action')"
					:aria-label="t('buildiq', 'Remove action')"
					@click="removeAction(index)">
					✕
				</button>
			</div>

			<div v-if="act.type === 'object-op'" class="action-builder__detail">
				<div
					v-for="(value, key) in act.values || {}"
					:key="key"
					class="action-builder__row">
					<span class="action-builder__key">{{ key }}</span>
					<input
						:value="String(value)"
						type="text"
						class="action-builder__field"
						:aria-label="t('buildiq', 'Value for {field}', { field: key })"
						@input="setValue(index, key, $event.target.value)">
					<button
						type="button"
						class="action-builder__remove"
						:title="t('buildiq', 'Remove field')"
						:aria-label="t('buildiq', 'Remove field')"
						@click="removeValue(index, key)">
						✕
					</button>
				</div>
				<div class="action-builder__row">
					<input
						v-model="newKeys[index]"
						type="text"
						class="action-builder__field action-builder__new-key"
						:placeholder="t('buildiq', 'Field to set')"
						:aria-label="t('buildiq', 'Field to set')">
					<button type="button" class="action-builder__add action-builder__add-field" @click="addValue(index)">
						+ {{ t('buildiq', 'Add field') }}
					</button>
				</div>
				<p v-if="refusals[index]" class="action-builder__error" role="alert">
					{{ refusals[index] }}
				</p>
			</div>

			<div v-if="act.type === 'run-node'" class="action-builder__detail action-builder__row">
				<select
					v-if="flowOptions.length > 0"
					:value="act.flowId || ''"
					class="action-builder__field action-builder__flow"
					:aria-label="t('buildiq', 'Flow')"
					@change="updateField(index, 'flowId', $event.target.value)">
					<option value="">
						{{ t('buildiq', '— flow —') }}
					</option>
					<option v-for="flow in flowOptions" :key="flow.value" :value="flow.value">
						{{ flow.label }}
					</option>
				</select>
				<input
					v-else
					:value="act.flowId || ''"
					type="text"
					class="action-builder__field action-builder__flow"
					:placeholder="t('buildiq', 'Flow id')"
					:aria-label="t('buildiq', 'Flow id')"
					@input="updateField(index, 'flowId', $event.target.value)">
				<input
					:value="act.nodeId || ''"
					type="text"
					class="action-builder__field action-builder__node"
					:placeholder="t('buildiq', 'Node id')"
					:aria-label="t('buildiq', 'Node id')"
					@input="updateField(index, 'nodeId', $event.target.value)">
				<span v-if="flowMissing(act)" class="action-builder__error" role="alert">
					{{ t('buildiq', 'This flow is no longer part of the app.') }}
				</span>
			</div>

			<div v-if="act.type === 'open-form'" class="action-builder__detail action-builder__row">
				<input
					:value="act.register || ''"
					type="text"
					class="action-builder__field"
					:placeholder="t('buildiq', 'Register')"
					:aria-label="t('buildiq', 'Register')"
					@input="updateField(index, 'register', $event.target.value)">
				<input
					:value="act.schema || ''"
					type="text"
					class="action-builder__field action-builder__schema"
					:placeholder="t('buildiq', 'Schema')"
					:aria-label="t('buildiq', 'Schema')"
					@input="updateField(index, 'schema', $event.target.value)">
			</div>

			<div v-if="act.type === 'open-page'" class="action-builder__detail action-builder__row">
				<select
					:value="act.target || ''"
					class="action-builder__field action-builder__page"
					:aria-label="t('buildiq', 'Page')"
					@change="updateField(index, 'target', $event.target.value)">
					<option value="">
						{{ t('buildiq', '— page —') }}
					</option>
					<option v-for="page in pageOptions" :key="page.value" :value="page.value">
						{{ page.label }}
					</option>
				</select>
			</div>

			<div v-if="act.type === 'navigate'" class="action-builder__detail action-builder__row">
				<input
					:value="act.target || ''"
					type="url"
					class="action-builder__field action-builder__link"
					:placeholder="t('buildiq', 'Link (https://…)')"
					:aria-label="t('buildiq', 'Link (https://…)')"
					@input="updateField(index, 'target', $event.target.value)">
			</div>

			<div v-if="act.type === 'export'" class="action-builder__detail action-builder__row">
				<label v-for="format in exportFormats" :key="format" class="action-builder__inline">
					<input
						type="checkbox"
						:checked="(act.formats || []).includes(format)"
						@change="toggleFormat(index, format, $event.target.checked)">
					{{ format }}
				</label>
			</div>

			<div v-if="act.type === 'object-op' || act.type === 'run-node'" class="action-builder__detail action-builder__row">
				<label class="action-builder__inline">
					<input
						type="checkbox"
						class="action-builder__confirm"
						:checked="act.confirm === true"
						@change="updateField(index, 'confirm', $event.target.checked ? true : '')">
					{{ t('buildiq', 'Ask to confirm') }}
				</label>
				<input
					v-if="act.confirm === true"
					:value="act.confirmMessage || ''"
					type="text"
					class="action-builder__field"
					:placeholder="t('buildiq', 'Confirm question')"
					:aria-label="t('buildiq', 'Confirm question')"
					@input="updateField(index, 'confirmMessage', $event.target.value)">
			</div>

			<div v-if="editable(act) && act.type" class="action-builder__detail">
				<span class="action-builder__caption">{{ t('buildiq', 'Show only when') }}</span>
				<VisibleWhenBuilder
					:modelValue="act.visibleWhen || null"
					:fieldOptions="fieldOptions"
					@update:modelValue="updateField(index, 'visibleWhen', $event === null ? '' : $event)" />
			</div>

			<p v-if="editable(act) && act.type && missing(act)" class="action-builder__warn">
				{{ missing(act) }}
			</p>
		</div>
		<button type="button" class="action-builder__add" @click="addAction">
			+ {{ t('buildiq', 'Add action') }}
		</button>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import VisibleWhenBuilder from './VisibleWhenBuilder.vue'
import {
	EXPORT_FORMATS,
	isEditableType,
	lifecycleFieldPatched,
	migrateActions,
	missingFor,
	withType,
} from './actionTypes.js'

export default {
	name: 'ActionBuilder',
	components: { VisibleWhenBuilder },
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** This app's pages, `{id, title?}`, for "go to a page". */
		pages: {
			type: Array,
			default: () => [],
		},

		/** The app's declared flows, `{flow, label?}`, for "start a flow". */
		flows: {
			type: Array,
			default: () => [],
		},

		/** The record's fields a `visibleWhen` condition may test. */
		fieldOptions: {
			type: Array,
			default: () => [],
		},

		/** Fields a lifecycle moves; a "change fields" action may not set them. */
		lifecycleFields: {
			type: Array,
			default: () => ['status'],
		},
	},

	emits: ['update:modelValue'],
	data() {
		return { newKeys: {}, refusals: {}, exportFormats: EXPORT_FORMATS }
	},

	computed: {
		/**
		 * The stored actions with old `target` actions migrated (D3).
		 *
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-old-actions-keep-working-req-bqab-004
		 */
		migration() {
			return migrateActions(this.modelValue, this.pageOptions.map((page) => page.value))
		},

		/**
		 * The actions as edited.
		 *
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
		 */
		localActions() {
			return this.migration.actions
		},

		/**
		 * What a new button can do, in the maker's words.
		 *
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
		 */
		typeOptions() {
			return [
				{ value: 'object-op', label: t('buildiq', 'Change fields on this record') },
				{ value: 'run-node', label: t('buildiq', 'Start a flow') },
				{ value: 'open-form', label: t('buildiq', 'Open a form') },
				{ value: 'open-page', label: t('buildiq', 'Go to a page') },
				{ value: 'navigate', label: t('buildiq', 'Open a link') },
				{ value: 'export', label: t('buildiq', 'Export') },
			]
		},

		/**
		 * The app's pages as options.
		 *
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
		 */
		pageOptions() {
			return (Array.isArray(this.pages) ? this.pages : [])
				.filter((page) => page && page.id)
				.map((page) => ({ value: page.id, label: page.title ? `${page.title} (${page.id})` : page.id }))
		},

		/**
		 * The app's flows as options.
		 *
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
		 */
		flowOptions() {
			return (Array.isArray(this.flows) ? this.flows : [])
				.filter((flow) => flow && flow.flow)
				.map((flow) => ({ value: flow.flow, label: flow.label || flow.flow }))
		},
	},

	methods: {
		/**
		 * Whether an action may be edited here (no type yet, or an offered type).
		 *
		 * @param {object} act - The action.
		 * @return {boolean}
		 */
		editable(act) {
			return !act.type || isEditableType(act.type)
		},

		/**
		 * What is missing for the action to be saved, translated, or ''.
		 *
		 * @param {object} act - The action.
		 * @return {string}
		 */
		missing(act) {
			const message = missingFor(act)
			return message === '' ? '' : t('buildiq', message)
		},

		/**
		 * Whether a run-node action names a flow the app no longer declares.
		 *
		 * @param {object} act - The action.
		 * @return {boolean}
		 */
		flowMissing(act) {
			return Boolean(act.flowId) && this.flowOptions.length > 0
				&& !this.flowOptions.some((flow) => flow.value === act.flowId)
		},

		/**
		 * Set or clear one key of an action; an empty value deletes the key,
		 * except `id`, which is always written.
		 *
		 * @param {number} index - Position of the action.
		 * @param {string} key - The key.
		 * @param {*} value - The new value.
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
		 */
		updateField(index, key, value) {
			const next = this.localActions.slice()
			const current = next[index] || {}
			if (value === '' && key !== 'id') {
				const { [key]: _omit, ...rest } = current
				next[index] = rest
			} else {
				next[index] = { ...current, [key]: value }
			}
			this.$emit('update:modelValue', next)
		},

		/**
		 * Switch an action to another type, keeping only what the type allows.
		 *
		 * @param {number} index - Position of the action.
		 * @param {string} type - The new type, '' for none.
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-the-action-editor-authors-typed-actions-req-bqab-001
		 */
		setType(index, type) {
			const next = this.localActions.slice()
			const typed = withType(next[index] || {}, type)
			if (type === '') {
				delete typed.type
			}
			next[index] = typed
			this.clearRefusal(index)
			this.$emit('update:modelValue', next)
		},

		/**
		 * Forget a refusal shown for one action.
		 *
		 * @param {number} index - Position of the action.
		 */
		clearRefusal(index) {
			const { [index]: _omit, ...rest } = this.refusals
			this.refusals = rest
		},

		/**
		 * Write the `values` of an object-op, refusing a lifecycle field (D2).
		 *
		 * @param {number} index - Position of the action.
		 * @param {object} values - The next values.
		 * @return {boolean} Whether the values were written.
		 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md#requirement-status-buttons-follow-the-lifecycle-req-bqab-003
		 */
		writeValues(index, values) {
			const candidate = { ...this.localActions[index], values }
			const field = lifecycleFieldPatched(candidate, this.lifecycleFields)
			if (field !== null) {
				this.refusals = {
					...this.refusals,
					[index]: t('buildiq', '{field} moves through the lifecycle. Use a status button instead.', { field }),
				}
				return false
			}
			this.clearRefusal(index)
			const next = this.localActions.slice()
			next[index] = candidate
			this.$emit('update:modelValue', next)
			return true
		},

		/**
		 * Add the field typed in the "Field to set" box.
		 *
		 * @param {number} index - Position of the action.
		 */
		addValue(index) {
			const key = String(this.newKeys[index] || '').trim()
			if (key === '') {
				return
			}
			if (this.writeValues(index, { ...(this.localActions[index].values || {}), [key]: '' })) {
				this.newKeys = { ...this.newKeys, [index]: '' }
			}
		},

		/**
		 * Set one value; "true", "false" and numbers are stored as such.
		 *
		 * @param {number} index - Position of the action.
		 * @param {string} key - The field.
		 * @param {string} raw - The typed value.
		 */
		setValue(index, key, raw) {
			let value = raw
			if (raw === 'true' || raw === 'false') {
				value = raw === 'true'
			} else if (raw.trim() !== '' && !Number.isNaN(Number(raw))) {
				value = Number(raw)
			}
			this.writeValues(index, { ...(this.localActions[index].values || {}), [key]: value })
		},

		/**
		 * Remove one field from the values.
		 *
		 * @param {number} index - Position of the action.
		 * @param {string} key - The field.
		 */
		removeValue(index, key) {
			const { [key]: _omit, ...rest } = this.localActions[index].values || {}
			this.writeValues(index, rest)
		},

		/**
		 * Offer or withdraw one export format.
		 *
		 * @param {number} index - Position of the action.
		 * @param {string} format - The format.
		 * @param {boolean} on - Whether it is offered.
		 */
		toggleFormat(index, format, on) {
			const current = (this.localActions[index].formats || []).filter((f) => f !== format)
			const formats = on ? [...current, format] : current
			this.updateField(index, 'formats', formats.length > 0 ? formats : '')
		},

		/**
		 * Append an empty action.
		 */
		addAction() {
			this.$emit('update:modelValue', [...this.localActions, { id: '', label: '' }])
		},

		/**
		 * Drop one action.
		 *
		 * @param {number} index - Position of the action.
		 */
		removeAction(index) {
			const next = this.localActions.slice()
			next.splice(index, 1)
			this.clearRefusal(index)
			this.$emit('update:modelValue', next)
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.action-builder {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.action-builder__action {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-bottom: 6px;
	border-bottom: 1px solid var(--color-border);
}

.action-builder__row {
	display: flex;
	gap: 6px;
	align-items: center;
	flex-wrap: wrap;
}

.action-builder__detail {
	padding-inline-start: 12px;
}

.action-builder__field {
	flex: 1 1 auto;
	@include c.control;
}

.action-builder__field--narrow {
	flex: 0 0 110px;
}

.action-builder__key,
.action-builder__caption,
.action-builder__readonly,
.action-builder__note,
.action-builder__warn {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.action-builder__error {
	color: var(--color-error-text);
	margin: 0;
}

.action-builder__inline {
	display: inline-flex;
	gap: 4px;
	align-items: center;
}

.action-builder__remove {
	@include c.danger-button;
}

.action-builder__add {
	align-self: flex-start;
	@include c.add-button;
}
</style>
