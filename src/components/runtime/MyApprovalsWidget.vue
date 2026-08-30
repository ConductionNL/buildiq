<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - MyApprovalsWidget — "My approvals" runtime widget (automation-approval-
  - steps task 4.1/4.2, spec automation-approval-action REQ "My Approvals
  - runtime widget lists pending steps for the viewer's groups").
  -
  - Registrable page-widget type for a built (virtual) app: lists PENDING
  - OpenRegister `ApprovalStep`s whose `role` is present in the viewer's NC
  - groups (read via `loadState('buildiq', 'currentUserGroups')`, published
  - by DashboardController::builder() — never a DOM attribute read, ADR-004
  - hard rule). Approve/reject buttons call OpenRegister's
  - `/api/approval-steps/{id}/approve|reject` DIRECTLY — no Buildiq
  - pass-through controller exists for these calls (ADR-022 redundant-
  - controller gate; design.md Decision 4 of automation-approval-steps).
  -
  - OpenRegister's `GET /api/approval-steps` has no "assigned to me" filter
  - (only status/role/chainId/objectUuid) — client-side group filtering is
  - the only option without an OR-side API addition, and matches the SAME
  - group-based check OR itself enforces server-side (`verifyRole`), so the
  - client-side filter can never show an action a server call would reject.
  -->
<template>
	<div class="my-approvals-widget">
		<h3 class="my-approvals-widget__title">
			{{ t('buildiq', 'My approvals') }}
		</h3>

		<div v-if="loading" class="my-approvals-widget__state">
			{{ t('buildiq', 'Loading…') }}
		</div>

		<div
			v-else-if="error"
			class="my-approvals-widget__state my-approvals-widget__state--error">
			<p>{{ t('buildiq', 'Could not load pending approvals.') }}</p>
			<NcButton variant="secondary" @click="load">
				{{ t('buildiq', 'Retry') }}
			</NcButton>
		</div>

		<p
			v-else-if="pendingSteps.length === 0"
			class="my-approvals-widget__state"
			data-testid="my-approvals-empty">
			{{ t('buildiq', 'No approvals are waiting for you.') }}
		</p>

		<ul v-else class="my-approvals-widget__list">
			<li
				v-for="step in pendingSteps"
				:key="step.id"
				class="my-approvals-widget__row"
				data-testid="my-approvals-row">
				<div class="my-approvals-widget__row-main">
					<span class="my-approvals-widget__role">{{ step.role }}</span>
					<span class="my-approvals-widget__object">{{
						step.objectUuid
					}}</span>
				</div>
				<div class="my-approvals-widget__row-actions">
					<NcButton
						variant="primary"
						:disabled="!!deciding[step.id]"
						data-testid="approve-button"
						@click="decide(step, 'approve')">
						{{ t('buildiq', 'Approve') }}
					</NcButton>
					<NcButton
						variant="error"
						:disabled="!!deciding[step.id]"
						data-testid="reject-button"
						@click="decide(step, 'reject')">
						{{ t('buildiq', 'Reject') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<NcNoteCard v-if="decideError" type="error">
			{{ decideError }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { getCurrentUserGroups } from '../../composables/useRole.js'

export default {
	name: 'MyApprovalsWidget',
	components: { NcButton, NcNoteCard },
	data() {
		return {
			loading: false,
			error: false,
			steps: [],
			deciding: {},
			decideError: '',
		}
	},

	computed: {
		/**
		 * Pending steps whose `role` is one of the viewer's NC groups
		 * (client-side filter — task 4.1).
		 *
		 * @return {Array}
		 */
		pendingSteps() {
			const groups = getCurrentUserGroups()
			if (groups.length === 0) {
				return []
			}
			return this.steps.filter((step) => groups.includes(step.role))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load pending approval steps directly from OpenRegister's REST API.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#4.1
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				const url = generateUrl('/apps/openregister/api/approval-steps')
				const { data } = await axios.get(url, {
					params: { status: 'pending' },
				})
				this.steps = Array.isArray(data) ? data : []
			} catch (err) {
				this.error = true
				this.steps = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Approve or reject a step by calling OpenRegister's endpoint DIRECTLY
		 * — no Buildiq controller mediates the call (task 4.2).
		 *
		 * @param {object} step - the approval step row.
		 * @param {string} action - 'approve' or 'reject'.
		 * @return {Promise<void>}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#4.2
		 */
		async decide(step, action) {
			this.decideError = ''
			this.deciding = { ...this.deciding, [step.id]: true }
			try {
				const url = generateUrl(
					`/apps/openregister/api/approval-steps/${step.id}/${action}`,
				)
				await axios.post(url, {})
				await this.load()
			} catch (err) {
				this.decideError =
					action === 'approve'
						? t('buildiq', 'Could not approve this step.')
						: t('buildiq', 'Could not reject this step.')
			} finally {
				const next = { ...this.deciding }
				delete next[step.id]
				this.deciding = next
			}
		},
	},
}
</script>

<style scoped>
.my-approvals-widget {
	padding: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.my-approvals-widget__title {
	margin: 0;
}

.my-approvals-widget__state {
	padding: 8px 0;
	color: var(--color-text-maxcontrast);
}

.my-approvals-widget__state--error {
	color: var(--color-error);
}

.my-approvals-widget__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.my-approvals-widget__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.my-approvals-widget__row-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.my-approvals-widget__role {
	font-weight: bold;
}

.my-approvals-widget__object {
	color: var(--color-text-maxcontrast);
	font-size: 0.85em;
}

.my-approvals-widget__row-actions {
	display: flex;
	gap: 6px;
}
</style>
