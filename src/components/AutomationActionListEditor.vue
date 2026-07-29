<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - AutomationActionListEditor — reusable typed-action list editor
  - (automation-approval-steps task 3.1: "on-approve/on-reject nested
  - action-list editors reusing the existing typed-action components").
  -
  - Renders an ordered list of typed actions (send-notification / object-op /
  - webhook — the same field shapes AutomationEditDialog's top-level Actions
  - section already composes) with add/remove controls. Used TWICE inside
  - AutomationEditDialog for an `approval` action's `onApprove` and
  - `onReject` follow-up lists, composed from the SAME typed-action
  - vocabulary as the automation's own top-level actions[] (design.md
  - Decision 3 of automation-approval-steps).
  -->
<template>
	<div class="automation-action-list">
		<div class="automation-action-list__header">
			<span class="automation-action-list__label">{{ label }}</span>
			<NcButton type="tertiary" @click="addAction">
				{{ t('openbuild', 'Add action') }}
			</NcButton>
		</div>

		<p v-if="items.length === 0" class="automation-action-list__hint">
			{{ t('openbuild', 'No follow-up actions.') }}
		</p>

		<div
			v-for="(item, index) in items"
			:key="item._key"
			class="automation-action-list__row"
			data-testid="follow-up-action-row">
			<NcSelect
				:model-value="typeOption(item.type)"
				:input-label="t('openbuild', 'Action type')"
				:options="typeOptions"
				:clearable="false"
				label="label"
				@update:modelValue="onTypeChange(index, $event)" />

			<template v-if="item.type === 'send-notification'">
				<NcTextField
					:model-value="item.subjectEn"
					:label="t('openbuild', 'Subject (English)')"
					@update:modelValue="updateItem(index, 'subjectEn', $event)" />
				<NcTextField
					:model-value="item.subjectNl"
					:label="t('openbuild', 'Subject (Dutch)')"
					@update:modelValue="updateItem(index, 'subjectNl', $event)" />
			</template>

			<template v-else-if="item.type === 'object-op'">
				<NcSelect
					:model-value="operationOption(item.operation)"
					:input-label="t('openbuild', 'Operation')"
					:options="operationOptions"
					:clearable="false"
					label="label"
					@update:modelValue="updateItem(index, 'operation', $event ? $event.value : 'update')" />
				<NcTextField
					:model-value="item.schema"
					:label="t('openbuild', 'Target schema')"
					@update:modelValue="updateItem(index, 'schema', $event)" />
				<NcTextArea
					:model-value="item.fieldMappingText"
					:label="t('openbuild', 'Field mapping (JSON)')"
					@update:modelValue="updateItem(index, 'fieldMappingText', $event)" />
			</template>

			<template v-else-if="item.type === 'webhook'">
				<NcTextField
					:model-value="item.url"
					:label="t('openbuild', 'Webhook URL')"
					@update:modelValue="updateItem(index, 'url', $event)" />
				<NcTextArea
					:model-value="item.payloadTemplateText"
					:label="t('openbuild', 'Payload template (JSON)')"
					@update:modelValue="updateItem(index, 'payloadTemplateText', $event)" />
			</template>

			<NcButton type="error" :aria-label="t('openbuild', 'Remove follow-up action')" @click="removeAction(index)">
				{{ t('openbuild', 'Remove') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'

let keyCounter = 0
function nextKey() {
	keyCounter += 1
	return `follow-up-${keyCounter}`
}

export default {
	name: 'AutomationActionListEditor',
	components: { NcButton, NcSelect, NcTextArea, NcTextField },
	props: {
		/** Stored follow-up action records (send-notification/object-op/webhook shape). */
		modelValue: { type: Array, default: () => [] },
		/** Section label, e.g. "On approve" / "On reject". */
		label: { type: String, required: true },
	},
	emits: ['update:modelValue'],
	data() {
		return {
			items: (this.modelValue || []).map((a) => this.toEditorShape(a)),
		}
	},
	computed: {
		typeOptions() {
			return [
				{ value: 'send-notification', label: t('openbuild', 'Send notification') },
				{ value: 'object-op', label: t('openbuild', 'Create/update an object') },
				{ value: 'webhook', label: t('openbuild', 'Webhook') },
			]
		},
		operationOptions() {
			return [
				{ value: 'create', label: t('openbuild', 'Create') },
				{ value: 'update', label: t('openbuild', 'Update') },
			]
		},
	},
	watch: {
		modelValue(next) {
			// Only resync from an external reset (e.g. the parent dialog's
			// hydrate()) — never while this component's own edits are the
			// source of the change, or every keystroke would rebuild `items`
			// and drop cursor focus.
			if (JSON.stringify(next) === JSON.stringify(this.emittedValue())) {
				return
			}
			this.items = (next || []).map((a) => this.toEditorShape(a))
		},
	},
	methods: {
		/**
		 * Convert a stored follow-up action record to the flat editor shape.
		 *
		 * @param {object} action - the stored action record.
		 * @return {object}
		 */
		toEditorShape(action) {
			const subject = action.subject || {}
			return {
				_key: nextKey(),
				type: action.type || 'send-notification',
				subjectEn: subject.en || '',
				subjectNl: subject.nl || '',
				operation: action.operation || 'update',
				schema: action.schema || '',
				fieldMappingText: JSON.stringify(action.fieldMapping || {}, null, 2),
				url: action.url || '',
				payloadTemplateText: JSON.stringify(action.payloadTemplate || {}, null, 2),
			}
		},
		typeOption(type) {
			return this.typeOptions.find((o) => o.value === type) || this.typeOptions[0]
		},
		operationOption(operation) {
			return this.operationOptions.find((o) => o.value === operation) || this.operationOptions[1]
		},
		addAction() {
			this.items = [...this.items, this.toEditorShape({ type: 'send-notification' })]
			this.emit()
		},
		removeAction(index) {
			this.items = this.items.filter((_, i) => i !== index)
			this.emit()
		},
		onTypeChange(index, option) {
			this.updateItem(index, 'type', option ? option.value : 'send-notification')
		},
		updateItem(index, key, value) {
			this.items = this.items.map((item, i) => (i === index ? { ...item, [key]: value } : item))
			this.emit()
		},
		/**
		 * Build the persisted action-record shape from the editor's flat state.
		 *
		 * @return {Array}
		 */
		emittedValue() {
			return this.items.map((item) => {
				if (item.type === 'send-notification') {
					return { type: 'send-notification', subject: { en: item.subjectEn, nl: item.subjectNl } }
				}
				if (item.type === 'object-op') {
					let fieldMapping = {}
					try {
						fieldMapping = JSON.parse(item.fieldMappingText || '{}')
					} catch (e) {
						fieldMapping = {}
					}
					const objectOp = { type: 'object-op', operation: item.operation, schema: item.schema }
					// OpenRegister rejects `{}` (and `null`) for a nested array-item
					// object property — "expects object but got empty ({})" — so an
					// empty mapping must be OMITTED, not sent as an empty object.
					if (Object.keys(fieldMapping).length > 0) {
						objectOp.fieldMapping = fieldMapping
					}
					return objectOp
				}
				let payloadTemplate = {}
				try {
					payloadTemplate = JSON.parse(item.payloadTemplateText || '{}')
				} catch (e) {
					payloadTemplate = {}
				}
				const webhook = { type: 'webhook', url: item.url }
				// Same OpenRegister empty-nested-object rejection as fieldMapping above.
				if (Object.keys(payloadTemplate).length > 0) {
					webhook.payloadTemplate = payloadTemplate
				}
				return webhook
			})
		},
		emit() {
			this.$emit('update:modelValue', this.emittedValue())
		},
	},
}
</script>

<style scoped>
.automation-action-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.automation-action-list__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.automation-action-list__label {
	font-weight: bold;
}

.automation-action-list__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.9em;
}

.automation-action-list__row {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}
</style>
