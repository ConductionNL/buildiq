<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AutomationsPage — the unified "when X happens, do Y" surface
  - (spec automation-designer REQ-AUTD-001). Lists every `automation`
  - object for the selected Application + ApplicationVersion with enabled
  - state and drift badges; opens AutomationEditDialog to compose a new
  - automation or edit an existing one, and AutomationTestPanelModal for a
  - dry-run.
  -
  - Automation object CRUD goes through OpenRegister's REST surface
  - (ADR-022); the effectual compile/enable/disable/dry-run/status calls go
  - through the buildiq AutomationsController API.
  -->
<template>
	<div class="automations-page">
		<header class="automations-page__header">
			<h2>{{ t('buildiq', 'Automations') }}</h2>
			<NcButton
				variant="secondary"
				:disabled="!selectedApp"
				@click="openFlows">
				{{ t('buildiq', 'Edit flows…') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!selectedVersionId"
				@click="openNew">
				{{ t('buildiq', 'New automation') }}
			</NcButton>
		</header>

		<div class="automations-page__pickers">
			<NcSelect
				v-model="selectedApp"
				class="automations-page__picker"
				:inputLabel="t('buildiq', 'Application')"
				:options="applications"
				:loading="loadingApplications"
				label="name"
				trackBy="slug"
				@update:modelValue="onAppChange" />
			<NcSelect
				v-model="selectedVersion"
				class="automations-page__picker"
				:inputLabel="t('buildiq', 'Version')"
				:options="versions"
				:loading="loadingVersions"
				:disabled="!selectedApp"
				label="name"
				trackBy="id"
				@update:modelValue="onVersionChange" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="selectedVersionId && automations.length === 0"
			:name="t('buildiq', 'No automations yet')"
			:description="
				t(
					'buildiq',
					'Create an automation to compose a trigger, an optional condition, and one or more actions.',
				)
			" />

		<p v-else-if="!selectedVersionId" class="automations-page__hint">
			{{
				t(
					'buildiq',
					'Select an application and a version to see its automations.',
				)
			}}
		</p>

		<ul v-else class="automations-page__list">
			<li
				v-for="automation in automations"
				:key="automation.id"
				class="automations-page__item"
				data-testid="automation-row">
				<div class="automations-page__item-main">
					<strong>{{ automation.name || automation.slug }}</strong>
					<span class="automations-page__item-meta">
						{{ triggerSummary(automation) }} ·
						{{ actionSummary(automation) }}
					</span>
					<span
						v-if="driftFor(automation.id)"
						class="automations-page__drift-badge"
						data-testid="drift-badge">
						{{ t('buildiq', 'Drift detected') }}
						<NcButton variant="tertiary" @click="recompile(automation)">
							{{ t('buildiq', 'Recompile (overwrite)') }}
						</NcButton>
					</span>
					<span
						v-if="approvalStateFor(automation.id)"
						class="automations-page__approval-badge"
						:class="`automations-page__approval-badge--${approvalStateFor(automation.id)}`"
						data-testid="approval-state-badge">
						{{ approvalStateLabel(approvalStateFor(automation.id)) }}
					</span>
				</div>
				<div class="automations-page__item-side">
					<NcCheckboxRadioSwitch
						type="switch"
						:modelValue="automation.enabled !== false"
						@update:modelValue="toggleEnabled(automation, $event)">
						{{ t('buildiq', 'Enabled') }}
					</NcCheckboxRadioSwitch>
					<NcButton variant="tertiary" @click="openTestPanel(automation)">
						{{ t('buildiq', 'Test') }}
					</NcButton>
					<NcButton variant="tertiary" @click="openEdit(automation)">
						{{ t('buildiq', 'Edit') }}
					</NcButton>
					<NcButton variant="tertiary" @click="remove(automation)">
						{{ t('buildiq', 'Delete') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>

		<AutomationEditDialog
			v-model:open="editDialogOpen"
			:automation="editingAutomation"
			:register="selectedVersion ? selectedVersion.register : ''"
			@saved="onDialogSaved" />

		<AutomationTestPanelModal
			v-if="testPanelOpen"
			:automation="testingAutomation"
			@close="testPanelOpen = false" />

		<!--
		  flow-engine-unification task 6.4: the shared node/edge canvas, scoped
		  to this built application (`app`), not the fixed "buildiq" — each
		  application under construction gets its own flows, mirroring how
		  automations are already scoped by `applicationSlug` above. CnFlowDetail
		  treats the literal id "new" as "start blank", so this always opens
		  usably even before the app has a first flow.
		-->
		<FlowPickerDialog
			v-if="flowPickerOpen"
			:app="selectedApp ? selectedApp.slug : null"
			@pick="onFlowPicked"
			@close="flowPickerOpen = false" />

		<CnFlowEditModal
			v-if="flowModalOpen"
			:flowId="editingFlowId"
			:app="selectedApp ? selectedApp.slug : null"
			@close="flowModalOpen = false" />

		<ConfirmActionDialog
			v-model:open="confirmDeleteOpen"
			:name="t('buildiq', 'Delete automation')"
			:message="
				t(
					'buildiq',
					'Delete this automation? This also removes its compiled artifacts.',
				)
			"
			:confirmLabel="t('buildiq', 'Delete')"
			:busy="deleting"
			destructive
			@confirm="onConfirmDelete" />
	</div>
</template>

<script>
import { CnFlowEditModal } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import AutomationEditDialog from '../dialogs/AutomationEditDialog.vue'
import ConfirmActionDialog from '../dialogs/ConfirmActionDialog.vue'
import FlowPickerDialog from '../dialogs/FlowPickerDialog.vue'
import AutomationTestPanelModal from '../modals/AutomationTestPanelModal.vue'

export default {
	name: 'AutomationsPage',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		AutomationEditDialog,
		AutomationTestPanelModal,
		ConfirmActionDialog,
		CnFlowEditModal,
		FlowPickerDialog,
	},

	data() {
		return {
			loading: false,
			loadingApplications: false,
			loadingVersions: false,
			applications: [],
			selectedApp: null,
			versions: [],
			selectedVersion: null,
			automations: [],
			statusByUuid: {},
			errorMessage: '',
			editDialogOpen: false,
			flowPickerOpen: false,
			flowModalOpen: false,
			editingFlowId: 'new',
			editingAutomation: null,
			testPanelOpen: false,
			testingAutomation: null,
			confirmDeleteOpen: false,
			pendingDelete: null,
			deleting: false,
		}
	},

	computed: {
		/** @spec openspec/changes/automation-designer/tasks.md#5.1 */
		selectedVersionId() {
			return this.selectedVersion ? this.selectedVersion.id : ''
		},
	},

	mounted() {
		this.fetchApplications()
	},

	methods: {
		/**
		 * Load the caller's Applications for the picker.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-001
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
		 * Handle an application selection: reset the version + list, fetch versions.
		 *
		 * @return {void}
		 */
		onAppChange() {
			this.selectedVersion = null
			this.versions = []
			this.automations = []
			if (this.selectedApp) {
				this.fetchVersions()
			}
		},

		/**
		 * Load the selected Application's versions for the picker (REQ-AUTD-001
		 * version selector).
		 *
		 * @return {Promise<void>}
		 */
		async fetchVersions() {
			this.loadingVersions = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					`/apps/buildiq/api/applications/${this.selectedApp.slug}/versions`,
				)
				const { data } = await axios.get(url)
				this.versions = this.extractResults(data)
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not load versions.')
			} finally {
				this.loadingVersions = false
			}
		},

		/**
		 * Handle a version selection: fetch its automations.
		 *
		 * @return {void}
		 */
		onVersionChange() {
			this.automations = []
			if (this.selectedVersion) {
				this.fetchAutomations()
			}
		},

		/**
		 * Load every `automation` object and filter to the selected
		 * Application + ApplicationVersion, then fetch drift status for each.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-001
		 */
		async fetchAutomations() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/buildiq/automation',
				)
				const { data } = await axios.get(url)
				const all = this.extractResults(data)
				this.automations = all.filter(
					(a) =>
						a.applicationSlug === this.selectedApp.slug
						&& a.versionUuid === this.selectedVersionId,
				)
				await this.refreshStatuses()
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not load automations.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Refresh the drift-status badge for every listed automation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-005
		 */
		async refreshStatuses() {
			const entries = await Promise.all(
				this.automations.map(async (automation) => {
					try {
						const url = generateUrl(
							`/apps/buildiq/api/automations/${automation.id}/status`,
						)
						const { data } = await axios.get(url)
						return [automation.id, data]
					} catch (error) {
						return [automation.id, null]
					}
				}),
			)
			const map = {}
			entries.forEach(([id, status]) => {
				map[id] = status
			})
			this.statusByUuid = map
		},

		/**
		 * Whether the given automation has detected drift.
		 *
		 * @param {string} uuid - the automation's uuid.
		 * @return {boolean}
		 */
		driftFor(uuid) {
			const status = this.statusByUuid[uuid]
			return !!(status && status.drift === true)
		},

		/**
		 * The automation's live aggregate approval state, or '' when none/absent
		 * (spec REQ-AUTD-007 — status surfaces approval state).
		 *
		 * @param {string} uuid - the automation's uuid.
		 * @return {string}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#5.1
		 */
		approvalStateFor(uuid) {
			const status = this.statusByUuid[uuid]
			const state = status && status.approvalState
			return state && state !== 'none' ? state : ''
		},

		/**
		 * Human label for an approval state value.
		 *
		 * @param {string} state - one of pending|approved|rejected.
		 * @return {string}
		 * @spec openspec/changes/automation-approval-steps/tasks.md#5.1
		 */
		approvalStateLabel(state) {
			const labels = {
				pending: t('buildiq', 'Approval pending'),
				approved: t('buildiq', 'Approved'),
				rejected: t('buildiq', 'Rejected'),
			}
			return labels[state] || state
		},

		/**
		 * Human trigger summary for a row.
		 *
		 * @param {object} automation - the automation object.
		 * @return {string}
		 */
		triggerSummary(automation) {
			const trigger = automation.trigger || {}
			const labels = {
				'object-created': t('buildiq', 'When an object is created'),
				'object-updated': t('buildiq', 'When an object is updated'),
				'object-deleted': t('buildiq', 'When an object is deleted'),
				'lifecycle-transition': t('buildiq', 'On lifecycle transition'),
				schedule: t('buildiq', 'On a schedule'),
				manual: t('buildiq', 'Manual'),
			}
			return labels[trigger.type] || t('buildiq', 'No trigger')
		},

		/**
		 * Human action summary for a row.
		 *
		 * @param {object} automation - the automation object.
		 * @return {string}
		 */
		actionSummary(automation) {
			const actions = Array.isArray(automation.actions)
				? automation.actions
				: []
			if (actions.length === 0) {
				return t('buildiq', 'No actions')
			}
			return actions.map((a) => a.type).join(', ')
		},

		/**
		 * Open the dialog for a new automation, pre-seeded with the selected
		 * Application + Version.
		 *
		 * @return {void}
		 */
		openNew() {
			this.editingAutomation = {
				slug: '',
				name: '',
				description: '',
				applicationSlug: this.selectedApp.slug,
				versionUuid: this.selectedVersionId,
				enabled: true,
				trigger: { type: 'manual' },
				condition: null,
				actions: [],
			}
			this.editDialogOpen = true
		},

		/**
		 * Offer the selected application's flows: pick one to edit, or start a
		 * new one.
		 *
		 * `flow-engine-unification` task 6.4. This used to hard-code `new`,
		 * which made the modal a creator that could never edit — a flow saved a
		 * minute earlier was unreachable from this surface.
		 *
		 * @return {void}
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-001
		 */
		openFlows() {
			this.flowPickerOpen = true
		},

		/**
		 * Open the flow the picker chose on the shared canvas.
		 *
		 * @param {string} id The flow id, or the literal `new`.
		 * @return {void}
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-001
		 */
		onFlowPicked(id) {
			this.editingFlowId = id
			this.flowPickerOpen = false
			this.flowModalOpen = true
		},

		/**
		 * Open the dialog to edit an existing automation.
		 *
		 * @param {object} automation - the automation to edit.
		 * @return {void}
		 */
		openEdit(automation) {
			this.editingAutomation = automation
			this.editDialogOpen = true
		},

		/**
		 * Refresh the list after the dialog saves.
		 *
		 * @return {void}
		 */
		onDialogSaved() {
			this.editDialogOpen = false
			this.fetchAutomations()
		},

		/**
		 * Open the dry-run test panel for an automation.
		 *
		 * @param {object} automation - the automation to test.
		 * @return {void}
		 */
		openTestPanel(automation) {
			this.testingAutomation = automation
			this.testPanelOpen = true
		},

		/**
		 * Enable or disable an automation (spec REQ-AUTD-006).
		 *
		 * @param {object} automation - the automation row.
		 * @param {boolean} checked - the new enabled state.
		 * @return {Promise<void>}
		 */
		async toggleEnabled(automation, checked) {
			this.errorMessage = ''
			const action = checked ? 'enable' : 'disable'
			try {
				const url = generateUrl(
					`/apps/buildiq/api/automations/${automation.id}/${action}`,
				)
				await axios.post(url, {})
				await this.fetchAutomations()
			} catch (error) {
				this.errorMessage = checked
					? t('buildiq', 'Could not enable the automation.')
					: t('buildiq', 'Could not disable the automation.')
			}
		},

		/**
		 * Recompile-overwrite a drifted automation (spec REQ-AUTD-005).
		 *
		 * @param {object} automation - the automation row.
		 * @return {Promise<void>}
		 */
		async recompile(automation) {
			this.errorMessage = ''
			try {
				const url = generateUrl(
					`/apps/buildiq/api/automations/${automation.id}/compile`,
				)
				await axios.post(url, {})
				await this.fetchAutomations()
			} catch (error) {
				this.errorMessage = t('buildiq', 'Recompile failed.')
			}
		},

		/**
		 * Delete an automation. The OR delete triggers the server-side
		 * AutomationCleanupListener, which removes exactly the
		 * provenance-listed compiled artifacts (REQ-AUTD-005).
		 *
		 * @param {object} automation - the automation to delete.
		 * @return {Promise<void>}
		 */
		/**
		 * Ask for confirmation before deleting an automation.
		 *
		 * This no longer deletes anything itself — it stages the target and
		 * opens the dialog. The DELETE lives in onConfirmDelete().
		 *
		 * @param {object} automation - the automation to delete.
		 * @return {void}
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-005
		 */
		remove(automation) {
			this.pendingDelete = automation
			this.confirmDeleteOpen = true
		},

		/**
		 * Delete the pending automation once the user has confirmed it.
		 *
		 * The DELETE runs ONLY from here, so a cancelled or dismissed dialog
		 * cannot reach the network. `deleting` keeps the dialog open and its
		 * buttons disabled while the request is in flight, which the old
		 * synchronous window.confirm could not express.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/automation-designer/spec.md#req-autd-005
		 */
		async onConfirmDelete() {
			const automation = this.pendingDelete
			if (!automation) {
				this.confirmDeleteOpen = false
				return
			}
			this.errorMessage = ''
			this.deleting = true
			try {
				// Buildiq's own endpoint: it authorises against the parent
				// Application's `permissions` block and removes the COMPILED
				// ARTIFACTS before deleting the definition. Deleting straight
				// over OR REST 403s for every non-admin (the `automation`
				// schema is admin-only on `delete`) and, for an admin, would
				// leave the compiled notifications/schedules live with no
				// definition left to edit them from. Conduction/buildiq#173.
				const url = generateUrl(
					`/apps/buildiq/api/automations/${automation.id}`,
				)
				await axios.delete(url)
				await this.fetchAutomations()
			} catch (error) {
				this.errorMessage = t('buildiq', 'Could not delete the automation.')
			} finally {
				this.deleting = false
				this.confirmDeleteOpen = false
				this.pendingDelete = null
			}
		},

		/**
		 * Normalise an OR REST list response to a plain array.
		 *
		 * @param {object|Array} data - the raw axios response body.
		 * @return {Array}
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
.automations-page {
	padding: 16px;
}

.automations-page__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 16px;
}

.automations-page__pickers {
	display: flex;
	gap: 12px;
	margin-bottom: 16px;
	max-width: 600px;
}

.automations-page__picker {
	flex: 1;
}

.automations-page__hint {
	color: var(--color-text-maxcontrast);
}

.automations-page__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.automations-page__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
	gap: 12px;
}

.automations-page__item-main {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.automations-page__item-meta {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.automations-page__item-side {
	display: flex;
	align-items: center;
	gap: 8px;
}

.automations-page__drift-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-warning-text, var(--color-warning));
	font-size: 0.85em;
}

.automations-page__approval-badge {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 0.85em;
	font-weight: bold;
}

.automations-page__approval-badge--pending {
	color: var(--color-warning-text, var(--color-warning));
}

.automations-page__approval-badge--approved {
	color: var(--color-success-text, var(--color-success));
}

.automations-page__approval-badge--rejected {
	color: var(--color-error-text, var(--color-error));
}
</style>
