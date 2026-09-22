<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - RegistrationFormEditor: what a case type actually asks, and who it asks.
  -
  - The form object, the rules that judge it and the leaf that serves it all
  - existed before this editor did. What was missing was any way to author one:
  - the panel could add a form and nothing else, so every field, section,
  - preset, channel and confirmation sentence had to be written into the store
  - by hand. That is the gap this closes (row 11.4, "form builder in the UI").
  -
  - It is a controlled component. It holds no network and no store: the list
  - that mounts it owns both, so there is one place that saves a form and one
  - sentence shown when the server refuses.
  -
  - Every refusal here is the server's own. Repeating the rules in the browser
  - would put a second copy of them somewhere they can drift, and the copy that
  - drifts is always the one the administrator reads.
  -
  - @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004, REQ-OBRF-005, REQ-OBRF-007, REQ-OBRF-008, REQ-OBRF-009)
  -->
<template>
	<section class="form-editor">
		<h4 class="form-editor__title">
			{{ modelValue.name || t('buildiq', 'This form') }}
		</h4>

		<fieldset class="form-editor__block">
			<legend>{{ t('buildiq', 'This form') }}</legend>

			<label class="form-editor__field">
				{{ t('buildiq', 'Name') }}
				<input
					type="text"
					:value="modelValue.name || ''"
					:placeholder="t('buildiq', 'Application for a building permit')"
					@input="write('name', $event.target.value)" />
			</label>

			<label class="form-editor__field">
				{{ t('buildiq', 'Who fills it in') }}
				<select
					:value="modelValue.audience || 'client'"
					@change="write('audience', $event.target.value)">
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

			<label class="form-editor__field">
				{{ t('buildiq', 'Which property carries the channel') }}
				<select
					v-if="properties && properties.length"
					:value="modelValue.channelProperty || ''"
					@change="write('channelProperty', $event.target.value)">
					<option value="">
						{{ t('buildiq', 'None') }}
					</option>
					<option v-for="p in properties" :key="p" :value="p">
						{{ p }}
					</option>
				</select>
				<input
					v-else
					type="text"
					:value="modelValue.channelProperty || ''"
					:placeholder="t('buildiq', 'intakeChannel')"
					@input="write('channelProperty', $event.target.value)" />
			</label>

			<label class="form-editor__field">
				{{ t('buildiq', 'Intake channel') }}
				<select
					v-if="channels && channels.length"
					:value="modelValue.channel || ''"
					@change="write('channel', $event.target.value)">
					<option value="">
						{{ t('buildiq', 'Every channel') }}
					</option>
					<option v-for="c in channels" :key="c" :value="c">
						{{ c }}
					</option>
				</select>
				<input
					v-else
					type="text"
					:value="modelValue.channel || ''"
					:placeholder="t('buildiq', 'portal')"
					@input="write('channel', $event.target.value)" />
			</label>
			<p v-if="channelsUnknown" class="form-editor__hint">
				{{
					t(
						'buildiq',
						'Name the property above and this becomes a list of the channels the other app accepts.',
					)
				}}
			</p>

			<label class="form-editor__inline">
				<input
					type="checkbox"
					:checked="!!modelValue.isDefault"
					@change="write('isDefault', $event.target.checked)" />
				{{ t('buildiq', 'The intake picks this one') }}
			</label>

			<label class="form-editor__field">
				{{ t('buildiq', 'State') }}
				<select
					:value="modelValue.status || 'draft'"
					@change="write('status', $event.target.value)">
					<option value="draft">
						{{ t('buildiq', 'Draft') }}
					</option>
					<option value="published">
						{{ t('buildiq', 'Published') }}
					</option>
				</select>
			</label>
		</fieldset>

		<fieldset class="form-editor__block">
			<legend>{{ t('buildiq', 'Who may open it') }}</legend>

			<label class="form-editor__inline">
				<input
					type="checkbox"
					:checked="!!modelValue.isPublic"
					@change="write('isPublic', $event.target.checked)" />
				{{ t('buildiq', 'Anyone may fill this in, without signing in') }}
			</label>

			<label class="form-editor__field">
				{{ t('buildiq', 'What they read after sending') }}
				<textarea
					rows="3"
					:value="modelValue.confirmationText || ''"
					:placeholder="
						t(
							'buildiq',
							'Uw aanvraag is ontvangen. U hoort binnen acht weken van ons.',
						)
					"
					@input="write('confirmationText', $event.target.value)" />
			</label>
			<p class="form-editor__hint">
				{{
					t(
						'buildiq',
						'The sentence that ends an application is part of the application.',
					)
				}}
			</p>
		</fieldset>

		<FormLayoutBuilder
			:sections="modelValue.sections || []"
			:fields="modelValue.fields || []"
			:properties="properties"
			@update:sections="write('sections', $event)"
			@update:fields="write('fields', $event)" />

		<FormPresetsBuilder
			:presets="modelValue.presets || []"
			:properties="properties"
			@update:presets="write('presets', $event)" />

		<p v-if="note" class="form-editor__warn" role="alert">
			{{ note }}
		</p>

		<div class="form-editor__actions">
			<button type="button" :disabled="saving" @click="$emit('save')">
				{{ saving ? t('buildiq', 'Saving') : t('buildiq', 'Save form') }}
			</button>
			<button type="button" @click="$emit('close')">
				{{ t('buildiq', 'Close') }}
			</button>
		</div>
	</section>
