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
					<span
						v-if="formLine(step)"
						class="my-approvals-widget__form"
						:class="{ 'my-approvals-widget__form--blocked': formState(step) !== 'ready' }"
						data-testid="my-approvals-form">
						{{ formLine(step) }}
					</span>
				</div>
				<div class="my-approvals-widget__row-actions">
					<NcButton
						variant="primary"
						:disabled="!!deciding[step.id] || !approvable(step)"
						data-testid="approve-button"
						@click="approve(step)">
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

		<ApprovalTaskFormDialog
			v-if="formTask"
			:task="formTask"
			:dialogTitle="formTask.displayTitle || formTask.title || ''"
			@completed="onFormCompleted"
			@close="formTask = null" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { defineAsyncComponent } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

// Loaded when an approver opens a form, so the widget (and every host that
// registers it) does not pull the task form in at boot.
const ApprovalTaskFormDialog = defineAsyncComponent(() => import('../../dialogs/ApprovalTaskFormDialog.vue'))

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

/**
 * The rows of one scope's answer: a bare list or a `results` envelope.
 *
 * @param {Array<object>|object|null} data - the response body.
 * @return {Array<object>}
 */
function pageRows(data) {
	return Array.isArray(data) ? data : (data?.results ?? [])
}

/**
 * Merge the scope answers into one list. A claimed task can surface in more
 * than one scope: show it once.
 *
 * @param {Array<{data: object}>} pages - one axios response per scope.
 * @return {Array<object>} the task rows, in arrival order.
 */
function uniqueRows(pages) {
	const seen = new Set()
	const rows = []
	for (const { data } of pages) {
		for (const row of pageRows(data)) {
			const key = row.uuid ?? row.id
			if (!seen.has(key)) {
				seen.add(key)
				rows.push(row)
			}
		}
	}
	return rows
}

/**
 * Order two deadlines: by time when both are set, the dated one first when
 * only one is; 0 when neither is (and NaN for an unreadable pair).
 *
 * @param {?string} da - a deadline.
 * @param {?string} db - another deadline.
 * @return {number}
 */
function compareDeadlines(da, db) {
	if (!da || !db) {
		return compareMissingDeadlines(da, db)
	}
	return new Date(da) - new Date(db)
}

/**
 * Order two deadlines of which at least one is missing.
 *
 * @param {?string} da - a deadline.
 * @param {?string} db - another deadline.
 * @return {number} -1 when only `da` is set, 1 when only `db` is, else 0.
 */
function compareMissingDeadlines(da, db) {
	if (da) {
		return -1
	}
	return db ? 1 : 0
}

