<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  AgentEditDialog — standalone dialog (modal-isolation rule) composing one
  Agent: name, instructions, modelTaskType, enabledTools (a subset of the
  eight OpenBuildToolProvider tool ids — never a superset), maxActionsPerRun
  (spec agent-workspace REQ "Agent entity declares a named, tool-scoped
  configuration"). CRUD goes through OpenRegister's generic REST surface
  (ADR-022), mirroring AutomationEditDialog's posture for the `automation`
  object — `enabledTools` is validated server-side at save time against the
  same eight-tool enum (lib/Settings/register.d/70-agent-workspace.json).
-->
<template>
	<NcModal
		v-if="open"
		size="normal"
		:name="editing ? t('openbuild', 'Edit agent') : t('openbuild', 'New agent')"
		@close="onClose">
		<div class="agent-edit">
			<h2 class="agent-edit__title">
				{{
					editing
						? t('openbuild', 'Edit agent')
						: t('openbuild', 'New agent')
				}}
			</h2>

			<NcTextField
				:modelValue="name"
				:label="t('openbuild', 'Name')"
				data-testid="agent-name-field"
				@update:modelValue="name = $event" />

			<NcTextArea
				:modelValue="instructions"
				:label="t('openbuild', 'Instructions')"
				:placeholder="
					t(
						'openbuild',
						'Tell this agent how it should help — prefixed onto its system prompt for every message.',
					)
				"
				data-testid="agent-instructions-field"
				@update:modelValue="instructions = $event" />

			<NcSelect
				v-model="modelTaskTypeOption"
				:inputLabel="t('openbuild', 'Model task type')"
				:options="modelTaskTypeOptions"
				:clearable="false"
				label="label"
				data-testid="agent-model-task-type-select" />

			<NcSelect
				:modelValue="enabledToolsSelection"
				:inputLabel="t('openbuild', 'Enabled tools')"
				:options="toolOptions"
				:multiple="true"
				:clearable="false"
				label="label"
				data-testid="agent-enabled-tools-select"
				@update:modelValue="onEnabledToolsSelect" />
			<p class="agent-edit__hint">
				{{
					t(
						'openbuild',
						'This agent can never use a tool outside this list — enforced server-side on every request.',
					)
				}}
			</p>

			<NcTextField
				:modelValue="String(maxActionsPerRun)"
				type="number"
				:label="t('openbuild', 'Max actions per run')"
				data-testid="agent-max-actions-field"
				@update:modelValue="onMaxActionsInput" />

			<p
				v-if="showValidation && !valid"
				class="agent-edit__error"
				role="alert">
				{{ validationMessage }}
			</p>
			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>

			<div class="agent-edit__actions">
				<NcButton @click="onClose">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving"
					data-testid="agent-save-button"
					@click="onSave">
					{{ saving ? t('openbuild', 'Saving…') : t('openbuild', 'Save') }}
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

export default {
	name: 'AgentEditDialog',

	components: { NcButton, NcModal, NcNoteCard, NcSelect, NcTextArea, NcTextField },

	props: {
		open: { type: Boolean, default: false },
		agent: { type: Object, default: null },
		applicationSlug: { type: String, required: true },
	},

	emits: ['update:open', 'saved'],

	data() {
		return {
			id: null,
			name: '',
			instructions: '',
			modelTaskTypeOption: {
				value: 'TextToText',
				label: t('openbuild', 'Text to text'),
			},

			enabledTools: [],
			maxActionsPerRun: 10,
			showValidation: false,
			saving: false,
			errorMessage: '',
		}
	},

	computed: {
		/** @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend */
		editing() {
			return !!this.id
		},

		/**
		 * The eight OpenBuildToolProvider tool ids, mirrored 1:1 with the schema enum.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		toolOptions() {
			return [
				{ value: 'openbuild.listApps', label: t('openbuild', 'List apps') },
				{
					value: 'openbuild.getAppManifest',
					label: t('openbuild', 'Get app manifest'),
				},
				{
					value: 'openbuild.createApp',
					label: t('openbuild', 'Create app'),
				},
				{
					value: 'openbuild.promoteVersion',
					label: t('openbuild', 'Promote version'),
				},
				{
					value: 'openbuild.upsertSchema',
					label: t('openbuild', 'Create or update schema'),
				},
				{
					value: 'openbuild.upsertPage',
					label: t('openbuild', 'Create or update page'),
				},
				{
					value: 'openbuild.addWidget',
					label: t('openbuild', 'Add widget'),
				},
				{
					value: 'openbuild.upsertMenuItem',
					label: t('openbuild', 'Create or update menu item'),
				},
			]
		},

		/**
		 * v1 exposes exactly one task type — schema field kept for a v1.1 follow-up (design.md Open Questions).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		modelTaskTypeOptions() {
			return [{ value: 'TextToText', label: t('openbuild', 'Text to text') }]
		},

		/**
		 * Selected NcSelect option objects for `enabledTools`.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		enabledToolsSelection() {
			return this.toolOptions.filter((o) =>
				this.enabledTools.includes(o.value),
			)
		},

		/**
		 * @return {boolean} Whether the form can be saved.
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		valid() {
			return (
				this.name.trim().length >= 2
				&& this.enabledTools.length > 0
				&& this.maxActionsPerRun >= 1
			)
		},

		/**
		 * @return {string} The first-violated validation message.
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		validationMessage() {
			if (this.name.trim().length < 2) {
				return t('openbuild', 'Name must be at least 2 characters.')
			}
			if (this.enabledTools.length === 0) {
				return t('openbuild', 'Select at least one enabled tool.')
			}
			return t('openbuild', 'Max actions per run must be at least 1.')
		},
	},

	watch: {
		/**
		 * Re-hydrate the form fields whenever the dialog opens.
		 *
		 * @param {boolean} isOpen - the dialog's new open state.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		open(isOpen) {
			if (isOpen) {
				this.hydrate()
			}
		},
	},

	methods: {
		/**
		 * Populate local reactive fields from the `agent` prop (edit) or defaults (new).
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		hydrate() {
			this.showValidation = false
			this.errorMessage = ''
			const a = this.agent || {}
			this.id = a.id || a.uuid || null
			this.name = a.name || ''
			this.instructions = a.instructions || ''
			const taskType = a.modelTaskType || 'TextToText'
			this.modelTaskTypeOption =
				this.modelTaskTypeOptions.find((o) => o.value === taskType)
				|| this.modelTaskTypeOptions[0]
			this.enabledTools = Array.isArray(a.enabledTools)
				? [...a.enabledTools]
				: []
			this.maxActionsPerRun = Number.isFinite(a.maxActionsPerRun)
				? a.maxActionsPerRun
				: 10
		},

		/**
		 * Apply an enabled-tools multi-select change.
		 *
		 * @param {?Array<object>} options - the selected tool options.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		onEnabledToolsSelect(options) {
			this.enabledTools = Array.isArray(options)
				? options.map((o) => o.value)
				: []
		},

		/**
		 * Apply the max-actions-per-run numeric field change, clamped to >= 1.
		 *
		 * @param {string} value - the raw field value.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		onMaxActionsInput(value) {
			const parsed = parseInt(value, 10)
			this.maxActionsPerRun =
				Number.isFinite(parsed) && parsed > 0 ? parsed : 1
		},

		/**
		 * Persist the agent via OpenRegister's generic REST surface (ADR-022).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		async onSave() {
			this.showValidation = true
			if (!this.valid) {
				return
			}
			this.saving = true
			this.errorMessage = ''
			const payload = {
				name: this.name,
				instructions: this.instructions,
				modelTaskType: this.modelTaskTypeOption
					? this.modelTaskTypeOption.value
					: 'TextToText',

				enabledTools: this.enabledTools,
				maxActionsPerRun: this.maxActionsPerRun,
				applicationSlug: this.applicationSlug,
			}
			try {
				const base = generateUrl(
					'/apps/openregister/api/objects/openbuild/agent',
				)
				if (this.editing && this.id) {
					await axios.put(`${base}/${this.id}`, payload)
				} else {
					await axios.post(base, payload)
				}
				this.$emit('saved')
			} catch (error) {
				this.errorMessage = t('openbuild', 'Could not save the agent.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		onClose() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.agent-edit {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	min-width: 420px;
}

.agent-edit__title {
	margin: 0;
}

.agent-edit__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.9em;
}

.agent-edit__error {
	color: var(--color-error);
	margin: 0;
}

.agent-edit__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
