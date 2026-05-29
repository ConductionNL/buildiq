<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="openbuild-admin">
		<CnVersionInfoCard
			:app-name="'OpenBuild'"
			:app-version="appVersion"
			:is-up-to-date="true"
			:show-update-button="true"
			:title="t('openbuild', 'Version Information')"
			:description="t('openbuild', 'Information about the current OpenBuild installation')">
			<template #footer>
				<div class="cn-support-info">
					<h4>{{ t('openbuild', 'Support') }}</h4>
					<p>{{ t('openbuild', 'For support, contact us at') }} <a href="mailto:support@conduction.nl">support@conduction.nl</a></p>
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
			appVersion: loadState('openbuild', 'version', 'Unknown'),
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
}
</script>

<style scoped>
.openbuild-admin {
	max-width: 900px;
}
</style>
