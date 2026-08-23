<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Owner-only Permission history modal — read-only view of permission
  - changes (and rbac.admin_bypass events) recorded in OR's per-object
  - audit trail (REQ-OBRBAC-007). Consumes OR's existing audit REST; no
  - new audit endpoint is shipped. Per ADR-004 (gate-modal-isolation)
  - this modal lives in its own src/modals/ file rather than being
  - inlined in ApplicationEditor.vue.
  -->
<template>
	<NcDialog
		:name="t('buildiq', 'Permission history')"
		:open="open"
		size="large"
		@update:open="onClose">
		<div class="buildiq-permission-history">
			<p class="buildiq-permission-history__help">
				{{
					t(
						'buildiq',
						"Read-only view of permission changes and admin-bypass events on this application. Sourced from OpenRegister's per-object audit trail.",
					)
				}}
			</p>

			<NcEmptyContent
				v-if="loading"
				:name="t('buildiq', 'Loading audit trail…')">
				<template #icon>
					<NcLoadingIcon />
				</template>
			</NcEmptyContent>

			<NcEmptyContent
				v-else-if="entries.length === 0"
				:name="t('buildiq', 'No permission changes recorded')"
				:description="
					t(
						'buildiq',
						'Future updates to owners / editors / viewers and any admin-bypass events will appear here.',
					)
				">
				<template #icon>
					<HistoryIcon :size="48" />
				</template>
			</NcEmptyContent>

			<ul v-else class="buildiq-permission-history__list">
				<li
					v-for="entry in entries"
					:key="entry.id"
					class="buildiq-permission-history__row"
					:class="rowClass(entry)">
					<div class="buildiq-permission-history__row-meta">
						<span class="buildiq-permission-history__row-actor">
							{{ entry.actor || t('buildiq', 'system') }}
						</span>
						<span class="buildiq-permission-history__row-stamp">
							{{ formatStamp(entry.timestamp) }}
						</span>
					</div>
					<div class="buildiq-permission-history__row-event">
						{{ eventLabel(entry) }}
					</div>
					<div
						v-if="entry.before && entry.after"
						class="buildiq-permission-history__row-diff">
						<div class="buildiq-permission-history__row-diff-col">
							<span class="buildiq-permission-history__row-diff-h">
								{{ t('buildiq', 'Before') }}
							</span>
							<pre>{{ pretty(entry.before) }}</pre>
						</div>
						<div class="buildiq-permission-history__row-diff-col">
							<span class="buildiq-permission-history__row-diff-h">
								{{ t('buildiq', 'After') }}
							</span>
							<pre>{{ pretty(entry.after) }}</pre>
						</div>
					</div>
				</li>
			</ul>

			<div class="buildiq-permission-history__actions">
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
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import HistoryIcon from 'vue-material-design-icons/History.vue'

/**
 * PermissionHistoryModal — owner-only read view of permission changes
 * on a single Application, sourced from OR's audit trail.
 *
 * @spec openspec/changes/openbuild-rbac/tasks.md#task-2-6
 */
