<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Version-history sibling panel inside ApplicationEditor.vue. Reads
  - ApplicationVersion rows from OR REST filtered by applicationUuid
  - (no app-local wrapper service per ADR-022). Each row carries
  - rollback + "compare with current draft" affordances per
  - REQ-OBR-008, REQ-OBR-009, design.md OQ-4.
  -->
<template>
	<div class="version-history">
		<header class="version-history__header">
			<h3>{{ t('openbuilt', 'Version history') }}</h3>
		</header>
		<p v-if="loading" class="version-history__empty">
			{{ t('openbuilt', 'Loading…') }}
		</p>
		<p v-else-if="!versions.length" class="version-history__empty">
			{{ t('openbuilt', 'No versions yet — publish this app to create the first snapshot.') }}
		</p>
		<ul v-else class="version-history__list">
			<li
				v-for="row in versions"
				:key="rowKey(row)"
				class="version-history__row"
				:class="{ 'version-history__row--current': isCurrent(row) }">
				<div class="version-history__row-main">
					<strong>{{ rowVersion(row) }}</strong>
					<span class="version-history__when">{{ formatDate(rowPublishedAt(row)) }}</span>
					<small class="version-history__by">{{ t('openbuilt', 'By') }}: {{ rowPublishedBy(row) }}</small>
					<small v-if="rowNotes(row)" class="version-history__notes">{{ rowNotes(row) }}</small>
				</div>
				<div class="version-history__actions">
					<button class="version-history__btn" @click="compare(row)">
						{{ t('openbuilt', 'Compare with current draft') }}
					</button>
					<button class="version-history__btn version-history__btn--danger" @click="askRollback(row)">
						{{ t('openbuilt', 'Roll back to this version') }}
					</button>
				</div>
			</li>
		</ul>

		<RollbackConfirmModal
			:open="rollbackOpen"
			:version="rollbackTarget"
			@update:open="rollbackOpen = $event"
			@confirm="onRollbackConfirmed"
			@cancel="onRollbackCancelled" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import RollbackConfirmModal from '../modals/RollbackConfirmModal.vue'

export default {
	name: 'VersionHistory',
	components: {
		RollbackConfirmModal,
	},
	props: {
		applicationUuid: {
			type: String,
			required: true,
		},
		currentVersionUuid: {
			type: String,
			default: '',
		},
	},
	emits: ['compare', 'rollback'],
	data() {
		return {
			versions: [],
			loading: false,
			rollbackOpen: false,
			rollbackTarget: null,
		}
	},
	watch: {
		applicationUuid: {
			immediate: true,
			handler(uuid) {
				if (uuid) {
					this.refresh()
				} else {
					this.versions = []
				}
			},
		},
	},
	methods: {
		async refresh() {
			if (!this.applicationUuid) {
				this.versions = []
				return
			}
			this.loading = true
			try {
				const url = generateUrl('/apps/openregister/api/objects/openbuilt/application-version')
				const { data } = await axios.get(url, {
					params: {
						applicationUuid: this.applicationUuid,
						_limit: 200,
					},
				})
				const raw = (data && data.results) ? data.results : (Array.isArray(data) ? data : [])
				// Filter client-side too (OR returns full register page if param ignored) and sort newest first.
				this.versions = raw
					.filter(r => this.rowApplicationUuid(r) === this.applicationUuid)
					.sort((a, b) => {
						const aT = new Date(this.rowPublishedAt(a) || 0).getTime()
						const bT = new Date(this.rowPublishedAt(b) || 0).getTime()
						return bT - aT
					})
			} catch (e) {
				this.versions = []
				// Empty state with no console error per REQ-OBR-008 scenario 2.
			} finally {
				this.loading = false
			}
		},
		rowKey(row) {
			return this.rowUuid(row) || JSON.stringify(row).slice(0, 32)
		},
		rowUuid(row) {
			const self = (row && row['@self']) || {}
			return self.id || self.uuid || row.uuid || ''
		},
		rowApplicationUuid(row) {
			return (row && row.applicationUuid) || ''
		},
		rowVersion(row) {
			return (row && row.version) || ''
		},
		rowPublishedAt(row) {
			return (row && row.publishedAt) || ''
		},
		rowPublishedBy(row) {
			return (row && row.publishedBy) || ''
		},
		rowNotes(row) {
			return (row && row.notes) || ''
		},
		isCurrent(row) {
			return this.rowUuid(row) === this.currentVersionUuid
		},
		formatDate(iso) {
			if (!iso) {
				return ''
			}
			try {
				return new Date(iso).toLocaleString()
			} catch (e) {
				return iso
			}
		},
		compare(row) {
			this.$emit('compare', { from: 'draft', to: this.rowUuid(row) })
		},
		askRollback(row) {
			this.rollbackTarget = {
				uuid: this.rowUuid(row),
				version: this.rowVersion(row),
				manifest: row.manifest,
				publishedAt: this.rowPublishedAt(row),
			}
			this.rollbackOpen = true
		},
		onRollbackConfirmed(version) {
			this.$emit('rollback', version)
			this.rollbackOpen = false
			this.rollbackTarget = null
		},
		onRollbackCancelled() {
			this.rollbackOpen = false
			this.rollbackTarget = null
		},
	},
}
</script>

<style scoped>
.version-history {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.version-history__header h3 {
	margin: 0;
	font-size: 15px;
}

.version-history__empty {
	color: var(--color-text-maxcontrast, #888);
	font-size: 13px;
	font-style: italic;
}

.version-history__list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.version-history__row {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 10px 12px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover, transparent);
}

.version-history__row--current {
	border-color: var(--color-primary-element, #0082c9);
	background: var(--color-primary-light, #e6f0fa);
}

.version-history__row-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.version-history__when {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
}

.version-history__by,
.version-history__notes {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
}

.version-history__actions {
	display: flex;
	gap: 8px;
}

.version-history__btn {
	font-size: 13px;
	padding: 4px 8px;
	border-radius: var(--border-radius, 4px);
	cursor: pointer;
	border: 1px solid var(--color-border, #ddd);
	background: var(--color-main-background, #fff);
	color: var(--color-main-text, #222);
}

.version-history__btn--danger {
	color: var(--color-error, #d63f3f);
}
</style>
