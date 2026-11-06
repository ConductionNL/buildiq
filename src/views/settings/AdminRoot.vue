<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<CnAdminSettingsShell
		appId="buildiq"
		appName="Buildiq"
		@reimported="onReimported">
		<Settings v-if="storesReady" :key="settingsKey" />
		<!-- Seeding the starter templates is an admin action, never a setup
		     wizard step (wizard-dataset-card-load). Same `seed-templates`
		     action the wizard ran. -->
		<CnAdminActionCard
			appId="buildiq"
			action="seed-templates"
			:title="t('buildiq', 'Install starter templates')"
			:description="
				t(
					'buildiq',
					'Fills the Store with the starter templates. Run it after you enable OpenRegister, or to repair a partial install. Templates that are already there stay as they are.',
				)
			"
			:buttonLabel="t('buildiq', 'Install templates')" />
	</CnAdminSettingsShell>
</template>

<script>
import { CnAdminActionCard, CnAdminSettingsShell } from '@conduction/nextcloud-vue'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnAdminActionCard,
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
