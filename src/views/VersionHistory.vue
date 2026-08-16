<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Version-history list for an Application. Reads ApplicationVersion rows from
  - the slug-based OpenBuild endpoint (`/api/applications/{slug}/versions`) — the
  - OR objects endpoint + `applicationUuid` filter never matched this register
  - shape, so this is the working source (version-lifecycle-and-switcher,
  - version-routing-ui MODIFIED). Each row can be opened (view/use) in the live
  - shell, edited (editor+) in the designer, released to production (owner, draft
  - only), or rolled back. Archived versions are hidden by default (decision 4).
  -->
<template>
	<div class="version-history">
		<header class="version-history__header">
			<h3>{{ t('openbuild', 'Version history') }}</h3>
		</header>
		<p v-if="loading" class="version-history__empty">
			{{ t('openbuild', 'Loading…') }}
		</p>
		<p v-else-if="!versions.length" class="version-history__empty">
			{{
				t(
					'openbuild',
					'No versions yet — create a draft to start a new version.',
				)
			}}
		</p>
		<ul v-else class="version-history__list">
			<li
				v-for="row in versions"
				:key="rowKey(row)"
				class="version-history__row"
				:class="{ 'version-history__row--current': isProduction(row) }"
				tabindex="0"
				role="button"
				@click="openVersion(row)"
				@keydown.enter="openVersion(row)">
				<div class="version-history__row-main">
					<div class="version-history__row-title">
						<strong>{{ rowName(row) }}</strong>
						<small class="version-history__semver">{{
							rowSemver(row)
						}}</small>
						<span
							class="version-history__badge"
							:class="`version-history__badge--${rowStatus(row)}`">
							{{ statusLabel(row) }}
						</span>
						<span
							v-if="isProduction(row)"
							class="version-history__badge version-history__badge--production">
							{{ t('openbuild', 'Production') }}
						</span>
					</div>
				</div>
				<!-- Actions stop row-click propagation so a button never doubles as "open". -->
				<div class="version-history__actions" @click.stop>
					<button class="version-history__btn" @click="openVersion(row)">
						{{ t('openbuild', 'Open') }}
					</button>
					<button
						v-if="canEdit"
						class="version-history__btn"
						@click="editVersion(row)">
						{{ t('openbuild', 'Edit') }}
					</button>
					<button
						v-if="canRelease && rowStatus(row) === 'draft'"
						class="version-history__btn version-history__btn--primary"
						:disabled="releasing === rowUuid(row)"
						@click="release(row)">
						{{ t('openbuild', 'Release') }}
					</button>
					<button
						v-if="!isProduction(row)"
						class="version-history__btn version-history__btn--danger"
						@click="askRollback(row)">
						{{ t('openbuild', 'Roll back') }}
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
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import RollbackConfirmModal from '../modals/RollbackConfirmModal.vue'