export default {
	name: 'PermissionHistoryModal',

	components: {
		NcButton,
		NcDialog,
		NcEmptyContent,
		NcLoadingIcon,
		HistoryIcon,
	},

	props: {
		open: {
			type: Boolean,
			required: true,
		},

		applicationUuid: {
			type: String,
			required: true,
		},
	},

	emits: ['update:open'],

	data() {
		return {
			entries: [],
			loading: false,
		}
	},

	watch: {
		open: {
			immediate: true,
			async handler(isOpen) {
				if (isOpen === true) {
					await this.load()
				}
			},
		},
	},

	methods: {
		onClose() {
			this.$emit('update:open', false)
		},

		async load() {
			this.loading = true
			try {
				// OR audit endpoint — per-object audit trail filtered by
				// register + schema + uuid. The endpoint is paginated; the
				// owner-only panel intentionally fetches only the first
				// page (most recent ~50 events) — older history can be
				// inspected via OR's admin UI.
				const url = generateUrl(
					`/apps/openregister/api/objects/buildiq/application/${this.applicationUuid}/audit`,
				)
				const res = await axios.get(url, {
					params: {
						filter: 'permissions,rbac.admin_bypass',
						limit: 50,
					},
				})
				const list = res.data?.data ?? res.data ?? []
				this.entries = this.shape(list)
			} catch (e) {
				const status = e?.response?.status
				if (status === 403 || status === 401) {
					// Endpoint correctly rejected the caller. Render empty
					// state; the modal would not be visible to non-owners.
					this.entries = []
				} else {
					showError(this.t('buildiq', 'Failed to load permission history'))
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Normalise the OR audit envelope so the template can iterate
		 * uniformly. OR's audit responses vary slightly by version (some
		 * surface `changes.before/after`, others a flat `delta`).
		 *
		 * @param {Array<{id?: string, uuid?: string, actor?: string, userId?: string,
		 *   user?: string, timestamp?: string, createdAt?: string, created?: string,
		 *   event?: string, action?: string, changes?: {before: object, after: object},
		 *   before?: object, after?: object}>} rawList - Audit rows straight off
		 *   OpenRegister's audit-trail endpoint (already unwrapped from the response
		 *   envelope by the caller). Every field is optional because the accepted
		 *   spelling differs per OR version; each row is collapsed to one canonical
		 *   shape, with a positional fallback id for rows carrying neither `id` nor
		 *   `uuid`.
		 * @return {Array<{id: string, actor: string, timestamp: string, event: string,
		 *   before: object|null, after: object|null}>} Normalised rows for the table.
		 */
		shape(rawList) {
			return rawList.map((row, idx) => ({
				id: row.id ?? row.uuid ?? `idx-${idx}`,
				actor: row.actor ?? row.userId ?? row.user ?? '',
				timestamp: row.timestamp ?? row.createdAt ?? row.created ?? '',
				event: row.event ?? row.action ?? 'permissions.changed',
				before: row.changes?.before ?? row.before ?? null,
				after: row.changes?.after ?? row.after ?? null,
			}))
		},

		rowClass(entry) {
			if (entry.event === 'rbac.admin_bypass') {
				return 'buildiq-permission-history__row--bypass'
			}
			return ''
		},

		eventLabel(entry) {
			if (entry.event === 'rbac.admin_bypass') {
				return this.t('buildiq', 'Administrator bypass')
			}
			if (entry.event === 'permissions.changed') {
				return this.t('buildiq', 'Permissions changed')
			}
			return entry.event
		},

		formatStamp(stamp) {
			if (stamp === '' || stamp === null) {
				return ''
			}
			try {
				return new Date(stamp).toLocaleString()
			} catch (e) {
				return String(stamp)
			}
		},

		pretty(obj) {
			try {
				return JSON.stringify(obj, null, 2)
			} catch (e) {
				return String(obj)
			}
		},
	},
}
</script>

<style scoped>
.buildiq-permission-history {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-width: 480px;
}

.buildiq-permission-history__help {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.buildiq-permission-history__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-height: 400px;
	overflow-y: auto;
}

.buildiq-permission-history__row {
	padding: 12px;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-large);
}

.buildiq-permission-history__row--bypass {
	border-left: 4px solid var(--color-warning);
}

.buildiq-permission-history__row-meta {
	display: flex;
	justify-content: space-between;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.buildiq-permission-history__row-actor {
	font-weight: 600;
}

.buildiq-permission-history__row-event {
	font-weight: 500;
	margin-bottom: 8px;
}

.buildiq-permission-history__row-diff {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.buildiq-permission-history__row-diff-h {
	display: block;
	font-size: 11px;
	text-transform: uppercase;
	color: var(--color-text-maxcontrast);
	margin-bottom: 4px;
}

.buildiq-permission-history__row-diff pre {
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 8px;
	font-size: 11px;
	max-height: 120px;
	overflow: auto;
	margin: 0;
}

.buildiq-permission-history__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}
</style>
