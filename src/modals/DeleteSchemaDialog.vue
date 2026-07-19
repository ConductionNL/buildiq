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
		:name="t('openbuild', 'Delete schema')"
		:open="open"
		size="small"
		@update:open="onOpenUpdate">
		<p class="openbuild-delete-schema-dialog__warning">
			{{ t('openbuild', 'You are about to delete the schema {slug}. All objects of this schema may be affected. Type the schema slug below to confirm.', { slug: schemaSlug }) }}
		</p>
		<NcTextField
			:model-value="typed"
			:label="t('openbuild', 'Type the slug to confirm')"
			:placeholder="schemaSlug"
			@update:model-value="typed = $event" />
		<template #actions>
			<NcButton @click="onCancel">
				{{ t('openbuild', 'Cancel') }}
			</NcButton>
			<NcButton
				type="error"
				:disabled="!canDelete"
				@click="onConfirm">
				{{ t('openbuild', 'Delete schema') }}
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
		/**
		 * Enable delete only when the typed slug matches the target exactly.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {boolean} True when deletion is confirmed.
		 */
		canDelete() {
			return this.typed === this.schemaSlug && this.schemaSlug !== ''
		},
	},
	watch: {
		/**
		 * Clear the typed confirmation when the dialog closes.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {boolean} value Open state.
		 * @return {void}
		 */
		open(value) {
			if (!value) {
				this.typed = ''
			}
		},
	},
	methods: {
		/**
		 * Confirm deletion only when the confirmation gate is met.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {void}
		 */
		onConfirm() {
			if (!this.canDelete) {
				return
			}
			this.$emit('confirm')
			this.typed = ''
		},
		/**
		 * Cancel deletion and reset the confirmation input.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {void}
		 */
		onCancel() {
			this.typed = ''
			this.$emit('cancel')
		},
		/**
		 * Sync modal open state and emit cancel when closed.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {boolean} value Open state.
		 * @return {void}
		 */
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
.openbuild-delete-schema-dialog__warning {
	margin: 0 0 12px;
	line-height: 1.5;
	color: var(--color-text-maxcontrast);
}
</style>
