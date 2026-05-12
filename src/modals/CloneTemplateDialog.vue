<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcModal v-if="open" size="normal" @close="onClose">
		<div class="clone-dialog">
			<h2>{{ t('openbuilt', 'Use this template') }}</h2>
			<p v-if="template" class="clone-dialog__summary">
				{{ t('openbuilt', 'Create a new application from') }}
				<strong>{{ resolvedTitle }}</strong>.
				{{ t('openbuilt', 'You can edit everything after cloning.') }}
			</p>
			<NcTextField
				:value="localName"
				:label="t('openbuilt', 'Application name')"
				:placeholder="t('openbuilt', 'My permits')"
				@update:value="localName = $event" />
			<NcTextField
				:value="localSlug"
				:label="t('openbuilt', 'Slug (kebab-case, max 32 chars)')"
				:placeholder="t('openbuilt', 'my-permits')"
				@update:value="localSlug = $event" />
			<p v-if="error" class="clone-dialog__error" role="alert">
				{{ error }}
			</p>
			<div class="clone-dialog__actions">
				<NcButton @click="onClose">
					{{ t('openbuilt', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!canSubmit || submitting" @click="submit">
					{{ submitting ? t('openbuilt', 'Cloning…') : t('openbuilt', 'Clone template') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcModal, NcTextField } from '@nextcloud/vue'

export default {
	name: 'CloneTemplateDialog',
	components: { NcButton, NcModal, NcTextField },
	props: {
		open: { type: Boolean, default: false },
		template: { type: Object, default: null },
	},
	emits: ['close', 'submit'],
	data() {
		return {
			localName: '',
			localSlug: '',
			error: '',
			submitting: false,
		}
	},
	computed: {
		resolvedTitle() {
			if (!this.template) return ''
			return t('openbuilt', this.template.title || this.template.slug)
		},
		canSubmit() {
			return this.localName.trim().length > 0
				&& /^[a-z0-9]+(-[a-z0-9]+)*$/.test(this.localSlug)
				&& this.localSlug.length <= 32
		},
	},
	watch: {
		open(value) {
			if (value) {
				this.localName = ''
				this.localSlug = ''
				this.error = ''
				this.submitting = false
			}
		},
	},
	methods: {
		onClose() {
			if (this.submitting) return
			this.$emit('close')
		},
		async submit() {
			if (!this.canSubmit) {
				this.error = t('openbuilt', 'Provide a name and a kebab-case slug (max 32 chars).')
				return
			}
			this.submitting = true
			this.error = ''
			try {
				await this.$emit('submit', { name: this.localName.trim(), slug: this.localSlug.trim() })
			} catch (e) {
				this.error = e?.message || t('openbuilt', 'Clone failed.')
				this.submitting = false
			}
		},
		setError(message) {
			this.error = message
			this.submitting = false
		},
	},
}
</script>

<style scoped>
.clone-dialog {
	padding: 24px;
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-width: 320px;
}

.clone-dialog__summary {
	color: var(--color-text-maxcontrast);
	margin: 0 0 8px 0;
}

.clone-dialog__error {
	color: var(--color-error);
	margin: 4px 0 0 0;
}

.clone-dialog__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 12px;
}
</style>
