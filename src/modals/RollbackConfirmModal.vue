<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - Rollback confirmation modal — lives in src/modals/ (NOT inline in
  - VersionHistory.vue) per Hydra ADR-004 hard rule + hydra-gate-13
  - modal-isolation. The parent emits open/close; this component owns
  - the confirm/cancel UX only.
  -->
<template>
	<NcDialog
		:open="open"
		:name="title"
		size="normal"
		@update:open="onUpdateOpen">
		<template #default>
			<p class="rollback-confirm__body">
				{{ t('openbuilt', 'Rolling back copies this snapshot\'s manifest onto the current draft. Existing history is preserved (append-only).') }}
			</p>
			<dl v-if="version" class="rollback-confirm__meta">
				<dt>{{ t('openbuilt', 'Version') }}</dt>
				<dd>{{ version.version }}</dd>
				<dt>{{ t('openbuilt', 'Published') }}</dt>
				<dd>{{ formattedPublishedAt }}</dd>
			</dl>
		</template>
		<template #actions>
			<NcButton type="tertiary" @click="cancel">
				{{ t('openbuilt', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" @click="confirm">
				{{ t('openbuilt', 'Roll back') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton } from '@nextcloud/vue'

export default {
	name: 'RollbackConfirmModal',
	components: {
		NcDialog,
		NcButton,
	},
	props: {
		open: {
			type: Boolean,
			required: true,
		},
		version: {
			type: Object,
			default: null,
		},
	},
	emits: ['confirm', 'cancel', 'update:open'],
	computed: {
		title() {
			const v = this.version?.version || ''
			return t('openbuilt', 'Roll back to version {version}?', { version: v })
		},
		formattedPublishedAt() {
			if (!this.version?.publishedAt) {
				return ''
			}
			try {
				return new Date(this.version.publishedAt).toLocaleString()
			} catch (e) {
				return this.version.publishedAt
			}
		},
	},
	methods: {
		confirm() {
			this.$emit('confirm', this.version)
			this.$emit('update:open', false)
		},
		cancel() {
			this.$emit('cancel')
			this.$emit('update:open', false)
		},
		onUpdateOpen(value) {
			if (!value) {
				this.$emit('cancel')
			}
			this.$emit('update:open', value)
		},
	},
}
</script>

<style scoped>
.rollback-confirm__body {
	font-size: 14px;
	margin-bottom: 12px;
}

.rollback-confirm__meta {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast, #888);
}

.rollback-confirm__meta dt {
	font-weight: bold;
}

.rollback-confirm__meta dd {
	margin: 0;
}
</style>
