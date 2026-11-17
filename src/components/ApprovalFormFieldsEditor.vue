<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - ApprovalFormFieldsEditor — the fields an approver fills in before
  - approving (logic-approval-task-form, design D1, REQ-BQTF-001). A picker
  - over the trigger schema's askable properties, and per chosen field a row
  - with its name, a "Required" switch, move up, move down and remove.
  -->
<template>
	<div class="approval-form-fields" data-testid="approval-form-fields">
		<NcSelect
			:modelValue="null"
			:inputLabel="t('buildiq', 'Fields the approver fills in')"
			:options="availableOptions"
			label="label"
			data-testid="approval-form-field-select"
			@update:modelValue="addField" />
		<p class="approval-form-fields__hint">
			{{ t('buildiq', 'Leave empty: the approver only approves or rejects.') }}
		</p>
		<ol v-if="rows.length > 0" class="approval-form-fields__list">
			<li
				v-for="(row, index) in rows"
				:key="row.field"
				class="approval-form-fields__row"
				data-testid="approval-form-field-row">
				<span class="approval-form-fields__name">{{ labelOf(row.field) }}</span>
				<NcCheckboxRadioSwitch
					type="switch"
					:modelValue="row.required"
					@update:modelValue="setRequired(index, $event)">
					{{ t('buildiq', 'Required') }}
				</NcCheckboxRadioSwitch>
				<NcButton
					variant="tertiary"
					:disabled="index === 0"
					:aria-label="t('buildiq', 'Move up')"
					data-testid="approval-form-field-up"
					@click="move(index, -1)">
					↑
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="index === rows.length - 1"
					:aria-label="t('buildiq', 'Move down')"
					data-testid="approval-form-field-down"
					@click="move(index, 1)">
					↓
				</NcButton>
				<NcButton
					variant="tertiary"
					:aria-label="t('buildiq', 'Remove field')"
					data-testid="approval-form-field-remove"
					@click="remove(index)">
					✕
				</NcButton>
			</li>
		</ol>
	</div>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcSelect } from '@nextcloud/vue'
import { moved } from '../dialogs/approvalFormFields.js'

export default {
	name: 'ApprovalFormFieldsEditor',
	components: { NcButton, NcCheckboxRadioSwitch, NcSelect },

	props: {
		modelValue: { type: Array, default: () => [] },
		options: { type: Array, default: () => [] },
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The field rows, `[]` for a missing list.
		 *
		 * @return {Array<{field: string, required: boolean}>}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
		 */
		rows() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		/**
		 * The picker's options: the askable fields not chosen yet.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
		 */
		availableOptions() {
			const chosen = new Set(this.rows.map((row) => row.field))
			return this.options.filter((option) => !chosen.has(option.value))
		},
	},

	methods: {
		/**
		 * The display label of a chosen field: its option label, else its name.
		 *
		 * @param {string} field - the field name.
		 * @return {string}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
		 */
		labelOf(field) {
			const option = this.options.find((o) => o.value === field)
			return option ? option.label : field
		},

		/**
		 * Append a picked field as an optional row; a repeat is ignored.
		 *
		 * @param {?{value: string}} option - the picked option.
		 * @return {void}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
		 */
		addField(option) {
			if (!option || !option.value || this.rows.some((row) => row.field === option.value)) {
				return
			}
			this.$emit('update:modelValue', [...this.rows, { field: option.value, required: false }])
		},

		/**
		 * Switch one row's `required` flag.
		 *
		 * @param {number} index - the row.
		 * @param {boolean} required - the new flag.
		 * @return {void}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
		 */
		setRequired(index, required) {
			const next = this.rows.slice()
			next[index] = { ...next[index], required: required === true }
			this.$emit('update:modelValue', next)
		},

		/**
		 * Move one row up (-1) or down (1).
		 *
		 * @param {number} index - the row.
		 * @param {number} offset - the direction.
		 * @return {void}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
		 */
		move(index, offset) {
			this.$emit('update:modelValue', moved(this.rows, index, offset))
		},

		/**
		 * Remove one row.
		 *
		 * @param {number} index - the row.
		 * @return {void}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001
		 */
		remove(index) {
			this.$emit('update:modelValue', this.rows.filter((row, i) => i !== index))
		},
	},
}
</script>

<style scoped>
.approval-form-fields {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.approval-form-fields__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.approval-form-fields__list {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.approval-form-fields__row {
	display: flex;
	align-items: center;
	gap: 6px;
}

.approval-form-fields__name {
	flex: 1 1 auto;
}
</style>
