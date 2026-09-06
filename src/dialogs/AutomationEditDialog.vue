<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AutomationEditDialog — standalone dialog (modal-isolation rule) composing
  - TRIGGER + optional CONDITION + ACTIONS for one automation (spec
  - automation-designer REQ-AUTD-002 / REQ-AUTD-003).
  -
  - Schema, transition and synchronization pickers are populated from
  - OpenRegister REST and degrade to free-text ids when a list cannot load
  - (mirrors ConnectorSourcePicker / ScheduleEditDialog). Matrix-invalid
  - combinations (design.md Decision 2 / src/services/automationMatrix.js)
  - are blocked inline with an explanatory message — the automation cannot
  - be saved in an unsupported shape.
  -->
<template>
	<NcModal
		v-if="open"
		size="large"
		:name="
			editing
				? t('buildiq', 'Edit automation')
				: t('buildiq', 'New automation')
		"
		@close="onClose">
		<div class="automation-edit">
			<h2 class="automation-edit__title">
				{{
					editing
						? t('buildiq', 'Edit automation')
						: t('buildiq', 'New automation')
				}}
			</h2>

			<NcTextField
				:modelValue="name"
				:label="t('buildiq', 'Name')"
				@update:modelValue="onNameInput" />
			<p class="automation-edit__hint">
				{{ t('buildiq', 'Identifier') }}:
				<code>{{ derivedSlug || '—' }}</code>
			</p>
			<NcTextField
				:modelValue="description"
				:label="t('buildiq', 'Description (optional)')"
				@update:modelValue="description = $event" />

			<!-- Trigger -->
			<section class="automation-edit__section">
				<h3>{{ t('buildiq', 'Trigger') }}</h3>
				<NcSelect
					v-model="triggerOption"
					:inputLabel="t('buildiq', 'When')"
					:options="triggerOptions"
					:clearable="false"
					label="label"
					@update:modelValue="onTriggerChange" />

				<template
					v-if="isObjectTrigger || triggerType === 'lifecycle-transition'">
					<NcSelect
						v-if="schemaPickerAvailable"
						v-model="schemaOption"
						:inputLabel="t('buildiq', 'Schema')"
						:options="schemaOptions"
						:loading="schemaLoading"
						label="label"
						@update:modelValue="onSchemaSelect" />
					<NcTextField
						v-else
						:modelValue="triggerSchema"
						:label="t('buildiq', 'Schema slug')"
						@update:modelValue="triggerSchema = $event" />
				</template>

				<template v-if="triggerType === 'lifecycle-transition'">
					<NcSelect
						v-if="transitionPickerAvailable"
						v-model="transitionOption"
						:inputLabel="t('buildiq', 'Transition')"
						:options="transitionOptions"
						:loading="transitionLoading"
						label="label" />
					<NcTextField
						v-else
						:modelValue="triggerTransition"
						:label="t('buildiq', 'Transition action name')"
						@update:modelValue="triggerTransition = $event" />
				</template>

				<template v-if="triggerType === 'schedule'">
					<NcSelect
						v-model="cadenceOption"
						:inputLabel="t('buildiq', 'Cadence')"
						:options="cadenceOptions"
						:clearable="false"
						label="label" />
					<NcTextField
						v-if="isCustomCron"
						:modelValue="triggerCron"
						:label="t('buildiq', 'Cron expression (5 fields)')"
						@update:modelValue="triggerCron = $event" />
					<NcTextField
						v-if="isCustomInterval"
						:modelValue="String(triggerInterval)"
						type="number"
						:label="t('buildiq', 'Interval (seconds)')"
						@update:modelValue="triggerInterval = Number($event)" />
				</template>
			</section>

			<!-- Condition -->
			<section class="automation-edit__section">
				<h3>{{ t('buildiq', 'Condition (optional)') }}</h3>
				<NcNoteCard
					v-if="conditionBlockedReason"
					type="warning"
					data-testid="condition-blocked">
					{{ conditionBlockedReason }}
				</NcNoteCard>
				<template v-else>
					<NcSelect
						v-model="conditionKindOption"
						:inputLabel="t('buildiq', 'Condition type')"
						:options="conditionKindOptions"
						label="label" />
					<NcTextField
						v-if="conditionKind === 'feel'"
						:modelValue="conditionExpression"
						:label="t('buildiq', 'FEEL expression')"
						placeholder="payload.amount > 1000"
						@update:modelValue="conditionExpression = $event" />
					<NcTextField
						v-if="conditionKind === 'rule-set'"
						:modelValue="conditionRuleSetSlug"
						:label="t('buildiq', 'Rule set slug')"
						@update:modelValue="conditionRuleSetSlug = $event" />
				</template>
			</section>

			<!-- Actions -->
			<section class="automation-edit__section">
				<div class="automation-edit__section-header">
					<h3>{{ t('buildiq', 'Actions') }}</h3>
					<NcButton @click="addAction">
						{{ t('buildiq', 'Add action') }}
					</NcButton>
				</div>

				<p v-if="actions.length === 0" class="automation-edit__hint">
					{{ t('buildiq', 'No actions yet.') }}
				</p>

				<div
					v-for="(action, index) in actions"
					:key="action._key"
					class="automation-edit__action"
					data-testid="action-row">
					<NcSelect
						:modelValue="actionTypeOption(action.type)"
						:inputLabel="t('buildiq', 'Action type')"
						:options="actionTypeOptions"
						:clearable="false"
						label="label"
						@update:modelValue="onActionTypeChange(index, $event)" />

					<NcNoteCard
						v-if="actionBlockedReason(action.type)"
						type="warning"
						data-testid="action-blocked">
						{{ actionBlockedReason(action.type) }}
					</NcNoteCard>

					<template v-else-if="action.type === 'send-notification'">
						<NcTextField
							:modelValue="action.subjectEn"
							:label="t('buildiq', 'Subject (English)')"
							@update:modelValue="
								updateAction(index, 'subjectEn', $event)
							" />
						<NcTextField
							:modelValue="action.subjectNl"
							:label="t('buildiq', 'Subject (Dutch)')"
							@update:modelValue="
								updateAction(index, 'subjectNl', $event)
							" />
					</template>

					<template v-else-if="action.type === 'run-synchronization'">
						<NcSelect
							v-if="syncPickerAvailable"
							:modelValue="syncOption(action.synchronizationId)"
							:inputLabel="t('buildiq', 'Synchronization')"
							:options="syncOptions"
							:loading="syncLoading"
							label="label"
							@update:modelValue="onSyncSelect(index, $event)" />
						<NcTextField
							v-else
							:modelValue="action.synchronizationId"
							:label="t('buildiq', 'Synchronization id')"
							@update:modelValue="
								updateAction(index, 'synchronizationId', $event)
							" />
					</template>

					<template v-else-if="action.type === 'object-op'">
						<NcSelect
							v-model="objectOpOperationOption[index]"
							:inputLabel="t('buildiq', 'Operation')"
							:options="objectOpOperationOptions"
							:clearable="false"
							label="label"
							@update:modelValue="
								updateAction(
									index,
									'operation',
									$event ? $event.value : 'create',
								)
							" />
						<NcTextField
							:modelValue="action.schema"
							:label="t('buildiq', 'Target schema')"
							@update:modelValue="
								updateAction(index, 'schema', $event)
							" />
						<NcTextArea
							:modelValue="action.fieldMappingText"
							:label="t('buildiq', 'Field mapping (JSON)')"
							@update:modelValue="
								updateAction(index, 'fieldMappingText', $event)
							" />
					</template>

					<template v-else-if="action.type === 'webhook'">
						<NcTextField
							:modelValue="action.url"
							:label="t('buildiq', 'Webhook URL')"
							@update:modelValue="
								updateAction(index, 'url', $event)
							" />
						<NcTextArea
							:modelValue="action.payloadTemplateText"
							:label="t('buildiq', 'Payload template (JSON)')"
							@update:modelValue="
								updateAction(index, 'payloadTemplateText', $event)
							" />
					</template>

					<template v-else-if="action.type === 'approval'">
						<NcSelect
							v-if="groupPickerAvailable"
							:modelValue="groupOption(action.assigneeGroup)"
							:inputLabel="t('buildiq', 'Assignee group')"
							:options="groupOptions"
							:loading="groupLoading"
							label="label"
							@update:modelValue="onGroupSelect(index, $event)" />
						<NcTextField
							v-else
							:modelValue="action.assigneeGroup"
							:label="t('buildiq', 'Assignee group id')"
							@update:modelValue="
								updateAction(index, 'assigneeGroup', $event)
							" />

						<AutomationActionListEditor
							:modelValue="action.onApprove"
							:label="t('buildiq', 'On approve')"
							data-testid="on-approve-editor"
							@update:modelValue="
								updateAction(index, 'onApprove', $event)
							" />
						<AutomationActionListEditor
							:modelValue="action.onReject"
							:label="t('buildiq', 'On reject')"
							data-testid="on-reject-editor"
							@update:modelValue="
								updateAction(index, 'onReject', $event)
							" />
					</template>

					<template v-else-if="action.type === 'generateDocument'">
						<NcSelect
							v-if="templatePickerAvailable"
							:modelValue="templateOption(action.templateId)"
							:inputLabel="t('buildiq', 'Document template')"
							:options="docudeskTemplateOptions"
							:loading="docudeskTemplatesLoading"
							label="label"
							data-testid="generate-document-template-select"
							@update:modelValue="onTemplateSelect(index, $event)" />
						<NcTextField
							v-else
							:modelValue="action.templateId"
							:label="t('buildiq', 'Template id')"
							data-testid="generate-document-template-text"
							@update:modelValue="
								updateAction(index, 'templateId', $event)
							" />
						<NcSelect
							:modelValue="outputModeSelection(action)"
							:inputLabel="t('buildiq', 'Output')"
							:options="outputModeOptions"
							:multiple="true"
							:clearable="false"
							label="label"
							data-testid="generate-document-output-select"
							@update:modelValue="
								onOutputModesSelect(index, $event)
							" />
					</template>

					<NcButton
						variant="error"
						:aria-label="t('buildiq', 'Remove action')"
						@click="removeAction(index)">
						{{ t('buildiq', 'Remove') }}
					</NcButton>
				</div>
			</section>

			<p
				v-if="showValidation && !valid"
				class="automation-edit__error"
				role="alert">
				{{ validationMessage }}
			</p>
			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>

			<div class="automation-edit__actions">
				<NcButton @click="onClose">
					{{ t('buildiq', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="saving" @click="onSave">
					{{ saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcModal,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import AutomationActionListEditor from '../components/AutomationActionListEditor.vue'
import { useAppStatus } from '../composables/useAppStatus.js'
import {
	fetchDocudeskTemplates,
	templateToOption,
} from '../composables/useDocudeskTemplates.js'
import {
	blockedActionReason,
	blockedConditionReason,
	isActionAllowed,
	isConditionAllowed,
} from '../services/automationMatrix.js'

const INTERVAL_PRESETS = Object.freeze([
	{ id: 'hourly', interval: 3600 },
	{ id: 'daily', interval: 86400 },
	{ id: 'weekly', interval: 604800 },
	{ id: 'monthly', interval: 2592000 },
])

let keyCounter = 0
/**
 *
 */
function nextKey() {
	keyCounter += 1
	return `aut-action-${keyCounter}`
}

/**
 * Derive a kebab-case slug from a human label.
 *
 * @param {string} label - the human label.
 * @return {string}
 */
function slugify(label) {
	return String(label || '')
		.toLowerCase()
		.trim()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

export default {
	name: 'AutomationEditDialog',
	components: {
		NcButton,
		NcModal,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
		AutomationActionListEditor,
	},

	props: {
		open: { type: Boolean, default: false },
		automation: { type: Object, default: null },
		register: { type: String, default: '' },
	},

	emits: ['update:open', 'saved'],
	/**
	 * Soft capability check for Docudesk (automation-document-action,
	 * mirrors `docudesk-document-templates` REQ-DDT-005's editor-side
	 * degradation and `ConnectorSourcePicker`'s existing `setup()` usage).
	 *
	 * @spec openspec/changes/automation-document-action/tasks.md#4.2
	 */
	setup() {
		const docudeskStatus = useAppStatus('filinq')
		return { docudeskStatus }
	},

	data() {
		return {
			id: null,
			slug: '',
			name: '',
			description: '',
			triggerType: 'manual',
			triggerSchema: '',
			triggerTransition: '',
			triggerInterval: 86400,
			triggerCron: '',
			cadenceOption: null,
			conditionKindOption: null,
			conditionExpression: '',
			conditionRuleSetSlug: '',
			actions: [],
			objectOpOperationOption: [],
			schemaOptions: [],
			schemaOption: null,
			schemaLoading: false,
			schemaFetchFailed: false,
			transitionOptions: [],
			transitionOption: null,
			transitionLoading: false,
			transitionFetchFailed: false,
			syncOptions: [],
			syncLoading: false,
			syncFetchFailed: false,
			groupOptions: [],
			groupLoading: false,
			groupFetchFailed: false,
			docudeskTemplateOptions: [],
			docudeskTemplatesLoading: false,
			docudeskTemplatesFetchFailed: false,
			saving: false,
			showValidation: false,
			errorMessage: '',
		}
	},

	computed: {
		editing() {
			return !!(this.automation && this.automation.slug)
		},

		derivedSlug() {
			if (this.editing) {
				return this.slug
			}
			return slugify(this.name)
		},

		/**
		 * @spec openspec/specs/automation-designer/spec.md
		 */
		triggerOptions() {
			return [
				{ value: 'object-created', label: t('buildiq', 'Object created') },
				{ value: 'object-updated', label: t('buildiq', 'Object updated') },
				{ value: 'object-deleted', label: t('buildiq', 'Object deleted') },
				{
					value: 'lifecycle-transition',
					label: t('buildiq', 'Lifecycle transition'),
				},
				{ value: 'schedule', label: t('buildiq', 'Cron schedule') },
				{ value: 'manual', label: t('buildiq', 'Manual') },
			]
		},

		triggerOption: {
			get() {
				return (
					this.triggerOptions.find((o) => o.value === this.triggerType)
					|| this.triggerOptions[5]
				)
			},

			set(option) {
				this.triggerType = option ? option.value : 'manual'
			},
		},

		isObjectTrigger() {
			return ['object-created', 'object-updated', 'object-deleted'].includes(
				this.triggerType,
			)
		},

		/**
		 * @spec openspec/specs/automation-designer/spec.md
		 */
		cadenceOptions() {
			return [
				{ id: 'hourly', label: t('buildiq', 'Hourly') },
				{ id: 'daily', label: t('buildiq', 'Daily') },
				{ id: 'weekly', label: t('buildiq', 'Weekly') },
				{ id: 'monthly', label: t('buildiq', 'Monthly') },
				{ id: 'custom-cron', label: t('buildiq', 'Custom (cron)') },
				{
					id: 'custom-interval',
					label: t('buildiq', 'Custom interval (seconds)'),
				},
			]
		},

		isCustomCron() {
			return this.cadenceOption && this.cadenceOption.id === 'custom-cron'
		},

		isCustomInterval() {
			return this.cadenceOption && this.cadenceOption.id === 'custom-interval'
		},

		/**
		 * @spec openspec/specs/automation-designer/spec.md
		 */
		conditionKindOptions() {
			return [
				{ value: 'none', label: t('buildiq', 'None') },
				{ value: 'feel', label: t('buildiq', 'FEEL expression') },
				{ value: 'rule-set', label: t('buildiq', 'Reference a rule set') },
			]
		},

		conditionKind() {
			return this.conditionKindOption ? this.conditionKindOption.value : 'none'
		},

		conditionBlockedReason() {
			return blockedConditionReason(this.triggerType)
		},

		/**
		 * Action type options for the type picker on each action row.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
		 */
		actionTypeOptions() {
			return [
				{
					value: 'send-notification',
					label: t('buildiq', 'Send notification'),
				},
				{
					value: 'run-synchronization',
					label: t('buildiq', 'Run a synchronization'),
				},
				{
					value: 'object-op',
					label: t('buildiq', 'Create/update an object'),
				},
				{ value: 'webhook', label: t('buildiq', 'Webhook') },
				{ value: 'approval', label: t('buildiq', 'Require approval') },
				{
					value: 'generateDocument',
					label: t('buildiq', 'Generate document'),
				},
			]
		},

		/**
		 * Whether Docudesk is available; assume available until the async
		 * soft-check resolves so the live UI does not flash (mirrors
		 * `ConnectorSourcePicker::appAvailable`).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.2
		 */
		docudeskAvailable() {
			return (
				!this.docudeskStatus.checked.value
				|| this.docudeskStatus.available.value
			)
		},

		/**
		 * Output-mode options for the `generateDocument` action's multi-select
		 * (design.md Decision 3 of automation-document-action).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		outputModeOptions() {
			return [
				{ value: 'attach', label: t('buildiq', 'Attach to object') },
				{ value: 'download-link', label: t('buildiq', 'Download link') },
				{ value: 'notify', label: t('buildiq', 'Notify') },
			]
		},

		/**
		 * Whether the live Docudesk template picker is usable, or the field
		 * should degrade to a free-text template id.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		templatePickerAvailable() {
			return (
				!this.docudeskTemplatesFetchFailed
				&& this.docudeskTemplateOptions.length > 0
			)
		},

		/**
		 * @spec openspec/specs/automation-designer/spec.md
		 */
		objectOpOperationOptions() {
			return [
				{ value: 'create', label: t('buildiq', 'Create') },
				{ value: 'update', label: t('buildiq', 'Update') },
			]
		},

		schemaPickerAvailable() {
			return !this.schemaFetchFailed && this.schemaOptions.length > 0
		},

		transitionPickerAvailable() {
			return !this.transitionFetchFailed && this.transitionOptions.length > 0
		},

		syncPickerAvailable() {
			return !this.syncFetchFailed && this.syncOptions.length > 0
		},

		/**
		 * Whether the live NC-group picker is usable (loaded successfully with
		 * at least one option), or the field should degrade to free-text.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
		 */
		groupPickerAvailable() {
			return !this.groupFetchFailed && this.groupOptions.length > 0
		},

		/**
		 * Whether the current shape (trigger + condition + every action) is
		 * savable per the v1 matrix (REQ-AUTD-003), extended by the
		 * `generateDocument` availability + completeness checks
		 * (automation-document-action).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		valid() {
			if (this.derivedSlug === '' || this.name.trim() === '') {
				return false
			}
			if (
				this.conditionKind !== 'none'
				&& !isConditionAllowed(this.triggerType)
			) {
				return false
			}
			if (this.actions.length === 0) {
				return false
			}
			if (
				!this.actions.every((a) => isActionAllowed(this.triggerType, a.type))
			) {
				return false
			}
			if (
				!this.actions.every((a) => this.actionBlockedReason(a.type) === '')
			) {
				return false
			}
			return this.actions
				.filter((a) => a.type === 'generateDocument')
				.every((a) => this.generateDocumentActionValid(a))
		},

		/**
		 * @spec openspec/specs/automation-designer/spec.md
		 */
		validationMessage() {
			if (this.derivedSlug === '' || this.name.trim() === '') {
				return t('buildiq', 'Please enter a name.')
			}
			if (this.actions.length === 0) {
				return t('buildiq', 'Add at least one action.')
			}
			return t(
				'buildiq',
				'Please resolve the highlighted blocked combination(s) before saving.',
			)
		},
	},

	watch: {
		/**
		 * Re-hydrate and refresh every live picker's options whenever the
		 * dialog opens.
		 *
		 * @param {boolean} isOpen - the dialog's new open state.
		 * @return {void}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
		 */
		open(isOpen) {
			if (isOpen) {
				this.hydrate()
				this.fetchSchemas()
				this.fetchSynchronizations()
				this.fetchGroups()
				this.docudeskStatus.check()
				this.fetchDocudeskTemplateOptions()
			}
		},
	},

	methods: {
		/** @spec openspec/changes/automation-designer/tasks.md#5.2 */
		hydrate() {
			this.showValidation = false
			this.errorMessage = ''
			const a = this.automation || {}
			this.id = a.id || a.uuid || null
			this.slug = a.slug || ''
			this.name = a.name || ''
			this.description = a.description || ''

			const trigger = a.trigger || { type: 'manual' }
			this.triggerType = trigger.type || 'manual'
			this.triggerSchema = trigger.schema || ''
			this.triggerTransition = trigger.transition || ''
			this.triggerCron = trigger.cron || ''
			this.triggerInterval = trigger.interval || 86400
			const preset = INTERVAL_PRESETS.find(
				(p) => p.interval === trigger.interval,
			)
			if (trigger.cron) {
				this.cadenceOption = this.cadenceOptions.find(
					(o) => o.id === 'custom-cron',
				)
			} else if (preset) {
				this.cadenceOption = this.cadenceOptions.find(
					(o) => o.id === preset.id,
				)
			} else {
				this.cadenceOption = this.cadenceOptions.find(
					(o) => o.id === 'custom-interval',
				)
			}

			const condition = a.condition || null
			if (!condition) {
				this.conditionKindOption = this.conditionKindOptions[0]
			} else if (condition.type === 'feel') {
				this.conditionKindOption = this.conditionKindOptions[1]
				this.conditionExpression = condition.expression || ''
			} else {
				this.conditionKindOption = this.conditionKindOptions[2]
				this.conditionRuleSetSlug = condition.ruleSetSlug || ''
			}

			this.actions = (Array.isArray(a.actions) ? a.actions : []).map(
				(action) => this.actionToEditor(action),
			)
			this.objectOpOperationOption = this.actions.map(
				(action) =>
					this.objectOpOperationOptions.find(
						(o) => o.value === action.operation,
					) || this.objectOpOperationOptions[0],
			)
		},

		/**
		 * Convert a stored action record into the editor's flat working shape.
		 *
		 * @param {object} action - the stored action record.
		 * @return {object}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
		 */
		actionToEditor(action) {
			const subject = action.subject || {}
			return {
				_key: nextKey(),
				type: action.type,
				subjectEn: subject.en || '',
				subjectNl: subject.nl || '',
				channels: action.channels || ['nc-notification'],
				recipients: action.recipients || [
					{ kind: 'object-acl', permission: 'manage' },
				],

				synchronizationId: action.synchronizationId || '',
				operation: action.operation || 'create',
				schema: action.schema || '',
				fieldMappingText: JSON.stringify(action.fieldMapping || {}, null, 2),
				url: action.url || '',
				payloadTemplateText: JSON.stringify(
					action.payloadTemplate || {},
					null,
					2,
				),

				assigneeGroup: action.assigneeGroup || '',
				onApprove: Array.isArray(action.onApprove) ? action.onApprove : [],
				onReject: Array.isArray(action.onReject) ? action.onReject : [],
				templateId: action.templateId || '',
				output: Array.isArray(action.output)
					? action.output
					: action.output
						? [action.output]
						: [],
			}
		},

		/**
		 * Load the version register's schemas for the schema picker; degrades
		 * to free-text on failure (mirrors BuilderHost's picker).
		 *
		 * @return {Promise<void>}
		 */
		async fetchSchemas() {
			if (!this.register) {
				this.schemaFetchFailed = true
				return
			}
			this.schemaLoading = true
			this.schemaFetchFailed = false
			try {
				const url = generateUrl(
					`/apps/openregister/api/registers/${encodeURIComponent(this.register)}/schemas`,
				)
				const { data } = await axios.get(url)
				const list = Array.isArray(data)
					? data
					: data && Array.isArray(data.results)
						? data.results
						: []
				this.schemaOptions = list.map((s) => ({
					value: s.slug || s.id,
					label: s.title || s.slug || String(s.id),
				}))
				this.schemaOption =
					this.schemaOptions.find((o) => o.value === this.triggerSchema)
					|| null
				if (this.schemaOptions.length === 0) {
					this.schemaFetchFailed = true
				} else if (
					this.triggerType === 'lifecycle-transition'
					&& this.triggerSchema
				) {
					this.fetchTransitions()
				}
			} catch (error) {
				this.schemaFetchFailed = true
				this.schemaOptions = []
			} finally {
				this.schemaLoading = false
			}
		},

		/**
		 * Handle a schema selection from the live picker.
		 *
		 * @param {?object} option - the selected option.
		 * @return {void}
		 */
		onSchemaSelect(option) {
			this.triggerSchema = option ? option.value : ''
			if (this.triggerType === 'lifecycle-transition' && this.triggerSchema) {
				this.fetchTransitions()
			}
		},

		/**
		 * Load the selected schema's `x-openregister-lifecycle` transitions;
		 * degrades to free-text on failure.
		 *
		 * @return {Promise<void>}
		 */
		async fetchTransitions() {
			this.transitionLoading = true
			this.transitionFetchFailed = false
			this.transitionOptions = []
			try {
				const url = generateUrl(
					`/apps/openregister/api/schemas/${encodeURIComponent(this.triggerSchema)}`,
				)
				const { data } = await axios.get(url)
				const lifecycle = (data && data['x-openregister-lifecycle']) || {}
				const transitions = (lifecycle && lifecycle.transitions) || {}
				this.transitionOptions = Object.keys(transitions).map((name) => ({
					value: name,
					label: name,
				}))
				this.transitionOption =
					this.transitionOptions.find(
						(o) => o.value === this.triggerTransition,
					) || null
				if (this.transitionOptions.length === 0) {
					this.transitionFetchFailed = true
				}
			} catch (error) {
				this.transitionFetchFailed = true
			} finally {
				this.transitionLoading = false
			}
		},

		/**
		 * Load OpenConnector synchronizations for the run-synchronization
		 * action picker; degrades to free-text on failure.
		 *
		 * @return {Promise<void>}
		 */
		async fetchSynchronizations() {
			this.syncLoading = true
			this.syncFetchFailed = false
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/openconnector/synchronization',
				)
				const { data } = await axios.get(url, { params: { limit: 500 } })
				const list = Array.isArray(data && data.results)
					? data.results
					: Array.isArray(data)
						? data
						: []
				this.syncOptions = list
					.map((sync) => ({
						id: String(sync.id || sync.uuid),
						label: sync.name || sync.title || sync.id,
					}))
					.filter((o) => o.id && o.id !== 'undefined')
				if (this.syncOptions.length === 0) {
					this.syncFetchFailed = true
				}
			} catch (error) {
				this.syncFetchFailed = true
				this.syncOptions = []
			} finally {
				this.syncLoading = false
			}
		},

		/**
		 * Resolve the selected sync option for an action row.
		 *
		 * @param {string} syncId - the stored synchronization id.
		 * @return {?object}
		 */
		syncOption(syncId) {
			return this.syncOptions.find((o) => o.id === syncId) || null
		},

		onSyncSelect(index, option) {
			this.updateAction(index, 'synchronizationId', option ? option.id : '')
		},

		/**
		 * Load Nextcloud groups for the `approval` action's assignee-group
		 * picker; degrades to free-text on failure (task 3.1). Mirrors the
		 * OCS group-listing call already used elsewhere in the fleet
		 * (e.g. openregister's EditSchema.vue).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
		 */
		async fetchGroups() {
			this.groupLoading = true
			this.groupFetchFailed = false
			try {
				const response = await fetch(
					'/ocs/v1.php/cloud/groups?format=json',
					{
						headers: { 'OCS-APIRequest': 'true' },
					},
				)
				if (!response.ok) {
					throw new Error('OCS groups request failed')
				}
				const data = await response.json()
				const groups =
					(data && data.ocs && data.ocs.data && data.ocs.data.groups) || []
				this.groupOptions = groups.map((gid) => ({ value: gid, label: gid }))
				if (this.groupOptions.length === 0) {
					this.groupFetchFailed = true
				}
			} catch (error) {
				this.groupFetchFailed = true
				this.groupOptions = []
			} finally {
				this.groupLoading = false
			}
		},

		/**
		 * Resolve the selected group option for an approval action row.
		 *
		 * @param {string} groupId - the stored assignee group id.
		 * @return {?object}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
		 */
		groupOption(groupId) {
			return this.groupOptions.find((o) => o.value === groupId) || null
		},

		/**
		 * Apply a group-picker selection to an approval action row.
		 *
		 * @param {number} index - the action's index in `actions`.
		 * @param {?object} option - the selected group option.
		 * @return {void}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
		 */
		onGroupSelect(index, option) {
			this.updateAction(index, 'assigneeGroup', option ? option.value : '')
		},

		/**
		 * Load Docudesk's template list for the `generateDocument` action's
		 * template picker — the SAME shared fetch the Documents-section
		 * builder UI uses (`useDocudeskTemplates.js`), degrading to free-text
		 * on failure/absence.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		async fetchDocudeskTemplateOptions() {
			if (!this.docudeskAvailable) {
				this.docudeskTemplatesFetchFailed = true
				return
			}
			this.docudeskTemplatesLoading = true
			const templates = await fetchDocudeskTemplates()
			this.docudeskTemplateOptions = templates.map(templateToOption)
			this.docudeskTemplatesFetchFailed =
				this.docudeskTemplateOptions.length === 0
			this.docudeskTemplatesLoading = false
		},

		/**
		 * Resolve the selected template option for a `generateDocument`
		 * action row.
		 *
		 * @param {string} templateId - the stored template uuid.
		 * @return {?object}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		templateOption(templateId) {
			return (
				this.docudeskTemplateOptions.find((o) => o.uuid === templateId)
				|| null
			)
		},

		/**
		 * Apply a template-picker selection to a `generateDocument` action row.
		 *
		 * @param {number} index - the action's index in `actions`.
		 * @param {?object} option - the selected template option.
		 * @return {void}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		onTemplateSelect(index, option) {
			this.updateAction(index, 'templateId', option ? option.uuid : '')
		},

		/**
		 * Resolve the selected output-mode options for a `generateDocument`
		 * action row's multi-select.
		 *
		 * @param {object} action - the editor-shape action row.
		 * @return {Array<object>}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		outputModeSelection(action) {
			const modes = Array.isArray(action.output) ? action.output : []
			return this.outputModeOptions.filter((o) => modes.includes(o.value))
		},

		/**
		 * Apply an output-mode multi-select change to a `generateDocument`
		 * action row.
		 *
		 * @param {number} index - the action's index in `actions`.
		 * @param {?Array<object>} options - the selected output-mode options.
		 * @return {void}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		onOutputModesSelect(index, options) {
			const modes = Array.isArray(options) ? options.map((o) => o.value) : []
			this.updateAction(index, 'output', modes)
		},

		/**
		 * Mirrors `AutomationCompilerService::assertGenerateDocumentActions()`
		 * — `templateId` present, `output` non-empty, `notify` never alone —
		 * so the editor blocks an incomplete save with the SAME rule the
		 * backend compiler enforces, rather than only discovering it on a
		 * failed compile.
		 *
		 * @param {object} action - the editor-shape `generateDocument` action.
		 * @return {boolean}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.1
		 */
		generateDocumentActionValid(action) {
			if (!action.templateId) {
				return false
			}
			const modes = Array.isArray(action.output) ? action.output : []
			if (modes.length === 0) {
				return false
			}
			const hasNotify = modes.includes('notify')
			const hasDeliveryMode =
				modes.includes('attach') || modes.includes('download-link')
			return !hasNotify || hasDeliveryMode
		},

		onNameInput(value) {
			this.name = value
		},

		onTriggerChange() {
			if (this.triggerType === 'lifecycle-transition' && this.triggerSchema) {
				this.fetchTransitions()
			}
		},

		actionTypeOption(type) {
			return (
				this.actionTypeOptions.find((o) => o.value === type)
				|| this.actionTypeOptions[0]
			)
		},

		/**
		 * Blocked-combination reason for an action row: the matrix
		 * (trigger/action) reason, OR — for `generateDocument` specifically —
		 * the missing-Docudesk hint (mirrors `docudesk-document-templates`
		 * REQ-DDT-005's degradation posture).
		 *
		 * @param {string} type - the action's type.
		 * @return {string}
		 * @spec openspec/changes/automation-document-action/tasks.md#4.2
		 * @spec openspec/changes/automation-document-action/tasks.md#4.3
		 */
		actionBlockedReason(type) {
			const matrixReason = blockedActionReason(this.triggerType, type)
			if (matrixReason !== '') {
				return matrixReason
			}
			if (type === 'generateDocument' && !this.docudeskAvailable) {
				return t(
					'buildiq',
					'Docudesk is not installed — document-generation actions are unavailable.',
				)
			}
			return ''
		},

		addAction() {
			this.actions.push(this.actionToEditor({ type: 'send-notification' }))
			this.objectOpOperationOption.push(null)
		},

		removeAction(index) {
			this.actions.splice(index, 1)
			this.objectOpOperationOption.splice(index, 1)
		},

		onActionTypeChange(index, option) {
			this.updateAction(
				index,
				'type',
				option ? option.value : 'send-notification',
			)
		},

		updateAction(index, key, value) {
			const next = this.actions.slice()
			next[index] = { ...next[index], [key]: value }
			this.actions = next
		},

		/**
		 * Assemble the persisted `condition` block from the working state.
		 *
		 * @return {?object}
		 */
		buildCondition() {
			if (this.conditionKind === 'feel') {
				return { type: 'feel', expression: this.conditionExpression }
			}
			if (this.conditionKind === 'rule-set') {
				return { type: 'rule-set', ruleSetSlug: this.conditionRuleSetSlug }
			}
			return null
		},

		/**
		 * Assemble the persisted `trigger` block from the working state.
		 *
		 * @return {object}
		 */
		buildTrigger() {
			const trigger = { type: this.triggerType }
			if (
				this.isObjectTrigger
				|| this.triggerType === 'lifecycle-transition'
			) {
				trigger.schema = this.triggerSchema
			}
			if (this.triggerType === 'lifecycle-transition') {
				trigger.transition = this.triggerTransition
			}
			if (this.triggerType === 'schedule') {
				if (this.isCustomCron) {
					trigger.cron = this.triggerCron
				} else if (this.isCustomInterval) {
					trigger.interval = this.triggerInterval
				} else {
					const preset = INTERVAL_PRESETS.find(
						(p) =>
							p.id === (this.cadenceOption && this.cadenceOption.id),
					)
					trigger.interval = preset ? preset.interval : 86400
				}
			}
			return trigger
		},

		/**
		 * Assemble the persisted `actions[]` from the working state.
		 *
		 * @return {Array}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#3.1
		 */
		buildActions() {
			return this.actions.map((action) => {
				if (action.type === 'send-notification') {
					return {
						type: 'send-notification',
						channels: action.channels,
						recipients: action.recipients,
						subject: { en: action.subjectEn, nl: action.subjectNl },
					}
				}
				if (action.type === 'run-synchronization') {
					return {
						type: 'run-synchronization',
						synchronizationId: action.synchronizationId,
					}
				}
				if (action.type === 'object-op') {
					// OpenRegister rejects BOTH an empty object ({}) and null for this
					// nested (array-item) object property, so an object-op with no
					// field mapping must OMIT the key entirely rather than send {}/null.
					let fieldMapping = null
					try {
						const parsed = JSON.parse(action.fieldMappingText || 'null')
						fieldMapping =
							parsed
							&& typeof parsed === 'object'
							&& Object.keys(parsed).length > 0
								? parsed
								: null
					} catch (e) {
						fieldMapping = null
					}
					const objectOp = {
						type: 'object-op',
						operation: action.operation,
						schema: action.schema,
					}
					if (fieldMapping !== null) {
						objectOp.fieldMapping = fieldMapping
					}
					return objectOp
				}
				if (action.type === 'approval') {
					return {
						type: 'approval',
						assigneeGroup: action.assigneeGroup,
						onApprove: Array.isArray(action.onApprove)
							? action.onApprove
							: [],
						onReject: Array.isArray(action.onReject)
							? action.onReject
							: [],
					}
				}
				if (action.type === 'generateDocument') {
					return {
						type: 'generateDocument',
						templateId: action.templateId,
						output: Array.isArray(action.output) ? action.output : [],
					}
				}
				// Same OpenRegister nested-object rule as object-op above: an empty
				// payload template must OMIT the key rather than send {}/null.
				let payloadTemplate = null
				try {
					const parsed = JSON.parse(action.payloadTemplateText || 'null')
					payloadTemplate =
						parsed
						&& typeof parsed === 'object'
						&& Object.keys(parsed).length > 0
							? parsed
							: null
				} catch (e) {
					payloadTemplate = null
				}
				const webhook = { type: 'webhook', url: action.url }
				if (payloadTemplate !== null) {
					webhook.payloadTemplate = payloadTemplate
				}
				return webhook
			})
		},

		/**
		 * Persist the working automation through Buildiq's own write routes.
		 *
		 * This method is the whole reason those routes exist: it used to POST/PUT
		 * `openregister/api/objects/buildiq/automation` directly, which the
		 * `automation` schema's admin-only `create`/`update` ACL refused for every
		 * editor and owner — precisely the people REQ-AUTD-008 says may author one.
		 *
		 * @return {Promise<void>} Resolves once the save (and compile) has settled.
		 *
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-008
		 */
		async onSave() {
			this.showValidation = true
			if (!this.valid) {
				return
			}
			this.saving = true
			this.errorMessage = ''
			const payload = {
				slug: this.derivedSlug,
				name: this.name,
				description: this.description,
				applicationSlug: this.automation.applicationSlug,
				versionUuid: this.automation.versionUuid,
				enabled: this.automation.enabled !== false,
				trigger: this.buildTrigger(),
				condition: this.buildCondition(),
				actions: this.buildActions(),
			}
			try {
				// Buildiq's own endpoints, NOT OR REST directly.
				//
				// The `automation` schema is admin-only on `create`/`update` at
				// the OpenRegister layer, so this dialog used to 403 for every
				// editor and owner — the exact people REQ-AUTD-008 says may
				// author one. These routes authorise against the parent
				// Application's `permissions` block first and then write in
				// system context. See Conduction/buildiq#173.
				const base = generateUrl('/apps/buildiq/api/automations')
				let uuid = this.id
				if (this.editing && this.id) {
					await axios.put(`${base}/${this.id}`, payload)
				} else {
					const { data } = await axios.post(base, payload)
					uuid =
						data
						&& (data.id
							|| data.uuid
							|| (data['@self'] && data['@self'].id))
				}
				if (uuid) {
					await axios.post(
						generateUrl(`/apps/buildiq/api/automations/${uuid}/compile`),
						{},
					)
				}
				this.$emit('saved')
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not save the automation.')
			} finally {
				this.saving = false
			}
		},

		onClose() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.automation-edit {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	min-width: 420px;
}

.automation-edit__title {
	margin: 0;
}

.automation-edit__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.9em;
}

.automation-edit__section {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.automation-edit__section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.automation-edit__action {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.automation-edit__error {
	color: var(--color-error);
	margin: 0;
}

.automation-edit__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
