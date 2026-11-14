<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  AgentEditDialog — standalone dialog (modal-isolation rule) composing one
  Agent: name, instructions, modelTaskType, enabledTools (a subset of the
  eight BuildiqToolProvider tool ids — never a superset), maxActionsPerRun
  (spec agent-workspace REQ "Agent entity declares a named, tool-scoped
  configuration"). CRUD goes through OpenRegister's generic REST surface
  (ADR-022), mirroring AutomationEditDialog's posture for the `automation`
  object — `enabledTools` is validated server-side at save time against the
  same eight-tool enum (lib/Settings/register.d/70-agent-workspace.json).
  The Knowledge section attaches text and Markdown files to the agent object
  (OpenRegister object files), listed in `knowledge[]`, within a 40,000
  character budget the plan prompt reads them under
  (ai-agents-knowledge-and-run-trace REQ-BQAG-001).
-->
<template>
	<NcModal
		v-if="open"
		size="normal"
		:name="editing ? t('buildiq', 'Edit agent') : t('buildiq', 'New agent')"
		@close="onClose">
		<div class="agent-edit">
			<h2 class="agent-edit__title">
				{{
					editing ? t('buildiq', 'Edit agent') : t('buildiq', 'New agent')
				}}
			</h2>

			<NcTextField
				:modelValue="name"
				:label="t('buildiq', 'Name')"
				data-testid="agent-name-field"
				@update:modelValue="name = $event" />

			<NcTextArea
				:modelValue="instructions"
				:label="t('buildiq', 'Instructions')"
				:placeholder="
					t(
						'buildiq',
						'Tell this agent how it should help — prefixed onto its system prompt for every message.',
					)
				"
				data-testid="agent-instructions-field"
				@update:modelValue="instructions = $event" />

			<NcSelect
				v-model="modelTaskTypeOption"
				:inputLabel="t('buildiq', 'Model task type')"
				:options="modelTaskTypeOptions"
				:clearable="false"
				label="label"
				data-testid="agent-model-task-type-select" />

			<NcSelect
				:modelValue="enabledToolsSelection"
				:inputLabel="t('buildiq', 'Enabled tools')"
				:options="toolOptions"
				:multiple="true"
				:clearable="false"
				label="label"
				data-testid="agent-enabled-tools-select"
				@update:modelValue="onEnabledToolsSelect" />
			<p class="agent-edit__hint">
				{{
					t(
						'buildiq',
						'This agent can never use a tool outside this list — enforced server-side on every request.',
					)
				}}
			</p>

			<NcTextField
				:modelValue="String(maxActionsPerRun)"
				type="number"
				:label="t('buildiq', 'Max actions per run')"
				data-testid="agent-max-actions-field"
				@update:modelValue="onMaxActionsInput" />

			<section class="agent-edit__knowledge" data-testid="agent-knowledge-section">
				<h3 class="agent-edit__subtitle">
					{{ t('buildiq', 'Knowledge') }}
				</h3>
				<p class="agent-edit__hint">
					{{
						t(
							'buildiq',
							'Text and Markdown files this agent reads before it plans. {room} characters of room left.',
							{ room: formatCount(knowledgeRoom) },
						)
					}}
				</p>
				<ul v-if="knowledge.length > 0" class="agent-edit__knowledge-list">
					<li
						v-for="file in knowledge"
						:key="file.fileName"
						class="agent-edit__knowledge-item"
						data-testid="agent-knowledge-item">
						<span class="agent-edit__knowledge-name">{{ file.fileName }}</span>
						<span class="agent-edit__hint">
							{{
								t('buildiq', '{count} characters', {
									count: formatCount(file.characters || 0),
								})
							}}
						</span>
						<NcButton
							variant="tertiary"
							:aria-label="t('buildiq', 'Remove {file}', { file: file.fileName })"
							data-testid="agent-knowledge-remove"
							@click="removeKnowledge(file.fileName)">
							{{ t('buildiq', 'Remove') }}
						</NcButton>
					</li>
				</ul>
				<label class="agent-edit__knowledge-add">
					<span>{{ t('buildiq', 'Add knowledge file') }}</span>
					<input
						type="file"
						:accept="knowledgeAccept"
						data-testid="agent-knowledge-input"
						@change="onKnowledgePick">
				</label>
				<p
					v-if="knowledgeError"
					class="agent-edit__error"
					role="alert"
					data-testid="agent-knowledge-error">
					{{ knowledgeError }}
				</p>
			</section>

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
					{{ t('buildiq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="saving"
					data-testid="agent-save-button"
					@click="onSave">
					{{ saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
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

/**
 * The stored id of an agent record (`id`, else `uuid`), or null for a new one.
 *
 * @param {object} agent - the agent record, `{}` for a new agent.
 * @return {string|null}
 */
function agentIdOf(agent) {
	return agent.id || agent.uuid || null
}

/**
 * The most characters of knowledge one agent carries; the plan prompt reads
 * no more (AgentKnowledgeReader::BUDGET_CHARACTERS).
 *
 * @type {number}
 */
export const KNOWLEDGE_BUDGET = 40000

/**
 * File name extensions accepted as knowledge: plain text and Markdown.
 *
 * @type {Array<string>}
 */
const KNOWLEDGE_EXTENSIONS = ['txt', 'md', 'markdown']

/**
 * Whether a file name is a type accepted as knowledge.
 *
 * @param {string} fileName - the picked file's name.
 * @return {boolean}
 */
function isKnowledgeType(fileName) {
	const dot = fileName.lastIndexOf('.')
	return dot > 0 && KNOWLEDGE_EXTENSIONS.includes(fileName.slice(dot + 1).toLowerCase())
}

/**
 * The character count of a text, in code points as the server counts them.
 *
 * @param {string} text - the file text.
 * @return {number}
 */
function characterCount(text) {
	return [...text].length
}

/**
 * The agent's stored knowledge entries, without malformed ones.
 *
 * @param {object} agent - the agent record, `{}` for a new agent.
 * @return {Array<object>}
 */
function knowledgeOf(agent) {
	if (!Array.isArray(agent.knowledge)) {
		return []
	}
	return agent.knowledge
		.filter((entry) => entry && typeof entry.fileName === 'string' && entry.fileName !== '')
		.map((entry) => ({ ...entry }))
}

/**
 * The agent's max actions per run, or 10 when it is not a finite number.
 *
 * @param {object} agent - the agent record, `{}` for a new agent.
 * @return {number}
 */
function actionLimitOf(agent) {
	return Number.isFinite(agent.maxActionsPerRun) ? agent.maxActionsPerRun : 10
}

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
				label: t('buildiq', 'Text to text'),
			},

			enabledTools: [],
			maxActionsPerRun: 10,
			knowledge: [],
			pendingKnowledge: {},
			removedKnowledge: [],
			knowledgeError: '',
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
		 * The eight BuildiqToolProvider tool ids, mirrored 1:1 with the schema enum.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		toolOptions() {
			return [
				{ value: 'buildiq.listApps', label: t('buildiq', 'List apps') },
				{
					value: 'buildiq.getAppManifest',
					label: t('buildiq', 'Get app manifest'),
				},
				{
					value: 'buildiq.createApp',
					label: t('buildiq', 'Create app'),
				},
				{
					value: 'buildiq.promoteVersion',
					label: t('buildiq', 'Promote version'),
				},
				{
					value: 'buildiq.upsertSchema',
					label: t('buildiq', 'Create or update schema'),
				},
				{
					value: 'buildiq.upsertPage',
					label: t('buildiq', 'Create or update page'),
				},
				{
					value: 'buildiq.addWidget',
					label: t('buildiq', 'Add widget'),
				},
				{
					value: 'buildiq.upsertMenuItem',
					label: t('buildiq', 'Create or update menu item'),
				},
			]
		},

		/**
		 * The file input's accepted types.
		 *
		 * @return {string}
		 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-a-maker-attaches-knowledge-files-to-an-agent-req-bqag-001
		 */
		knowledgeAccept() {
			return '.txt,.md,.markdown,text/plain,text/markdown'
		},

		/**
		 * Characters of knowledge the agent has room for.
		 *
		 * @return {number}
		 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-a-maker-attaches-knowledge-files-to-an-agent-req-bqag-001
		 */
		knowledgeRoom() {
			const used = this.knowledge.reduce(
				(sum, file) => sum + (Number.isFinite(file.characters) ? file.characters : 0),
				0,
			)
			return Math.max(0, KNOWLEDGE_BUDGET - used)
		},

		/**
		 * v1 exposes exactly one task type — schema field kept for a v1.1 follow-up (design.md Open Questions).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		modelTaskTypeOptions() {
			return [{ value: 'TextToText', label: t('buildiq', 'Text to text') }]
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
				return t('buildiq', 'Name must be at least 2 characters.')
			}
			if (this.enabledTools.length === 0) {
				return t('buildiq', 'Select at least one enabled tool.')
			}
			return t('buildiq', 'Max actions per run must be at least 1.')
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
			this.id = agentIdOf(a)
			this.name = a.name || ''
			this.instructions = a.instructions || ''
			this.modelTaskTypeOption = this.taskTypeOption(a.modelTaskType)
			this.enabledTools = Array.isArray(a.enabledTools)
				? [...a.enabledTools]
				: []
			this.maxActionsPerRun = actionLimitOf(a)
			this.knowledge = knowledgeOf(a)
			this.pendingKnowledge = {}
			this.removedKnowledge = []
			this.knowledgeError = ''
		},

		/**
		 * A count written for the reader, with digit grouping.
		 *
		 * @param {number} count - the count.
		 * @return {string}
		 */
		formatCount(count) {
			return Number(count).toLocaleString()
		},

		/**
		 * Take a picked file into the agent's knowledge, or refuse it: a type
		 * other than text or Markdown, a name already listed, or a file that
		 * would pass the budget (the refusal names the room left). The file is
		 * uploaded to the agent object on save.
		 *
		 * @param {Event} event - the file input's change event.
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-a-maker-attaches-knowledge-files-to-an-agent-req-bqag-001
		 */
		async onKnowledgePick(event) {
			const file = event?.target?.files?.[0]
			this.knowledgeError = ''
			if (!file) {
				return
			}
			try {
				this.knowledgeError = await this.addKnowledgeFile(file)
			} finally {
				if (event.target) {
					event.target.value = ''
				}
			}
		},

		/**
		 * Add one file to the knowledge list.
		 *
		 * @param {File} file - the picked file.
		 * @return {Promise<string>} The refusal message, or '' when added.
		 */
		async addKnowledgeFile(file) {
			if (!isKnowledgeType(file.name)) {
				return t('buildiq', 'Only text (.txt) and Markdown (.md) files can be added as knowledge.')
			}
			if (this.knowledge.some((entry) => entry.fileName === file.name)) {
				return t('buildiq', '{file} is already in this agent\'s knowledge.', { file: file.name })
			}
			const text = await file.text()
			const characters = characterCount(text)
			if (characters > this.knowledgeRoom) {
				return t(
					'buildiq',
					'{file} has {size} characters, but only {room} characters of room are left.',
					{
						file: file.name,
						size: this.formatCount(characters),
						room: this.formatCount(this.knowledgeRoom),
					},
				)
			}
			this.knowledge.push({
				fileName: file.name,
				addedBy: getCurrentUser()?.uid || '',
				addedAt: new Date().toISOString(),
				characters,
			})
			this.pendingKnowledge = { ...this.pendingKnowledge, [file.name]: text }
			this.removedKnowledge = this.removedKnowledge.filter((name) => name !== file.name)
			return ''
		},

		/**
		 * Take a file out of the agent's knowledge; a stored file is detached
		 * from the agent object on save.
		 *
		 * @param {string} fileName - the file to remove.
		 * @return {void}
		 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-a-maker-attaches-knowledge-files-to-an-agent-req-bqag-001
		 */
		removeKnowledge(fileName) {
			this.knowledge = this.knowledge.filter((entry) => entry.fileName !== fileName)
			this.knowledgeError = ''
			if (fileName in this.pendingKnowledge) {
				const pending = { ...this.pendingKnowledge }
				delete pending[fileName]
				this.pendingKnowledge = pending
				return
			}
			if (!this.removedKnowledge.includes(fileName)) {
				this.removedKnowledge.push(fileName)
			}
		},

		/**
		 * The task-type option for a stored task type, else the first option.
		 *
		 * @param {string|undefined} storedType - the agent's `modelTaskType`.
		 * @return {object}
		 */
		taskTypeOption(storedType) {
			const taskType = storedType || 'TextToText'
			return (
				this.modelTaskTypeOptions.find((o) => o.value === taskType)
				|| this.modelTaskTypeOptions[0]
			)
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
		 * The agent object the form saves.
		 *
		 * @return {object} The payload.
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		agentPayload() {
			return {
				name: this.name,
				instructions: this.instructions,
				modelTaskType: this.modelTaskTypeOption
					? this.modelTaskTypeOption.value
					: 'TextToText',

				enabledTools: this.enabledTools,
				maxActionsPerRun: this.maxActionsPerRun,
				knowledge: this.knowledge.map((entry) => ({ ...entry })),
				applicationSlug: this.applicationSlug,
			}
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
			try {
				await this.persistAgent()
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not save the agent.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * PUT an existing agent to its uuid, or POST a new one, then emit
		 * `saved`.
		 *
		 * @return {Promise<void>}
		 */
		async persistAgent() {
			const base = generateUrl(
				'/apps/openregister/api/objects/buildiq/buildAgent',
			)
			let agentId = this.id
			if (this.editing && this.id) {
				await axios.put(`${base}/${this.id}`, this.agentPayload())
			} else {
				const response = await axios.post(base, this.agentPayload())
				agentId = agentIdOf(response?.data || {})
			}
			if (agentId) {
				await this.syncKnowledgeFiles(`${base}/${agentId}/files`)
			}
			this.$emit('saved')
		},

		/**
		 * Upload the files added since the dialog opened to the agent object,
		 * and detach the stored files that were removed.
		 *
		 * @param {string} filesUrl - the agent object's files URL.
		 * @return {Promise<void>}
		 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-knowledge-base/spec.md#requirement-a-maker-attaches-knowledge-files-to-an-agent-req-bqag-001
		 */
		async syncKnowledgeFiles(filesUrl) {
			for (const [name, content] of Object.entries(this.pendingKnowledge)) {
				await axios.post(filesUrl, { name, content })
			}
			this.pendingKnowledge = {}
			if (this.removedKnowledge.length === 0) {
				return
			}
			const listed = await axios.get(filesUrl)
			const attachments = listed?.data?.results || []
			for (const name of this.removedKnowledge) {
				const attachment = attachments.find((f) => f?.title === name || f?.name === name)
				if (attachment?.id) {
					await axios.delete(`${filesUrl}/${attachment.id}`)
				}
			}
			this.removedKnowledge = []
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

.agent-edit__subtitle {
	margin: 0;
	font-size: 1.1em;
}

.agent-edit__knowledge {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.agent-edit__knowledge-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.agent-edit__knowledge-item {
	display: flex;
	align-items: center;
	gap: 8px;
}

.agent-edit__knowledge-name {
	flex: 1;
	overflow-wrap: anywhere;
}

.agent-edit__knowledge-add {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.agent-edit__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
