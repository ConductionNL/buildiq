<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAdminSettingsShell
		appId="buildiq"
		appName="Buildiq"
		@reimported="onReimported">
		<Settings v-if="storesReady" :key="settingsKey" />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminSettingsShell,
		Settings,
	},

	data() {
		return {
			storesReady: false,
			// Bumped after a re-import so the Settings section is re-created and
			// re-reads the refreshed store state in its `created` hook.
			settingsKey: 0,
		}
	},

	/**
	 * Observed behaviour of `created` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-4
	 */
	async created() {
		await initializeStores()
		this.storesReady = true
	},

	methods: {
		/**
		 * Re-load the app stores after the shell's Re-import action so the
		 * Configuration section reflects the freshly imported settings.
		 */
		async onReimported() {
			await initializeStores()
			this.settingsKey += 1
		},
	},
}
</script>
