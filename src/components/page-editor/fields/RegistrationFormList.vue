<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - RegistrationFormList: the forms one case type carries, and which one the
  - intake picks by default.
  -
  - A case type can have several. One for the client, one for the desk, one for
  - a supplier. Each owns what it asks, so this list is per type and not per
  - schema.
  -
  - Every refusal shown here comes from the server. The rules live in one place
  - and this panel repeats their sentence rather than guessing at a second copy
  - that could drift.
  -
  - @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
  -->
<template>
	<fieldset class="form-list">
		<legend>{{ t('buildiq', 'Forms for this case type') }}</legend>

		<p v-if="!scoped" class="form-list__note">
			{{
				t(
					'buildiq',
					'Pick a register, a schema and a case type first. A form belongs to one type.',
				)
			}}
		</p>

		<template v-else>
			<p v-if="loading" class="form-list__note">
				{{ t('buildiq', 'Reading the forms.') }}
			</p>

			<p v-else-if="forms.length === 0" class="form-list__note">
				{{ t('buildiq', 'No form yet. Add one and the intake can use it.') }}
			</p>

			<ul v-else class="form-list__items">
				<li v-for="form in forms" :key="form.id" class="form-list__item">
					<span class="form-list__name">{{ form.name || form.id }}</span>
					<span class="form-list__audience">{{
						audienceLabel(form)
					}}</span>
					<span v-if="form.channel" class="form-list__audience">{{
						form.channel
					}}</span>
					<span v-if="form.isDefault" class="form-list__default">
						{{ t('buildiq', 'Default') }}
					</span>
					<button type="button" @click="edit(form)">
						{{ t('buildiq', 'Edit') }}
					</button>
				</li>
			</ul>

			<RegistrationFormEditor
				v-if="editing"
				:modelValue="editing"
				:properties="properties"
				:channels="channels"
				:note="note"
				:saving="adding"
				@update:modelValue="editing = $event"
				@save="saveEdited"
				@close="editing = null" />

			<ul v-if="warnings.length" class="form-list__warnings" role="alert">
				<li v-for="warning in warnings" :key="warning">
					{{ warning }}
				</li>
			</ul>

			<div class="form-list__add">
				<label class="form-list__field">
					{{ t('buildiq', 'Name') }}
					<input
						type="text"
						:value="draftName"
						:placeholder="
							t('buildiq', 'Application for a building permit')
						"
						@input="draftName = $event.target.value" />
				</label>

				<label class="form-list__field">
					{{ t('buildiq', 'Who fills it in') }}
					<select
						:value="draftAudience"
						@change="draftAudience = $event.target.value">
						<option value="client">
							{{ t('buildiq', 'The client') }}
						</option>
						<option value="internal">
							{{ t('buildiq', 'A colleague') }}
						</option>
						<option value="supplier">
							{{ t('buildiq', 'A supplier') }}
						</option>
					</select>
				</label>

				<label class="form-list__inline">
					<input
						type="checkbox"
						:checked="draftDefault"
						@change="draftDefault = $event.target.checked" />
					{{ t('buildiq', 'The intake picks this one') }}
				</label>

				<button
					type="button"
					:disabled="adding || draftName.trim() === ''"
					@click="addForm">
					{{ adding ? t('buildiq', 'Saving') : t('buildiq', 'Add form') }}
				</button>
			</div>

			<p v-if="refusal" class="form-list__warn" role="alert">
				{{ refusal }}
			</p>
		</template>
	</fieldset>
</template>

<script>
import RegistrationFormEditor from './RegistrationFormEditor.vue'
import {
	fetchRegistrationForms,
	fetchTargetSchema,
	saveRegistrationForm,
} from '../../../services/registrationForms.js'

