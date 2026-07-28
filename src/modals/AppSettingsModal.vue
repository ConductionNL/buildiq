<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - AppSettingsModal — owner-facing app settings. Holds the publish/unpublish
  - toggle (whether the app is live in the Nextcloud app menu), the
  - allow-user-overrides toggle, and the Data registers section (add/remove
  - `Application.dataRegisters` bindings — data-registers-runtime task 5.1).
  - Emits intent; the parent (ApplicationDetailActions) performs the API
  - calls. Kept in its own file per ADR-004 gate-modal-isolation.
  -->
<template>
	<NcModal v-if="open" :name="t('openbuild', 'App settings')" @close="$emit('update:open', false)">
		<div class="app-settings">
			<h3 class="app-settings__title">{{ appName }}</h3>

			<div class="app-settings__row">
				<NcCheckboxRadioSwitch
					type="switch"
					:model-value="isPublished"
					:disabled="busy"
					@update:modelValue="$emit('set-published', $event)">
					{{ t('openbuild', 'Published') }}
				</NcCheckboxRadioSwitch>
				<p class="app-settings__hint">
					{{ t('openbuild', 'Published apps appear in the Nextcloud app menu. Drafts are hidden.') }}
				</p>
			</div>

			<div class="app-settings__row">
				<NcCheckboxRadioSwitch
					type="switch"
					:model-value="allowUserOverrides"
					:disabled="busy"
					@update:modelValue="$emit('update:allow-overrides', $event)">
					{{ t('openbuild', 'Allow per-user customisation') }}
				</NcCheckboxRadioSwitch>
				<p class="app-settings__hint">
					{{ t('openbuild', 'Let each user layer their own manifest changes on top of the shared app.') }}
				</p>
			</div>

			<div class="app-settings__row">
				<h4 class="app-settings__subtitle">
					{{ t('openbuild', 'Data registers') }}
				</h4>
				<p class="app-settings__hint app-settings__hint--inline">
					{{ t('openbuild', 'Shared, non-versioned OpenRegister registers this app binds to alongside its own per-version register (e.g. a dataset fed by OpenConnector). Not owned by this app — promotion and export treat them as reference-only.') }}
				</p>
				<div v-for="(row, index) in rows" :key="index" class="app-settings__data-register-row">
					<NcTextField
						:model-value="row.register"
						:label="t('openbuild', 'Register slug')"
						:disabled="busy"
						@update:modelValue="updateRow(index, 'register', $event)" />
					<NcTextField
						:model-value="row.label"
						:label="t('openbuild', 'Label (optional)')"
						:disabled="busy"
						@update:modelValue="updateRow(index, 'label', $event)" />
					<NcButton
						type="tertiary"
						:disabled="busy"
						:aria-label="t('openbuild', 'Remove data register')"
						@click="removeRow(index)">
						{{ t('openbuild', 'Remove') }}
					</NcButton>
				</div>
				<NcButton type="secondary" :disabled="busy" @click="addRow">
					{{ t('openbuild', 'Add data register') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcTextField from '@nextcloud/vue/components/NcTextField'

export default {
	name: 'AppSettingsModal',
	components: {
		NcModal, NcButton, NcCheckboxRadioSwitch, NcTextField,
	},
	props: {
		/** Whether the modal is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** Display name of the app. */
		appName: { type: String, default: '' },
		/** Whether the app is currently published. */
		isPublished: { type: Boolean, default: false },
		/** Whether per-user overrides are enabled. */
		allowUserOverrides: { type: Boolean, default: false },
		/** The Application's declared `dataRegisters` bindings (`{register, label?}`). */
		dataRegisters: { type: Array, default: () => [] },
		/** Whether an action is in flight (disables the switches). */
		busy: { type: Boolean, default: false },
	},
	emits: ['update:open', 'set-published', 'update:allow-overrides', 'update:data-registers'],
	data() {
		return {
			// Staged editing rows, kept in sync with the `dataRegisters` prop
			// (see the watcher below). `register`/`label` default to '' so
			// NcTextField always receives a string, never undefined.
			rows: this.toRows(this.dataRegisters),
		}
	},
	watch: {
		dataRegisters: {
			deep: true,
			/**
			 * Re-sync the staged rows whenever the canonical prop changes —
			 * covers the initial async Application load (empty at mount,
			 * populated once `obLoadApp()` resolves) and the echoed value
			 * `obPatchApp()`'s response carries back after every save.
			 *
			 * @param {Array} next The new dataRegisters value.
			 * @return {void}
			 */
			handler(next) {
				this.rows = this.toRows(next)
			},
		},
	},
	methods: {
		/**
		 * Normalise a `dataRegisters` array into editable rows.
		 *
		 * @param {Array} list The dataRegisters prop value.
		 * @return {Array<{register: string, label: string}>}
		 */
		toRows(list) {
			return (Array.isArray(list) ? list : []).map((binding) => ({
				register: (binding && binding.register) || '',
				label: (binding && binding.label) || '',
			}))
		},
		/**
		 * Append a new, empty row. Not emitted until it carries a
		 * non-empty `register` (see emitRows()).
		 *
		 * @return {void}
		 */
		addRow() {
			const next = this.rows.slice()
			next.push({ register: '', label: '' })
			this.rows = next
			this.emitRows()
		},
		/**
		 * Remove a row by index.
		 *
		 * @param {number} index Row index.
		 * @return {void}
		 */
		removeRow(index) {
			const next = this.rows.slice()
			next.splice(index, 1)
			this.rows = next
			this.emitRows()
		},
		/**
		 * Update a single field on a row by index.
		 *
		 * @param {number} index Row index.
		 * @param {string} key `register` or `label`.
		 * @param {string} value New field value.
		 * @return {void}
		 */
		updateRow(index, key, value) {
			const next = this.rows.slice()
			next[index] = { ...next[index], [key]: value }
			this.rows = next
			this.emitRows()
		},
		/**
		 * Emit the full `dataRegisters` array — every change (add/remove/
		 * edit) emits immediately, matching the existing
		 * `update:allow-overrides` pattern on this same modal. Rows with
		 * no `register` slug yet (mid-edit) are dropped from the emitted
		 * payload; a present-but-empty `label` is omitted rather than sent
		 * as `''`.
		 *
		 * @return {void}
		 */
		emitRows() {
			const cleaned = this.rows
				.map((row) => ({ register: (row.register || '').trim(), label: (row.label || '').trim() }))
				.filter((row) => row.register !== '')
				.map((row) => (row.label !== '' ? row : { register: row.register }))
			this.$emit('update:data-registers', cleaned)
		},
	},
}
</script>

<style scoped>
.app-settings {
	padding: 20px 24px 24px;
	display: flex;
	flex-direction: column;
	gap: 18px;
}

.app-settings__title {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.app-settings__subtitle {
	margin: 0;
	font-size: 15px;
	font-weight: 600;
}

.app-settings__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.app-settings__hint {
	margin: 0 0 0 44px;
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.app-settings__hint--inline {
	margin-left: 0;
}

.app-settings__data-register-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}
</style>
