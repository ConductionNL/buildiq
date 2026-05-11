<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Add Schema dialog wraps SchemaHeaderForm in a modal so the
  - SchemaListPanel can surface the Add flow without leaving the list
  - route. Isolated in its own SFC per ADR-004 + hydra-gate-13.
  -->
<template>
	<NcDialog
		:name="t('openbuilt', 'Add schema')"
		:open="open"
		size="normal"
		@update:open="onOpenUpdate">
		<SchemaHeaderForm
			ref="form"
			:value="local"
			:slug-error="slugError"
			@input="onInput" />
		<template #actions>
			<NcButton @click="onCancel">
				{{ t('openbuilt', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!isValid || submitting"
				@click="onConfirm">
				{{ submitting ? t('openbuilt', 'Saving…') : t('openbuilt', 'Add schema') }}
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
		isValid() {
			return SLUG_PATTERN.test(this.local.slug)
				&& this.local.title.trim().length > 0
				&& SEMVER_PATTERN.test(this.local.version)
		},
	},
	watch: {
		open(value) {
			if (value) {
				this.local = { slug: '', title: '', description: '', version: '0.1.0' }
			}
		},
	},
	methods: {
		onInput(value) {
			this.local = { ...this.local, ...value }
		},
		onConfirm() {
			if (!this.isValid) {
				return
			}
			this.$emit('confirm', { ...this.local })
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
