<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Add Schema dialog wraps SchemaHeaderForm in a modal so the
  - SchemaListPanel can surface the Add flow without leaving the list
  - route. Isolated in its own SFC per ADR-004 + hydra-gate-13.
  -->
<template>
	<NcDialog
		:name="t('buildiq', 'Add schema')"
		:open="open"
		size="normal"
		@update:open="onOpenUpdate">
		<SchemaHeaderForm
			ref="form"
			:value="local"
			:slugError="slugError"
			@input="onInput" />
		<template #actions>
			<NcButton @click="onCancel">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!isValid || submitting"
				@click="onConfirm">
				{{
					submitting ? t('buildiq', 'Saving…') : t('buildiq', 'Add schema')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import SchemaHeaderForm from '../components/schema-editor/SchemaHeaderForm.vue'

const SLUG_PATTERN = /^[a-z][a-z0-9-]*$/
const SEMVER_PATTERN = /^\d+\.\d+\.\d+$/

export default {
	name: 'AddSchemaDialog',
	components: { NcButton, NcDialog, SchemaHeaderForm },
	props: {
		open: { type: Boolean, default: false },
		submitting: { type: Boolean, default: false },
		slugError: { type: String, default: '' },
	},

	emits: ['confirm', 'cancel', 'update:open'],
	data() {
		return {
			local: {
				slug: '',
				title: '',
				description: '',
				version: '0.1.0',
			},
		}
	},

	computed: {
		/**
		 * Validate the new-schema slug, title, and semver version.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {boolean} True when the form is valid.
		 */
		isValid() {
			return (
				SLUG_PATTERN.test(this.local.slug)
				&& this.local.title.trim().length > 0
				&& SEMVER_PATTERN.test(this.local.version)
			)
		},
	},

	watch: {
		/**
		 * Reset the local form when the dialog opens.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {boolean} value Open state.
		 * @return {void}
		 */
		open(value) {
			if (value) {
				this.local = {
					slug: '',
					title: '',
					description: '',
					version: '0.1.0',
				}
			}
		},
	},

	methods: {
		/**
		 * Merge partial form input into the local draft.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {object} value Partial form values.
		 * @return {void}
		 */
		onInput(value) {
			this.local = { ...this.local, ...value }
		},

		/**
		 * Confirm only when valid, emitting the new schema payload.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {void}
		 */
		onConfirm() {
			if (!this.isValid) {
				return
			}
			this.$emit('confirm', { ...this.local })
		},

		/**
		 * Emit a cancel event.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {void}
		 */
		onCancel() {
			this.$emit('cancel')
		},

		/**
		 * Sync the modal open state and emit cancel when closed.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {boolean} value Open state.
		 * @return {void}
		 */
		onOpenUpdate(value) {
			this.$emit('update:open', value)
			if (!value) {
				this.$emit('cancel')
			}
		},
	},
}
</script>
