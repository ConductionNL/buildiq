<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	UseLibraryFormDialog: add a library form to an app
	(reuse-gallery-categories-and-form-library, REQ-BQGL-004).

	The maker picks the app and version, the target (a new form page, or a
	registration form for a schema and a type value) and the schema the form
	maps to. The properties that schema lacks are listed with "Add these
	properties". The whole form is shown before it is added, and nothing is
	written before the maker confirms.
-->
<template>
	<NcDialog
		:open="open"
		:name="t('buildiq', 'Use this form')"
		size="normal"
		@update:open="$emit('update:open', $event)"
		@closing="onClose">
		<div class="bq-use-form">
			<p class="bq-use-form__intro">
				{{ template ? template.name : '' }}
			</p>

			<section
				class="bq-use-form__preview"
				:aria-label="t('buildiq', 'What the form asks')">
				<h3>{{ t('buildiq', 'What the form asks') }}</h3>
				<ul>
					<li v-for="field in previewFields" :key="field">
						{{ field }}
					</li>
				</ul>
				<p v-if="confirmationText" class="bq-use-form__note">
					{{
						t('buildiq', 'After sending: {text}', {
							text: confirmationText,
						})
					}}
				</p>
			</section>

			<NcSelect
				:modelValue="appOption"
				:options="appOptions"
				:inputLabel="t('buildiq', 'App')"
				:loading="loadingApps"
				:clearable="false"
				@update:modelValue="onApp" />
			<NcSelect
				:modelValue="versionOption"
				:options="versionOptions"
				:inputLabel="t('buildiq', 'Version')"
				:disabled="!appSlug"
				:clearable="false"
				@update:modelValue="onVersion" />

			<fieldset class="bq-use-form__targets">
				<legend>{{ t('buildiq', 'Add it as') }}</legend>
				<NcCheckboxRadioSwitch
					v-model="target"
					type="radio"
					name="bq-use-form-target"
					value="form-page">
					{{ t('buildiq', 'A new form page') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					v-model="target"
					type="radio"
					name="bq-use-form-target"
					value="registration-form">
					{{ t('buildiq', 'A registration form for a case type') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcSelect
				:modelValue="schemaOption"
				:options="schemaOptions"
				:inputLabel="t('buildiq', 'Schema the form saves into')"
				:disabled="!register"
				:clearable="false"
				@update:modelValue="onSchema" />

			<template v-if="target === 'registration-form'">
				<NcTextField
					:modelValue="typeProperty"
					:label="t('buildiq', 'Property that holds the case type')"
					@update:modelValue="typeProperty = $event" />
				<NcTextField
					:modelValue="typeValue"
					:label="t('buildiq', 'Case type')"
					@update:modelValue="typeValue = $event" />
			</template>

			<div
				v-if="missingNames.length"
				class="bq-use-form__missing"
				data-testid="missing-properties">
				<p>
					{{
						n(
							'buildiq',
							'The schema does not have this property yet:',
							'The schema does not have these properties yet:',
							missingNames.length,
						)
					}}
				</p>
				<ul>
					<li v-for="name in missingNames" :key="name">
						<code>{{ name }}</code>
					</li>
				</ul>
				<NcCheckboxRadioSwitch
					v-model="addProperties"
					data-testid="add-properties">
					{{ t('buildiq', 'Add these properties') }}
				</NcCheckboxRadioSwitch>
			</div>

			<p v-if="error" class="bq-use-form__error" role="alert">
				{{ error }}
			</p>
		</div>

		<template #actions>
			<NcButton @click="onClose">
				{{ t('buildiq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!canConfirm"
				data-testid="confirm-use-form"
				@click="confirm">
				{{
					working
						? t('buildiq', 'Adding…')
						: t('buildiq', 'Add to the app')
				}}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import { boundProperties } from '../services/formCapture.js'
import {
	fetchRegisterSchemas,
	planLibraryFormUse,
	useLibraryForm,
} from '../services/formLibrary.js'
import { saveRegistrationForm } from '../services/registrationForms.js'

/**
 * The results array of a list answer.
 *
 * @param {object|Array<object>|null} data The response body.
 * @return {Array<object>}
 */
function resultsOf(data) {
	if (Array.isArray(data)) {
		return data
	}
	return Array.isArray(data && data.results) ? data.results : []
}

export default {
	name: 'UseLibraryFormDialog',

	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcSelect, NcTextField },

	props: {
		open: { type: Boolean, default: false },
		// The form-template record to use.
		template: { type: Object, default: null },
	},

	emits: ['update:open', 'used'],

	data() {
		return {
			apps: [],
			loadingApps: false,
			appSlug: '',
			versions: [],
			version: null,
			schemas: [],
			schemaSlug: '',
			target: 'form-page',
			typeProperty: '',
			typeValue: '',
			addProperties: false,
			working: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The fields the form asks, by label or property name.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		previewFields() {
			const fields =
				(this.template && this.template.form && this.template.form.fields)
				|| []
			return fields
				.map(
					(field) =>
						(field
							&& (field.label
								|| field.title
								|| field.name
								|| field.key))
						|| '',
				)
				.filter((label) => label !== '')
		},

		/**
		 * The form's confirmation text.
		 *
		 * @return {string}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		confirmationText() {
			return (
				(this.template
					&& this.template.form
					&& this.template.form.confirmationText)
				|| ''
			)
		},

		/**
		 * The apps as select options.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		appOptions() {
			return this.apps
				.filter((app) => app && app.slug)
				.map((app) => ({ id: app.slug, label: app.name || app.slug }))
		},

		/**
		 * The selected app option.
		 *
		 * @return {?object}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		appOption() {
			return (
				this.appOptions.find((option) => option.id === this.appSlug) || null
			)
		},

		/**
		 * The versions as select options.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		versionOptions() {
			return this.versions
				.filter((version) => version && version.slug)
				.map((version) => ({
					id: version.slug,
					label: version.name || version.semver || version.slug,
				}))
		},

		/**
		 * The selected version option.
		 *
		 * @return {?object}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		versionOption() {
			return (
				this.versionOptions.find(
					(option) => this.version && option.id === this.version.slug,
				) || null
			)
		},

		/**
		 * The data register of the selected version.
		 *
		 * @return {string}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		register() {
			const register = this.version && this.version.register
			return typeof register === 'string' ? register : ''
		},

		/**
		 * The register's schemas as select options.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		schemaOptions() {
			return this.schemas
				.filter((schema) => schema && schema.slug)
				.map((schema) => ({
					id: schema.slug,
					label: schema.title || schema.slug,
				}))
		},

		/**
		 * The selected schema option.
		 *
		 * @return {?object}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		schemaOption() {
			return (
				this.schemaOptions.find((option) => option.id === this.schemaSlug)
				|| null
			)
		},

		/**
		 * The stored schema the form maps to.
		 *
		 * @return {?object}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		schema() {
			return (
				this.schemas.find(
					(schema) => schema && schema.slug === this.schemaSlug,
				) || null
			)
		},

		/**
		 * The properties the mapped schema lacks.
		 *
		 * @return {Array<string>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		missingNames() {
			if (!this.template || !this.schema) {
				return []
			}
			return planLibraryFormUse(this.template, this.schema).missingNames
		},

		/**
		 * Whether everything the target needs is chosen.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		canConfirm() {
			if (this.working || !this.template || !this.version || !this.schema) {
				return false
			}
			if (
				this.target === 'registration-form'
				&& (this.typeProperty.trim() === '' || this.typeValue.trim() === '')
			) {
				return false
			}
			return this.missingNames.length === 0 || this.addProperties
		},
	},

	watch: {
		/**
		 * Reset and read the apps each time the dialog opens.
		 *
		 * @param {boolean} value The new open value.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		open(value) {
			if (value) {
				this.reset()
			}
		},
	},

	/**
	 * Read the apps when the dialog mounts open.
	 *
	 * @return {void}
	 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
	 */
	created() {
		if (this.open) {
			this.reset()
		}
	},

	methods: {
		/**
		 * Clear the choices and read the apps.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		async reset() {
			this.appSlug = ''
			this.versions = []
			this.version = null
			this.schemas = []
			this.schemaSlug = ''
			this.target =
				this.template && this.template.kind === 'registration-form'
					? 'registration-form'
					: 'form-page'
			this.typeProperty = ''
			this.typeValue = ''
			this.addProperties = false
			this.error = ''
			this.loadingApps = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/openregister/api/objects/buildiq/built-app'),
					{ params: { _limit: 200 } },
				)
				this.apps = resultsOf(data)
			} catch {
				this.apps = []
				this.error = t('buildiq', 'The apps could not be read.')
			} finally {
				this.loadingApps = false
			}
		},

		/**
		 * Pick an app and read its versions.
		 *
		 * @param {?{id: string}} option The app option.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		async onApp(option) {
			this.appSlug = (option && option.id) || ''
			this.versions = []
			this.version = null
			this.schemas = []
			this.schemaSlug = ''
			if (!this.appSlug) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/buildiq/api/applications/${encodeURIComponent(this.appSlug)}/versions`,
					),
				)
				this.versions = resultsOf(data)
			} catch {
				this.error = t(
					'buildiq',
					'The versions of this app could not be read.',
				)
			}
		},

		/**
		 * Pick a version, read it whole and read its register's schemas.
		 *
		 * @param {?{id: string}} option The version option.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		async onVersion(option) {
			const slug = (option && option.id) || ''
			this.version = null
			this.schemas = []
			this.schemaSlug = ''
			if (!slug) {
				return
			}
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/buildiq/api/applications/${encodeURIComponent(this.appSlug)}/versions/${encodeURIComponent(slug)}`,
					),
				)
				this.version = data && typeof data === 'object' ? data : null
				this.schemas = await fetchRegisterSchemas(this.register)
			} catch {
				this.error = t('buildiq', 'This version could not be read.')
			}
		},

		/**
		 * Map the form to a schema.
		 *
		 * @param {?{id: string}} option The schema option.
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		onSchema(option) {
			this.schemaSlug = (option && option.id) || ''
			this.addProperties = false
		},

		/**
		 * Close unless the form is being added.
		 *
		 * @return {void}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		onClose() {
			if (!this.working) {
				this.$emit('update:open', false)
			}
		},

		/**
		 * Add the form to the app: the missing properties first when the maker
		 * ticked that, then the page or the registration form.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/form-library/spec.md#requirement-a-library-form-can-be-added-to-an-app-req-bqgl-004
		 */
		async confirm() {
			if (!this.canConfirm) {
				return
			}
			this.working = true
			this.error = ''
			try {
				const result = await useLibraryForm({
					template: this.template,
					target: this.target,
					schema: this.schema,
					register: this.register,
					addProperties: this.addProperties,
					version: this.version,
					registration: {
						typeProperty: this.typeProperty.trim(),
						typeValue: this.typeValue.trim(),
						targetApp: this.appSlug,
					},
					saveRegistrationForm,
				})
				this.$emit('used', {
					...result,
					appSlug: this.appSlug,
					bound: boundProperties(this.template.form, this.template.kind),
				})
				this.$emit('update:open', false)
			} catch (error) {
				const data = error && error.response && error.response.data
				this.error =
					(error
					&& error.message
					&& !error.response
					&& error.message !== 'missing-properties'
						? error.message
						: '')
					|| (data && (data.detail || data.message || data.error))
					|| t('buildiq', 'Adding the form to the app failed.')
			} finally {
				this.working = false
			}
		},
	},
}
</script>

<style scoped>
.bq-use-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
}

.bq-use-form__intro {
	margin: 0;
	font-weight: bold;
}

.bq-use-form__preview,
.bq-use-form__missing {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
}

.bq-use-form__preview h3 {
	margin: 0 0 8px;
	font-size: 1rem;
}

.bq-use-form__note {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.bq-use-form__targets {
	border: 0;
	padding: 0;
}

.bq-use-form__error {
	margin: 0;
	color: var(--color-text-error, var(--color-error));
}
</style>
