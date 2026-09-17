<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - BackToVirtualApps: the way back from a running virtual app to Buildiq's
  - app list, for the people building the app. The runtime host
  - (src/builder.js) renders it at the top of the app's navigation, through
  - CnAppNav's `primary-action` slot, so it is never part of the manifest and
  - an in-app save cannot store it.
  -->
<template>
	<div class="ob-back-to-apps">
		<a class="ob-back-to-apps__link" :href="href">
			<span aria-hidden="true">←</span>
			{{ t('buildiq', 'Back to virtual apps') }}
		</a>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

/**
 * Whether the running app shows the link: in a version preview, which only
 * owners and editors can open, or for the app's owner.
 *
 * @param {{versionSlug?: string, manifest?: object}} context - the runtime's
 *   `?_version=` value and the manifest the server returned.
 * @return {boolean} true when the link shows.
 * @spec openspec/changes/runtime-back-to-virtual-apps/specs/openbuild-runtime/spec.md#requirement-the-standalone-shell-offers-builders-a-way-back-to-the-app-list
 */
export function showBackToVirtualApps({ versionSlug = '', manifest = null } = {}) {
	if (versionSlug) {
		return true
	}
	const user = manifest && manifest.runtime && manifest.runtime.user
	return !!(user && user.isOwner === true)
}

/**
 * Whether the app declares its own primary action for the page on screen,
 * which owns the navigation's top region.
 *
 * @param {?object} manifest - the running app's manifest.
 * @param {?string} routeName - the name (page id) of the route on screen.
 * @return {boolean} true when a primary action is declared.
 * @spec openspec/changes/runtime-back-to-virtual-apps/specs/openbuild-runtime/spec.md#requirement-the-standalone-shell-offers-builders-a-way-back-to-the-app-list
 */
export function declaresPrimaryAction(manifest, routeName) {
	if (!manifest) {
		return false
	}
	const pages = Array.isArray(manifest.pages) ? manifest.pages : []
	const page = pages.find((p) => p && p.id === routeName)
	if (page && page.primaryAction) {
		return true
	}
	return !!(manifest.nav && manifest.nav.primaryAction)
}

export default {
	name: 'BackToVirtualApps',

	computed: {
		/**
		 * Buildiq's app list.
		 *
		 * @return {string} the URL.
		 * @spec openspec/changes/runtime-back-to-virtual-apps/specs/openbuild-runtime/spec.md#requirement-the-standalone-shell-offers-builders-a-way-back-to-the-app-list
		 */
		href() {
			return generateUrl('/apps/buildiq/applications')
		},
	},
}
</script>

<style scoped>
.ob-back-to-apps {
	padding: 8px 12px;
}

.ob-back-to-apps__link {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	color: var(--color-main-text);
	text-decoration: underline;
}

.ob-back-to-apps__link:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}
</style>
