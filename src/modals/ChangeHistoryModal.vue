<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Change history: who saved the app or one of its versions, and when,
  - newest first (REQ-BQCV-014). Read from GET
  - /api/applications/{slug}/history, which reads OpenRegister's audit trail
  - and refuses a user without a role on the app. Lives in its own file per
  - ADR-004 (gate-modal-isolation).
  -->
<template>
	<NcDialog
		:name="t('buildiq', 'Change history')"
		:open="open"
		size="large"
		@update:open="onClose">
		<div class="buildiq-change-history">
			<NcEmptyContent
				v-if="loading"
				:name="t('buildiq', 'Loading change history…')">
				<template #icon>
					<NcLoadingIcon />
				</template>
			</NcEmptyContent>

			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcEmptyContent
				v-else-if="entries.length === 0"
				:name="t('buildiq', 'No changes recorded')"
				:description="t('buildiq', 'Saves of the app and its versions will appear here.')">
				<template #icon>
					<HistoryIcon :size="48" />
				</template>
			</NcEmptyContent>

			<table v-else class="buildiq-change-history__table" data-testid="change-history-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('buildiq', 'When') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'Who') }}
						</th>
						<th scope="col">
							{{ t('buildiq', 'What changed') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(entry, index) in entries"
						:key="index"
						class="buildiq-change-history__row">
						<td>{{ formatStamp(entry.at) }}</td>
						<td>{{ entry.userName || entry.user || t('buildiq', 'system') }}</td>
						<td>{{ subjectLabel(entry) }}</td>
					</tr>
				</tbody>
			</table>

			<div class="buildiq-change-history__actions">
				<NcButton variant="primary" @click="onClose">
					{{ t('buildiq', 'Close') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import HistoryIcon from 'vue-material-design-icons/History.vue'

/**
 * The words for an audit action, per object kind.
 *
 * @param {string} action The audit action (create, update, delete, revert).
 * @return {string} The translated verb.
 */
function actionWord(action) {
	switch (action) {
	case 'create':
		return t('buildiq', 'created')
	case 'delete':
		return t('buildiq', 'deleted')
	case 'revert':
	case 'reverted':
		return t('buildiq', 'rolled back')
	default:
		return t('buildiq', 'saved')
	}
}

/**
 * ChangeHistoryModal: the saves of an app and of its versions' manifests.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
 */
export default {
	name: 'ChangeHistoryModal',

	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		HistoryIcon,
	},

	props: {
		open: {
			type: Boolean,
			required: true,
		},

		slug: {
			type: String,
			required: true,
		},
	},

	emits: ['update:open'],

	data() {
		return {
			entries: [],
			loading: false,
			error: '',
		}
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Read the history each time the dialog opens.
			 *
			 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
			 * @param {boolean} isOpen Whether the dialog is open.
			 * @return {Promise<void>}
			 */
			async handler(isOpen) {
				if (isOpen === true) {
					await this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Close the dialog.
		 *
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
		 * @return {void}
		 */
		onClose() {
			this.$emit('update:open', false)
		},

		/**
		 * Read the history; a refusal or failure shows its message instead.
		 *
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const res = await axios.get(
					generateUrl(`/apps/buildiq/api/applications/${encodeURIComponent(this.slug)}/history`),
				)
				const entries = res && res.data && res.data.entries
				this.entries = Array.isArray(entries) ? entries : []
			} catch (e) {
				this.entries = []
				const status = e && e.response && e.response.status
				this.error = status === 403
					? t('buildiq', 'You need a role on this app to see its change history.')
					: t('buildiq', 'The change history could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * What an entry changed: the app itself or a named version.
		 *
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
		 * @param {object} entry A history entry.
		 * @return {string} The label.
		 */
		subjectLabel(entry) {
			const verb = actionWord(entry.action)
			if (entry.object === 'version') {
				return t('buildiq', 'Version {version} {verb}', {
					version: entry.versionName || entry.version || '',
					verb,
				})
			}
			return t('buildiq', 'App settings {verb}', { verb })
		},

		/**
		 * The local date and time of an ISO stamp, or the stamp as given.
		 *
		 * @spec openspec/changes/capability-requirements-backfill/specs/openbuild-rbac/spec.md#requirement-an-owner-sees-who-changed-an-apps-design-and-when-req-bqcv-014
		 * @param {string|null} stamp The ISO 8601 stamp.
		 * @return {string} The formatted time.
		 */
		formatStamp(stamp) {
			if (!stamp) {
				return ''
			}
			const date = new Date(stamp)
			return Number.isNaN(date.getTime()) ? String(stamp) : date.toLocaleString()
		},
	},
}
</script>

<style scoped>
.buildiq-change-history {
	padding: calc(var(--default-grid-baseline) * 2);
}

.buildiq-change-history__table {
	width: 100%;
	border-collapse: collapse;
}

.buildiq-change-history__table th,
.buildiq-change-history__table td {
	text-align: start;
	padding: var(--default-grid-baseline) calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.buildiq-change-history__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline) * 4);
}
</style>
