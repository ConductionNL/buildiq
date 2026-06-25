<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - DeleteAppDialog — owner confirmation for the destructive full delete of an
  - app (Application + versions + per-version registers). Kept in its own file
  - per ADR-004 gate-modal-isolation.
  -->
<template>
	<NcDialog
		v-if="open"
		:name="t('openbuild', 'Delete app')"
		:no-close="busy"
		@closing="$emit('update:open', false)">
		<div class="delete-app">
			<p>
				{{ t('openbuild', 'Permanently delete "{name}" and all of its versions and data? This cannot be undone.', { name: appName }) }}
			</p>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('update:open', false)">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton type="error" :disabled="busy" @click="$emit('confirm')">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ busy ? t('openbuild', 'Deleting…') : t('openbuild', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/dist/Components/NcDialog.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

export default {
	name: 'DeleteAppDialog',
	components: { NcDialog, NcButton, NcLoadingIcon },
	props: {
		/** Whether the dialog is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** Display name of the app being deleted. */
		appName: { type: String, default: '' },
		/** Whether the delete is in flight. */
		busy: { type: Boolean, default: false },
	},
	emits: ['update:open', 'confirm'],
}
</script>

<style scoped>
.delete-app {
	padding: 16px 24px 8px;
}
</style>
