<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - IndexViewModesBuilder — which views an index page offers (table, cards,
  - calendar, board, date axis) and, per view that needs them, the schema
  - fields it is drawn from. Writes `config.viewModes` (table always kept) and
  - `config.calendar`, `config.board`, `config.dateAxis`. Unticking a view
  - removes its block. REQ-PEC-008, REQ-PEC-010.
  -->
<template>
	<div class="index-view-modes">
		<div class="index-view-modes__modes">
			<label v-for="mode in modes" :key="mode.id" class="index-view-modes__mode">
				<input
					type="checkbox"
					:data-mode="mode.id"
					:checked="offered.includes(mode.id)"
					:disabled="mode.id === 'table'"
					@change="toggle(mode.id, $event.target.checked)" />
				{{ mode.label }}
			</label>
		</div>

		<div
			v-for="mode in fieldedModes"
			:key="mode"
			class="index-view-modes__fields"
			:data-fields="mode">
			<div v-for="field in fieldsOf(mode)" :key="field.key" class="index-view-modes__field">
				<NcSelect
					:inputLabel="field.label"
					:modelValue="valueOf(mode, field.key)"
					:options="optionsFor(field.kind)"
					:clearable="true"
					:placeholder="field.required ? t('buildiq', 'Pick a field') : t('buildiq', 'None')"
					:data-field="mode + '.' + field.key"
					@update:modelValue="setField(mode, field.key, $event)" />
				<InlineFieldMark :error="findingFor(mode, field.key)" />
			</div>
		</div>
	</div>
</template>

<script>
import { NcSelect } from '@nextcloud/vue'
import InlineFieldMark from './InlineFieldMark.vue'
import { resolveValidationMessage } from '../../../services/manifestValidation/connectorErrorMessages.js'
import {
	propertiesOfKind,
	validateIndexViewConfig,
	VIEW_FIELDS,
	VIEW_MODES,
	viewModesOf,
} from '../../../services/manifestValidation/indexViews.js'

export default {
	name: 'IndexViewModesBuilder',
	components: { NcSelect, InlineFieldMark },

	props: {
		/** The index page's config. */
		config: {
			type: Object,
			default: () => ({}),
		},

		/** The page schema's JSON-Schema `properties` map. */
		schemaProperties: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:config'],

	computed: {
		/**
		 * The view modes with their labels, in editor order.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-calendar-view-req-pec-008
		 */
		modes() {
			const labels = {
				table: t('buildiq', 'Table'),
				cards: t('buildiq', 'Cards'),
				calendar: t('buildiq', 'Calendar'),
				board: t('buildiq', 'Board'),
				dateAxis: t('buildiq', 'Date axis'),
			}
			return VIEW_MODES.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The modes the page offers now.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-calendar-view-req-pec-008
		 */
		offered() {
			return viewModesOf(this.config)
		},

		/**
		 * The offered modes that need fields.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
		 */
		fieldedModes() {
			return this.offered.filter((mode) => VIEW_FIELDS[mode])
		},

		/**
		 * The findings for these settings, checked against the schema when it is loaded.
		 *
		 * @return {string[]}
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
		 */
		findings() {
			const properties = Object.keys(this.schemaProperties || {}).length > 0 ? this.schemaProperties : null
			return validateIndexViewConfig(this.config, '', properties)
		},
	},

	methods: {
		/**
		 * The fields one mode is drawn from, with their labels and the kind of property each takes.
		 *
		 * @param {string} mode - calendar, board or dateAxis.
		 * @return {Array<{key: string, label: string, kind: string, required: boolean}>}
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
		 */
		fieldsOf(mode) {
			const all = {
				calendar: [
					{ key: 'dateField', label: t('buildiq', 'Calendar date field'), kind: 'date' },
					{ key: 'endDateField', label: t('buildiq', 'Calendar end date field (optional)'), kind: 'date' },
					{ key: 'titleField', label: t('buildiq', 'Calendar title field (optional)'), kind: 'text' },
				],

				board: [
					{ key: 'statusField', label: t('buildiq', 'Board status field'), kind: 'status' },
					{ key: 'swimlaneField', label: t('buildiq', 'Board swimlane field (optional)'), kind: 'any' },
				],

				dateAxis: [
					{ key: 'startField', label: t('buildiq', 'Date axis start field'), kind: 'date' },
					{ key: 'endField', label: t('buildiq', 'Date axis end field'), kind: 'date' },
					{ key: 'laneField', label: t('buildiq', 'Date axis lane field (optional)'), kind: 'any' },
				],
			}
			return (all[mode] || []).map((field) => ({
				...field,
				required: VIEW_FIELDS[mode].required.includes(field.key),
			}))
		},

		/**
		 * The property names a field may be picked from.
		 *
		 * @param {string} kind - date, status, text or any.
		 * @return {string[]}
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
		 */
		optionsFor(kind) {
			return propertiesOfKind(this.schemaProperties, kind)
		},

		/**
		 * The field currently set for a mode, or null.
		 *
		 * @param {string} mode - the view mode.
		 * @param {string} key - the field key.
		 * @return {string|null}
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
		 */
		valueOf(mode, key) {
			const block = this.config && this.config[mode]
			return (block && typeof block[key] === 'string' && block[key]) || null
		},

		/**
		 * The inline mark for one field, from the findings.
		 *
		 * @param {string} mode - the view mode.
		 * @param {string} key - the field key.
		 * @return {{hasError: boolean, message: string}}
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-calendar-view-req-pec-008
		 */
		findingFor(mode, key) {
			const prefix = `/${mode}/${key}: `
			const hit = this.findings.find((finding) => finding.startsWith(prefix))
			if (!hit) {
				return { hasError: false, message: '' }
			}
			return { hasError: true, message: resolveValidationMessage(hit).slice(prefix.length) }
		},

		/**
		 * Offer a view or stop offering it. Unticking removes its settings block.
		 *
		 * @param {string} mode - the view mode.
		 * @param {boolean} on - whether the page offers it.
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-calendar-view-req-pec-008
		 */
		toggle(mode, on) {
			if (mode === 'table') {
				return
			}
			const next = { ...this.config }
			const offered = this.offered.filter((entry) => entry !== mode)
			if (on) {
				offered.push(mode)
			} else {
				delete next[mode]
			}
			const ordered = VIEW_MODES.filter((entry) => offered.includes(entry))
			if (ordered.length <= 1) {
				delete next.viewModes
			} else {
				next.viewModes = ordered
			}
			this.$emit('update:config', next)
		},

		/**
		 * Set or clear one field of a view's settings block.
		 *
		 * @param {string} mode - the view mode.
		 * @param {string} key - the field key.
		 * @param {string|null} value - the property name, or null to clear it.
		 * @spec openspec/changes/pages-index-calendar-and-manual-order/specs/page-editor-coverage/spec.md#requirement-an-index-page-can-offer-a-board-or-a-date-axis-view-req-pec-010
		 */
		setField(mode, key, value) {
			const next = { ...this.config }
			const block = { ...((typeof next[mode] === 'object' && next[mode]) || {}) }
			if (typeof value === 'string' && value !== '') {
				block[key] = value
			} else {
				delete block[key]
			}
			if (Object.keys(block).length === 0) {
				delete next[mode]
			} else {
				next[mode] = block
			}
			this.$emit('update:config', next)
		},
	},
}
</script>

<style scoped>
.index-view-modes {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.index-view-modes__modes {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.index-view-modes__mode {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	font-size: 13px;
}

.index-view-modes__fields {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding-inline-start: 12px;
	border-inline-start: 2px solid var(--color-border);
}

.index-view-modes__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
</style>
