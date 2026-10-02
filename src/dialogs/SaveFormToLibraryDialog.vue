<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	SaveFormToLibraryDialog: save a form page or a registration form to the
	form library (reuse-gallery-categories-and-form-library, REQ-BQGL-002).

	Opened from FormPageEditor and from RegistrationFormEditor (through the
	list that owns it). It reads the schema the form writes to, captures the
	form with formCapture.js and refuses a form bound to a property that schema
	lacks, naming the property. Only configuration is saved, never records.
-->
<template>
	<NcDialog
		:open="open"
		:name="t('buildiq', 'Save to form library')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="bq-save-form">
			<p class="bq-save-form__intro">
				{{
					t(
						'buildiq',
						'Save this form so makers in your organisation can find it and use it in other apps.',
					)
				}}
			</p>

			<NcTextField
				:modelValue="name"
				:label="t('buildiq', 'Form name')"
				@update:modelValue="onNameInput" />
			<NcTextField
				:modelValue="slug"
				:label="t('buildiq', 'Slug')"
				@update:modelValue="onSlugInput" />
			<NcTextArea
				:modelValue="description"
				:label="t('buildiq', 'Description')"
				@update:modelValue="description = $event" />
			<NcSelect
				:modelValue="categoryOption"
				:options="categoryOptions"
				:inputLabel="t('buildiq', 'Category')"
				:clearable="false"
				@update:modelValue="category = $event ? $event.id : ''" />
			<NcTextField
				:modelValue="publisher"
				:label="t('buildiq', 'Publisher')"
				@update:modelValue="publisher = $event" />

			<p v-if="loadingSchema" class="bq-save-form__note">
				{{ t('buildiq', 'Reading the schema the form saves into.') }}
			</p>
			<p v-else-if="schemaError" class="bq-save-form__error" role="alert">
				{{ schemaError }}
			</p>
			<div v-else-if="missing.length" class="bq-save-form__error" role="alert">
				<p>
					{{
						n(
							'buildiq',
							'This form uses a property its schema does not have. Remove the field or add the property first:',
							'This form uses properties its schema does not have. Remove the fields or add the properties first:',
							missing.length,
						)
					}}
				</p>
				<ul>
					<li v-for="property in missing" :key="property">
						<code>{{ property }}</code>
					</li>
				</ul>
			</div>
			<p v-else-if="record" class="bq-save-form__note">
				{{
					n(
						'buildiq',
						'The form and the definition of {count} property are saved. No records are saved.',
						'The form and the definitions of {count} properties are saved. No records are saved.',
						Object.keys(record.schemaFragment).length,
						{ count: Object.keys(record.schemaFragment).length },
					)
				}}
			</p>

			<p v-if="saveError" class="bq-save-form__error" role="alert">
				{{ saveError }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canSave"
				data-testid="save-form-to-library"
				@click="save">
				{{
					saving
						? t('buildiq', 'Saving…')
						: t('buildiq', 'Save to form library')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { captureForm, FormBindingError } from '../services/formCapture.js'
import { createLibraryForm, fetchRegisterSchema } from '../services/formLibrary.js'
import { suggestSlug, TEMPLATE_CATEGORIES } from '../services/templateCapture.js'

export default {
	name: 'SaveFormToLibraryDialog',

	components: { NcButton, NcDialog, NcSelect, NcTextArea, NcTextField },

	props: {
		open: { type: Boolean, default: false },
		// 'form-page' or 'registration-form'.
		kind: { type: String, required: true },
		// The form page config, or the registrationForm object.
		form: { type: Object, default: null },
		// The register and schema the form writes to.
		register: { type: String, default: '' },
		schemaSlug: { type: String, default: '' },
		// The app the form is saved from, for de-namespacing the schema slug.
		appSlug: { type: String, default: '' },
		// The organisation name offered as publisher.
		defaultPublisher: { type: String, default: '' },
	},

	emits: ['update:open', 'saved'],

	data() {
		return {
			name: '',
			slug: '',
			slugEdited: false,
			description: '',
			category: '',
			publisher: '',
			schema: null,
			loadingSchema: false,
			schemaError: '',
			saving: false,
			saveError: '',
		}
	},

	computed: {
		/**
		 * The four template categories as select options.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		categoryOptions() {
			const labels = {
				'government-services': t('buildiq', 'Government services'),
				'internal-operations': t('buildiq', 'Internal operations'),
				'citizen-engagement': t('buildiq', 'Citizen engagement'),
				'field-work': t('buildiq', 'Field work'),
			}
			return TEMPLATE_CATEGORIES.map((id) => ({ id, label: labels[id] }))
		},

		/**
		 * The selected category option, or null.
		 *
		 * @return {?{id: string, label: string}}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		categoryOption() {
			return (
				this.categoryOptions.find((option) => option.id === this.category)
				|| null
			)
		},

		/**
		 * The captured record, or null when the form cannot be captured.
		 *
		 * @return {?object}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		capture() {
			if (!this.form || !this.schema) {
				return { record: null, missing: [] }
			}
			try {
				const user = getCurrentUser()
				return {
					record: captureForm({
						kind: this.kind,
						form: this.form,
						schema: this.schema,
						appSlug: this.appSlug,
						metadata: {
							slug: this.slug,
							name: this.name.trim(),
							description: this.description,
							category: this.category,
							publisher: this.publisher.trim(),
							createdBy: (user && user.uid) || '',
						},
					}),

					missing: [],
				}
			} catch (error) {
				if (error instanceof FormBindingError) {
					return { record: null, missing: error.missing }
				}
				return { record: null, missing: [] }
			}
		},

		/**
		 * The captured record.
		 *
		 * @return {?object}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		record() {
			return this.capture.record
		},

		/**
		 * The properties the form binds that its schema lacks.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		missing() {
			return this.capture.missing
		},

		/**
		 * Whether Save is allowed.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		canSave() {
			return (
				!this.saving
				&& this.name.trim() !== ''
				&& /^[a-z0-9][a-z0-9-]*[a-z0-9]$/.test(this.slug)
				&& this.slug.length <= 64
				&& this.category !== ''
				&& this.record !== null
			)
		},
	},

	watch: {
		/**
		 * Reset and read the schema each time the dialog opens.
		 *
		 * @param {boolean} value The new open value.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		open(value) {
			if (value) {
				this.reset()
			}
		},
	},

	/**
	 * Seed the fields when the dialog mounts open.
	 *
	 * @return {void}
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
	 */
	created() {
		if (this.open) {
			this.reset()
		}
	},

	methods: {
		/**
		 * Seed the fields from the form and read its schema.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		async reset() {
			const seed = (this.form && (this.form.name || this.form.title)) || ''
			this.name = seed
			this.slug = suggestSlug(seed)
			this.slugEdited = false
			this.description = ''
			this.category = ''
			this.publisher = this.defaultPublisher
			this.saveError = ''
			this.schemaError = ''
			this.schema = null
			if (!this.register || !this.schemaSlug) {
				this.schemaError = t(
					'buildiq',
					'Pick the schema this form saves into before you save it to the library.',
				)
				return
			}
			this.loadingSchema = true
			try {
				this.schema = await fetchRegisterSchema(
					this.register,
					this.schemaSlug,
				)
				if (!this.schema) {
					this.schemaError = t(
						'buildiq',
						'The schema this form saves into could not be read.',
					)
				}
			} catch {
				this.schemaError = t(
					'buildiq',
					'The schema this form saves into could not be read.',
				)
			} finally {
				this.loadingSchema = false
			}
		},

		/**
		 * Follow the name with the slug until the maker edits the slug.
		 *
		 * @param {string} value The new name.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		onNameInput(value) {
			this.name = value
			if (!this.slugEdited) {
				this.slug = suggestSlug(value)
			}
		},

		/**
		 * Keep a slug the maker typed.
		 *
		 * @param {string} value The new slug.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		onSlugInput(value) {
			this.slug = value
			this.slugEdited = true
		},

		/**
		 * Close unless a save is running.
		 *
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		onClose() {
			if (!this.saving) {
				this.$emit('update:open', false)
			}
		},

		/**
		 * Store the captured form in the library.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-form-can-be-saved-to-the-library-req-bqgl-002
		 */
		async save() {
			if (!this.canSave) {
				return
			}
			this.saving = true
			this.saveError = ''
			try {
				const stored = await createLibraryForm(this.record)
				this.$emit('saved', stored || this.record)
				this.$emit('update:open', false)
			} catch (error) {
				const data = error && error.response && error.response.data
				this.saveError =
					(data && (data.detail || data.message || data.error))
					|| t('buildiq', 'Saving the form to the library failed.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.bq-save-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.bq-save-form__intro,
.bq-save-form__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.bq-save-form__error {
	margin: 0;
	color: var(--color-text-error, var(--color-error));
}
</style>
