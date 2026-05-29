<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - VirtualAppsActions — the actions bar on the VirtualApps index page
  - (`config.actionsComponent: "VirtualAppsActions"`).
  -
  - Renders the "Add Application" button which opens the four-step
  - CreateApplicationWizard. On wizard completion, navigates the admin
  - to /applications/{applicationUuid} so they land on the detail page of
  - the newly-created app.
  -
  - spec: openbuild-app-creation-wizard REQ-OBWIZ-001
  -->
<template>
	<div class="ob-va-actions">
		<NcButton
			type="primary"
			@click="showWizard = true">
			{{ t('openbuild', 'Add application') }}
		</NcButton>

		<CreateApplicationWizard
			:show.sync="showWizard"
			@created="onWizardCreated" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CreateApplicationWizard from '../dialogs/CreateApplicationWizard.vue'

export default {
	name: 'VirtualAppsActions',

	components: {
		NcButton,
		CreateApplicationWizard,
	},

	data() {
		return {
			showWizard: false,
		}
	},

	methods: {
		/**
		 * Observed behaviour of `onWizardCreated` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-application-detail-ui/tasks.md#task-4
		 */
		onWizardCreated(applicationUuid) {
			this.showWizard = false

			if (this.$router && applicationUuid) {
				this.$router.push({
					name: 'VirtualAppDetail',
					params: { objectId: applicationUuid },
				})
			}
		},
	},
}
</script>

<style scoped>
.ob-va-actions {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
