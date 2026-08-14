<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	ManifestWidget — the app-detail dashboard card that surfaces the manifest
	customization LAYERS for an app (layered-versioned-app-deltas), replacing the
	manifest-derived Schemas / Pages / Menu structural widgets. It does NOT render
	raw manifest JSON; it shows the three layers and their state:

	  1. Base       — the bundled/base manifest (read-only).
	  2. Admin      — the instance-wide shared delta (current version label).
	  3. Your delta — the calling user's own delta, when the app allows per-user
	                  overrides: its current state + Edit / Reset, or a
	                  "Create override" affordance when none exists yet.

	Clicking "View versions" opens the routed Manifest detail page (per-layer OR
	version history + rollback). The user-delta create/edit/reset calls go to the
	owner-scoped /api/app-overrides/{appId}/user endpoints (the owner is always
	the session user — no-admin-idor).
-->
<template>
	<div class="ob-manifest-widget">
		<header class="ob-manifest-widget__header">
			<h3 class="ob-manifest-widget__title">
				{{ t('openbuild', 'Manifest layers') }}
			</h3>
			<a
				class="ob-manifest-widget__view-all"
				role="button"
				tabindex="0"
				@click="$emit('open-detail')"
				@keyup.enter="$emit('open-detail')">
				{{ t('openbuild', 'View versions') }}
			</a>
		</header>

		<ul class="ob-manifest-widget__layers">
			<!-- 1. Base layer (read-only) -->
			<li class="ob-manifest-widget__layer">
				<div class="ob-manifest-widget__layer-main">
					<span class="ob-manifest-widget__layer-name">{{
						t('openbuild', 'Base')
					}}</span>
					<span class="ob-manifest-widget__layer-meta">
						{{
							isHybrid
								? t('openbuild', 'Installed app manifest')
								: t('openbuild', 'Built manifest')
						}}
					</span>
				</div>
				<span
					class="ob-manifest-widget__badge ob-manifest-widget__badge--muted">
					{{ t('openbuild', 'Read-only') }}
				</span>
			</li>

			<!-- 2. Admin (shared) delta -->
			<li class="ob-manifest-widget__layer">
				<div class="ob-manifest-widget__layer-main">
					<span class="ob-manifest-widget__layer-name">{{
						t('openbuild', 'Admin delta')
					}}</span>
					<span class="ob-manifest-widget__layer-meta">
						{{
							t('openbuild', 'Shared · {label}', { label: adminLabel })
						}}
					</span>
				</div>
				<span class="ob-manifest-widget__badge">{{ adminStatusLabel }}</span>
			</li>

			<!-- 3. Your (per-user) delta -->
			<li class="ob-manifest-widget__layer ob-manifest-widget__layer--user">
				<div class="ob-manifest-widget__layer-main">
					<span class="ob-manifest-widget__layer-name">{{
						t('openbuild', 'Your delta')
					}}</span>
					<span class="ob-manifest-widget__layer-meta">{{
						userMeta
					}}</span>
				</div>
				<div class="ob-manifest-widget__layer-actions">
					<NcLoadingIcon v-if="userLoading" :size="20" />
					<template v-else-if="!allowUserOverrides">
						<span
							class="ob-manifest-widget__badge ob-manifest-widget__badge--muted">
							{{ t('openbuild', 'Disabled') }}
						</span>
					</template>
					<template v-else-if="userDelta.exists">
						<NcButton variant="tertiary" @click="$emit('edit-override')">
							{{ t('openbuild', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" @click="resetOverride">
							{{ t('openbuild', 'Reset') }}
						</NcButton>
					</template>
					<template v-else>
						<NcButton
							variant="secondary"
							:disabled="creating"
							@click="createOverride">
							{{ t('openbuild', 'Create override') }}
						</NcButton>
					</template>
				</div>
			</li>
		</ul>

		<!-- Maintainer-only: how many users have a personal override across the
		     whole instance. Only resolves (non-null) for an owner/editor/admin
		     (the endpoint 403s otherwise). Opens the detail page's full list. -->
		<footer
			v-if="userOverrideCount !== null"
			class="ob-manifest-widget__overrides">
			<a
				class="ob-manifest-widget__view-all"
				role="button"
				tabindex="0"
				@click="$emit('open-detail')"
				@keyup.enter="$emit('open-detail')">
				{{
					n(
						'openbuild',
						'%n user override',
						'%n user overrides',
						userOverrideCount,
					)
				}}
			</a>
		</footer>

		<p v-if="error" class="ob-manifest-widget__error">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'ManifestWidget',
	components: { NcButton, NcLoadingIcon },
	props: {
		/** The app's kebab-case slug (= the fleet appId for a hybrid app). */
		appSlug: { type: String, required: true },
		/** Whether this is a hybrid app (mirrors an installed Nextcloud app). */
		isHybrid: { type: Boolean, default: false },
		/** Whether the app permits per-user overrides (the allowUserOverrides flag). */
		allowUserOverrides: { type: Boolean, default: false },
		/** Human label for the admin delta's current version (e.g. semver or name). */
		adminLabel: { type: String, default: '' },
		/** Lifecycle status of the admin delta's current version. */
		adminStatus: { type: String, default: '' },
	},

	emits: ['open-detail', 'edit-override', 'changed'],
	data() {
		return {
			userDelta: { allowed: false, exists: false, versionUuid: null },
			userLoading: false,
			creating: false,
			error: '',
			// Count of ALL users' overrides — non-null only for a maintainer
			// (owner/editor/admin); the endpoint 403s for everyone else.
			userOverrideCount: null,
		}
	},

	computed: {
		/**
		 * Pre-translated label for the admin delta's lifecycle status.
		 *
		 * @return {string}
		 */
		adminStatusLabel() {
			const map = {
				published: t('openbuild', 'Published'),
				draft: t('openbuild', 'Draft'),
				archived: t('openbuild', 'Archived'),
			}
			return map[this.adminStatus] || t('openbuild', 'Current')
		},

		/**
		 * Pre-translated meta line for the user-delta layer.
		 *
		 * @return {string}
		 */
		userMeta() {
			if (!this.allowUserOverrides) {
				return t(
					'openbuild',
					'Per-user overrides are turned off for this app',
				)
			}
			if (this.userLoading) {
				return t('openbuild', 'Loading…')
			}
			return this.userDelta.exists
				? t('openbuild', 'Personal · layered over the admin delta')
				: t('openbuild', 'No personal override yet')
		},
	},

	watch: {
		appSlug: 'loadUserDelta',
		allowUserOverrides: 'loadUserDelta',
	},

	mounted() {
		this.loadUserDelta()
		this.loadOverrideCount()
	},

	methods: {
		/**
		 * Load the count of ALL users' overrides (maintainer-only). Stays null
		 * (footer hidden) for non-maintainers — the endpoint 403s.
		 *
		 * @return {Promise<void>}
		 */
		async loadOverrideCount() {
			if (!this.appSlug) return
			try {
				const url = generateUrl(
					'/apps/openbuild/api/app-overrides/{appId}/user-deltas',
					{ appId: this.appSlug },
				)
				const { data } = await axios.get(url)
				this.userOverrideCount =
					data && typeof data.total === 'number' ? data.total : 0
			} catch (e) {
				this.userOverrideCount = null
			}
		},

		/**
		 * Load the calling user's own user-delta state for this app.
		 *
		 * @return {Promise<void>}
		 */
		async loadUserDelta() {
			if (!this.appSlug) return
			this.userLoading = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/openbuild/api/app-overrides/{appId}/user',
					{ appId: this.appSlug },
				)
				const { data } = await axios.get(url)
				this.userDelta = {
					allowed: !!(data && data.allowed),
					exists: !!(data && data.exists),
					versionUuid: (data && data.versionUuid) || null,
				}
			} catch (e) {
				// Best-effort: a 401/403 just means no personal layer is available.
				this.userDelta = { allowed: false, exists: false, versionUuid: null }
			} finally {
				this.userLoading = false
			}
		},

		/**
		 * Create an empty user delta (the "I want my own override" no-op state).
		 *
		 * @return {Promise<void>}
		 */
		async createOverride() {
			if (!this.appSlug || this.creating) return
			this.creating = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/openbuild/api/app-overrides/{appId}/user',
					{ appId: this.appSlug },
				)
				await axios.put(url, {})
				await this.loadUserDelta()
				this.$emit('changed')
			} catch (e) {
				this.error = this.extractError(
					e,
					t('openbuild', 'Could not create your override'),
				)
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
				const url = generateUrl(
					'/apps/openbuild/api/app-overrides/{appId}/user',
					{ appId: this.appSlug },
				)
				await axios.delete(url)
				await this.loadUserDelta()
				this.$emit('changed')
			} catch (e) {
				this.error = this.extractError(
					e,
					t('openbuild', 'Could not reset your override'),
				)
			}
		},

		/**
		 * Extract a human error message from an axios failure.
		 *
		 * @param {Error} e The caught error.
		 * @param {string} fallback A pre-translated fallback message.
		 * @return {string}
		 */
		extractError(e, fallback) {
			const detail =
				e
				&& e.response
				&& e.response.data
				&& (e.response.data.detail || e.response.data.error)
			return detail ? `${fallback}: ${detail}` : fallback
		},
	},
}
</script>

