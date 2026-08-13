<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - VirtualAppsActions — the actions bar on the Apps index page
  - (`config.actionsComponent: "VirtualAppsActions"`).
  -
  - Renders an all/virtual/hybrid filter (persisted in the `?filter=` URL query
  - param so a filtered view is shareable) plus the "Add app" button which opens
  - the CreateApplicationWizard. On wizard completion, navigates the admin to
  - /applications/{applicationUuid} so they land on the detail page of the
  - newly-created app.
  -
  - spec: openbuild-app-creation-wizard REQ-OBWIZ-001
  - spec: openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
  -->
<template>
	<div class="ob-va-actions">
		<div
			class="ob-va-actions__filter"
			role="group"
			:aria-label="t('openbuild', 'Filter apps by type')">
			<NcButton
				v-for="opt in filterOptions"
				:key="opt.value"
				:type="activeFilter === opt.value ? 'secondary' : 'tertiary'"
				:pressed="activeFilter === opt.value"
				@click="setFilter(opt.value)">
				{{ opt.label }}
			</NcButton>
		</div>

		<NcButton type="primary" @click="showWizard = true">
			{{ t('openbuild', 'Add app') }}
		</NcButton>

		<CreateApplicationWizard
			v-model:show="showWizard"
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

	computed: {
		/**
		 * The available all/virtual/hybrid filter options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		filterOptions() {
			return [
				{ value: 'all', label: t('openbuild', 'All') },
				{ value: 'virtual', label: t('openbuild', 'Virtual') },
				{ value: 'hybrid', label: t('openbuild', 'Hybrid') },
			]
		},
		/**
		 * The active filter, read from the `?filter=` URL query param. Defaults
		 * to 'all' when absent or unrecognised.
		 *
		 * @return {string}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		activeFilter() {
			const filter =
				this.$route && this.$route.query ? this.$route.query.filter : null
			return ['virtual', 'hybrid'].includes(filter) ? filter : 'all'
		},
	},

	methods: {
		/**
		 * Persist the chosen filter in the `?filter=` URL query param so the
		 * filtered view is shareable/bookmarkable. 'all' clears the param.
		 *
		 * @param {string} value The filter value ('all' | 'virtual' | 'hybrid').
		 *
		 * @return {void}
		 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
		 */
		setFilter(value) {
			if (!this.$router) {
				return
			}
			const query = { ...(this.$route ? this.$route.query : {}) }
			if (value === 'all') {
				delete query.filter
			} else {
				query.filter = value
			}
			// Avoid a NavigationDuplicated error when the filter is unchanged.
			if ((query.filter || 'all') !== (this.activeFilter || 'all')) {
				this.$router.replace({ query }).catch(() => {})
			}
		},
		/**
		 * Observed behaviour of `onWizardCreated` (retrofit annotation).
		 *
		 * @param {string|undefined} applicationUuid - OpenRegister UUID of the
		 *   Application the creation wizard just created, used as the `objectId` route
		 *   param to jump straight to its detail page. The wizard deliberately emits
		 *   `created` with no uuid on its AI-copilot path (which has already
		 *   navigated), so an absent value must only close the wizard, not navigate.
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

.ob-va-actions__filter {
	display: flex;
	align-items: center;
	gap: 2px;
}
</style>
