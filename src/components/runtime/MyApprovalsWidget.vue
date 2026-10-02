<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - MyApprovalsWidget — "My approvals" runtime widget (automation-approval-
  - steps task 4.1/4.2, spec automation-approval-action REQ "My Approvals
  - runtime widget lists pending steps for the viewer's groups").
  -
  - Registrable page-widget type for a built (virtual) app: lists the open
  - OpenRegister tasks waiting for the viewer. Approve/reject buttons call OpenRegister's
  - `/api/flow-tasks/{uuid}/complete` DIRECTLY — no Buildiq pass-through
  - controller exists for these calls (ADR-022 redundant-controller gate;
  - design.md Decision 4 of automation-approval-steps).
  -
  - ⚠️ MIGRATED off the retired approval surface (openregister #3302). The
  - `/api/approval-steps` list and its `approve` / `reject` verbs are gone; an
  - approval is an ordered task sequence, and a decision is `complete` with an
  - `outcome`. A rejecting outcome refuses an empty comment, so one is sent.
  -
  - The task inbox narrows to the caller server side: `scope=pooled` is the
  - unclaimed tasks in the caller's candidate groups, `scope=assigned` the
  - ones the caller holds (TaskInboxCriteria). A task names its candidates in
  - `candidateGroups` / `candidateRole` and has no `role` field, so the old
  - client-side role filter dropped every row (#936). `isTerminal=false`
  - keeps completed tasks out; the endpoint has no `status` parameter.
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
					<span class="my-approvals-widget__role">{{
						step.displayTitle || step.title || t('buildiq', 'Approval')
					}}</span>
					<span
						v-if="candidateLabel(step)"
						class="my-approvals-widget__object"
						>{{ candidateLabel(step) }}</span
					>
					<span
						v-if="deadlineOf(step)"
						class="my-approvals-widget__deadline">
						<span
							v-if="deadlineFlag(step)"
							class="my-approvals-widget__flag"
							:class="[
								`my-approvals-widget__flag--${deadlineFlag(step)}`,
							]"
							data-testid="my-approvals-deadline-flag">
							{{
								deadlineFlag(step) === 'overdue'
									? t('buildiq', 'Overdue')
									: t('buildiq', 'Due soon')
							}}
						</span>
						<time
							:datetime="deadlineOf(step)"
							data-testid="my-approvals-due">
							{{
								t('buildiq', 'Due {date}', {
									date: formatDeadline(deadlineOf(step)),
								})
							}}
						</time>
					</span>
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

/**
 * The inbox scopes that together mean "waiting for me": the unclaimed tasks
 * in my groups, and the ones I hold.
 */
const INBOX_SCOPES = ['pooled', 'assigned']

/**
 * A task with less than this many whole days left is flagged "Due soon".
 * OpenRegister's `daysUntilDue` counts whole days, so 0 is under a day and
 * 1 is under two.
 */
const DUE_SOON_DAYS = 2

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
		 * Open tasks waiting for the viewer. OpenRegister already narrowed
		 * each scope to the viewer's user and groups, so no client filter.
		 *
		 * @return {Array}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#4.1
		 */
		pendingSteps() {
			return this.steps
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The task's deadline: its advisory due date, else its enforced
		 * expiry. The same order OpenRegister's `TaskTemporalProjection`
		 * uses for `overdue` and `daysUntilDue`.
		 *
		 * @param {object} step - the task row.
		 * @return {string|null} ISO-8601 instant, or null.
		 * @spec openspec/changes/automation-approval-steps/tasks.md#4.1
		 */
		deadlineOf(step) {
			return step.dueAt || step.expiresAt || null
		},

		/**
		 * Which warning a row carries, read off the server's projection.
		 *
		 * @param {object} step - the task row.
		 * @return {string} 'overdue', 'soon' or ''.
		 * @spec openspec/changes/automation-approval-steps/tasks.md#4.1
		 */
		deadlineFlag(step) {
			if (step.overdue === true) {
				return 'overdue'
			}
			if (
				typeof step.daysUntilDue === 'number'
				&& step.daysUntilDue < DUE_SOON_DAYS
			) {
				return 'soon'
			}
			return ''
		},

		/**
		 * Format a deadline in the viewer's locale.
		 *
		 * @param {string} iso - ISO-8601 instant.
		 * @return {string}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#4.1
		 */
		formatDeadline(iso) {
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) {
				return iso
			}
			return date.toLocaleString(undefined, {
				dateStyle: 'medium',
				timeStyle: 'short',
			})
		},

		/**
		 * Who the task is offered to, for the row's second line.
		 *
		 * @param {object} step - the task row.
		 * @return {string}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#4.1
		 */
		candidateLabel(step) {
			const groups = Array.isArray(step.candidateGroups)
				? step.candidateGroups
				: []
			if (groups.length > 0) {
				return groups.join(', ')
			}
			return step.candidateRole || ''
		},

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
				// openregister #3302 retired /api/approval-steps; an approval is an
				// ordered task sequence now, and its open positions are tasks.
				const url = generateUrl('/apps/openregister/api/flow-tasks')
				const pages = await Promise.all(
					INBOX_SCOPES.map((scope) =>
						axios.get(url, {
							params: { scope, isTerminal: 'false', limit: 50 },
						}),
					),
				)
				// A claimed task can surface in more than one scope: show it once.
				const seen = new Set()
				const rows = []
				for (const { data } of pages) {
					const page = Array.isArray(data) ? data : (data?.results ?? [])
					for (const row of page) {
						const key = row.uuid ?? row.id
						if (!seen.has(key)) {
							seen.add(key)
							rows.push(row)
						}
					}
				}
				// Each scope comes back sorted by deadline; keep that order
				// across the merge, with tasks that have no deadline last.
				this.steps = rows
					.map((row, index) => ({ row, index }))
					.sort((a, b) => {
						const da = this.deadlineOf(a.row)
						const db = this.deadlineOf(b.row)
						if (da && db) {
							return new Date(da) - new Date(db) || a.index - b.index
						}
						if (da || db) {
							return da ? -1 : 1
						}
						return a.index - b.index
					})
					.map(({ row }) => row)
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
				// One endpoint with an outcome, not two verbs: #3302 replaced
				// approve/reject with the task lifecycle's `complete`.
				const url = generateUrl(
					`/apps/openregister/api/flow-tasks/${step.uuid ?? step.id}/complete`,
				)
				await axios.post(url, {
					outcome: action === 'approve' ? 'approved' : 'rejected',
					// A rejecting outcome refuses an empty comment (TaskService::
					// completeInternal), and this widget has no comment field, so
					// send a truthful provenance line rather than an empty string.
					...(action === 'reject'
						? {
								comment: t(
									'buildiq',
									'Rejected from the My approvals widget.',
								),
							}
						: {}),
				})
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

.my-approvals-widget__deadline {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.my-approvals-widget__flag {
	padding: 0 6px;
	border-radius: var(--border-radius-pill, 12px);
	font-weight: bold;
}

.my-approvals-widget__flag--overdue {
	color: var(--color-error-text, var(--color-error));
	border: 1px solid var(--color-error);
}

.my-approvals-widget__flag--soon {
	color: var(--color-warning-text, var(--color-warning));
	border: 1px solid var(--color-warning);
}

.my-approvals-widget__row-actions {
	display: flex;
	gap: 6px;
}
</style>
