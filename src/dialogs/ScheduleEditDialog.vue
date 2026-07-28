<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ScheduleEditDialog — standalone dialog (modal-isolation rule) to author a
  - single manifest `schedules[]` entry (REQ-OBSA-002..004, REQ-OBSA-006).
  -
  - Cadence: an NcSelect preset (Hourly/Daily/Weekly/Monthly → `interval`
  - seconds; Custom cron → validated 5-field `cron`; Custom interval → a raw
  - seconds number, the reverse-mapping escape hatch). The saved entry carries
  - EITHER `interval` OR `cron`, never both — the one-of invariant is enforced
  - at write time, not only at validate time.
  -
  - Action: an NcSelect (`:input-label`) whose only v1 option "Run a
  - synchronization" maps to `action: "openconnector:synchronization"`, plus a
  - synchronization picker fed by OpenRegister objects that degrades to a
  - free-text id field when the list can't load (mirrors ConnectorSourcePicker).
  -
  - Enabled: an NcCheckboxRadioSwitch (default true). Id: a stable kebab-case
  - slug derived from a human label (or typed), unique within the manifest.
  - Emits the assembled entry on save.
  -->
<template>
	<NcModal
		v-if="open"
		:name="editing ? t('openbuild', 'Edit scheduled task') : t('openbuild', 'Add scheduled task')"
		@close="onClose">
		<div class="ob-schedule-edit">
			<h2 class="ob-schedule-edit__title">
				{{ editing ? t('openbuild', 'Edit scheduled task') : t('openbuild', 'Add scheduled task') }}
			</h2>

			<NcTextField
				:model-value="label"
				:label="t('openbuild', 'Label')"
				:placeholder="t('openbuild', 'e.g. Nightly BRP sync')"
				@update:modelValue="onLabelInput" />
			<p class="ob-schedule-edit__hint">
				{{ t('openbuild', 'Identifier') }}: <code>{{ derivedId || '—' }}</code>
			</p>

			<NcSelect
				v-model="cadenceOption"
				:input-label="t('openbuild', 'Cadence')"
				:options="cadenceOptions"
				:clearable="false"
				label="label" />

			<NcTextField
				v-if="isCustomCron"
				:model-value="cron"
				:label="t('openbuild', 'Cron expression (5 fields)')"
				:placeholder="t('openbuild', 'e.g. 0 3 * * 1')"
				:error="cron !== '' && !cronValid"
				:helper-text="cron !== '' && !cronValid ? t('openbuild', 'Enter a valid 5-field cron expression.') : ''"
				@update:modelValue="cron = $event" />

			<NcTextField
				v-if="isCustomInterval"
				:model-value="String(intervalSeconds)"
				type="number"
				:label="t('openbuild', 'Interval (seconds)')"
				:placeholder="t('openbuild', 'e.g. 43200')"
				@update:modelValue="intervalSeconds = $event" />

			<NcSelect
				v-model="actionOption"
				:input-label="t('openbuild', 'Action')"
				:options="actionOptions"
				:clearable="false"
				label="label" />

			<div v-if="isSyncAction" class="ob-schedule-edit__sync">
				<NcSelect
					v-if="syncPickerAvailable"
					v-model="syncOption"
					:input-label="t('openbuild', 'Synchronization')"
					:options="syncOptions"
					:loading="syncLoading"
					label="label"
					@input="onSyncSelect" />
				<div v-else class="ob-schedule-edit__sync-manual">
					<p class="ob-schedule-edit__hint ob-schedule-edit__hint--warning">
						{{ t('openbuild', 'The synchronization list could not be loaded. Enter a synchronization id manually.') }}
					</p>
					<NcTextField
						:model-value="syncId"
						:label="t('openbuild', 'Synchronization id')"
						:placeholder="t('openbuild', 'e.g. 00000000-0000-0000-0000-000000000000')"
						@update:modelValue="syncId = $event" />
				</div>
			</div>

			<NcCheckboxRadioSwitch
				:model-value="enabled"
				type="switch"
				@update:modelValue="enabled = $event">
				{{ t('openbuild', 'Enabled') }}
			</NcCheckboxRadioSwitch>

			<p v-if="showValidation && !valid" class="ob-schedule-edit__error" role="alert">
				{{ t('openbuild', 'Please complete the scheduled task before saving.') }}
			</p>

			<div class="ob-schedule-edit__actions">
				<NcButton @click="onClose">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!valid" @click="onSave">
					{{ t('openbuild', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton, NcSelect, NcTextField, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { validateScheduleEntry, isValidCron } from '../services/manifestValidation/schedules.js'

/** Cadence presets that write an `interval` in seconds. */
const INTERVAL_PRESETS = Object.freeze([
	{ id: 'hourly', interval: 3600 },
	{ id: 'daily', interval: 86400 },
	{ id: 'weekly', interval: 604800 },
	{ id: 'monthly', interval: 2592000 },
])

/**
 * Derive a kebab-case slug from a human label.
 *
 * @param {string} label - the human label.
 * @return {string}
 */
function slugify(label) {
	return String(label || '')
		.toLowerCase()
		.trim()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

/**
 * Suffix `-2`, `-3`, … until the slug is unique against `taken`.
 *
 * @param {string} base - the candidate slug.
 * @param {string[]} taken - ids already used by other entries.
 * @return {string}
 */
function uniqueSlug(base, taken) {
	if (!base) {
		return base
	}
	if (!taken.includes(base)) {
		return base
	}
	let n = 2
	while (taken.includes(`${base}-${n}`)) {
		n++
	}
	return `${base}-${n}`
}

export default {
	name: 'ScheduleEditDialog',
	components: { NcModal, NcButton, NcSelect, NcTextField, NcCheckboxRadioSwitch },
	props: {
		// Whether the dialog is open.
		open: {
			type: Boolean,
			default: false,
		},
		// Existing entry when editing (null when adding).
		entry: {
			type: Object,
			default: null,
		},
		// Ids used by OTHER entries in manifest.schedules[] (for uniqueness).
		existingIds: {
			type: Array,
			default: () => ([]),
		},
	},
	emits: ['update:open', 'save'],
	data() {
		return {
			label: '',
			manualId: '',
			cadenceOption: null,
			cron: '',
			intervalSeconds: '',
			actionOption: null,
			syncOptions: [],
			syncOption: null,
			syncId: '',
			syncLoading: false,
			syncFetchFailed: false,
			enabled: true,
			showValidation: false,
		}
	},
	computed: {
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-005 */
		editing() {
			return !!this.entry
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-002 */
		cadenceOptions() {
			return [
				{ id: 'hourly', label: t('openbuild', 'Hourly') },
				{ id: 'daily', label: t('openbuild', 'Daily') },
				{ id: 'weekly', label: t('openbuild', 'Weekly') },
				{ id: 'monthly', label: t('openbuild', 'Monthly') },
				{ id: 'custom-cron', label: t('openbuild', 'Custom (cron)') },
				{ id: 'custom-interval', label: t('openbuild', 'Custom interval (seconds)') },
			]
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-003 */
		actionOptions() {
			return [
				{ value: 'openconnector:synchronization', label: t('openbuild', 'Run a synchronization') },
			]
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-002 */
		isCustomCron() {
			return this.cadenceOption && this.cadenceOption.id === 'custom-cron'
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-002 */
		isCustomInterval() {
			return this.cadenceOption && this.cadenceOption.id === 'custom-interval'
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-002 */
		cronValid() {
			return isValidCron(this.cron)
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-003 */
		isSyncAction() {
			return this.actionOption && this.actionOption.value === 'openconnector:synchronization'
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-003 */
		syncPickerAvailable() {
			return !this.syncFetchFailed && this.syncOptions.length > 0
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-004 */
		derivedId() {
			if (this.editing) {
				return this.entry.id
			}
			const base = this.manualId ? slugify(this.manualId) : slugify(this.label)
			return uniqueSlug(base, this.existingIds)
		},
		/**
		 * Assemble the candidate entry from the form state, enforcing the
		 * one-of `interval`|`cron` invariant.
		 *
		 * @return {object}
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-002
		 */
		candidateEntry() {
			const entry = {
				id: this.derivedId,
				enabled: this.enabled,
			}
			const cadenceId = this.cadenceOption ? this.cadenceOption.id : null
			const preset = INTERVAL_PRESETS.find((p) => p.id === cadenceId)
			if (preset) {
				entry.interval = preset.interval
			} else if (cadenceId === 'custom-cron') {
				entry.cron = this.cron.trim()
			} else if (cadenceId === 'custom-interval') {
				entry.interval = Number(this.intervalSeconds)
			}
			if (this.actionOption) {
				entry.action = this.actionOption.value
				if (this.isSyncAction) {
					entry.arguments = { synchronizationId: this.syncId.trim() }
				}
			}
			return entry
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-006 */
		valid() {
			if (validateScheduleEntry(this.candidateEntry).length > 0) {
				return false
			}
			// dialog-side uniqueness against the other entries
			return !this.existingIds.includes(this.candidateEntry.id)
		},
	},
	watch: {
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-001 */
		open(isOpen) {
			if (isOpen) {
				this.hydrate()
				this.fetchSynchronizations()
			}
		},
	},
	methods: {
		/**
		 * Seed the form from an existing entry when editing, reverse-mapping
		 * its cadence to a preset / custom-cron / custom-interval.
		 *
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-002
		 */
		hydrate() {
			this.showValidation = false
			this.syncFetchFailed = false
			this.syncOptions = []
			this.syncOption = null
			const e = this.entry
			if (!e) {
				this.label = ''
				this.manualId = ''
				this.cadenceOption = null
				this.cron = ''
				this.intervalSeconds = ''
				this.actionOption = this.actionOptions[0]
				this.syncId = ''
				this.enabled = true
				return
			}
			this.label = e.id || ''
			this.manualId = ''
			this.cron = typeof e.cron === 'string' ? e.cron : ''
			this.intervalSeconds = ''
			if (typeof e.cron === 'string') {
				this.cadenceOption = this.cadenceOptions.find((o) => o.id === 'custom-cron')
			} else {
				const preset = INTERVAL_PRESETS.find((p) => p.interval === e.interval)
				if (preset) {
					this.cadenceOption = this.cadenceOptions.find((o) => o.id === preset.id)
				} else {
					this.cadenceOption = this.cadenceOptions.find((o) => o.id === 'custom-interval')
					this.intervalSeconds = e.interval !== undefined ? String(e.interval) : ''
				}
			}
			this.actionOption = this.actionOptions.find((o) => o.value === e.action) || this.actionOptions[0]
			this.syncId = (e.arguments && e.arguments.synchronizationId) || ''
			this.enabled = e.enabled !== false
		},
		/**
		 * Keep the label field and the auto-slug in sync.
		 *
		 * @param {string} value - the typed label.
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-004
		 */
		onLabelInput(value) {
			this.label = value
		},
		/**
		 * Load synchronizations from OpenRegister objects, mapping to
		 * `{ id, label }`. On any failure the picker degrades to a free-text
		 * id field (mirrors ConnectorSourcePicker), preserving any stored id.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-003
		 */
		async fetchSynchronizations() {
			this.syncLoading = true
			this.syncFetchFailed = false
			try {
				const url = generateUrl('/apps/openregister/api/objects/openconnector/synchronization')
				const { data } = await axios.get(url, { params: { limit: 500 } })
				const list = Array.isArray(data && data.results)
					? data.results
					: Array.isArray(data) ? data : []
				this.syncOptions = list.map((sync) => ({
					id: String(sync.id || sync.uuid),
					label: sync.name || sync.title || sync.id,
				})).filter((o) => o.id && o.id !== 'undefined')
				if (this.syncOptions.length === 0) {
					this.syncFetchFailed = true
				} else {
					this.syncOption = this.syncOptions.find((o) => o.id === this.syncId) || null
				}
			} catch {
				this.syncFetchFailed = true
				this.syncOptions = []
			} finally {
				this.syncLoading = false
			}
		},
		/**
		 * Handle a synchronization selection from the live picker.
		 *
		 * @param {?object} option - the selected NcSelect option.
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-003
		 */
		onSyncSelect(option) {
			this.syncId = option && option.id ? option.id : ''
		},
		/**
		 * Assemble + emit the schedule entry (only when valid).
		 *
		 * @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-005
		 */
		onSave() {
			this.showValidation = true
			if (!this.valid) {
				return
			}
			this.$emit('save', this.candidateEntry)
			this.$emit('update:open', false)
		},
		/** @spec openspec/changes/schedules-editor/specs/openbuild-schedules-authoring/spec.md#req-obsa-001 */
		onClose() {
			this.$emit('update:open', false)
		},
	},
}
</script>

<style scoped>
.ob-schedule-edit {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 20px;
	min-width: 320px;
}

.ob-schedule-edit__title {
	margin: 0;
}

.ob-schedule-edit__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
	font-size: 0.9em;
}

.ob-schedule-edit__hint--warning {
	color: var(--color-warning-text, var(--color-warning));
}

.ob-schedule-edit__sync {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ob-schedule-edit__error {
	color: var(--color-error);
	margin: 0;
}

.ob-schedule-edit__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