export default {
	name: 'MyApprovalsWidget',
	components: { ApprovalTaskFormDialog, NcButton, NcNoteCard },
	data() {
		return {
			loading: false,
			error: false,
			steps: [],
			deciding: {},
			decideError: '',
			taskForms: {},
			formTask: null,
		}
	},

	computed: {
		/**
		 * Open tasks waiting for the viewer. OpenRegister already narrowed
		 * each scope to the viewer's user and groups, so no client filter.
		 *
		 * @return {Array}
		 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#4.1
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
		 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#4.1
		 */
		deadlineOf(step) {
			return step.dueAt || step.expiresAt || null
		},

		/**
		 * Which warning a row carries, read off the server's projection.
		 *
		 * @param {object} step - the task row.
		 * @return {string} 'overdue', 'soon' or ''.
		 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#4.1
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
		 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#4.1
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
		 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#4.1
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
		 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#4.1
		 */
		async load() {
			this.loading = true
			this.error = false
			try {
				const pages = await this.readInboxPages()
				this.steps = this.byDeadline(uniqueRows(pages))
				await this.readTaskForms(this.steps)
			} catch (err) {
				this.error = true
				this.steps = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Read the open tasks of every inbox scope.
		 *
		 * @return {Promise<Array<object>>} one axios response per scope.
		 */
		readInboxPages() {
			// openregister #3302 retired /api/approval-steps; an approval is an
			// ordered task sequence now, and its open positions are tasks.
			const url = generateUrl('/apps/openregister/api/flow-tasks')
			return Promise.all(
				INBOX_SCOPES.map((scope) =>
					axios.get(url, {
						params: { scope, isTerminal: 'false', limit: 50 },
					}),
				),
			)
		},

		/**
		 * Each scope comes back sorted by deadline; keep that order across the
		 * merge, with tasks that have no deadline last.
		 *
		 * @param {Array<object>} rows - the merged task rows, in arrival order.
		 * @return {Array<object>}
		 */
		byDeadline(rows) {
			return rows
				.map((row, index) => ({ row, index }))
				.sort((a, b) => this.compareByDeadline(a, b))
				.map(({ row }) => row)
		},

		/**
		 * Earlier deadline first; equal or unreadable deadlines, and two
		 * undated tasks, keep their arrival order.
		 *
		 * @param {{row: object, index: number}} a - a task and its position.
		 * @param {{row: object, index: number}} b - another task and its position.
		 * @return {number}
		 */
		compareByDeadline(a, b) {
			const da = this.deadlineOf(a.row)
			const db = this.deadlineOf(b.row)
			return compareDeadlines(da, db) || a.index - b.index
		},

		/**
		 * Whether an inbox row's task carries a form: OpenRegister copies a
		 * position's form into the task's `metadata.form` when it opens it.
		 *
		 * @param {object} step - the inbox row.
		 * @return {boolean}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003
		 */
		hasForm(step) {
			const form = step && step.metadata && step.metadata.form
			return Boolean(form) && typeof form === 'object' && !Array.isArray(form)
		},

		/**
		 * Read `GET /flow-tasks/{uuid}` for every row with a form, for the
		 * form's state and the dialog. A failed read leaves the row without
		 * a resolved form, so it cannot be approved.
		 *
		 * @param {Array<object>} steps - the inbox rows.
		 * @return {Promise<void>}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003
		 */
		async readTaskForms(steps) {
			const withForm = steps.filter((step) => this.hasForm(step))
			const read = await Promise.all(withForm.map(async (step) => {
				const uuid = step.uuid ?? step.id
				try {
					const { data } = await axios.get(
						generateUrl(`/apps/openregister/api/flow-tasks/${encodeURIComponent(uuid)}`),
					)
					return [step.id, data || null]
				} catch {
					return [step.id, null]
				}
			}))
			this.taskForms = Object.fromEntries(read)
		},

		/**
		 * The form state of a row: '' without a form, `unread` when its task
		 * could not be read, else the state OpenRegister reports.
		 *
		 * @param {object} step - the inbox row.
		 * @return {string}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003
		 */
		formState(step) {
			if (!this.hasForm(step)) {
				return ''
			}
			const task = this.taskForms[step.id]
			const form = task && task.form
			if (!form || typeof form !== 'object') {
				return 'unread'
			}
			return String(form.state || 'broken')
		},

		/**
		 * The row's extra line: how many fields the approver fills in, or
		 * why the form cannot be used.
		 *
		 * @param {object} step - the inbox row.
		 * @return {string}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003
		 */
		formLine(step) {
			const state = this.formState(step)
			if (state === '') {
				return ''
			}
			if (state === 'unread') {
				return t('buildiq', 'The form of this approval could not be loaded.')
			}
			const form = this.taskForms[step.id].form
			const fields = Array.isArray(form.fields) ? form.fields : []
			if (state === 'ready') {
				return n('buildiq', '{count} field to fill in', '{count} fields to fill in', fields.length, { count: fields.length })
			}
			const broken = fields.find((field) => field && field.renderable === false && field.reason)
			return (broken && broken.reason) || form.error || t('buildiq', 'The form of this approval cannot be filled in.')
		},

		/**
		 * Whether Approve is offered: always without a form, only for a ready one with.
		 *
		 * @param {object} step - the inbox row.
		 * @return {boolean}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003
		 */
		approvable(step) {
			const state = this.formState(step)
			return state === '' || state === 'ready'
		},

		/**
		 * Approve a row: open the form dialog when its task asks for fields,
		 * else complete it as before.
		 *
		 * @param {object} step - the inbox row.
		 * @return {Promise<void>|void}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003
		 */
		approve(step) {
			if (!this.approvable(step)) {
				return undefined
			}
			if (this.formState(step) === 'ready') {
				this.decideError = ''
				this.formTask = this.taskForms[step.id]
				return undefined
			}
			return this.decide(step, 'approve')
		},

		/**
		 * The form dialog completed the task: close it and reload the inbox.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md#requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003
		 */
		async onFormCompleted() {
			this.formTask = null
			await this.load()
		},

		/**
		 * Approve or reject a step by calling OpenRegister's endpoint DIRECTLY
		 * — no Buildiq controller mediates the call (task 4.2).
		 *
		 * @param {object} step - the approval step row.
		 * @param {string} action - 'approve' or 'reject'.
		 * @return {Promise<void>}
		 * @spec openspec/changes/archive/2026-07-23-automation-approval-steps/tasks.md#4.2
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

<style scoped lang="scss">
@use '../../assets/editor-controls.scss' as c;

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
	@include c.plain-list(8px);
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

.my-approvals-widget__form {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}

.my-approvals-widget__form--blocked {
	color: var(--color-error-text, var(--color-error));
}

.my-approvals-widget__row-actions {
	display: flex;
	gap: 6px;
}
</style>