export default {
	name: 'VersionHistory',
	components: {
		RollbackConfirmModal,
	},

	props: {
		/** The parent Application slug — drives the working versions endpoint. */
		appSlug: {
			type: String,
			default: '',
		},

		/** The parent Application UUID (kept for back-compat callers). */
		applicationUuid: {
			type: String,
			default: '',
		},

		/** The current production version UUID — marks the "Production" row. */
		currentVersionUuid: {
			type: String,
			default: '',
		},

		/** Whether the caller may edit versions (owner/editor/admin). */
		canEdit: {
			type: Boolean,
			default: false,
		},

		/** Whether the caller may release a draft to production (owner only). */
		canRelease: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['rollback', 'released'],
	data() {
		return {
			versions: [],
			loading: false,
			releasing: '',
			rollbackOpen: false,
			rollbackTarget: null,
		}
	},

	watch: {
		appSlug: {
			immediate: true,
			/**
			 * Reload the list when the parent slug resolves.
			 *
			 * @param {string} slug The parent Application slug.
			 * @return {void}
			 *
			 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-routing-ui/spec.md
			 */
			handler(slug) {
				if (slug) {
					this.refresh()
				} else if (!this.applicationUuid) {
					this.versions = []
				}
			},
		},

		applicationUuid: {
			immediate: true,
			/**
			 * Fallback: reload via OR objects endpoint when only applicationUuid
			 * is supplied (no appSlug available yet).
			 *
			 * @param {string} uuid The parent Application UUID.
			 * @return {void}
			 *
			 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-routing-ui/spec.md
			 */
			handler(uuid) {
				if (uuid && !this.appSlug) {
					this.refresh()
				}
			},
		},
	},

	methods: {
		/**
		 * Load the ApplicationVersion rows.  When appSlug is available the slug
		 * endpoint is used; otherwise fall back to the OR objects endpoint
		 * filtered by applicationUuid (e.g. when accessed via applicationUuid
		 * prop only).  Results are client-side filtered by applicationUuid when
		 * available (IDOR defence-in-depth).  Archived versions are hidden by
		 * default (decision 4).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-routing-ui/spec.md
		 */
		async refresh() {
			if (!this.appSlug && !this.applicationUuid) {
				this.versions = []
				return
			}
			this.loading = true
			try {
				let url
				if (this.appSlug) {
					url = generateUrl(
						'/apps/openbuild/api/applications/{slug}/versions',
						{ slug: this.appSlug },
					)
				} else {
					url = generateUrl(
						'/apps/openbuild/api/applicationversions?applicationUuid={uuid}',
						{ uuid: this.applicationUuid },
					)
				}
				const { data } = await axios.get(url)
				const raw = Array.isArray(data)
					? data
					: data && data.results
						? data.results
						: []
				// The IDOR filter applies ONLY to the unscoped endpoint. The
				// by-slug URL above is already app-scoped server-side, and its
				// rows do not carry `applicationUuid` at all — measured, every
				// row comes back without the key:
				//
				//   GET /api/applications/pw-verchain/versions
				//   -> 3 rows, each { name, slug, manifest, ..., status } and no
				//      applicationUuid
				//
				// ApplicationVersionsTab passes BOTH app-slug and
				// application-uuid, so this filter removed every row and the
				// "Version history" tab rendered `.version-history__empty` for
				// every app, always. Filtering a server-scoped response against
				// a field that response does not contain is not defence in
				// depth — it is an unconditional deny.
				const filtered =
					this.applicationUuid && !this.appSlug
						? raw.filter(
								(r) =>
									r && r.applicationUuid === this.applicationUuid,
							)
						: raw
				this.versions = filtered
					.filter((r) => this.rowStatus(r) !== 'archived')
					.sort((a, b) => {
						const bProd = this.isProduction(b) ? 1 : 0
						const aProd = this.isProduction(a) ? 1 : 0
						if (bProd !== aProd) {
							return bProd - aProd
						}
						// Newest first by publishedAt.
						const aDate = (a && a.publishedAt) || ''
						const bDate = (b && b.publishedAt) || ''
						return bDate > aDate ? 1 : bDate < aDate ? -1 : 0
					})
			} catch (e) {
				this.versions = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Stable key for a version row.
		 *
		 * @param {object} row The version row.
		 * @return {string}
		 */
		rowKey(row) {
			return this.rowUuid(row) || this.rowSlug(row) + ':' + this.rowName(row)
		},

		/**
		 * The version row's own UUID (from `id` or the `@self` envelope).
		 *
		 * @param {object} row The version row.
		 * @return {string}
		 */
		rowUuid(row) {
			const self = (row && row['@self']) || {}
			return (row && row.id) || self.id || self.uuid || (row && row.uuid) || ''
		},

		/**
		 * The version row's human label (name, falling back to slug).
		 *
		 * @param {object} row The version row.
		 * @return {string}
		 */
		rowName(row) {
			return (row && (row.name || row.slug)) || ''
		},

		/**
		 * The version row's slug (used for `?_version=`).
		 *
		 * @param {object} row The version row.
		 * @return {string}
		 */
		rowSlug(row) {
			return (row && row.slug) || ''
		},

		/**
		 * The version row's semver string.  Reads the canonical `semver` field
		 * first, then falls back to the `version` field used by the OR-backed
		 * shape (e.g. ApplicationVersion objects returned from the OR endpoint).
		 *
		 * @param {object} row The version row.
		 * @return {string}
		 *
		 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-routing-ui/spec.md
		 */
		rowSemver(row) {
			return (row && (row.semver || row.version)) || ''
		},

		/**
		 * The version row's lifecycle status.
		 *
		 * @param {object} row The version row.
		 * @return {string}
		 */
		rowStatus(row) {
			return (row && row.status) || 'draft'
		},

		/**
		 * Translated label for a row's status.
		 *
		 * @param {object} row The version row.
		 * @return {string}
		 */
		statusLabel(row) {
			const status = this.rowStatus(row)
			if (status === 'published') {
				return t('openbuild', 'Published')
			}
			if (status === 'archived') {
				return t('openbuild', 'Archived')
			}
			return t('openbuild', 'Draft')
		},

		/**
		 * Whether the row is the Application's current production version.
		 *
		 * @param {object} row The version row.
		 * @return {boolean}
		 */
		isProduction(row) {
			return (
				!!this.currentVersionUuid
				&& this.rowUuid(row) === this.currentVersionUuid
			)
		},

		/**
		 * Open a version in the live shell — production at the canonical URL,
		 * any other version via `?_version=` (RBAC-gated server-side).
		 *
		 * @param {object} row The version row.
		 * @return {void}
		 *
		 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-lifecycle-ui/spec.md
		 */
		openVersion(row) {
			if (!this.appSlug) {
				return
			}
			const base = generateUrl('/apps/openbuild/builder/{slug}', {
				slug: this.appSlug,
			})
			window.location.href = this.isProduction(row)
				? base
				: base + '?_version=' + encodeURIComponent(this.rowSlug(row))
		},

		/**
		 * Edit a version in the page designer, scoped via `?_version=` for
		 * non-production versions (editor+ only — gated by `canEdit`).
		 *
		 * @param {object} row The version row.
		 * @return {void}
		 *
		 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-lifecycle-ui/spec.md
		 */
		editVersion(row) {
			if (!this.appSlug) {
				return
			}
			const base = generateUrl('/apps/openbuild/builder/{slug}/pages', {
				slug: this.appSlug,
			})
			window.location.href = this.isProduction(row)
				? base
				: base + '?_version=' + encodeURIComponent(this.rowSlug(row))
		},

		/**
		 * Release a draft version: set-as-production + publish + demote previous
		 * production (owner only, server-enforced). Refreshes on success.
		 *
		 * @param {object} row The version row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-lifecycle-ui/spec.md
		 */
		async release(row) {
			const versionSlug = this.rowSlug(row)
			if (!this.appSlug || !versionSlug || this.releasing) {
				return
			}
			this.releasing = this.rowUuid(row)
			try {
				const url = generateUrl(
					'/apps/openbuild/api/applications/{slug}/versions/{versionSlug}/release',
					{ slug: this.appSlug, versionSlug },
				)
				await axios.post(url, {})
				showSuccess(
					t('openbuild', '“{name}” is now the production version.', {
						name: this.rowName(row),
					}),
				)
				this.$emit('released')
				await this.refresh()
			} catch (e) {
				const detail =
					(e && e.response && e.response.data && e.response.data.detail)
					|| (e && e.message)
					|| ''
				showError(
					t('openbuild', 'Release failed') + (detail ? ': ' + detail : ''),
				)
			} finally {
				this.releasing = ''
			}
		},

		/**
		 * Open the rollback confirmation for a non-production version.
		 *
		 * @param {object} row The version row.
		 * @return {void}
		 *
		 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-lifecycle-ui/spec.md
		 */
		askRollback(row) {
			this.rollbackTarget = {
				uuid: this.rowUuid(row),
				version:
					this.rowSemver(row)
					|| (this.rowName(row) + ' ' + this.rowSemver(row)).trim(),

				manifest: row.manifest,
				publishedAt: (row && row.publishedAt) || '',
			}
			this.rollbackOpen = true
		},

		/**
		 * Forward a confirmed rollback to the parent (which performs the PUT).
		 *
		 * @param {object} version The rollback target.
		 * @return {void}
		 */
		onRollbackConfirmed(version) {
			this.$emit('rollback', version)
			this.rollbackOpen = false
			this.rollbackTarget = null
		},

		/**
		 * Dismiss the rollback confirmation.
		 *
		 * @return {void}
		 */
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
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 8px;
	padding: 10px 12px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	background: var(--color-main-background, transparent);
	cursor: pointer;
}

.version-history__row:hover,
.version-history__row:focus-visible {
	background: var(--color-background-hover, #f5f5f5);
}

.version-history__row--current {
	border-color: var(--color-primary-element, #0082c9);
	background: var(--color-primary-light, #e6f0fa);
}

.version-history__row-title {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
}

.version-history__semver {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #888);
}

.version-history__badge {
	font-size: 11px;
	padding: 1px 8px;
	border-radius: 10px;
	background: var(--color-background-dark, #f0f0f0);
	color: var(--color-text-maxcontrast, #666);
}

.version-history__badge--published {
	background: var(--color-success, #2d7d46);
	color: var(--color-success-text, #fff);
}

.version-history__badge--production {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
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

.version-history__btn--primary {
	background: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border-color: var(--color-primary-element, #0082c9);
}

.version-history__btn--danger {
	color: var(--color-error, #d63f3f);
}
</style>
