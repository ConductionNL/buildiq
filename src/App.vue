<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
 OpenBuild app shell. Mounts CnAppRoot with the bundled manifest and the v2
 kind-tagged registry (ADR-036); CnAppRoot handles the OpenRegister dependency
 check, renders CnAppNav from manifest.menu, and routes <router-view> pages
 through CnPageRenderer. The #dependency-missing slot keeps OpenBuild's
 original "OpenRegister is required" empty state.

 @adr ADR-024 (app manifest) — OpenBuild is now Tier-1+ (its own shell is
 manifest-driven, like the virtual apps it builds).
 @adr ADR-036 (v2 registry) — all consumer components are registered via the
 `registry` prop; the deprecated `customComponents` prop is no longer used.
-->
<template>
	<CnAppRoot
		app-id="openbuild"
		:ai-companion="true"
		:manifest="manifest"
		:registry="registry"
		:custom-components="flatRegistry"
		:page-types="pageTypes"
		:translate="translateForApp"
		:permissions="permissions">
		<template #dependency-missing>
			<NcAppContent class="open-register-missing">
				<NcEmptyContent
					:name="t('openbuild', 'OpenRegister is required')"
					:description="t('openbuild', 'This app needs OpenRegister to store and manage data. Please install OpenRegister from the app store to get started.')">
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
							{{ t('openbuild', 'Install OpenRegister') }}
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
import AppDeleteDialogSlot from './components/AppDeleteDialogSlot.vue'

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
		 * V2 kind-tagged registry (ADR-036) — map of registry key →
		 * `{ kind: "page", component }`. CnPageRenderer resolves every
		 * manifest-referenced component name (type:"custom" pages,
		 * cardComponent, headerComponent, actionsComponent,
		 * sidebarTabs[].component) against the `kind: "page"` entries here.
		 * Replaces the deprecated `customComponents` prop.
		 *
		 * @type {object}
		 */
		registry: {
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
		 * Flattened `{ name: component }` map derived from the v2 kind-tagged
		 * `registry` (ADR-036), passed to CnAppRoot as the `customComponents`
		 * prop.
		 *
		 * Why this is needed: CnPageRenderer resolves `slots.*`,
		 * `actionsComponent`, `headerComponent`, `sidebarComponent` and
		 * `type:"custom"` page components against `effectiveCustomComponents`
		 * (= the `customComponents` prop, falling back to the injected
		 * `cnCustomComponents`). In @conduction/nextcloud-vue 1.0.0-beta.107
		 * that resolver does NOT consult the v2 `cnRegistry` inject, so when an
		 * app passes only `:registry` (and no `customComponents`), every
		 * slot-override / custom-page name fails to resolve — the page renders
		 * empty and the console logs `… not found in registry`. This affected
		 * the whole app (VirtualAppsActions "Add application" button, the
		 * schema-designer slot, etc.), not just the Schemas page.
		 *
		 * Unwrapping the registry's `{ kind, component }` entries to
		 * `{ name: component }` and feeding it through `customComponents`
		 * restores resolution for every slot/custom dispatch while keeping the
		 * single v2 `registry` as the source of truth.
		 *
		 * @return {object} Map of registry key → Vue component.
		 */
		flatRegistry() {
			const out = {}
			for (const [name, entry] of Object.entries(this.registry || {})) {
				const component = entry && typeof entry === 'object' && 'component' in entry
					? entry.component
					: entry
				if (component) {
					out[name] = component
				}
			}
			// Slot-override component for CnIndexPage's `#delete-dialog` slot on
			// the applications index (manifest page.slots["delete-dialog"]). Kept
			// here rather than in the kind-tagged `registry` prop: CnPageRenderer
			// resolves slot components by name against customComponents too, and
			// CnAppRoot's registry validator throws on any kind it doesn't know.
			// See AppDeleteDialogSlot.
			out.AppDeleteDialogSlot = AppDeleteDialogSlot
			return out
		},

		/**
		 * The current user's Nextcloud permission flags, passed to CnAppNav.
		 *
		 * @return {Array} Permission identifiers (empty when unavailable).
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		permissions() {
			return window.OC?.currentUser?.permissions ?? []
		},

		/**
		 * Whether the current user is a Nextcloud admin — gates the
		 * "Install OpenRegister" button in the dependency-missing slot.
		 *
		 * @return {boolean} True for admins.
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
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
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		appIcon() {
			return imagePath('openbuild', 'app-dark.svg')
		},

		/**
		 * Deep link to OpenRegister's app-store entry.
		 *
		 * @return {string} Settings URL.
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		appStoreUrl() {
			return generateUrl('/settings/apps/integration/openregister')
		},
	},

	/**
	 * Observed behaviour of `created` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
	 */
	async created() {
		// Pinia stores still come up so the legacy views (settings store,
		// schema designer, etc.) keep working. CnAppRoot doesn't depend on
		// them. main.js also awaits this before $mount — idempotent.
		try {
			await initializeStores()
		} catch (e) {
			// eslint-disable-next-line no-console
			console.error('openbuild: initializeStores() failed', e)
		}
	},

	methods: {
		/**
		 * Translate function handed to CnAppRoot / CnAppNav / CnPageRenderer.
		 * Closes over Nextcloud's translate so the lib never needs the app id.
		 *
		 * @param {string} key Translation key.
		 * @return {string} Translated string (or the key on miss).
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-5
		 */
		translateForApp(key) {
			return ncT('openbuild', key)
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
