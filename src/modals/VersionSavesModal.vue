<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Saves of one version, newest first, with a rollback on each earlier save
  - (REQ-BQCV-015). Read from GET /api/applications/{slug}/versions/{version}/saves.
  - Rolling back reads that save's manifest and writes it through the versions
  - API (PUT /api/applications/{slug}/versions/{version}), which keeps the
  - version link and applies its own write rules; the rollback is itself a save,
  - so it comes back as the newest one. Lives in its own file per ADR-004.
  -->
<template>
	<NcDialog
		:name="t('buildiq', 'Saves of {version}', { version: versionName })"
		:open="open"
		size="normal"
		@update:open="onClose">
		<div class="buildiq-version-saves">
			<NcEmptyContent
				v-if="loading"
				:name="t('buildiq', 'Loading saves…')">
				<template #icon>
					<NcLoadingIcon />
				</template>
			</NcEmptyContent>

			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcEmptyContent
				v-else-if="saves.length === 0"
				:name="t('buildiq', 'No saves kept yet')"
				:description="t('buildiq', 'Each save of this version from now on is kept here, so you can roll back to it.')">
				<template #icon>
					<HistoryIcon :size="48" />
				</template>
			</NcEmptyContent>

			<ol v-else class="buildiq-version-saves__list" data-testid="version-saves-list">
				<li
					v-for="(save, index) in saves"
					:key="save.id"
					class="buildiq-version-saves__row"
					:data-testid="'version-save-' + save.id">
					<div class="buildiq-version-saves__what">
						<strong>{{ formatStamp(save.savedAt) }}</strong>
						<span v-if="index === 0" class="buildiq-version-saves__current">
							{{ t('buildiq', 'current') }}
						</span>
						<span class="buildiq-version-saves__meta">
							{{ save.user || t('buildiq', 'system') }} · {{ pagesLabel(save) }}
						</span>
					</div>
					<template v-if="index > 0 && canEdit">
						<div v-if="confirming === save.id" class="buildiq-version-saves__confirm" role="group">
							<span>{{ t('buildiq', 'Replace this version\'s pages with this save?') }}</span>
							<NcButton
								variant="error"
								:disabled="restoring"
								data-testid="version-save-confirm"
								@click="restore(save)">
								{{ t('buildiq', 'Roll back') }}
							</NcButton>
							<NcButton
								:disabled="restoring"
								data-testid="version-save-cancel"
								@click="confirming = null">
								{{ t('buildiq', 'Cancel') }}
							</NcButton>
						</div>
						<NcButton
							v-else
							data-testid="version-save-rollback"
							@click="confirming = save.id">
							{{ t('buildiq', 'Roll back to this save') }}
						</NcButton>
					</template>
				</li>
			</ol>

			<div class="buildiq-version-saves__actions">
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
 * The address of a version's saves, or of one save.
 *
 * @param {string} appSlug The app.
 * @param {string} versionSlug The version.
 * @param {string} [saveId] One save.
 * @return {string}
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
export function savesUrl(appSlug, versionSlug, saveId) {
	const base = `/apps/buildiq/api/applications/${encodeURIComponent(appSlug)}/versions/${encodeURIComponent(versionSlug)}`
	return generateUrl(saveId ? `${base}/saves/${encodeURIComponent(saveId)}` : `${base}/saves`)
}

/**
 * VersionSavesModal: the saves of one version, with a rollback on each earlier save.
 *
 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
 */
export default {
	name: 'VersionSavesModal',

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

		appSlug: {
			type: String,
			required: true,
		},

		/**
		 * The version row: `slug` and, when it has one, `name`.
		 */
		version: {
			type: Object,
			default: null,
		},

		/**
		 * Whether the caller may write the version (owners and editors).
		 */
		canEdit: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'restored'],

	data() {
		return {
			saves: [],
			loading: false,
			error: '',
			confirming: null,
			restoring: false,
		}
	},

	computed: {
		/**
		 * The version's slug, or ''.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
		 */
		versionSlug() {
			return (this.version && this.version.slug) || ''
		},

		/**
		 * The version's name for the title.
		 *
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
		 */
		versionName() {
			return (this.version && (this.version.name || this.version.slug)) || ''
		},
	},

	watch: {
		open: {
			immediate: true,
			/**
			 * Read the saves each time the dialog opens.
			 *
			 * @param {boolean} isOpen Whether the dialog is open.
			 * @return {Promise<void>}
			 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
			 */
			async handler(isOpen) {
				this.confirming = null
				if (isOpen === true && this.versionSlug !== '') {
					await this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Close the dialog.
		 *
		 * @return {void}
		 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
		 */
		onClose() {
			this.$emit('update:open', false)
		},

		/**
		 * Read the saves; a refusal or failure shows its message instead.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const res = await axios.get(savesUrl(this.appSlug, this.versionSlug))
				const saves = res && res.data && res.data.saves
				this.saves = Array.isArray(saves) ? saves : []
			} catch (e) {
				this.saves = []
				const status = e && e.response && e.response.status
				this.error = status === 403
					? t('buildiq', 'You need a role on this app to see its saves.')
					: t('buildiq', 'The saves could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Roll the version back to a save: read its manifest, write it through the versions API, read the saves again.
		 *
		 * @param {{id: string}} save The save.
		 * @return {Promise<void>}
		 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
		 */
		async restore(save) {
			this.restoring = true
			this.error = ''
			try {
				const res = await axios.get(savesUrl(this.appSlug, this.versionSlug, save.id))
				const manifest = res && res.data && res.data.manifest
				if (!manifest || typeof manifest !== 'object') {
					throw new Error('no manifest')
				}
				await axios.put(
					generateUrl(`/apps/buildiq/api/applications/${encodeURIComponent(this.appSlug)}/versions/${encodeURIComponent(this.versionSlug)}`),
					{ manifest },
				)
				this.confirming = null
				this.$emit('restored', { version: this.versionSlug, save: save.id })
				await this.load()
			} catch (e) {
				const status = e && e.response && e.response.status
				this.error = status === 403
					? t('buildiq', 'You need to be an owner or editor of this app to roll it back.')
					: t('buildiq', 'The rollback failed. The version is unchanged.')
			} finally {
				this.restoring = false
			}
		},

		/**
		 * The pages a save holds, as a short label.
		 *
		 * @param {{pages: Array<string>}} save The save.
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
		 */
		pagesLabel(save) {
			const pages = Array.isArray(save.pages) ? save.pages : []
			if (pages.length === 0) {
				return t('buildiq', 'no pages')
			}
			return t('buildiq', 'Pages: {pages}', { pages: pages.join(', ') })
		},

		/**
		 * The local date and time of an ISO stamp, or the stamp as given.
		 *
		 * @param {string} stamp The ISO 8601 stamp.
		 * @return {string}
		 * @spec openspec/changes/capability-requirements-backfill/specs/application-versions/spec.md#requirement-a-maker-rolls-a-version-back-to-an-earlier-manifest-req-bqcv-015
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
.buildiq-version-saves {
	padding: calc(var(--default-grid-baseline) * 2);
}

.buildiq-version-saves__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.buildiq-version-saves__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.buildiq-version-saves__what {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.buildiq-version-saves__current {
	font-size: 0.8rem;
	color: var(--color-success-text);
}

.buildiq-version-saves__meta {
	font-size: 0.85rem;
	color: var(--color-text-maxcontrast);
}

.buildiq-version-saves__confirm {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.buildiq-version-saves__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline) * 4);
}
</style>
