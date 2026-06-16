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
	<form class="openbuild-schema-header-form" @submit.prevent>
		<div class="openbuild-schema-header-form__row">
			<NcTextField
				:value="value.slug"
				:label="t('openbuild', 'Schema slug')"
				:placeholder="t('openbuild', 'kebab-case, e.g. customer')"
				:disabled="lockedSlug"
				:error="!!slugError || (touched.slug && !slugValid)"
				:helper-text="slugError || (touched.slug && !slugValid ? t('openbuild', 'Slug must be kebab-case (lowercase letters, digits, hyphens) and start with a letter.') : '')"
				@update:value="onChange('slug', $event)"
				@blur="touched.slug = true" />
		</div>
		<div class="openbuild-schema-header-form__row">
			<NcTextField
				:value="value.title"
				:label="t('openbuild', 'Title')"
				:error="touched.title && !titleValid"
				:helper-text="touched.title && !titleValid ? t('openbuild', 'Title is required.') : ''"
				@update:value="onChange('title', $event)"
				@blur="touched.title = true" />
		</div>
		<div class="openbuild-schema-header-form__row">
			<NcTextField
				:value="value.description || ''"
				:label="t('openbuild', 'Description')"
				:placeholder="t('openbuild', 'Optional')"
				@update:value="onChange('description', $event)" />
		</div>
		<div class="openbuild-schema-header-form__row">
			<NcTextField
				:value="value.version"
				:label="t('openbuild', 'Version (semver)')"
				:placeholder="'0.1.0'"
				:error="touched.version && !versionValid"
				:helper-text="touched.version && !versionValid ? t('openbuild', 'Version must follow semver MAJOR.MINOR.PATCH.') : ''"
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
		/**
		 * Validate the slug against the lowercase-kebab pattern.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {boolean} True when valid.
		 */
		slugValid() {
			return SLUG_PATTERN.test(this.value.slug || '')
		},
		/**
		 * Validate that a non-empty title is present.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {boolean} True when valid.
		 */
		titleValid() {
			return !!(this.value.title && this.value.title.trim())
		},
		/**
		 * Validate the version string against semver MAJOR.MINOR.PATCH.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {boolean} True when valid.
		 */
		versionValid() {
			return SEMVER_PATTERN.test(this.value.version || '')
		},
		/**
		 * Aggregate validity of the whole header form.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @return {boolean} True when slug, title, and version are all valid.
		 */
		allValid() {
			return this.slugValid && this.titleValid && this.versionValid
		},
	},
	methods: {
		/**
		 * Emit an updated header object when a field changes.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-1
		 * @param {string} field Field name.
		 * @param {*} val New value.
		 * @return {void}
		 */
		onChange(field, val) {
			this.$emit('input', { ...this.value, [field]: val })
		},
	},
}
</script>

<style scoped>
.openbuild-schema-header-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuild-schema-header-form__row {
	display: flex;
	flex-direction: column;
}
</style>
