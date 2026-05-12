<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Confirm-before-destructive dialog for removing a property from a
  - schema (REQ-OBSD-008). Isolated in its own SFC per ADR-004 hard
  - rule + hydra-gate-13 (modal isolation).
  -->
<template>
	<NcDialog
		:name="t('openbuilt', 'Delete property')"
		:open="open"
		size="small"
		@update:open="onOpenUpdate">
		<p class="openbuilt-delete-field-dialog__warning">
			{{ t('openbuilt', 'You are about to remove the property {name} from this schema. Existing objects of this schema may have data in this property that will become unreachable after Save.', { name: fieldName }) }}
		</p>
		<template #actions>
			<NcButton @click="onCancel">
				{{ t('openbuilt', 'Cancel') }}
			</NcButton>
			<NcButton type="error" @click="onConfirm">
				{{ t('openbuilt', 'Delete property') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'DeleteFieldDialog',
	components: { NcButton, NcDialog },
	props: {
		open: { type: Boolean, default: false },
		fieldName: { type: String, default: '' },
	},
	emits: ['confirm', 'cancel', 'update:open'],
	methods: {
		onConfirm() {
			this.$emit('confirm')
		},
		onCancel() {
			this.$emit('cancel')
		},
		onOpenUpdate(value) {
			this.$emit('update:open', value)
			if (!value) {
				this.$emit('cancel')
			}
		},
	},
}
</script>

<style scoped>
.openbuilt-delete-field-dialog__warning {
	margin: 0;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
