<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  AgentRunHistory — the transparent run-log list for one Agent (spec
  agent-workspace REQ "Every agent run is transparently logged and
  reviewable", the Retool tool-chip transparency pattern). Fetches
  `GET /api/agents/{uuid}/runs` (owners/editors-only, enforced server-side
  in AgentsController — never the generic OpenRegister object REST surface,
  which has no per-Application row-level RBAC). Every tool call renders its
  tool name, arguments, and result — never summarised or redacted.
-->
<template>
	<div data-testid="agent-run-history" class="agent-run-history">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="runs.length === 0"
			:name="t('openbuild', 'No runs yet')"
			:description="t('openbuild', 'Every plan this agent generates — applied, rolled back, or discarded — appears here with full tool-call detail.')" />

		<ul v-else class="agent-run-history__list">
			<li v-for="run in runs"
				:key="run.id || run.uuid"
				class="agent-run-history__run"
				data-testid="agent-run-row">
				<div class="agent-run-history__run-header">
					<span
						class="agent-run-history__outcome-badge"
						:class="`agent-run-history__outcome-badge--${run.outcome}`"
						data-testid="agent-run-outcome">
						{{ outcomeLabel(run.outcome) }}
					</span>
					<time class="agent-run-history__timestamp">{{ run.createdAt }}</time>
				</div>
				<p class="agent-run-history__prompt">
					{{ run.prompt }}
				</p>

				<ul v-if="(run.toolCalls || []).length > 0" class="agent-run-history__tool-calls">
					<li v-for="(call, idx) in run.toolCalls"
						:key="idx"
						class="agent-run-history__tool-call"
						data-testid="agent-run-tool-call">
						<code class="agent-run-history__tool-name">{{ call.tool }}</code>
						<details class="agent-run-history__tool-detail">
							<summary>{{ t('openbuild', 'Arguments & result') }}</summary>
							<pre class="agent-run-history__tool-json">{{ formatJson(call.arguments) }}</pre>
							<pre class="agent-run-history__tool-json">{{ formatJson(call.result) }}</pre>
						</details>
					</li>
				</ul>
				<p v-else class="agent-run-history__no-calls">
					{{ t('openbuild', 'No tool calls in this run.') }}
				</p>
			</li>
		</ul>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'AgentRunHistory',

	components: { NcEmptyContent, NcLoadingIcon, NcNoteCard },

	props: {
		/** The Agent object uuid whose run history is shown. */
		agentId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			runs: [],
			errorMessage: '',
		}
	},

	watch: {
		agentId: {
			immediate: true,
			/**
			 * Re-fetch the run history whenever the selected agent changes.
			 *
			 * @return {void}
			 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
			 */
			handler() {
				if (this.agentId) {
					this.fetchRuns()
				}
			},
		},
	},

	methods: {
		/**
		 * Load this agent's run history, newest first.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/specs/agent-workspace/spec.md
		 */
		async fetchRuns() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl(`/apps/openbuild/api/agents/${this.agentId}/runs`)
				const { data } = await axios.get(url)
				this.runs = Array.isArray(data) ? data : []
			} catch (error) {
				this.errorMessage = t('openbuild', 'Could not load run history.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Human-readable label for a run's outcome.
		 *
		 * @param {string} outcome - one of applied|rolled-back|discarded|plan-rejected.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		outcomeLabel(outcome) {
			const labels = {
				applied: t('openbuild', 'Applied'),
				'rolled-back': t('openbuild', 'Rolled back'),
				discarded: t('openbuild', 'Discarded'),
				'plan-rejected': t('openbuild', 'Plan rejected'),
			}
			return labels[outcome] || outcome
		},
		/**
		 * Pretty-print a tool call's arguments/result for the expandable detail view.
		 *
		 * @param {object} value - the arguments or result object.
		 * @return {string}
		 * @spec openspec/changes/archive/2026-07-24-agent-workspace/tasks.md#4-frontend
		 */
		formatJson(value) {
			try {
				return JSON.stringify(value || {}, null, 2)
			} catch (e) {
				return String(value)
			}
		},
	},
}
</script>

<style scoped>
.agent-run-history__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.agent-run-history__run {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
}

.agent-run-history__run-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.agent-run-history__outcome-badge {
	border-radius: var(--border-radius-pill);
	padding: 2px 10px;
	font-size: 0.85em;
	font-weight: 600;
	background: var(--color-background-hover);
}

.agent-run-history__outcome-badge--applied {
	background: var(--color-success);
	color: var(--color-primary-element-text);
}

.agent-run-history__outcome-badge--rolled-back,
.agent-run-history__outcome-badge--plan-rejected {
	background: var(--color-error);
	color: var(--color-primary-element-text);
}

.agent-run-history__timestamp {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.agent-run-history__prompt {
	margin: 8px 0;
	font-weight: 600;
}

.agent-run-history__tool-calls {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.agent-run-history__tool-json {
	white-space: pre-wrap;
	word-break: break-word;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px;
	font-size: 0.85em;
	max-width: 100%;
	overflow-x: auto;
}

.agent-run-history__no-calls {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}
</style>
