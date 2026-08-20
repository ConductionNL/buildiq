<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - DeleteAppDialog — owner confirmation for deleting an app (Application +
  - versions + routes). By default the underlying registers and their data are
  - PRESERVED; the owner must tick "also delete all data" to wipe them. Kept in
  - its own file per ADR-004 gate-modal-isolation.
  -->
<template>
	<NcDialog
		v-if="open"
		:name="t('openbuild', 'Delete app')"
		:noClose="busy"
		@closing="$emit('update:open', false)">
		<div class="delete-app">
			<p>
				{{
					t(
						'openbuild',
						'Delete "{name}" and all of its versions? This cannot be undone.',
						{ name: appName },
					)
				}}
			</p>
			<NcCheckboxRadioSwitch v-model="deleteData" :disabled="busy">
				{{
					t(
						'openbuild',
						"Also permanently delete all data (the app's registers and everything stored in them)",
					)
				}}
			</NcCheckboxRadioSwitch>
			<p class="delete-app__hint">
				{{
					deleteData
						? t(
								'openbuild',
								'All data will be permanently removed. The app slug becomes available again.',
							)
						: t(
								'openbuild',
								'The app is removed but its data is kept in OpenRegister.',
							)
				}}
			</p>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('update:open', false)">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				@click="$emit('confirm', deleteData)">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ busy ? t('openbuild', 'Deleting…') : t('openbuild', 'Delete') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'DeleteAppDialog',
	components: { NcDialog, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon },
	props: {
		/** Whether the dialog is shown (bind with `.sync`). */
		open: { type: Boolean, default: false },
		/** Display name of the app being deleted. */
		appName: { type: String, default: '' },
		/** Whether the delete is in flight. */
		busy: { type: Boolean, default: false },
	},

	emits: ['update:open', 'confirm'],
	data() {
		return {
			// Opt-in destructive-data toggle. Reset every time the dialog opens so
			// a previous "delete data" choice never carries over silently.
			deleteData: false,
		}
	},

	watch: {
		open(value) {
			if (value) {
				this.deleteData = false
			}
		},
	},
}
</script>

<style scoped>
.delete-app {
	padding: 16px 24px 8px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.delete-app__hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0;
}
</style>
