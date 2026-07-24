<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - AppBrandedHeader — renders the app-theming `headerStyle: "branded"`
  - logo strip (openspec/changes/app-theming). CnAppRoot (the installed
  - @conduction/nextcloud-vue version) exposes no dedicated top-bar
  - branding/logo slot — only `#header-actions` (toolbar buttons rendered
  - alongside the router-view) and `#menu` (left-rail nav) — so this is an
  - OpenBuild-side binding rendered as a sibling ABOVE the nested CnAppRoot
  - inside BuilderHost's own `[data-openbuild-theme-scope]` wrapper,
  - documented as a deviation from design.md's Open Question lean ("app
  - header bar") rather than a CnAppRoot slot override. It never touches
  - the NC chrome or CnAppRoot's own internals.
  -
  - Logo resolution (REQ "Logo defaults to the Application's existing icon
  - fields"):
  -   - `logoRef` null/unset → the Application's existing light icon via
  -     the EXISTING `/apps/openbuild/icons/{slug}.svg` route
  -     (IconController/IconService, app-icon-management — reused
  -     UNMODIFIED, no new backend route).
  -   - `logoRef.ref` set → the dedicated uploaded theme logo, resolved via
  -     OpenRegister's EXISTING generic file-listing endpoint
  -     (`GET .../objects/openbuild/application/{uuid}/files`), matching by
  -     filename and using the returned `downloadUrl` — again no new
  -     backend route (proposal.md Impact: "no new controller/route").
  -     Falls back to the default icon on any resolution failure (same
  -     graceful-degradation pattern `useAppTheme.js` uses for nldesign).
  -
  - Colors: `--ob-theme-secondary` (header background) / `--ob-theme-accent`
  - (bottom accent bar) are the scoped custom properties
  - `useAppCustomTheme.js` injects — read here via plain CSS var()
  - inheritance from the ancestor `[data-openbuild-theme-scope]` element,
  - no prop plumbing needed.
  -
  - @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-logo-defaults-to-the-applications-existing-icon-fields
  - @spec openspec/changes/app-theming/specs/app-theming/spec.md#requirement-an-active-nldesign-theme-takes-precedence-over-apptheme-colors
  -->
<template>
	<header class="ob-branded-header" data-testid="ob-branded-header">
		<img
			:src="logoSrc"
			:alt="appName"
			class="ob-branded-header__logo"
			@error="onLogoError">
		<span class="ob-branded-header__name">{{ appName }}</span>
	</header>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AppBrandedHeader',
	props: {
		/** The virtual-app slug (used for the default icon URL). */
		appSlug: { type: String, required: true },
		/** Display name shown next to the logo. */
		appName: { type: String, default: '' },
		/** `appTheme.logoRef` — `null`/`{ ref: '' }` uses the app icon; `{ ref: '<filename>' }` uses a dedicated theme logo. */
		logoRef: { type: Object, default: null },
		/** The owning Application's OR uuid, required to resolve a dedicated `logoRef`. */
		applicationUuid: { type: String, default: '' },
	},
	data() {
		return {
			dedicatedLogoUrl: null,
			logoErrored: false,
		}
	},
	computed: {
		/**
		 * The default (app-icon) logo URL — the existing, unmodified
		 * IconController route.
		 *
		 * @return {string}
		 */
		defaultIconUrl() {
			return generateUrl(`/apps/openbuild/icons/${this.appSlug}.svg`)
		},
		/**
		 * The resolved logo source — the dedicated theme logo when one was
		 * found, otherwise the app icon.
		 *
		 * @return {string}
		 */
		logoSrc() {
			if (this.logoErrored) {
				return this.defaultIconUrl
			}
			return this.dedicatedLogoUrl || this.defaultIconUrl
		},
	},
	watch: {
		logoRef: { immediate: true, handler: 'resolveDedicatedLogo' },
		applicationUuid: 'resolveDedicatedLogo',
	},
	methods: {
		/**
		 * Resolve a dedicated theme-logo filename to its OR download URL via
		 * the existing generic files-listing endpoint. No-ops (falls back to
		 * the app icon) when no dedicated ref is set, the uuid is unknown, or
		 * the file cannot be found — same graceful-degradation contract the
		 * rest of this feature follows.
		 *
		 * @return {Promise<void>}
		 */
		async resolveDedicatedLogo() {
			this.logoErrored = false
			const ref = this.logoRef && this.logoRef.ref
			if (!ref || !this.applicationUuid) {
				this.dedicatedLogoUrl = null
				return
			}
			try {
				const url = generateUrl(`/apps/openregister/api/objects/openbuild/application/${this.applicationUuid}/files`)
				const { data } = await axios.get(url)
				const files = Array.isArray(data) ? data : ((data && data.results) ? data.results : [])
				const match = files.find((f) => f && (f.title === ref || f.name === ref))
				this.dedicatedLogoUrl = (match && (match.downloadUrl || match.accessUrl)) || null
			} catch {
				this.dedicatedLogoUrl = null
			}
		},
		/**
		 * Fall back to the app icon if the resolved logo image itself fails
		 * to load (broken/stale downloadUrl).
		 *
		 * @return {void}
		 */
		onLogoError() {
			if (!this.logoErrored) {
				this.logoErrored = true
			}
		},
	},
}
</script>

<style scoped>
.ob-branded-header {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 16px;
	background: var(--ob-theme-secondary, var(--color-main-background));
	border-bottom: 3px solid var(--ob-theme-accent, var(--color-primary-element));
}

.ob-branded-header__logo {
	height: 32px;
	width: 32px;
	object-fit: contain;
}

.ob-branded-header__name {
	font-weight: 600;
	font-size: 1.1rem;
	color: var(--color-main-text);
}
</style>
