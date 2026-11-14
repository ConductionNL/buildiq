<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  AgentPlanReview — where a person decides a plan an outside agent submitted
  (openbuild-mcp-adoption, task 2). An MCP write without an approved plan
  writes nothing and answers the agent with a plan id; the agent passes that
  id to its user, who opens it here, reads every step (tool and arguments)
  and approves or rejects it. The server decides who may: an owner or editor
  of the plan's app (AgentPlanService); this component only shows the
  answer.

  @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
-->
<template>
	<section data-testid="agent-plan-review" class="agent-plan-review" :aria-label="t('buildiq', 'Plans from outside agents')">
		<h4 class="agent-plan-review__heading">
			{{ t('buildiq', 'Plans from outside agents') }}
		</h4>
		<form class="agent-plan-review__open" @submit.prevent="open">
			<NcTextField
				v-model="planId"
				data-testid="agent-plan-id"
				:label="t('buildiq', 'Plan id')"
				:disabled="busy" />
			<NcButton
				type="submit"
				variant="secondary"
				:disabled="busy || !validId">
				{{ t('buildiq', 'Open plan') }}
			</NcButton>
		</form>

		<div v-if="plan" data-testid="agent-plan" class="agent-plan-review__plan">
			<p class="agent-plan-review__meta">
				{{
					t('buildiq', 'For {app}, submitted by {user}.', {
						app: plan.appSlug || '',
						user: plan.submittedBy || '',
					})
				}}
				<strong>{{ statusLabel }}</strong>
			</p>
			<ol class="agent-plan-review__steps">
				<li
					v-for="(step, index) in plan.steps || []"
					:key="index"
					class="agent-plan-review__step">
					<code>{{ step.tool }}</code>
					<pre class="agent-plan-review__arguments">{{ formatArguments(step.arguments) }}</pre>
				</li>
			</ol>
			<div v-if="plan.status === 'pending'" class="agent-plan-review__actions">
				<NcButton
					data-testid="agent-plan-approve"
					variant="primary"
					:disabled="busy"
					@click="decide(true)">
					{{ t('buildiq', 'Approve plan') }}
				</NcButton>
				<NcButton
					data-testid="agent-plan-reject"
					variant="secondary"
					:disabled="busy"
					@click="decide(false)">
					{{ t('buildiq', 'Reject plan') }}
				</NcButton>
			</div>
		</div>

		<p
			v-if="notice"
			role="status"
			class="agent-plan-review__notice"
			:class="{ 'agent-plan-review__notice--error': notice.failed }">
			{{ notice.text }}
		</p>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'

/** A plan id is a uuid, as the routes require. */
const PLAN_ID = /^[0-9a-fA-F-]{36}$/

export default {
	name: 'AgentPlanReview',

	components: { NcButton, NcTextField },

	data() {
		return {
			planId: '',
			plan: null,
			busy: false,
			/** The outcome of the last request: { text, failed }, or null. */
			notice: null,
		}
	},

	computed: {
		/**
		 * Whether the typed id can name a plan.
		 *
		 * @return {boolean}
		 */
		validId() {
			return PLAN_ID.test(this.planId.trim())
		},

		/**
		 * The plan's status in words.
		 *
		 * @return {string}
		 */
		statusLabel() {
			const labels = {
				pending: this.t('buildiq', 'Waiting for a decision'),
				approved: this.t('buildiq', 'Approved'),
				rejected: this.t('buildiq', 'Rejected'),
			}
			return labels[this.plan?.status] || String(this.plan?.status || '')
		},
	},

	methods: {
		/**
		 * Open the plan named by the typed id.
		 *
		 * @return {Promise<boolean>} Whether it was opened.
		 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
		 */
		async open() {
			const id = this.planId.trim()
			if (!PLAN_ID.test(id)) {
				return false
			}
			this.busy = true
			this.notice = null
			this.plan = null
			try {
				const { data } = await axios.get(this.planUrl(id))
				this.plan = data
				return true
			} catch (error) {
				this.notice = { text: this.failureText(error), failed: true }
				return false
			} finally {
				this.busy = false
			}
		},

		/**
		 * Approve or reject the open plan.
		 *
		 * @param {boolean} approve True to approve.
		 * @return {Promise<boolean>} Whether the decision was stored.
		 * @spec openspec/changes/openbuild-mcp-adoption/specs/openbuild-mcp-surface/spec.md#requirement-a-builder-tool-shall-write-only-under-a-plan-a-person-approved
		 */
		async decide(approve) {
			if (!this.plan || !this.plan.planId) {
				return false
			}
			this.busy = true
			this.notice = null
			try {
				const { data } = await axios.post(this.planUrl(this.plan.planId) + (approve ? '/approve' : '/reject'))
				this.plan = { ...this.plan, status: data.status }
				this.notice = {
					text: approve
						? this.t('buildiq', 'The plan is approved. The agent can now make these changes.')
						: this.t('buildiq', 'The plan is rejected. Nothing was changed.'),

					failed: false,
				}
				return true
			} catch (error) {
				this.notice = { text: this.failureText(error), failed: true }
				return false
			} finally {
				this.busy = false
			}
		},

		/**
		 * The endpoint of one plan.
		 *
		 * @param {string} id The plan id.
		 * @return {string}
		 */
		planUrl(id) {
			return generateUrl(`/apps/buildiq/api/agent-plans/${encodeURIComponent(id)}`)
		},

		/**
		 * The sentence for a refused or failed request.
		 *
		 * @param {object} error The axios error.
		 * @return {string}
		 */
		failureText(error) {
			const code = error?.response?.data?.error
			if (code === 'forbidden') {
				return this.t('buildiq', 'Only an owner or editor of this app can see and decide its plans.')
			}
			if (code === 'not_found') {
				return this.t('buildiq', 'There is no plan with this id.')
			}
			if (code === 'plan_decided') {
				return this.t('buildiq', 'This plan was already approved or rejected.')
			}
			return this.t('buildiq', 'The plan could not be loaded or saved. Try again.')
		},

		/**
		 * A step's arguments as readable JSON.
		 *
		 * @param {object} args The arguments.
		 * @return {string}
		 */
		formatArguments(args) {
			return JSON.stringify(args ?? {}, null, 2)
		},
	},
}
</script>

<style scoped>
.agent-plan-review {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}

.agent-plan-review__heading {
	margin: 0;
}

.agent-plan-review__open {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	flex-wrap: wrap;
}

.agent-plan-review__steps {
	margin: 0;
	padding-inline-start: 20px;
}

.agent-plan-review__arguments {
	margin: 4px 0 0;
	padding: 8px;
	max-height: 200px;
	overflow: auto;
	white-space: pre-wrap;
	word-break: break-word;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	border-radius: var(--border-radius);
}

.agent-plan-review__actions {
	display: flex;
	gap: 8px;
}

.agent-plan-review__notice {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.agent-plan-review__notice--error {
	color: var(--color-error-text, var(--color-error));
}
</style>
