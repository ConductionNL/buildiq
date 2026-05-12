<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
 OpenBuilt app shell. Mounts CnAppRoot with the bundled manifest and the
 customComponents registry; CnAppRoot handles the OpenRegister dependency
 check, renders CnAppNav from manifest.menu, and routes <router-view> pages
 through CnPageRenderer. The #dependency-missing slot keeps OpenBuilt's
 original "OpenRegister is required" empty state.

 @adr ADR-024 (app manifest) — OpenBuilt is now Tier-1+ (its own shell is
 manifest-driven, like the virtual apps it builds).
-->
<template>
	<CnAppRoot
		app-id="openbuilt"
		:manifest="manifest"
		:custom-components="customComponents"
		:page-types="pageTypes"
		:translate="translateForApp"
		:permissions="permissions">
		<template #dependency-missing>
			<NcAppContent class="open-register-missing">
				<NcEmptyContent
					:name="t('openbuilt', 'OpenRegister is required')"
					:description="t('openbuilt', 'This app needs OpenRegister to store and manage data. Please install OpenRegister from the app store to get started.')">
					<template #icon>
						<img :src="appIcon"
							alt=""
							width="64"
							height="64">
					</template>
					<template #action>
						<NcButton
							v-if="isAdmin"
							type="primary"
							:href="appStoreUrl">
							{{ t('openbuilt', 'Install OpenRegister') }}
						</NcButton>
					</template>
				</NcEmptyContent>
			</NcAppContent>
		</template>
	</CnAppRoot>
</template>

<script>
import { translate as ncT } from '@nextcloud/l10n'
import { generateUrl, imagePath } from '@nextcloud/router'
import { CnAppRoot } from '@conduction/nextcloud-vue'
import { NcAppContent, NcButton, NcEmptyContent } from '@nextcloud/vue'
import { initializeStores } from './store/store.js'
import { useSettingsStore } from './store/modules/settings.js'

export default {
	name: 'App',

	components: {
		CnAppRoot,
		NcAppContent,
		NcButton,
		NcEmptyContent,
	},

	props: {
		/**
		 * Bundled app manifest — passed from main.js. CnAppRoot reads
		 * `manifest.dependencies` for the dependency-check phase and
		 * `manifest.menu` for CnAppNav.
		 *
		 * @type {object}
		 */
		manifest: {
			type: Object,
			required: true,
		},
		/**
		 * Registry of consumer-injected components used by `type: "custom"`
		 * pages (`page.component`) and other manifest slot overrides.
		 *
		 * @type {object}
		 */
		customComponents: {
			type: Object,
			default: () => ({}),
		},
		/**
		 * Page-type registry — `{ index, detail, dashboard, custom, ... }`.
		 * Wired through to descendant CnPageRenderer instances.
		 *
		 * @type {?object}
		 */
		pageTypes: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/**
		 * The current user's Nextcloud permission flags, passed to CnAppNav.
		 *
		 * @return {Array} Permission identifiers (empty when unavailable).
		 */
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},

		/**
		 * Whether the current user is a Nextcloud admin — gates the
		 * "Install OpenRegister" button in the dependency-missing slot.
		 *
		 * @return {boolean} True for admins.
		 */
		isAdmin() {
			try {
				return useSettingsStore().getIsAdmin === true
			} catch (e) {
				return typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
			}
		},

		/**
		 * Path to the white-on-transparent app icon for the empty state.
		 *
		 * @return {string} Image path.
		 */
		appIcon() {
			return imagePath('openbuilt', 'app-dark.svg')
		},

		/**
		 * Deep link to OpenRegister's app-store entry.
		 *
		 * @return {string} Settings URL.
		 */
		appStoreUrl() {
			return generateUrl('/settings/apps/integration/openregister')
		},
	},

	async created() {
		// Pinia stores still come up so the legacy views (settings store,
		// schema designer, etc.) keep working. CnAppRoot doesn't depend on
		// them. main.js also awaits this before $mount — idempotent.
		try {
			await initializeStores()
		} catch (e) {
			// eslint-disable-next-line no-console
			console.error('openbuilt: initializeStores() failed', e)
		}
	},

	methods: {
		/**
		 * Translate function handed to CnAppRoot / CnAppNav / CnPageRenderer.
		 * Closes over Nextcloud's translate so the lib never needs the app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 */
		translateForApp(key) {
			return ncT('openbuilt', key)
		},
	},
}
</script>

<style scoped>
.open-register-missing {
	display: flex;
	align-items: center;
	justify-content: center;
}
</style>
