<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Owner-only Permissions panel modal — three group pickers (owners,
  - editors, viewers) bound to the Application's `permissions` arrays.
  - Per ADR-004 (`gate-modal-isolation`) this modal lives in its own
  - `src/modals/` file rather than being inlined in ApplicationEditor.vue.
  - The orphan-check guard rejects an `owners = []` save before sending
  - per REQ-OBRBAC-005. NcSelects carry an `input-label` prop per
  - ADR-004 (`gate-nc-input-labels`).
  -->
<template>
	<NcDialog
		:name="t('openbuild', 'Permissions')"
		:open="open"
		size="normal"
		@update:open="onClose">
		<div class="openbuild-permissions-modal">
			<p class="openbuild-permissions-modal__help">
				{{ t('openbuild', 'Configure which Nextcloud groups can view, edit, or own this app. Members of any of these groups will see the app in their list; only owners may publish, archive, delete, transfer ownership, or change these permissions.') }}
			</p>

			<NcSelect
				v-model="ownersModel"
				:options="groupOptions"
				:multiple="true"
				:input-label="t('openbuild', 'Owners (full control)')"
				label="label"
				track-by="value" />
			<NcSelect
				v-model="editorsModel"
				:options="groupOptions"
				:multiple="true"
				:input-label="t('openbuild', 'Editors (can save drafts)')"
				label="label"
				track-by="value" />
			<NcSelect
				v-model="viewersModel"
				:options="groupOptions"
				:multiple="true"
				:input-label="t('openbuild', 'Viewers (read-only)')"
				label="label"
				track-by="value" />

			<div v-if="orphanError" class="openbuild-permissions-modal__error">
				{{ t('openbuild', 'At least one owner group is required — saving with no owners would orphan this application.') }}
			</div>

			<div class="openbuild-permissions-modal__actions">
				<NcButton type="tertiary" @click="onClose">
					{{ t('openbuild', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ saving ? t('openbuild', 'Saving permissions…') : t('openbuild', 'Save permissions') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'PermissionsModal',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
	},
	props: {
		open: {
			type: Boolean,
			required: true,
		},
		application: {
			type: Object,
			default: null,
		},
		availableGroups: {
			type: Array,
			default: () => [],
		},
	},
	emits: ['update:open', 'save'],
	data() {
		return {
			ownersModel: [],
			editorsModel: [],
			viewersModel: [],
			orphanError: false,
			saving: false,
		}
	},
	computed: {
		/**
		 * Observed behaviour of `groupOptions` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
		 */
		groupOptions() {
			return this.availableGroups.map(gid => ({ label: gid, value: gid }))
		},
	},
	watch: {
		application: {
			immediate: true,
			/**
			 * Observed behaviour of `handler` (retrofit annotation).
			 *
			 * @param {{permissions?: {owners?: string[], editors?: string[],
			 *   viewers?: string[]}}|null} app - The incoming `application` prop.
			 *   Re-seeds the three group pickers from the server record whenever the
			 *   modal is pointed at a different application. Runs immediately, and is
			 *   `null` until the parent has a record.
			 *
			 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
			 */
			handler(app) {
				this.syncFromApplication(app)
			},
		},
	},
	methods: {
		/**
		 * Observed behaviour of `syncFromApplication` (retrofit annotation).
		 *
		 * Rebuilds the local NcSelect models from the record and drops any unsaved
		 * edit state (orphan warning, in-flight save flag).
		 *
		 * @param {{permissions?: {owners?: string[], editors?: string[],
		 *   viewers?: string[]}}|null} app - The Application whose permissions to show.
		 *   Its three role buckets hold plain Nextcloud group ids, which are wrapped
		 *   into the `{label, value}` option objects NcSelect binds to. A `null` app
		 *   (or one with no `permissions`) yields three empty pickers.
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
		 */
		syncFromApplication(app) {
			const perms = (app && app.permissions) || {}
			this.ownersModel = (perms.owners || []).map(g => ({ label: g, value: g }))
			this.editorsModel = (perms.editors || []).map(g => ({ label: g, value: g }))
			this.viewersModel = (perms.viewers || []).map(g => ({ label: g, value: g }))
			this.orphanError = false
			this.saving = false
		},
		/**
		 * Observed behaviour of `onClose` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
		 */
		onClose() {
			this.$emit('update:open', false)
		},
		/**
		 * Observed behaviour of `save` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
		 */
		async save() {
			const owners = this.ownersModel.map(o => o.value)
			const editors = this.editorsModel.map(o => o.value)
			const viewers = this.viewersModel.map(o => o.value)
			if (owners.length === 0) {
				// Orphan-check guard per REQ-OBRBAC-005 — frontend rejects
				// the save before sending. OR REST returns 4xx if bypassed.
				this.orphanError = true
				return
			}
			this.saving = true
			try {
				this.$emit('save', { owners, editors, viewers })
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.openbuild-permissions-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}

.openbuild-permissions-modal__help {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
}

.openbuild-permissions-modal__error {
	color: var(--color-error, #d63f3f);
	font-size: 13px;
}

.openbuild-permissions-modal__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 8px;
}
</style>
