<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SchedulesSection — the "Scheduled tasks" section on the page-designer
  - surface (REQ-OBSA-001). Lists the app's top-level `manifest.schedules[]`
  - entries with add / edit / remove actions, hosting the standalone
  - ScheduleEditDialog (modal-isolation rule).
  -
  - Pure controlled component: `manifest` prop in, `update:manifest` event out.
  - Every mutation emits a shallow-cloned manifest whose `schedules` array is
  - replaced; the section never calls a save API of its own — persistence rides
  - PageDesignerHost.save() (the ApplicationVersion PUT).
  -->
<template>
	<section class="ob-schedules-section">
		<header class="ob-schedules-section__header">
			<h3 class="ob-schedules-section__title">
				{{ t('openbuild', 'Scheduled tasks') }}
			</h3>
			<NcButton type="secondary" @click="openAdd">
				{{ t('openbuild', 'Add scheduled task') }}
			</NcButton>
		</header>

		<p v-if="schedules.length === 0" class="ob-schedules-section__empty">
			{{ t('openbuild', 'No scheduled tasks yet. Add one to run a synchronization on a schedule.') }}
		</p>
		<ul v-else class="ob-schedules-section__list">
			<li v-for="schedule in schedules" :key="schedule.id" class="ob-schedules-section__item">
				<div class="ob-schedules-section__item-main">
					<strong>{{ schedule.id }}</strong>
					<span class="ob-schedules-section__item-meta">
						{{ cadenceSummary(schedule) }} · {{ actionSummary(schedule) }} · {{ syncSummary(schedule) }}
					</span>
				</div>
				<div class="ob-schedules-section__item-side">
					<span
						class="ob-schedules-section__enabled"
						:class="{ 'ob-schedules-section__enabled--off': schedule.enabled === false }">
						{{ schedule.enabled === false ? t('openbuild', 'Disabled') : t('openbuild', 'Enabled') }}
					</span>
					<NcButton type="tertiary" @click="openEdit(schedule)">
						{{ t('openbuild', 'Edit') }}
					</NcButton>
					<NcButton type="tertiary" @click="remove(schedule)">
						{{ t('openbuild', 'Remove') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<ScheduleEditDialog
			:open.sync="dialogOpen"
			:entry="editingEntry"
			:existing-ids="otherIds"
			@save="onDialogSave" />
	</section>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ScheduleEditDialog from '../dialogs/ScheduleEditDialog.vue'

/** Known interval presets, seconds → i18n cadence label key. */
const INTERVAL_LABELS = Object.freeze({
	3600: 'Hourly',
	86400: 'Daily',
	604800: 'Weekly',
	2592000: 'Monthly',
})

export default {
	name: 'SchedulesSection',
	components: { NcButton, ScheduleEditDialog },
	props: {
		manifest: {
			type: Object,
			default: () => ({}),
		},
	},
	emits: ['update:manifest'],
	data() {
		return {
			dialogOpen: false,
			editingEntry: null,
		}
	},
	computed: {
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-001 */
		schedules() {
			return (this.manifest && Array.isArray(this.manifest.schedules) && this.manifest.schedules) || []
		},
		/**
		 * Ids used by entries OTHER than the one being edited (for the
		 * dialog's uniqueness check).
		 *
		 * @return {string[]}
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-004
		 */
		otherIds() {
			const editingId = this.editingEntry && this.editingEntry.id
			return this.schedules.map((s) => s.id).filter((id) => id && id !== editingId)
		},
	},
	methods: {
		/**
		 * Human cadence summary for a schedule row.
		 *
		 * @param {object} schedule - the schedule entry.
		 * @return {string}
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-001
		 */
		cadenceSummary(schedule) {
			if (typeof schedule.cron === 'string' && schedule.cron !== '') {
				return t('openbuild', 'Cron: {expr}', { expr: schedule.cron })
			}
			if (typeof schedule.interval === 'number') {
				const label = INTERVAL_LABELS[schedule.interval]
				if (label) {
					return t('openbuild', label)
				}
				return t('openbuild', 'Every {seconds}s', { seconds: schedule.interval })
			}
			return t('openbuild', 'No cadence')
		},
		/**
		 * Human action summary for a schedule row.
		 *
		 * @param {object} schedule - the schedule entry.
		 * @return {string}
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-003
		 */
		actionSummary(schedule) {
			if (schedule.action === 'openconnector:synchronization') {
				return t('openbuild', 'Run a synchronization')
			}
			return schedule.action || t('openbuild', 'No action')
		},
		/**
		 * The target synchronization id for a schedule row.
		 *
		 * @param {object} schedule - the schedule entry.
		 * @return {string}
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-003
		 */
		syncSummary(schedule) {
			const id = schedule.arguments && schedule.arguments.synchronizationId
			return id || t('openbuild', 'No synchronization')
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-001 */
		openAdd() {
			this.editingEntry = null
			this.dialogOpen = true
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-005 */
		openEdit(schedule) {
			this.editingEntry = schedule
			this.dialogOpen = true
		},
		/**
		 * Persist an added / edited entry into `manifest.schedules[]`.
		 *
		 * @param {object} entry - the assembled schedule entry from the dialog.
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-005
		 */
		onDialogSave(entry) {
			const list = this.schedules.slice()
			const idx = this.editingEntry ? list.findIndex((s) => s.id === this.editingEntry.id) : -1
			if (idx >= 0) {
				list.splice(idx, 1, entry)
			} else {
				list.push(entry)
			}
			this.$emit('update:manifest', this.withSchedules(list))
		},
		/**
		 * Remove an entry from `manifest.schedules[]`.
		 *
		 * @param {object} schedule - the entry to remove.
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-005
		 */
		remove(schedule) {
			const ok = typeof window !== 'undefined' && window.confirm
				? window.confirm(t('openbuild', 'Remove this scheduled task?'))
				: true
			if (!ok) {
				return
			}
			const list = this.schedules.filter((s) => s.id !== schedule.id)
			this.$emit('update:manifest', this.withSchedules(list))
		},
		/**
		 * Return a manifest copy with the given schedules list set (or the
		 * `schedules` key removed when empty so zero-schedule manifests
		 * serialize byte-identically).
		 *
		 * @param {Array} list - the schedules list.
		 * @return {object}
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-007
		 */
		withSchedules(list) {
			const next = { ...this.manifest }
			if (list.length === 0) {
				delete next.schedules
			} else {
				next.schedules = list
			}
			return next
		},
	},
}
</script>

<style scoped>
.ob-schedules-section__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.ob-schedules-section__title {
	margin: 0;
}

.ob-schedules-section__empty {
	color: var(--color-text-maxcontrast);
}

.ob-schedules-section__list {
	list-style: none;
	padding: 0;
	margin: 0;
}

.ob-schedules-section__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.ob-schedules-section__item-meta {
	color: var(--color-text-maxcontrast);
	margin-left: 8px;
}

.ob-schedules-section__item-side {
	display: flex;
	align-items: center;
	gap: 8px;
}

.ob-schedules-section__enabled {
	color: var(--color-success, var(--color-main-text));
	font-size: 0.9em;
}

.ob-schedules-section__enabled--off {
	color: var(--color-text-maxcontrast);
}
</style>
