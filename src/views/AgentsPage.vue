<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AgentsPage — the Agent Workspace surface (spec agent-workspace REQ
  - "Agents page provides CRUD and a per-agent chat panel"). Lists every
  - `agent` object for the selected Application, opens AgentEditDialog to
  - compose a new agent or edit an existing one, and — per selected agent —
  - a chat panel (reusing CopilotPanel.vue scoped to that agent's agentId/
  - name/instructions/enabledTools) plus a transparent run-history list
  - (AgentRunHistory.vue).
  -
  - Agent object CRUD goes through OpenRegister's REST surface (ADR-022);
  - the RBAC-sensitive run-history read goes through the buildiq
  - AgentsController API (never the generic OR REST surface — see its
  - docblock for why).
  -->
<template>
	<div class="agents-page">
		<header class="agents-page__header">
			<h2>{{ t('buildiq', 'Agents') }}</h2>
			<NcButton variant="primary" :disabled="!selectedApp" @click="openNew">
				{{ t('buildiq', 'New agent') }}
			</NcButton>
		</header>

		<div class="agents-page__picker">
			<NcSelect
				v-model="selectedApp"
				class="agents-page__picker-select"
				:inputLabel="t('buildiq', 'Application')"
				:options="applications"
				:loading="loadingApplications"
				label="name"
				trackBy="slug"
				@update:modelValue="onAppChange" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="selectedApp && agents.length === 0"
			:name="t('buildiq', 'No agents yet')"
			:description="
				t(
					'buildiq',
					'Create an agent to give it instructions and a scoped subset of the builder tools.',
				)
			" />

		<p v-else-if="!selectedApp" class="agents-page__hint">
			{{ t('buildiq', 'Select an application to see its agents.') }}
		</p>

		<div v-else class="agents-page__body">
			<ul class="agents-page__list">
				<li
					v-for="agent in agents"
					:key="agent.id"
					class="agents-page__item"
					:class="{
						'agents-page__item--active':
							selectedAgent && selectedAgent.id === agent.id,
					}"
					data-testid="agent-row">
					<button
						class="agents-page__item-main"
						@click="selectAgent(agent)">
						<strong>{{ agent.name }}</strong>
						<span class="agents-page__item-meta">
							{{
								t('buildiq', '{count} tool(s) enabled', {
									count: (agent.enabledTools || []).length,
								})
							}}
						</span>
					</button>
					<div class="agents-page__item-side">
						<NcButton variant="tertiary" @click="openEdit(agent)">
							{{ t('buildiq', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" @click="remove(agent)">
							{{ t('buildiq', 'Delete') }}
						</NcButton>
					</div>
				</li>
			</ul>

			<div v-if="selectedAgent" class="agents-page__detail">
				<div class="agents-page__detail-tabs">
					<NcButton
						:variant="activeTab === 'chat' ? 'primary' : 'tertiary'"
						@click="activeTab = 'chat'">
						{{ t('buildiq', 'Chat') }}
					</NcButton>
					<NcButton
						:variant="activeTab === 'history' ? 'primary' : 'tertiary'"
						@click="activeTab = 'history'">
						{{ t('buildiq', 'Run history') }}
					</NcButton>
				</div>

				<CopilotPanel
					v-if="activeTab === 'chat'"
					:key="selectedAgent.id"
					:appSlug="selectedApp.slug"
					:agentId="selectedAgent.id"
					:name="selectedAgent.name"
					:instructions="selectedAgent.instructions"
					:enabledTools="selectedAgent.enabledTools || []" />

				<AgentRunHistory
					v-else
					:key="`history-${selectedAgent.id}`"
					:agentId="selectedAgent.id" />
			</div>
		</div>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>

		<AgentEditDialog
			v-model:open="editDialogOpen"
			:agent="editingAgent"
			:applicationSlug="selectedApp ? selectedApp.slug : ''"
			@saved="onDialogSaved" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import AgentRunHistory from '../components/agents/AgentRunHistory.vue'
import CopilotPanel from '../components/copilot/CopilotPanel.vue'
import AgentEditDialog from '../dialogs/AgentEditDialog.vue'

export default {
	name: 'AgentsPage',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		CopilotPanel,
		AgentRunHistory,
		AgentEditDialog,
	},

	data() {
		return {
			loading: false,
			loadingApplications: false,
			applications: [],
			selectedApp: null,
			agents: [],
			selectedAgent: null,
			activeTab: 'chat',
			errorMessage: '',
			editDialogOpen: false,
			editingAgent: null,
		}
	},

	mounted() {
		this.fetchApplications()
	},

	methods: {
		/**
		 * Load the caller's Applications for the picker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
		 */
		async fetchApplications() {
			this.loadingApplications = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/buildiq/api/applications')
				const { data } = await axios.get(url)
				this.applications = this.extractResults(data)
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not load applications.')
			} finally {
				this.loadingApplications = false
			}
		},

		/**
		 * Handle an application selection: reset the agent list + selection, fetch agents.
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		onAppChange() {
			this.agents = []
			this.selectedAgent = null
			if (this.selectedApp) {
				this.fetchAgents()
			}
		},

		/**
		 * Load every `agent` object and filter to the selected Application
		 * (mirrors AutomationsPage's fetch-all-then-filter pattern for the
		 * generic OR REST list).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
		 */
		async fetchAgents() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/buildiq/agent',
				)
				const { data } = await axios.get(url)
				const all = this.extractResults(data)
				this.agents = all.filter(
					(a) => a.applicationSlug === this.selectedApp.slug,
				)
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not load agents.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Select an agent for the chat/history detail panel.
		 *
		 * @param {object} agent - the agent row.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		selectAgent(agent) {
			this.selectedAgent = agent
			this.activeTab = 'chat'
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		openNew() {
			this.editingAgent = null
			this.editDialogOpen = true
		},

		/**
		 * @param {object} agent - the agent row to edit.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		openEdit(agent) {
			this.editingAgent = agent
			this.editDialogOpen = true
		},

		/**
		 * @return {void}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		onDialogSaved() {
			this.editDialogOpen = false
			this.fetchAgents()
		},

		/**
		 * Delete an agent via OpenRegister's generic REST surface.
		 *
		 * @param {object} agent - the agent row to delete.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		async remove(agent) {
			try {
				const url = generateUrl(
					`/apps/openregister/api/objects/buildiq/agent/${agent.id}`,
				)
				await axios.delete(url)
				if (this.selectedAgent && this.selectedAgent.id === agent.id) {
					this.selectedAgent = null
				}
				await this.fetchAgents()
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not delete the agent.')
			}
		},

		/**
		 * Normalise an OpenRegister list response (bare array or `{results}` envelope).
		 *
		 * @param {Array|object} data - the raw response body.
		 * @return {Array<object>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		extractResults(data) {
			if (Array.isArray(data)) {
				return data
			}
			if (data && Array.isArray(data.results)) {
				return data.results
			}
			return []
		},
	},
}
</script>

<style scoped>
.agents-page {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 20px;
}

.agents-page__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.agents-page__picker-select {
	max-width: 320px;
}

.agents-page__hint {
	color: var(--color-text-maxcontrast);
}

.agents-page__body {
	display: flex;
	gap: 16px;
	align-items: flex-start;
}

.agents-page__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	flex: 0 0 280px;
}

.agents-page__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}

.agents-page__item--active {
	border-color: var(--color-primary-element);
}

.agents-page__item-main {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
	background: none;
	border: none;
	cursor: pointer;
	padding: 0;
	text-align: left;
}

.agents-page__item-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.agents-page__item-side {
	display: flex;
	gap: 4px;
}

.agents-page__detail {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	height: 480px;
}

.agents-page__detail-tabs {
	display: flex;
	gap: 4px;
}
</style>
