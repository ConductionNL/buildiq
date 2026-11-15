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
			:name="t('buildiq', 'No runs yet')"
			:description="
				t(
					'buildiq',
					'Every plan this agent generates — applied, rolled back, or discarded — appears here with full tool-call detail.',
				)
			" />

		<ul v-else class="agent-run-history__list">
			<li
				v-for="run in runs"
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
					<time class="agent-run-history__timestamp">{{
						run.createdAt
					}}</time>
				</div>
				<p class="agent-run-history__prompt">
					{{ run.prompt }}
				</p>

				<ol
					v-if="(run.modelSteps || []).length > 0"
					class="agent-run-history__model-steps"
					:aria-label="t('buildiq', 'Model steps')">
					<li
						v-for="step in run.modelSteps"
						:key="step.attempt"
						class="agent-run-history__model-step"
						data-testid="agent-run-model-step">
						<span class="agent-run-history__step-title">{{
							stepLabel(step)
						}}</span>
						<span class="agent-run-history__step-meta">{{
							durationLabel(step)
						}}</span>
						<p
							v-if="step.refusal"
							class="agent-run-history__step-refusal"
							data-testid="agent-run-step-refusal">
							{{ step.refusal }}
						</p>
						<details
							v-if="step.output"
							class="agent-run-history__tool-detail">
							<summary>{{ t('buildiq', 'Model answer') }}</summary>
							<pre class="agent-run-history__tool-json">{{
								step.output
							}}</pre>
						</details>
					</li>
				</ol>
				<p
					v-else-if="run.traceMissing"
					class="agent-run-history__no-calls"
					data-testid="agent-run-trace-missing">
					{{
						t(
							'buildiq',
							'The model steps of this run were not kept: the plan was made too long before it was applied.',
						)
					}}
				</p>

				<p
					v-if="(run.knowledgeUsed || []).length > 0"
					class="agent-run-history__knowledge"
					data-testid="agent-run-knowledge">
					{{ knowledgeLabel(run) }}
				</p>

				<ul
					v-if="(run.toolCalls || []).length > 0"
					class="agent-run-history__tool-calls">
					<li
						v-for="(call, idx) in run.toolCalls"
						:key="idx"
						class="agent-run-history__tool-call"
						data-testid="agent-run-tool-call">
						<code class="agent-run-history__tool-name">{{
							call.tool
						}}</code>
						<details class="agent-run-history__tool-detail">
							<summary>
								{{ t('buildiq', 'Arguments & result') }}
							</summary>
							<pre class="agent-run-history__tool-json">{{
								formatJson(call.arguments)
							}}</pre>
							<pre class="agent-run-history__tool-json">{{
								formatJson(call.result)
							}}</pre>
						</details>
					</li>
				</ul>
				<p v-else class="agent-run-history__no-calls">
					{{ t('buildiq', 'No tool calls in this run.') }}
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
				const url = generateUrl(
					`/apps/buildiq/api/agents/${this.agentId}/runs`,
				)
				const { data } = await axios.get(url)
				this.runs = Array.isArray(data) ? data : []
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not load run history.')
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
				applied: t('buildiq', 'Applied'),
				'rolled-back': t('buildiq', 'Rolled back'),
				discarded: t('buildiq', 'Discarded'),
				'plan-rejected': t('buildiq', 'Plan rejected'),
			}
			return labels[outcome] || outcome
		},

		/**
		 * The heading of one model step: "Attempt 1, repair needed", "Attempt 2".
		 *
		 * @param {object} step - one entry of `run.modelSteps`.
		 * @return {string}
		 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-the-run-history-shows-the-model-steps-req-bqag-005
		 */
		stepLabel(step) {
			const statuses = {
				'repair-needed': t('buildiq', 'Attempt {attempt}, repair needed', { attempt: step.attempt }),
				failed: t('buildiq', 'Attempt {attempt}, no usable answer', { attempt: step.attempt }),
				error: t('buildiq', 'Attempt {attempt}, the model did not answer', { attempt: step.attempt }),
			}
			return statuses[step.status] || t('buildiq', 'Attempt {attempt}', { attempt: step.attempt })
		},

		/**
		 * How long one model step took, in milliseconds.
		 *
		 * @param {object} step - one entry of `run.modelSteps`.
		 * @return {string}
		 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-the-run-history-shows-the-model-steps-req-bqag-005
		 */
		durationLabel(step) {
			return t('buildiq', '{duration} ms', { duration: step.durationMs || 0 })
		},

		/**
		 * The knowledge files a run read, as one line.
		 *
		 * @param {object} run - one run.
		 * @return {string}
		 * @spec openspec/changes/ai-agents-knowledge-and-run-trace/specs/agent-workspace/spec.md#requirement-the-run-history-shows-the-model-steps-req-bqag-005
		 */
		knowledgeLabel(run) {
			return t('buildiq', 'Knowledge used: {files}', { files: (run.knowledgeUsed || []).join(', ') })
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

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

.agent-run-history__list {
	@include c.plain-list(12px);
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
	@include c.plain-list(6px);
}

.agent-run-history__tool-json {
	white-space: pre-wrap;
	overflow-wrap: break-word;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px;
	font-size: 0.85em;
	max-width: 100%;
	overflow-x: auto;
}

.agent-run-history__model-steps {
	@include c.plain-list(6px);
	margin-bottom: 8px;
}

.agent-run-history__model-step {
	border-inline-start: 3px solid var(--color-border-dark);
	padding-inline-start: 8px;
}

.agent-run-history__step-title {
	font-weight: 600;
	margin-inline-end: 8px;
}

.agent-run-history__step-meta,
.agent-run-history__knowledge {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.agent-run-history__step-refusal {
	margin: 4px 0;
	color: var(--color-error-text);
	font-size: 0.9em;
}

.agent-run-history__no-calls {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}
</style>
