<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	EditTemplateMetadataDialog — standalone dialog (gate-modal-isolation)
	for editing an org-local template's METADATA ONLY (title, description,
	useCase, category, sourceUrl). The manifest + companionSchemas are never
	touched here — content updates go through SaveAsTemplateDialog's re-capture
	flow so the manifest and companions can never drift apart by hand-editing
	(REQ-SAT-005).
-->
<template>
	<NcDialog
		:open="open"
		:name="t('buildiq', 'Edit template details')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="ob-edit-template">
			<NcTextField
				:modelValue="form.title"
				:label="t('buildiq', 'Template title')"
				@update:modelValue="form.title = $event" />
			<NcTextField
				:modelValue="form.useCase"
				:label="t('buildiq', 'Use case (one line)')"
				@update:modelValue="form.useCase = $event" />
			<NcTextArea
				:modelValue="form.description"
				:label="t('buildiq', 'Description')"
				@update:modelValue="form.description = $event" />
			<NcSelect
				v-model="categoryOption"
				:inputLabel="t('buildiq', 'Category')"
				:options="categoryOptions"
				:clearable="false" />
			<NcTextField
				:modelValue="form.sourceUrl"
				:label="t('buildiq', 'Source URL (optional)')"
				@update:modelValue="form.sourceUrl = $event" />
			<p v-if="saveError" class="ob-edit-template__error" role="alert">
				{{ saveError }}
			</p>
		</div>
		<template #actions>
			<NcButton @click="onClose">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSave || saving" @click="save">
				{{ saving ? t('buildiq', 'Saving…') : t('buildiq', 'Save changes') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { requestErrorMessage } from '../utils/requestErrorMessage.js'
import { templateCategoryOptions, templateRecordUrl } from './templateRecords.js'

export default {
	name: 'EditTemplateMetadataDialog',
	components: { NcButton, NcDialog, NcSelect, NcTextField, NcTextArea },
	props: {
		open: { type: Boolean, default: false },
		template: { type: Object, default: null },
	},

	emits: ['update:open', 'saved'],
	data() {
		return {
			form: { title: '', useCase: '', description: '', sourceUrl: '' },
			categoryOption: null,
			saving: false,
			saveError: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/save-as-template/spec.md
		 */
		categoryOptions() {
			return templateCategoryOptions()
		},

		/**
		 * @spec openspec/specs/save-as-template/spec.md
		 */
		selectedCategory() {
			return this.categoryOption?.id ?? this.categoryOption ?? ''
		},

		/**
		 * @spec openspec/specs/save-as-template/spec.md
		 */
		canSave() {
			return this.form.title.trim().length > 0 && !!this.selectedCategory
		},
	},

	watch: {
		/**
		 * @param {boolean} value - The dialog's new `open` state. Only the transition
		 *   into "open" is acted on: it re-prefills the form from the template being
		 *   edited, discarding any edits abandoned on a previous open.
		 * @spec openspec/specs/save-as-template/spec.md
		 */
		open(value) {
			if (value) {
				this.resetForm()
			}
		},
	},

	methods: {
		/**
		 * Prefill from the template being edited.
		 *
		 * @return {void}
		 * @spec openspec/specs/save-as-template/spec.md
		 */
		resetForm() {
			const tpl = this.template || {}
			this.form = {
				title: tpl.title || '',
				useCase: tpl.useCase || '',
				description: tpl.description || '',
				sourceUrl: tpl.sourceUrl || '',
			}
			this.categoryOption =
				this.categoryOptions.find((o) => o.id === tpl.category)
				|| this.categoryOptions[0]
				|| null
			this.saving = false
			this.saveError = ''
		},

		/**
		 * @return {void}
		 * @spec openspec/specs/save-as-template/spec.md
		 */
		onClose() {
			if (this.saving) {
				return
			}
			this.$emit('update:open', false)
		},

		/**
		 * PUT a metadata-only patch onto the template record via OR REST.
		 * The manifest + companionSchemas are carried over unchanged from the
		 * existing record so they can never drift (REQ-SAT-005).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/save-as-template/spec.md
		 */
		async save() {
			if (!this.canSave || this.saving || !this.template) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				const existing = this.template
				const patch = {
					...existing,
					title: this.form.title.trim(),
					useCase: this.form.useCase,
					description: this.form.description,
					category: this.selectedCategory,
				}
				if (this.form.sourceUrl) {
					patch.sourceUrl = this.form.sourceUrl
				} else {
					delete patch.sourceUrl
				}
				await axios.put(templateRecordUrl(existing), patch)
				this.$emit('saved', { slug: existing.slug })
				this.$emit('update:open', false)
			} catch (e) {
				this.saveError = requestErrorMessage(
					e,
					t('buildiq', 'Saving the template failed.'),
				)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.ob-edit-template {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 360px;
}

.ob-edit-template__error {
	color: var(--color-error);
	margin: 0;
}
</style>
