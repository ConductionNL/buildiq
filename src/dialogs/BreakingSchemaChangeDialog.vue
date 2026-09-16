<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - BreakingSchemaChangeDialog: asks the author to confirm a schema change
  - that can make existing records invalid. OpenRegister refuses such a save
  - until the author confirms; the designer then saves again with the
  - confirmation. Kept in its own file per gate-modal-isolation.
  -->
<template>
	<NcDialog
		v-if="open"
		:name="t('buildiq', 'Save a change that affects existing records?')"
		:noClose="busy"
		@closing="$emit('cancel')">
		<div class="breaking-change" data-test="breaking-change-dialog">
			<p>
				{{ t('buildiq', 'This save changes the shape of records that already exist.') }}
			</p>
			<ul class="breaking-change__list">
				<li v-for="(line, index) in changes" :key="index">
					{{ line }}
				</li>
			</ul>
			<p class="breaking-change__hint">
				{{ t('buildiq', 'Save anyway to apply it. The schema gets a new major version.') }}
			</p>
		</div>
		<template #actions>
			<NcButton :disabled="busy" @click="$emit('cancel')">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-test="breaking-change-confirm"
				@click="$emit('confirm')">
				<template v-if="busy" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('buildiq', 'Save anyway') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'BreakingSchemaChangeDialog',
	components: { NcDialog, NcButton, NcLoadingIcon },
	props: {
		/** Whether the dialog is shown. */
		open: { type: Boolean, default: false },
		/** One plain sentence per breaking change. */
		changes: { type: Array, default: () => [] },
		/** Whether the confirmed save is in flight. */
		busy: { type: Boolean, default: false },
	},

	emits: ['confirm', 'cancel'],
}
</script>

<style scoped>
.breaking-change__list {
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0;
	padding-inline-start: calc(var(--default-grid-baseline, 4px) * 5);
	list-style: disc;
}

.breaking-change__hint {
	color: var(--color-text-maxcontrast);
}
</style>
