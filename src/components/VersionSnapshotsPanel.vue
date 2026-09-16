<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - VersionSnapshotsPanel: named snapshots of the selected version, on the
  - Version history tab. Take a snapshot with a label, see who took each one
  - and when, and roll back to one. A rollback keeps the replaced manifest as
  - a "Previous draft" snapshot, so it can be undone from this same list.
  -
  - The selected version comes from the header pills (`?_version=`); without
  - one the server uses the production version.
  -->
<template>
	<section class="ob-snapshots">
		<header class="ob-snapshots__header">
			<h3>{{ t('buildiq', 'Snapshots') }}</h3>
			<NcButton v-if="canEdit" variant="secondary" @click="promptOpen = true">
				{{ t('buildiq', 'Take snapshot') }}
			</NcButton>
		</header>

		<p v-if="error" class="ob-snapshots__error" role="alert">
			{{ error }}
		</p>
		<p v-if="loading" class="ob-snapshots__empty">
			{{ t('buildiq', 'Loading…') }}
		</p>
		<p v-else-if="!snapshots.length" class="ob-snapshots__empty">
			{{ t('buildiq', 'No snapshots yet. Take one before you make a change you may want to undo.') }}
		</p>
		<ul v-else class="ob-snapshots__list">
			<li
				v-for="snapshot in snapshots"
				:key="snapshot.id"
				class="ob-snapshots__row">
				<div class="ob-snapshots__meta">
					<strong class="ob-snapshots__label">{{ labelOf(snapshot) }}</strong>
					<small class="ob-snapshots__by">
						{{ t('buildiq', '{user}, {date}', { user: snapshot.takenBy || '?', date: formatDate(snapshot.takenAt) }) }}
					</small>
					<code
						v-if="snapshot.checksum"
						class="ob-snapshots__checksum"
						:title="snapshot.checksum">{{ snapshot.checksum.slice(0, 8) }}</code>
				</div>
				<NcButton
					v-if="canEdit"
					variant="tertiary"
					:disabled="busy"
					@click="askRestore(snapshot)">
					{{ t('buildiq', 'Roll back to this version') }}
				</NcButton>
			</li>
		</ul>

		<PromptTextDialog
			:open="promptOpen"
			:name="t('buildiq', 'Take snapshot')"
			:label="t('buildiq', 'Label')"
			:placeholder="t('buildiq', 'For example: before adding the tasks page')"
			:confirmLabel="t('buildiq', 'Save')"
			@update:open="promptOpen = $event"
			@submit="takeSnapshot" />
		<ConfirmActionDialog
			:open="restoreTarget !== null"
			:name="t('buildiq', 'Roll back to this version')"
			:message="restoreMessage"
			:detail="t('buildiq', 'The current state is kept as a Previous draft snapshot, so you can roll forward again.')"
			:confirmLabel="t('buildiq', 'Roll back')"
			:busy="busy"
			@update:open="onRestoreOpen"
			@confirm="restore" />
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import ConfirmActionDialog from '../dialogs/ConfirmActionDialog.vue'
import PromptTextDialog from '../dialogs/PromptTextDialog.vue'