<style lang="scss" scoped>
.ob-manifest-widget {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-main-background, #fff);
}

.ob-manifest-widget__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.ob-manifest-widget__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.ob-manifest-widget__view-all {
	font-size: 13px;
	color: var(--color-primary-element);
	cursor: pointer;
	text-decoration: none;

	&:hover {
		text-decoration: underline;
	}
}

.ob-manifest-widget__layers {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.ob-manifest-widget__layer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 10px 12px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius, 4px);
	background: var(--color-background-hover, transparent);
}

.ob-manifest-widget__layer--user {
	border-color: var(--color-primary-element, #0082c9);
}

.ob-manifest-widget__layer-main {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.ob-manifest-widget__layer-name {
	font-size: 14px;
	font-weight: 600;
}

.ob-manifest-widget__layer-meta {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #666);
}

.ob-manifest-widget__layer-actions {
	display: flex;
	align-items: center;
	gap: 4px;
	flex-shrink: 0;
}

.ob-manifest-widget__badge {
	font-size: 12px;
	padding: 2px 8px;
	border-radius: 10px;
	background: var(--color-primary-element-light, #e6f0fa);
	color: var(--color-primary-element, #0082c9);
	white-space: nowrap;
}

.ob-manifest-widget__badge--muted {
	background: var(--color-background-dark, #f0f0f0);
	color: var(--color-text-maxcontrast, #666);
}

.ob-manifest-widget__error {
	margin: 0;
	font-size: 13px;
	color: var(--color-error, #d63f3f);
}
</style>
