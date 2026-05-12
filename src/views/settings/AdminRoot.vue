<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="openbuilt-admin">
		<CnVersionInfoCard
			:app-name="'OpenBuilt'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('openbuilt', 'Version Information')"
			:description="t('openbuilt', 'Information about the current OpenBuilt installation')">
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('openbuilt', 'Support') }}</h4>
					<p>{{ t('openbuilt', 'For support, contact us at') }} <a href="mailto:support@conduction.nl">support@conduction.nl</a></p>
				</div>
			</template>
		</CnVersionInfoCard>

		<Settings v-if="storesReady" />
	</div>
</template>

<script>
import { CnVersionInfoCard } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import Settings from './Settings.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'AdminRoot',
	components: {
		CnVersionInfoCard,
		Settings,
	},
	data() {
		return {
			storesReady: false,
			// ADR-004 + hydra-gate-initial-state: server data flows via
			// IInitialState + loadState, never via DOM data-* attributes.
			appVersion: loadState('openbuilt', 'version', 'Unknown'),
		}
	},
	async created() {
		await initializeStores()
		this.storesReady = true
	},
}
</script>

<style scoped>
.openbuilt-admin {
	max-width: 900px;
}
</style>