export default {
	name: 'RegistrationFormList',

	components: { RegistrationFormEditor },

	props: {
		register: {
			type: String,
			default: '',
		},

		schema: {
			type: String,
			default: '',
		},

		// Which property carries the case type, and which value this list is
		// for. A form belongs to one type, so both are required.
		typeProperty: {
			type: String,
			default: '',
		},

		typeValue: {
			type: String,
			default: '',
		},

		// The app the forms are served to, stored on every form so the leaf can
		// tell two apps' forms apart.
		targetApp: {
			type: String,
			default: '',
		},
	},

	emits: ['added'],

	data() {
		return {
			forms: [],
			loading: false,
			adding: false,
			refusal: '',
			draftName: '',
			draftAudience: 'client',
			draftDefault: false,
			editing: null,
			properties: null,
			channels: null,
			note: '',
			warnings: [],
		}
	},

	computed: {
		/**
		 * Whether this list knows which type it is for.
		 *
		 * @return {boolean} True when the scope is complete.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
		 */
		scoped() {
			return (
				this.register !== ''
				&& this.schema !== ''
				&& this.typeProperty !== ''
				&& this.typeValue !== ''
			)
		},
	},

	watch: {
		typeValue: {
			immediate: true,
			/**
			 * Re-read whenever the panel moves to another case type.
			 *
			 * @return {void}
			 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
			 */
			handler() {
				this.reload()
			},
		},

		schema: {
			/**
			 * The same for the schema.
			 *
			 * @return {void}
			 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
			 */
			handler() {
				this.reload()
			},
		},

		'editing.channelProperty': {
			/**
			 * The channel list is the nominated property's enum, so it is re-read
			 * whenever the nomination changes. Leaving a stale list on screen
			 * would offer channels the newly named property never accepts.
			 *
			 * @return {void}
			 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-007)
			 */
			handler() {
				this.readTarget()
			},
		},
	},

	methods: {
		/**
		 * Read what the target schema declares, for the editor's pickers.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-007)
		 */
		async readTarget() {
			if (!this.scoped) {
				return
			}

			try {
				const target = await fetchTargetSchema({
					register: this.register,
					schema: this.schema,
					channelProperty: (this.editing || {}).channelProperty || '',
				})
				this.properties = target.properties
				this.channels = target.channels
				this.note = target.note || ''
			} catch (refusal) {
				// The editor still works on free text. Saying the pickers are
				// missing beats an empty picker that looks like a schema with no
				// properties.
				this.properties = null
				this.channels = null
				this.note = refusal.message
			}
		},

		/**
		 * Open one form for editing.
		 *
		 * @param {object} form - the stored form.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-008)
		 */
		edit(form) {
			// A copy, so closing without saving leaves the list as it was.
			this.editing = { ...form }
			this.refusal = ''
			this.warnings = []
			this.readTarget()
		},

		/**
		 * Save the form being edited.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-005, REQ-OBRF-008)
		 */
		async saveEdited() {
			this.adding = true
			this.refusal = ''
			this.warnings = []

			try {
				const result = await saveRegistrationForm(this.editing)
				this.warnings = result.warnings
				await this.reload()
			} catch (refusal) {
				this.refusal = refusal.message
			} finally {
				this.adding = false
			}
		},

		/**
		 * Read the forms for this type.
		 *
		 * The endpoint answers a whole schema, so the filter to this type
		 * happens here. Asking the server for a type it does not filter on
		 * would quietly list every type's forms under one heading.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
		 */
		async reload() {
			if (!this.scoped) {
				this.forms = []
				return
			}

			this.loading = true
			this.refusal = ''

			try {
				const all = await fetchRegistrationForms({
					register: this.register,
					schema: this.schema,
				})
				this.forms = all.filter(
					(form) =>
						(form.typeProperty || '') === this.typeProperty
						&& (form.typeValue || '') === this.typeValue,
				)
			} catch (refusal) {
				this.refusal = refusal.message
			} finally {
				this.loading = false
			}
		},

		/**
		 * Who fills a form in.
		 *
		 * @param {object} form - the form.
		 * @return {string} The label.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
		 */
		audienceLabel(form) {
			const labels = {
				client: t('buildiq', 'The client'),
				internal: t('buildiq', 'A colleague'),
				supplier: t('buildiq', 'A supplier'),
			}
			return labels[form.audience] || form.audience || ''
		},

		/**
		 * Add one form to this type.
		 *
		 * The name uniqueness and the one-default rule are the server's, and a
		 * refusal is shown with the sentence it wrote.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
		 */
		async addForm() {
			this.adding = true
			this.refusal = ''

			try {
				const result = await saveRegistrationForm({
					name: this.draftName.trim(),
					audience: this.draftAudience,
					isDefault: this.draftDefault,
					status: 'draft',
					targetApp: this.targetApp,
					register: this.register,
					schema: this.schema,
					typeProperty: this.typeProperty,
					typeValue: this.typeValue,
				})

				this.draftName = ''
				this.draftDefault = false
				this.$emit('added', result.form)
				await this.reload()

				// A form with no fields asks nothing, so adding one opens it.
				this.edit(result.form)
			} catch (refusal) {
				this.refusal = refusal.message
			} finally {
				this.adding = false
			}
		},
	},
}
</script>

<style scoped>
.form-list__items {
	list-style: none;
	padding: 0;
}

.form-list__item {
	display: flex;
	gap: 8px;
	align-items: center;
	flex-wrap: wrap;
	padding-block: 4px;
}

.form-list__audience {
	color: var(--color-text-maxcontrast);
}

.form-list__default {
	font-weight: bold;
}

.form-list__field {
	display: block;
	margin-block-end: 8px;
}

.form-list__warn {
	color: var(--color-error);
}

.form-list__warnings {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
	padding-inline-start: 16px;
}
</style>
