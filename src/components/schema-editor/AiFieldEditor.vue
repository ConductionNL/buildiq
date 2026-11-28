<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - AiFieldEditor: the AI fields section of the schema designer
  - (ai-llm-steps-and-computed-fields D5, REQ-BQLS-004). An AI field is a
  - read-only property the AI fills from other fields of the same record. The
  - editor declares the property on the staged schema, and after the schema is
  - saved it writes, or removes, the `origin: ai-field` automation that fills
  - it. Those automations are edited here, not in the automations list.
  -->
<template>
	<section class="buildiq-ai-field-editor" data-testid="ai-field-editor">
		<header class="buildiq-ai-field-editor__header">
			<h3>{{ t('buildiq', 'AI fields') }}</h3>
		</header>

		<NcNoteCard v-if="nodeChecked && !nodeAvailable" type="info" data-testid="ai-field-no-node">
			{{ noNodeReason }}
		</NcNoteCard>
		<p v-else class="buildiq-ai-field-editor__hint">
			{{ t('buildiq', 'The AI fills these fields when a record is created and again when one of their source fields changes. Only the source fields are sent to the AI.') }}
		</p>

		<ul class="buildiq-ai-field-editor__list">
			<li
				v-for="row in visibleRows"
				:key="row.key"
				class="buildiq-ai-field-editor__item"
				data-testid="ai-field-row">
				<strong>{{ row.field }}</strong>
				<span class="buildiq-ai-field-editor__meta">
					{{ operationLabel(row.operation) }} · {{ summaryOf(row) }}
				</span>
				<span v-if="!row.uuid" class="buildiq-ai-field-editor__meta">
					{{ t('buildiq', 'Saved with the schema.') }}
				</span>
				<NcButton
					variant="tertiary"
					:ariaLabel="t('buildiq', 'Remove the AI field {name}', { name: row.field })"
					data-testid="ai-field-remove"
					@click="removeRow(row)">
					{{ t('buildiq', 'Remove') }}
				</NcButton>
			</li>
		</ul>

		<div v-if="draft" class="buildiq-ai-field-editor__draft" data-testid="ai-field-draft">
			<NcTextField
				:modelValue="draft.field"
				:label="t('buildiq', 'Field name')"
				data-testid="ai-field-name"
				@update:modelValue="setDraft('field', $event)" />
			<NcSelect
				:modelValue="operationOptions.find((o) => o.value === draft.operation)"
				:inputLabel="t('buildiq', 'What the AI does')"
				:options="operationOptions"
				:clearable="false"
				label="label"
				data-testid="ai-field-operation"
				@update:modelValue="setDraft('operation', $event ? $event.value : 'summarise')" />
			<NcSelect
				:modelValue="sourceOptions.filter((o) => draft.sourceFields.includes(o.value))"
				:inputLabel="t('buildiq', 'Fields the AI reads')"
				:options="sourceOptions"
				:multiple="true"
				label="label"
				data-testid="ai-field-sources"
				@update:modelValue="setDraft('sourceFields', namesOf(($event || []).map((o) => o.value)))" />
			<NcSelect
				v-if="agentOptions.length > 0"
				:modelValue="agentOptions.find((o) => o.id === draft.agent) || null"
				:inputLabel="t('buildiq', 'Hermiq agent')"
				:options="agentOptions"
				label="label"
				data-testid="ai-field-agent"
				@update:modelValue="setDraft('agent', $event ? $event.id : '')" />
			<NcTextField
				v-else
				:modelValue="draft.agent"
				:label="t('buildiq', 'Hermiq agent id')"
				data-testid="ai-field-agent-text"
				@update:modelValue="setDraft('agent', $event)" />
			<NcTextField
				v-if="draft.operation === 'translate'"
				:modelValue="draft.language"
				:label="t('buildiq', 'Translate into')"
				data-testid="ai-field-language"
				@update:modelValue="setDraft('language', $event)" />
			<NcTextField
				v-if="draft.operation === 'categorise'"
				:modelValue="draft.labels.join(', ')"
				:label="t('buildiq', 'Labels, separated by commas')"
				data-testid="ai-field-labels"
				@update:modelValue="setDraft('labels', namesOf(String($event || '').split(',')))" />
			<NcTextArea
				v-if="draft.operation === 'prompt'"
				:modelValue="draft.prompt"
				:label="t('buildiq', 'Instruction')"
				data-testid="ai-field-prompt"
				@update:modelValue="setDraft('prompt', $event)" />
			<p v-if="draftProblem" role="alert" class="buildiq-ai-field-editor__refusal" data-testid="ai-field-problem">
				{{ draftProblem }}
			</p>
			<div class="buildiq-ai-field-editor__actions">
				<NcButton
					variant="primary"
					:disabled="draftProblem !== ''"
					data-testid="ai-field-add-confirm"
					@click="confirmDraft">
					{{ t('buildiq', 'Add AI field') }}
				</NcButton>
				<NcButton variant="tertiary" @click="draft = null">
					{{ t('buildiq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
		<NcButton
			v-else
			:disabled="!nodeAvailable"
			:title="nodeAvailable ? '' : noNodeReason"
			data-testid="ai-field-add"
			@click="startDraft">
			{{ t('buildiq', 'Add an AI field') }}
		</NcButton>

		<p v-if="errorMessage" role="alert" class="buildiq-ai-field-editor__refusal" data-testid="ai-field-error">
			{{ errorMessage }}
		</p>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import {
	aiFieldIncomplete,
	aiFieldProperty,
	aiFieldStep,
	aiStepSummary,
	fetchAgentNodeAvailable,
	fetchToolFreeAgents,
	missingNodeReason,
	namesOf,
	operationOfStep,
} from '../../services/aiSteps.js'
import { slugify } from '../../utils/slugify.js'

let rowCounter = 0

/**
 * A fresh row key.
 *
 * @return {string}
 */
function nextRowKey() {
	rowCounter += 1
	return `ai-field-${rowCounter}`
}

export default {
	name: 'AiFieldEditor',
	components: { NcButton, NcNoteCard, NcSelect, NcTextArea, NcTextField },

	props: {
		schemaSlug: { type: String, default: '' },
		applicationSlug: { type: String, default: '' },
		versionUuid: { type: String, default: '' },
		fieldNames: { type: Array, default: () => [] },
	},

	emits: ['declare', 'remove'],

	data() {
		return {
			rows: [],
			nodeAvailable: false,
			nodeChecked: false,
			agentOptions: [],
			draft: null,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-ai-steps-need-the-hermiq-node-req-bqls-005
		 */
		noNodeReason() {
			return missingNodeReason()
		},

		/**
		 * The rows that are not waiting to be removed.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		visibleRows() {
			return this.rows.filter((row) => !row.removed)
		},

		/**
		 * The operations of an AI field (D5).
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		operationOptions() {
			return [
				{ value: 'translate', label: t('buildiq', 'Translate') },
				{ value: 'categorise', label: t('buildiq', 'Categorise') },
				{ value: 'clean', label: t('buildiq', 'Clean up the text') },
				{ value: 'sentiment', label: t('buildiq', 'Sentiment') },
				{ value: 'summarise', label: t('buildiq', 'Summarise') },
				{ value: 'prompt', label: t('buildiq', 'Own instruction') },
			]
		},

		/**
		 * The fields an AI field can read: the schema's other fields.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		sourceOptions() {
			const aiFields = this.visibleRows.map((row) => row.field)
			return namesOf(this.fieldNames)
				.filter((name) => !aiFields.includes(name) && (!this.draft || name !== this.draft.field))
				.map((name) => ({ value: name, label: name }))
		},

		/**
		 * What the draft still needs, or ''.
		 *
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		draftProblem() {
			if (!this.draft) {
				return ''
			}
			const taken = namesOf(this.fieldNames).includes(this.draft.field)
				|| this.visibleRows.some((row) => row.field === this.draft.field)
			if (taken) {
				return t('buildiq', 'This schema already has a field {name}.', { name: this.draft.field })
			}
			return aiFieldIncomplete(this.draft)
		},
	},

	watch: {
		/**
		 * Reload when the designer opens another schema.
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		schemaSlug() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		namesOf,

		/**
		 * @param {string} operation - the operation id.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		operationLabel(operation) {
			const option = this.operationOptions.find((o) => o.value === operation)
			return option ? option.label : operation
		},

		/**
		 * @param {object} row - an AI field row.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		summaryOf(row) {
			return aiStepSummary(row)
		},

		/**
		 * Read the node catalogue, the agents and this schema's AI field automations.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		async load() {
			this.errorMessage = ''
			this.nodeAvailable = await fetchAgentNodeAvailable()
			this.nodeChecked = true
			if (this.nodeAvailable) {
				try {
					this.agentOptions = await fetchToolFreeAgents()
				} catch {
					this.agentOptions = []
				}
			}
			if (!this.schemaSlug || !this.versionUuid) {
				this.rows = []
				return
			}
			try {
				const { data } = await axios.get(generateUrl('/apps/openregister/api/objects/buildiq/automation'), {
					params: { _limit: 200, applicationSlug: this.applicationSlug, versionUuid: this.versionUuid, origin: 'ai-field' },
				})
				const list = Array.isArray(data && data.results) ? data.results : (Array.isArray(data) ? data : [])
				this.rows = list
					.filter((a) => a && a.origin === 'ai-field' && a.versionUuid === this.versionUuid
						&& a.trigger && a.trigger.schema === this.schemaSlug)
					.map((a) => this.rowOf(a))
					.filter((row) => row !== null)
			} catch {
				this.rows = []
				this.errorMessage = t('buildiq', 'Could not load the AI fields.')
			}
		},

		/**
		 * The row of a stored AI field automation, or null when it holds no AI step.
		 *
		 * @param {object} automation - the stored automation.
		 * @return {?object}
		 */
		rowOf(automation) {
			const step = (automation.actions || []).find((a) => a && a.type === 'ai-step')
			if (!step) {
				return null
			}
			return {
				key: nextRowKey(),
				uuid: automation.id || automation.uuid || (automation['@self'] && automation['@self'].id) || '',
				field: step.targetField || namesOf(step.fields)[0] || '',
				operation: operationOfStep(step),
				sourceFields: namesOf(step.sourceFields),
				step,
				removed: false,
			}
		},

		/** @return {void} */
		startDraft() {
			this.draft = { field: '', operation: 'summarise', sourceFields: [], agent: '', language: '', labels: [], prompt: '' }
		},

		/**
		 * @param {string} key - the draft key.
		 * @param {string|Array<string>} value - its new value.
		 * @return {void}
		 */
		setDraft(key, value) {
			this.draft = { ...this.draft, [key]: key === 'field' ? String(value || '').trim() : value }
		},

		/**
		 * Declare the read-only property on the staged schema and queue the automation.
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		confirmDraft() {
			if (this.draftProblem !== '') {
				return
			}
			const step = aiFieldStep(this.draft)
			this.$emit('declare', { name: this.draft.field, property: aiFieldProperty(this.draft) })
			this.rows.push({
				key: nextRowKey(),
				uuid: '',
				field: this.draft.field,
				operation: this.draft.operation,
				sourceFields: step.sourceFields,
				step,
				removed: false,
			})
			this.draft = null
		},

		/**
		 * Take the property off the staged schema; a saved automation is removed with the schema.
		 *
		 * @param {object} row - the row.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		removeRow(row) {
			this.$emit('remove', row.field)
			if (row.uuid) {
				row.removed = true
				return
			}
			this.rows = this.rows.filter((r) => r !== row)
		},

		/**
		 * After the schema saved: write each new AI field's automation and
		 * remove each removed one, with its flow (D5). Called by the designer.
		 *
		 * @return {Promise<boolean>} Whether every write succeeded.
		 * @spec openspec/changes/archive/2026-11-24-ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		 */
		async persist() {
			this.errorMessage = ''
			const base = generateUrl('/apps/buildiq/api/automations')
			let ok = true
			for (const row of this.rows.slice()) {
				try {
					if (row.removed) {
						await axios.delete(`${base}/${row.uuid}`)
						this.rows = this.rows.filter((r) => r !== row)
					} else if (!row.uuid) {
						row.uuid = await this.createAutomation(base, row)
					}
				} catch (error) {
					ok = false
					const response = error && error.response
					this.errorMessage = (response && response.data && response.data.detail)
						|| t('buildiq', 'Could not save the AI field {name}.', { name: row.field })
				}
			}
			return ok
		},

		/**
		 * Create and compile the automation of one AI field.
		 *
		 * @param {string} base - the automations endpoint.
		 * @param {object} row - the row.
		 * @return {Promise<string>} The new automation's uuid.
		 */
		async createAutomation(base, row) {
			const { data } = await axios.post(base, {
				slug: slugify(`ai-field-${this.schemaSlug}-${row.field}`).slice(0, 64).replace(/-+$/, ''),
				name: t('buildiq', 'AI field {name}', { name: row.field }),
				applicationSlug: this.applicationSlug,
				versionUuid: this.versionUuid,
				enabled: true,
				origin: 'ai-field',
				trigger: { type: 'object-created', schema: this.schemaSlug },
				condition: null,
				actions: [row.step],
			})
			const uuid = (data && (data.id || data.uuid || (data['@self'] && data['@self'].id))) || ''
			if (uuid) {
				await axios.post(`${base}/${uuid}/compile`, {})
			}
			return uuid
		},
	},
}
</script>

<style scoped>
.buildiq-ai-field-editor {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.buildiq-ai-field-editor__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.buildiq-ai-field-editor__item {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.buildiq-ai-field-editor__hint,
.buildiq-ai-field-editor__meta {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.buildiq-ai-field-editor__draft,
.buildiq-ai-field-editor__actions {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.buildiq-ai-field-editor__actions {
	flex-direction: row;
}

.buildiq-ai-field-editor__refusal {
	color: var(--color-error-text);
	margin: 0;
}
</style>
