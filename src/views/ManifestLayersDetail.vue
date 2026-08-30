<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	ManifestLayersDetail — the routed Manifest detail page for an app
	(layered-versioned-app-deltas). Reached from the app-detail dashboard's
	Manifest widget ("View versions"). Surfaces the three customization layers
	(Base / Admin delta / Your delta) and, for each non-base layer, its
	OpenRegister version history (time-travel + rollback) — reusing the existing
	VersionHistory view rather than reimplementing version storage. The caller's
	own user delta can be created / edited (UserDeltaEditModal) / reset here; a
	user can only ever touch their own delta (owner = session user).

	Registered as a manifest page (type custom) on the route
	/applications/:objectId/manifest, NOT a vue-router admin route (ADR-004).
-->
<template>
	<div class="ob-manifest-detail">
		<header class="ob-manifest-detail__header">
			<NcButton variant="tertiary" @click="goBack">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('buildiq', 'Back to app') }}
			</NcButton>
			<h2 class="ob-manifest-detail__title">
				{{ t('buildiq', 'Manifest layers — {name}', { name: appName }) }}
			</h2>
		</header>

		<p v-if="error" class="ob-manifest-detail__error">
			{{ error }}
		</p>

		<!-- Layer cards -->
		<section class="ob-manifest-detail__layers">
			<!-- Base -->
			<article class="ob-manifest-detail__layer">
				<h3>{{ t('buildiq', 'Base') }}</h3>
				<p class="ob-manifest-detail__layer-meta">
					{{
						isHybrid
							? t(
									'buildiq',
									'The installed Nextcloud app manifest. Read-only.',
								)
							: t('buildiq', 'The built app manifest. Read-only.')
					}}
				</p>
			</article>

			<!-- Admin (shared) delta -->
			<article class="ob-manifest-detail__layer">
				<h3>{{ t('buildiq', 'Admin delta') }}</h3>
				<p class="ob-manifest-detail__layer-meta">
					{{
						t(
							'buildiq',
							'Instance-wide shared customization managed by admins.',
						)
					}}
				</p>
				<NcButton
					v-if="adminVersionUuid"
					variant="tertiary"
					@click="openInOpenRegister(adminVersionUuid)">
					{{ t('buildiq', 'Open version history in OpenRegister') }}
				</NcButton>
			</article>

			<!-- Your (per-user) delta -->
			<article
				class="ob-manifest-detail__layer ob-manifest-detail__layer--user">
				<h3>{{ t('buildiq', 'Your delta') }}</h3>
				<p class="ob-manifest-detail__layer-meta">
					{{ userMeta }}
				</p>
				<div
					v-if="allowUserOverrides"
					class="ob-manifest-detail__layer-actions">
					<template v-if="userDelta.exists">
						<NcButton variant="secondary" @click="showEditModal = true">
							{{ t('buildiq', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" @click="resetOverride">
							{{ t('buildiq', 'Reset') }}
						</NcButton>
						<NcButton
							v-if="userDelta.versionUuid"
							variant="tertiary"
							@click="openInOpenRegister(userDelta.versionUuid)">
							{{
								t('buildiq', 'Open version history in OpenRegister')
							}}
						</NcButton>
					</template>
					<NcButton
						v-else
						variant="secondary"
						:disabled="creating"
						@click="createOverride">
						{{ t('buildiq', 'Create override') }}
					</NcButton>
				</div>
				<p v-else class="ob-manifest-detail__layer-meta">
					{{ t('buildiq', 'This app does not allow per-user overrides.') }}
				</p>
			</article>
		</section>

		<!-- All user overrides (maintainer view). Visible only to an owner/editor
		     of the app (or an admin); the endpoint 403s otherwise and the section
		     stays hidden. Lists every user's personal delta — who has one — not
		     the private delta bodies. -->
		<section v-if="canViewUserOverrides" class="ob-manifest-detail__overrides">
			<header class="ob-manifest-detail__overrides-header">
				<h3>{{ t('buildiq', 'User overrides') }}</h3>
				<span class="ob-manifest-detail__overrides-count">{{
					userOverrides.length
				}}</span>
			</header>
			<p
				v-if="userOverrides.length === 0"
				class="ob-manifest-detail__layer-meta">
				{{ t('buildiq', 'No users have created a personal override yet.') }}
			</p>
			<ul v-else class="ob-manifest-detail__overrides-list">
				<li
					v-for="ovr in userOverrides"
					:key="ovr.versionUuid || ovr.owner"
					class="ob-manifest-detail__override">
					<div class="ob-manifest-detail__override-main">
						<strong>{{ ovr.owner }}</strong>
						<small v-if="ovr.updatedAt">{{
							formatDate(ovr.updatedAt)
						}}</small>
					</div>
					<NcButton
						v-if="ovr.versionUuid"
						variant="tertiary"
						@click="openInOpenRegister(ovr.versionUuid)">
						{{ t('buildiq', 'Open in OpenRegister') }}
					</NcButton>
				</li>
			</ul>
		</section>

		<!-- Version history (reused). Lists the app's ApplicationVersion rows
		     (admin + user deltas) with rollback via OpenRegister versioning. -->
		<section class="ob-manifest-detail__history">
			<div v-if="canEdit" class="ob-manifest-detail__history-actions">
				<NcButton
					variant="secondary"
					:disabled="creatingDraft"
					@click="createDraft">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('buildiq', 'New draft') }}
				</NcButton>
			</div>
			<VersionHistory
				v-if="appUuid"
				ref="versionHistory"
				:appSlug="appSlug"
				:applicationUuid="appUuid"
				:currentVersionUuid="adminVersionUuid"
				:canEdit="canEdit"
				:canRelease="canRelease"
				@rollback="onRollback"
				@released="loadAll" />
		</section>

		<UserDeltaEditModal
			v-model:open="showEditModal"
			:appSlug="appSlug"
			:delta="userDelta.manifestDelta"
			@saved="onUserDeltaSaved" />
	</div>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import UserDeltaEditModal from '../modals/UserDeltaEditModal.vue'
import VersionHistory from './VersionHistory.vue'

export default {
	name: 'ManifestLayersDetail',
	components: { NcButton, ArrowLeft, Plus, VersionHistory, UserDeltaEditModal },
	props: {
		// The app UUID, forwarded from the route param by CnPageRenderer.
		objectId: { type: String, default: '' },
	},

	data() {
		return {
			application: null,
			adminVersionUuid: '',
			userDelta: {
				allowed: false,
				exists: false,
				versionUuid: null,
				manifestDelta: {},
			},

			creating: false,
			creatingDraft: false,
			showEditModal: false,
			error: '',
			// Maintainer view of ALL users' overrides (403 → not a maintainer → hidden).
			userOverrides: [],
			canViewUserOverrides: false,
		}
	},

	computed: {
		/**
		 * The app UUID (from the route param or the loaded record).
		 *
		 * @return {string}
		 */
		appUuid() {
			return (
				this.objectId
				|| (this.application
					&& (this.application.uuid || this.application.id))
				|| (this.$route && this.$route.params && this.$route.params.objectId)
				|| ''
			)
		},

		/**
		 * The app's kebab-case slug.
		 *
		 * @return {string}
		 */
		appSlug() {
			return (this.application && this.application.slug) || ''
		},

		/**
		 * The app's display name.
		 *
		 * @return {string}
		 */
		appName() {
			return (this.application && this.application.name) || this.appSlug
		},

		/**
		 * Whether this is a hybrid app.
		 *
		 * @return {boolean}
		 */
		isHybrid() {
			return (this.application && this.application.appType) === 'hybrid'
		},

		/**
		 * Whether the app allows per-user overrides.
		 *
		 * @return {boolean}
		 */
		allowUserOverrides() {
			return !!(this.application && this.application.allowUserOverrides)
		},

		/**
		 * Whether the caller may edit versions (owner / editor / NC admin).
		 *
		 * Server-side RBAC is authoritative; this only gates the affordance.
		 *
		 * @return {boolean}
		 */
		canEdit() {
			return this.hasRole('owners') || this.hasRole('editors') || this.isAdmin
		},

		/**
		 * Whether the caller may release a draft to production (owner only).
		 *
		 * Mirrors the server's owner-only-no-admin-bypass rule (REQ-OBV-110), so
		 * a non-owner admin does not see a button that would 403.
		 *
		 * @return {boolean}
		 */
		canRelease() {
			return this.hasRole('owners')
		},

		/**
		 * Whether the current user is a Nextcloud admin.
		 *
		 * @return {boolean}
		 */
		isAdmin() {
			return !!(
				typeof OC !== 'undefined'
				&& OC.isUserAdmin
				&& OC.isUserAdmin()
			)
		},

		/**
		 * Pre-translated meta line for the user-delta layer.
		 *
		 * @return {string}
		 *
		 * @spec openspec/specs/application-detail-overview/spec.md
		 */
		userMeta() {
			if (!this.allowUserOverrides) {
				return t(
					'buildiq',
					'Per-user overrides are turned off for this app.',
				)
			}
			return this.userDelta.exists
				? t('buildiq', 'Your personal delta, layered over the admin delta.')
				: t('buildiq', 'You have no personal override yet.')
		},
	},

	mounted() {
		this.loadAll()
	},

	methods: {
		/**
		 * Load the app record, its admin version, and the caller's user delta.
		 *
		 * @return {Promise<void>}
		 */
		async loadAll() {
			await this.loadApplication()
			await Promise.all([
				this.loadAdminVersion(),
				this.loadUserDelta(),
				this.loadUserOverrides(),
			])
		},

		/**
		 * Whether the current user is listed (by `user:<uid>`) in a permission
		 * role bucket on the loaded Application. Group membership is enforced
		 * server-side; this client check covers the direct-user grant.
		 *
		 * @param {string} role The role bucket name (owners / editors / viewers).
		 * @return {boolean}
		 */
		hasRole(role) {
			const uid = (getCurrentUser() && getCurrentUser().uid) || ''
			if (!uid || !this.application) {
				return false
			}
			const perms = this.application.permissions || {}
			const bucket = Array.isArray(perms[role]) ? perms[role] : []
			return bucket.includes('user:' + uid)
		},

		/**
		 * Load ALL users' overrides for this app (maintainer view). A 403 means
		 * the caller is not an owner/editor/admin — the section stays hidden.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-overview/spec.md
		 */
		async loadUserOverrides() {
			if (!this.appSlug) return
			try {
				const url = generateUrl(
					'/apps/buildiq/api/app-overrides/{appId}/user-deltas',
					{ appId: this.appSlug },
				)
				const { data } = await axios.get(url)
				this.userOverrides =
					data && Array.isArray(data.overrides) ? data.overrides : []
				this.canViewUserOverrides = true
			} catch (e) {
				// 403 (not a maintainer) or any error → hide the section.
				this.canViewUserOverrides = false
				this.userOverrides = []
			}
		},

		/**
		 * Format an ISO timestamp for display, falling back to the raw value.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string}
		 */
		formatDate(iso) {
			if (!iso) return ''
			try {
				return new Date(iso).toLocaleString()
			} catch (e) {
				return iso
			}
		},

		/**
		 * Load the Application record by UUID.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-overview/spec.md
		 */
		async loadApplication() {
			if (!this.appUuid) return
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/buildiq/application/{uuid}',
					{ uuid: this.appUuid },
				)
				const { data } = await axios.get(url)
				this.application = data || null
			} catch (e) {
				this.error = t('buildiq', 'Could not load the app.')
			}
		},

		/**
		 * Resolve the admin (production) version UUID for the version-history reuse.
		 *
		 * @return {Promise<void>}
		 */
		async loadAdminVersion() {
			const pv = this.application && this.application.productionVersion
			if (pv) {
				this.adminVersionUuid =
					typeof pv === 'string' ? pv : pv.uuid || pv.id || ''
			}
		},

		/**
		 * Load the caller's own user-delta state.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-overview/spec.md
		 */
		async loadUserDelta() {
			if (!this.appSlug) return
			try {
				const url = generateUrl(
					'/apps/buildiq/api/app-overrides/{appId}/user',
					{ appId: this.appSlug },
				)
				const { data } = await axios.get(url)
				this.userDelta = {
					allowed: !!(data && data.allowed),
					exists: !!(data && data.exists),
					versionUuid: (data && data.versionUuid) || null,
					manifestDelta: (data && data.manifestDelta) || {},
				}
			} catch (e) {
				this.userDelta = {
					allowed: false,
					exists: false,
					versionUuid: null,
					manifestDelta: {},
				}
			}
		},

		/**
		 * Create an empty user delta.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-overview/spec.md
		 */
		async createOverride() {
			if (!this.appSlug || this.creating) return
			this.creating = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/buildiq/api/app-overrides/{appId}/user',
					{ appId: this.appSlug },
				)
				await axios.put(url, {})
				await this.loadUserDelta()
			} catch (e) {
				this.error = t('buildiq', 'Could not create your override.')
			} finally {
				this.creating = false
			}
		},

		/**
		 * Delete the caller's own user delta.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/application-detail-overview/spec.md
		 */
		async resetOverride() {
			if (!this.appSlug) return
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/buildiq/api/app-overrides/{appId}/user',
					{ appId: this.appSlug },
				)
				await axios.delete(url)
				await this.loadUserDelta()
			} catch (e) {
				this.error = t('buildiq', 'Could not reset your override.')
			}
		},

		/**
		 * Refresh the user delta after the edit modal saved.
		 *
		 * @return {void}
		 */
		onUserDeltaSaved() {
			this.loadUserDelta()
		},

		/**
		 * No-op rollback handler — OpenRegister performs the version rollback;
		 * we just refresh the layer state afterwards.
		 *
		 * @return {void}
		 */
		onRollback() {
			this.loadAll()
		},

		/**
		 * The own UUID of an ApplicationVersion row (`id` or `@self` envelope).
		 *
		 * @param {object} row The version row.
		 * @return {string}
		 */
		rowUuid(row) {
			const self = (row && row['@self']) || {}
			return (row && row.id) || self.id || self.uuid || (row && row.uuid) || ''
		},

		/**
		 * Create a new draft version: clone the production manifest, share the
		 * production register (omit `register` so the backend inherits it), and
		 * auto-name it `Draft N` / `draft-n` (decision: auto naming).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/version-lifecycle-and-switcher/specs/version-lifecycle-ui/spec.md
		 */
		async createDraft() {
			if (!this.appSlug || this.creatingDraft) {
				return
			}
			this.creatingDraft = true
			this.error = ''
			try {
				const listUrl = generateUrl(
					'/apps/buildiq/api/applications/{slug}/versions',
					{ slug: this.appSlug },
				)
				const { data } = await axios.get(listUrl)
				const rows = Array.isArray(data)
					? data
					: data && data.results
						? data.results
						: []

				// Clone the current production version's manifest.
				let manifest = {}
				const prod = rows.find(
					(r) => this.rowUuid(r) === this.adminVersionUuid,
				)
				if (prod && prod.manifest) {
					manifest = prod.manifest
				}

				// Next "Draft N" / draft-n (one past the highest existing draft-N).
				let maxN = 0
				rows.forEach((r) => {
					const m = /^draft-(\d+)$/.exec((r && r.slug) || '')
					if (m) {
						maxN = Math.max(maxN, parseInt(m[1], 10))
					}
				})
				const n = maxN + 1
				let slug = 'draft-' + n
				if (rows.some((r) => (r && r.slug) === slug)) {
					slug = slug + '-' + Date.now().toString(36)
				}

				// Omit `register` → backend inherits production's (manifest-only versioning).
				await axios.post(listUrl, {
					name: 'Draft ' + n,
					slug,
					status: 'draft',
					manifest,
					application: this.appUuid,
				})
				showSuccess(
					t('buildiq', 'Draft “{name}” created.', {
						name: 'Draft ' + n,
					}),
				)
				if (this.$refs.versionHistory) {
					await this.$refs.versionHistory.refresh()
				}
			} catch (e) {
				const detail =
					(e && e.response && e.response.data && e.response.data.detail)
					|| ''
				this.error =
					t('buildiq', 'Could not create a draft.')
					+ (detail ? ' ' + detail : '')
			} finally {
				this.creatingDraft = false
			}
		},

		/**
		 * Deep-link to an ApplicationVersion row's OpenRegister object page
		 * (which carries OR's native version history / time-travel / rollback).
		 *
		 * @param {string} versionUuid The ApplicationVersion UUID.
		 * @return {void}
		 *
		 * @spec openspec/specs/application-detail-overview/spec.md
		 */
		openInOpenRegister(versionUuid) {
			if (!versionUuid) return
			const url = generateUrl(
				'/apps/openregister/objects/buildiq/applicationVersion/{uuid}',
				{ uuid: versionUuid },
			)
			window.location.href = url
		},

		/**
		 * Navigate back to the app detail page.
		 *
		 * @return {void}
		 */
		goBack() {
			if (this.$router && this.appUuid) {
				this.$router
					.push({
						name: 'VirtualAppDetail',
						params: { objectId: this.appUuid },
					})
					.catch(() => {})
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-manifest-detail {
	display: flex;
	flex-direction: column;
	gap: 20px;
	padding: 16px;
	max-width: 900px;
	margin: 0 auto;
}

.ob-manifest-detail__header {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ob-manifest-detail__title {
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

.ob-manifest-detail__error {
	margin: 0;
	color: var(--color-error, #d63f3f);
	font-size: 13px;
}

.ob-manifest-detail__layers {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 12px;
}

@media (max-width: 800px) {
	.ob-manifest-detail__layers {
		grid-template-columns: 1fr;
	}
}

.ob-manifest-detail__layer {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);

	h3 {
		margin: 0;
		font-size: 15px;
		font-weight: 600;
	}
}

.ob-manifest-detail__layer--user {
	border-color: var(--color-primary-element, #0082c9);
}

.ob-manifest-detail__layer-meta {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-manifest-detail__layer-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.ob-manifest-detail__history {
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-manifest-detail__history-actions {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}

.ob-manifest-detail__overrides {
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-manifest-detail__overrides-header {
	display: flex;
	align-items: center;
	gap: 8px;

	h3 {
		margin: 0;
		font-size: 15px;
		font-weight: 600;
	}
}

.ob-manifest-detail__overrides-count {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: 10px;
	background: var(--color-background-dark, #f0f0f0);
	color: var(--color-text-maxcontrast, #666);
}

.ob-manifest-detail__overrides-list {
	list-style: none;
	margin: 8px 0 0 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.ob-manifest-detail__override {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 12px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
}

.ob-manifest-detail__override-main {
	display: flex;
	flex-direction: column;
	gap: 2px;

	small {
		font-size: 12px;
		color: var(--color-text-maxcontrast, #666);
	}
}
</style>