export default {
	name: 'VersionSnapshotsPanel',
	components: { ConfirmActionDialog, NcButton, PromptTextDialog },

	props: {
		/** The parent Application slug. */
		appSlug: {
			type: String,
			required: true,
		},

		/** Whether the caller may take and restore snapshots (owner or editor). */
		canEdit: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['restored'],

	data() {
		return {
			snapshots: [],
			loading: false,
			busy: false,
			error: '',
			promptOpen: false,
			restoreTarget: null,
		}
	},

	computed: {
		/**
		 * The selected version slug, or '' for production.
		 *
		 * @return {string}
		 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
		 */
		versionSlug() {
			return (this.$route && this.$route.query && this.$route.query._version) || ''
		},

		/**
		 * The confirmation question for the snapshot being restored.
		 *
		 * @return {string}
		 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
		 */
		restoreMessage() {
			if (!this.restoreTarget) {
				return ''
			}
			return t('buildiq', 'Replace the current manifest with snapshot "{label}"?', {
				label: this.labelOf(this.restoreTarget),
			})
		},
	},

	watch: {
		appSlug: {
			immediate: true,
			/**
			 * Load when the app is known.
			 *
			 * @return {void}
			 */
			handler() {
				this.load()
			},
		},

		/**
		 * Reload when another version is selected.
		 *
		 * @return {void}
		 */
		versionSlug() {
			this.load()
		},
	},

	methods: {
		/**
		 * The snapshots endpoint.
		 *
		 * @return {string}
		 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
		 */
		endpoint() {
			return generateUrl('/apps/buildiq/api/applications/{slug}/snapshots', { slug: this.appSlug })
		},

		/**
		 * Load the selected version's snapshots.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
		 */
		async load() {
			if (!this.appSlug) {
				return
			}
			this.loading = true
			try {
				const { data } = await axios.get(this.endpoint(), {
					params: this.versionSlug ? { version: this.versionSlug } : {},
				})
				this.snapshots = Array.isArray(data) ? data : []
			} catch (e) {
				this.snapshots = []
				this.error = this.reason(e, t('buildiq', 'Could not load the snapshots.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take a snapshot with the entered label.
		 *
		 * @param {string} label The label.
		 * @return {Promise<void>}
		 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
		 */
		async takeSnapshot(label) {
			this.promptOpen = false
			this.error = ''
			this.busy = true
			try {
				const { data } = await axios.post(this.endpoint(), {
					label,
					version: this.versionSlug,
				})
				this.snapshots = [data, ...this.snapshots]
			} catch (e) {
				this.error = this.reason(e, t('buildiq', 'Could not take the snapshot.'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Ask before rolling back.
		 *
		 * @param {object} snapshot The snapshot.
		 * @return {void}
		 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
		 */
		askRestore(snapshot) {
			this.restoreTarget = snapshot
		},

		/**
		 * Close the confirmation.
		 *
		 * @param {boolean} open Whether the dialog stays open.
		 * @return {void}
		 */
		onRestoreOpen(open) {
			if (!open && !this.busy) {
				this.restoreTarget = null
			}
		},

		/**
		 * Roll back to the confirmed snapshot and reload the list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/version-snapshots/specs/openbuild-version-snapshots/spec.md
		 */
		async restore() {
			const snapshot = this.restoreTarget
			if (!snapshot) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				await axios.post(
					generateUrl('/apps/buildiq/api/applications/{slug}/snapshots/{id}/restore', {
						slug: this.appSlug,
						id: snapshot.id,
					}),
					{},
				)
				this.$emit('restored', snapshot)
				await this.load()
			} catch (e) {
				this.error = this.reason(e, t('buildiq', 'Could not roll back.'))
			} finally {
				this.busy = false
				this.restoreTarget = null
			}
		},

		/**
		 * The label to show; the kept state gets a translated name.
		 *
		 * @param {object} snapshot The snapshot.
		 * @return {string}
		 */
		labelOf(snapshot) {
			if (snapshot && snapshot.kind === 'previous-draft') {
				return t('buildiq', 'Previous draft')
			}
			return (snapshot && snapshot.label) || ''
		},

		/**
		 * Format a timestamp for the list.
		 *
		 * @param {string} value An ISO timestamp.
		 * @return {string}
		 */
		formatDate(value) {
			const date = value ? new Date(value) : null
			if (!date || Number.isNaN(date.getTime())) {
				return ''
			}
			return date.toLocaleString()
		},

		/**
		 * The server's message, or a fallback.
		 *
		 * @param {Error} e The failure.
		 * @param {string} fallback Text to show when the server gave none.
		 * @return {string}
		 */
		reason(e, fallback) {
			const data = (e && e.response && e.response.data) || {}
			return data.message ? `${fallback} ${data.message}` : fallback
		},
	},
}
</script>

<style scoped>
.ob-snapshots {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 16px;
}

.ob-snapshots__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.ob-snapshots__header h3 {
	margin: 0;
}

.ob-snapshots__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.ob-snapshots__row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.ob-snapshots__meta {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.ob-snapshots__by,
.ob-snapshots__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.ob-snapshots__checksum {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.ob-snapshots__error {
	color: var(--color-error-text, var(--color-error));
	font-size: 13px;
}
</style>
