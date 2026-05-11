<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - SchemaHeaderForm — captures `slug` (kebab-case), `title`
  - (required), `description` (optional), `version` (semver) for both
  - the Add Schema flow and the detail-header display
  - (REQ-OBSD-002). Pure presentational: emits a single `input` event
  - with the merged form value; parent owns validation + persistence.
  -->
<template>
	<form class="openbuilt-schema-header-form" @submit.prevent>
		<div class="openbuilt-schema-header-form__row">
			<NcTextField
				:value="value.slug"
				:label="t('openbuilt', 'Schema slug')"
				:placeholder="t('openbuilt', 'kebab-case, e.g. customer')"
				:disabled="lockedSlug"
				:error="!!slugError || (touched.slug && !slugValid)"
				:helper-text="slugError || (touched.slug && !slugValid ? t('openbuilt', 'Slug must be kebab-case (lowercase letters, digits, hyphens) and start with a letter.') : '')"
				@update:value="onChange('slug', $event)"
				@blur="touched.slug = true" />
		</div>
		<div class="openbuilt-schema-header-form__row">
			<NcTextField
				:value="value.title"
				:label="t('openbuilt', 'Title')"
				:error="touched.title && !titleValid"
				:helper-text="touched.title && !titleValid ? t('openbuilt', 'Title is required.') : ''"
				@update:value="onChange('title', $event)"
				@blur="touched.title = true" />
		</div>
		<div class="openbuilt-schema-header-form__row">
			<NcTextField
				:value="value.description || ''"
				:label="t('openbuilt', 'Description')"
				:placeholder="t('openbuilt', 'Optional')"
				@update:value="onChange('description', $event)" />
		</div>
		<div class="openbuilt-schema-header-form__row">
			<NcTextField
				:value="value.version"
				:label="t('openbuilt', 'Version (semver)')"
				:placeholder="'0.1.0'"
				:error="touched.version && !versionValid"
				:helper-text="touched.version && !versionValid ? t('openbuilt', 'Version must follow semver MAJOR.MINOR.PATCH.') : ''"
				@update:value="onChange('version', $event)"
				@blur="touched.version = true" />
		</div>
	</form>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'

const SLUG_PATTERN = /^[a-z][a-z0-9-]*$/
const SEMVER_PATTERN = /^\d+\.\d+\.\d+$/

export default {
	name: 'SchemaHeaderForm',
	components: { NcTextField },
	props: {
		value: {
			type: Object,
			required: true,
		},
		slugError: { type: String, default: '' },
		lockedSlug: { type: Boolean, default: false },
	},
	emits: ['input'],
	data() {
		return {
			touched: {
				slug: false,
				title: false,
				version: false,
			},
		}
	},
	computed: {
		slugValid() {
			return SLUG_PATTERN.test(this.value.slug || '')
		},
		titleValid() {
			return !!(this.value.title && this.value.title.trim())
		},
		versionValid() {
			return SEMVER_PATTERN.test(this.value.version || '')
		},
		allValid() {
			return this.slugValid && this.titleValid && this.versionValid
		},
	},
	methods: {
		onChange(field, val) {
			this.$emit('input', { ...this.value, [field]: val })
		},
	},
}
</script>

<style scoped>
.openbuilt-schema-header-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-schema-header-form__row {
	display: flex;
	flex-direction: column;
}
</style>
