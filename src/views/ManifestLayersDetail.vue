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
			<NcButton type="tertiary" @click="goBack">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>
				{{ t('openbuild', 'Back to app') }}
			</NcButton>
			<h2 class="ob-manifest-detail__title">
				{{ t('openbuild', 'Manifest layers — {name}', { name: appName }) }}
			</h2>
		</header>

		<p v-if="error" class="ob-manifest-detail__error">
			{{ error }}
		</p>

		<!-- Layer cards -->
		<section class="ob-manifest-detail__layers">
			<!-- Base -->
			<article class="ob-manifest-detail__layer">
				<h3>{{ t('openbuild', 'Base') }}</h3>
				<p class="ob-manifest-detail__layer-meta">
					{{ isHybrid ? t('openbuild', 'The installed Nextcloud app manifest. Read-only.') : t('openbuild', 'The built app manifest. Read-only.') }}
				</p>
			</article>

			<!-- Admin (shared) delta -->
			<article class="ob-manifest-detail__layer">
				<h3>{{ t('openbuild', 'Admin delta') }}</h3>
				<p class="ob-manifest-detail__layer-meta">
					{{ t('openbuild', 'Instance-wide shared customization managed by admins.') }}
				</p>
				<NcButton
					v-if="adminVersionUuid"
					type="tertiary"
					@click="openInOpenRegister(adminVersionUuid)">
					{{ t('openbuild', 'Open version history in OpenRegister') }}
				</NcButton>
			</article>

			<!-- Your (per-user) delta -->
			<article class="ob-manifest-detail__layer ob-manifest-detail__layer--user">
				<h3>{{ t('openbuild', 'Your delta') }}</h3>
				<p class="ob-manifest-detail__layer-meta">
					{{ userMeta }}
				</p>
				<div v-if="allowUserOverrides" class="ob-manifest-detail__layer-actions">
					<template v-if="userDelta.exists">
						<NcButton type="secondary" @click="showEditModal = true">
							{{ t('openbuild', 'Edit') }}
						</NcButton>
						<NcButton type="tertiary" @click="resetOverride">
							{{ t('openbuild', 'Reset') }}
						</NcButton>
						<NcButton
							v-if="userDelta.versionUuid"
							type="tertiary"
							@click="openInOpenRegister(userDelta.versionUuid)">
							{{ t('openbuild', 'Open version history in OpenRegister') }}
						</NcButton>
					</template>
					<NcButton v-else
						type="secondary"
						:disabled="creating"
						@click="createOverride">
						{{ t('openbuild', 'Create override') }}
					</NcButton>
				</div>
				<p v-else class="ob-manifest-detail__layer-meta">
					{{ t('openbuild', 'This app does not allow per-user overrides.') }}
				</p>
			</article>
		</section>

		<!-- Version history (reused). Lists the app's ApplicationVersion rows
		     (admin + user deltas) with rollback via OpenRegister versioning. -->
		<section class="ob-manifest-detail__history">
			<VersionHistory
				v-if="appUuid"
				:application-uuid="appUuid"
				:current-version-uuid="adminVersionUuid"
				@rollback="onRollback" />
		</section>

		<UserDeltaEditModal
			:open.sync="showEditModal"
			:app-slug="appSlug"
			:delta="userDelta.manifestDelta"
			@saved="onUserDeltaSaved" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'

import VersionHistory from './VersionHistory.vue'
import UserDeltaEditModal from '../modals/UserDeltaEditModal.vue'

export default {
	name: 'ManifestLayersDetail',
	components: { NcButton, ArrowLeft, VersionHistory, UserDeltaEditModal },
	props: {
		// The app UUID, forwarded from the route param by CnPageRenderer.
		objectId: { type: String, default: '' },
	},
	data() {
		return {
			application: null,
			adminVersionUuid: '',
			userDelta: { allowed: false, exists: false, versionUuid: null, manifestDelta: {} },
			creating: false,
			showEditModal: false,
			error: '',
		}
	},
	computed: {
		/**
		 * The app UUID (from the route param or the loaded record).
		 *
		 * @return {string}
		 */
		appUuid() {
			return this.objectId
				|| (this.application && (this.application.uuid || this.application.id))
				|| (this.$route && this.$route.params && this.$route.params.objectId)
				|| ''
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
		 * Pre-translated meta line for the user-delta layer.
		 *
		 * @return {string}
		 */
		userMeta() {
			if (!this.allowUserOverrides) {
				return t('openbuild', 'Per-user overrides are turned off for this app.')
			}
			return this.userDelta.exists
				? t('openbuild', 'Your personal delta, layered over the admin delta.')
				: t('openbuild', 'You have no personal override yet.')
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
			await Promise.all([this.loadAdminVersion(), this.loadUserDelta()])
		},
		/**
		 * Load the Application record by UUID.
		 *
		 * @return {Promise<void>}
		 */
		async loadApplication() {
			if (!this.appUuid) return
			try {
				const url = generateUrl(
					'/apps/openregister/api/objects/openbuild/application/{uuid}',
					{ uuid: this.appUuid },
				)
				const { data } = await axios.get(url)
				this.application = data || null
			} catch (e) {
				this.error = t('openbuild', 'Could not load the app.')
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
				this.adminVersionUuid = (typeof pv === 'string') ? pv : (pv.uuid || pv.id || '')
			}
		},
		/**
		 * Load the caller's own user-delta state.
		 *
		 * @return {Promise<void>}
		 */
		async loadUserDelta() {
			if (!this.appSlug) return
			try {
				const url = generateUrl('/apps/openbuild/api/app-overrides/{appId}/user', { appId: this.appSlug })
				const { data } = await axios.get(url)
				this.userDelta = {
					allowed: !!(data && data.allowed),
					exists: !!(data && data.exists),
					versionUuid: (data && data.versionUuid) || null,
					manifestDelta: (data && data.manifestDelta) || {},
				}
			} catch (e) {
				this.userDelta = { allowed: false, exists: false, versionUuid: null, manifestDelta: {} }
			}
		},
		/**
		 * Create an empty user delta.
		 *
		 * @return {Promise<void>}
		 */
		async createOverride() {
			if (!this.appSlug || this.creating) return
			this.creating = true
			this.error = ''
			try {
				const url = generateUrl('/apps/openbuild/api/app-overrides/{appId}/user', { appId: this.appSlug })
				await axios.put(url, {})
				await this.loadUserDelta()
			} catch (e) {
				this.error = t('openbuild', 'Could not create your override.')
			} finally {
				this.creating = false
			}
		},
		/**
		 * Delete the caller's own user delta.
		 *
		 * @return {Promise<void>}
		 */
		async resetOverride() {
			if (!this.appSlug) return
			this.error = ''
			try {
				const url = generateUrl('/apps/openbuild/api/app-overrides/{appId}/user', { appId: this.appSlug })
				await axios.delete(url)
				await this.loadUserDelta()
			} catch (e) {
				this.error = t('openbuild', 'Could not reset your override.')
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
		 * Deep-link to an ApplicationVersion row's OpenRegister object page
		 * (which carries OR's native version history / time-travel / rollback).
		 *
		 * @param {string} versionUuid The ApplicationVersion UUID.
		 * @return {void}
		 */
		openInOpenRegister(versionUuid) {
			if (!versionUuid) return
			const url = generateUrl(
				'/apps/openregister/objects/openbuild/applicationVersion/{uuid}',
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
				this.$router.push({ name: 'VirtualAppDetail', params: { objectId: this.appUuid } }).catch(() => {})
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
</style>
