<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Confirm-before-destructive dialog for deleting a schema
  - (REQ-OBSD-008). The user MUST type the schema slug exactly before
  - the Delete button activates. Isolated in its own SFC per ADR-004
  - hard rule + hydra-gate-13 (modal isolation).
  -->
<template>
	<NcDialog
		:name="t('openbuilt', 'Delete schema')"
		:open="open"
		size="small"
		@update:open="onOpenUpdate">
		<p class="openbuilt-delete-schema-dialog__warning">
			{{ t('openbuilt', 'You are about to delete the schema {slug}. All objects of this schema may be affected. Type the schema slug below to confirm.', { slug: schemaSlug }) }}
		</p>
		<NcTextField
			:value="typed"
			:label="t('openbuilt', 'Type the slug to confirm')"
			:placeholder="schemaSlug"
			@update:value="typed = $event" />
		<template #actions>
			<NcButton @click="onCancel">
				{{ t('openbuilt', 'Cancel') }}
			</NcButton>
			<NcButton
				type="error"
				:disabled="!canDelete"
				@click="onConfirm">
				{{ t('openbuilt', 'Delete schema') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'

export default {
	name: 'DeleteSchemaDialog',
	components: { NcButton, NcDialog, NcTextField },
	props: {
		open: { type: Boolean, default: false },
		schemaSlug: { type: String, default: '' },
	},
	emits: ['confirm', 'cancel', 'update:open'],
	data() {
		return {
			typed: '',
		}
	},
	computed: {
		canDelete() {
			return this.typed === this.schemaSlug && this.schemaSlug !== ''
		},
	},
	watch: {
		open(value) {
			if (!value) {
				this.typed = ''
			}
		},
	},
	methods: {
		onConfirm() {
			if (!this.canDelete) {
				return
			}
			this.$emit('confirm')
			this.typed = ''
		},
		onCancel() {
			this.typed = ''
			this.$emit('cancel')
		},
		onOpenUpdate(value) {
			this.$emit('update:open', value)
			if (!value) {
				this.typed = ''
				this.$emit('cancel')
			}
		},
	},
}
</script>

<style scoped>
.openbuilt-delete-schema-dialog__warning {
	margin: 0 0 12px;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