</template>

<script>
import FormLayoutBuilder from './FormLayoutBuilder.vue'
import FormPresetsBuilder from './FormPresetsBuilder.vue'

export default {
	name: 'RegistrationFormEditor',

	components: { FormLayoutBuilder, FormPresetsBuilder },

	props: {
		modelValue: {
			type: Object,
			default: () => ({}),
		},

		// The target schema's property names, or null when it could not be read.
		properties: {
			type: Array,
			default: null,
		},

		// The values the nominated channel property accepts, or null.
		channels: {
			type: Array,
			default: null,
		},

		// What the server said about a check that could not run.
		note: {
			type: String,
			default: '',
		},

		saving: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue', 'save', 'close'],

	computed: {
		/**
		 * Whether the channel is still free text because no property was
		 * nominated. Said out loud rather than left as an input that quietly
		 * accepts anything.
		 *
		 * @return {boolean} True when there is no channel list to pick from.
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-007)
		 */
		channelsUnknown() {
			return (this.channels || []).length === 0
		},
	},

	methods: {
		/**
		 * Write one key of the form, leaving every other key exactly as it was.
		 *
		 * A spread and not an assignment: a form carries keys this editor does
		 * not author, and rebuilding the object from the fields on screen would
		 * drop them.
		 *
		 * @param {string} key - the key to write.
		 * @param {string|boolean|Array<object>} value - its new value.
		 * @return {void}
		 * @spec openspec/changes/forms-per-case-type/specs/registration-form-builder/spec.md (REQ-OBRF-004)
		 */
		write(key, value) {
			this.$emit('update:modelValue', { ...this.modelValue, [key]: value })
		},
	},
}
</script>

<style scoped>
.form-editor {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px;
	margin-block: 8px;
}

.form-editor__title {
	margin-block: 0 8px;
}

.form-editor__block {
	margin-block-end: 12px;
}

.form-editor__field {
	display: block;
	margin-block-end: 8px;
}

.form-editor__inline {
	display: flex;
	gap: 4px;
	align-items: center;
	margin-block-end: 8px;
}

.form-editor__hint {
	color: var(--color-text-maxcontrast);
}

.form-editor__warn {
	color: var(--color-warning-text, var(--color-text-maxcontrast));
}

.form-editor__actions {
	display: flex;
	gap: 8px;
}
</style>
