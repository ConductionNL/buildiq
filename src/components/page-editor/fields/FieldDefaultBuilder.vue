<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FieldDefaultBuilder: what a form field is filled with when the form opens
  - (REQ-BQLV-001). A fixed value, the signed-in user, their name or e-mail,
  - today, or a field of the record the form was opened from. Stored as the
  - field's `default`: a literal or a token, never code.
  -
  - @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
  -->
<template>
	<div class="field-default-builder">
		<label class="field-default-builder__label">
			{{ t('buildiq', 'Filled in with') }}
			<select :value="kind" @change="chooseKind($event.target.value)">
				<option
					v-for="option in kinds"
					:key="option.value"
					:value="option.value">
					{{ option.label }}
				</option>
			</select>
		</label>
		<label v-if="kind === 'literal'" class="field-default-builder__label">
			{{ t('buildiq', 'Value') }}
			<input
				type="text"
				:value="literalValue"
				@input="emitValue($event.target.value)" />
		</label>
		<label v-if="kind === 'object'" class="field-default-builder__label">
			{{ t('buildiq', 'Field of the record') }}
			<input
				type="text"
				:value="objectField"
				:placeholder="t('buildiq', 'For example title')"
				@input="emitObjectField($event.target.value)" />
		</label>
	</div>
</template>

<script>
const TOKEN_KINDS = ['@me', '@me.displayName', '@me.email', '@today']

export default {
	name: 'FieldDefaultBuilder',
	props: {
		modelValue: {
			type: [Boolean, String, Number],
			default: null,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return { pickedKind: null }
	},

	computed: {
		/**
		 * The choices of the picker.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
		 */
		kinds() {
			return [
				{ value: 'none', label: t('buildiq', 'Nothing') },
				{ value: 'literal', label: t('buildiq', 'A fixed value') },
				{
					value: '@me',
					label: t('buildiq', 'The user name of the person filling it in'),
				},
				{ value: '@me.displayName', label: t('buildiq', 'Their name') },
				{ value: '@me.email', label: t('buildiq', 'Their e-mail') },
				{ value: '@today', label: t('buildiq', 'Today') },
				{
					value: 'object',
					label: t('buildiq', 'A field of the record the form opens from'),
				},
			]
		},

		/**
		 * Which kind of default the stored value is.
		 *
		 * @return {string}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
		 */
		kind() {
			const value = this.modelValue
			if (value === null || value === undefined || value === '') {
				return this.pickedKind || 'none'
			}
			if (typeof value === 'string' && TOKEN_KINDS.includes(value)) {
				return value
			}
			if (typeof value === 'string' && value.startsWith('@object.')) {
				return 'object'
			}
			return 'literal'
		},

		/**
		 * The literal value, or '' for a token.
		 *
		 * @return {string}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
		 */
		literalValue() {
			return this.kind === 'literal' && this.modelValue !== null
				? String(this.modelValue)
				: ''
		},

		/**
		 * The record field an `@object.` default reads.
		 *
		 * @return {string}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
		 */
		objectField() {
			return typeof this.modelValue === 'string'
				&& this.modelValue.startsWith('@object.')
				? this.modelValue.slice('@object.'.length)
				: ''
		},
	},

	methods: {
		/**
		 * Switch the kind of default.
		 *
		 * @param {string} kind The chosen kind.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
		 */
		chooseKind(kind) {
			this.pickedKind = kind
			if (TOKEN_KINDS.includes(kind)) {
				this.$emit('update:modelValue', kind)
				return
			}
			this.$emit('update:modelValue', null)
		},

		/**
		 * Write a fixed value.
		 *
		 * @param {string} value The value.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
		 */
		emitValue(value) {
			this.$emit('update:modelValue', value === '' ? null : value)
		},

		/**
		 * Write an `@object.<field>` default.
		 *
		 * @param {string} field The record field.
		 * @return {void}
		 * @spec openspec/changes/forms-live-values-and-checks/specs/form-live-values/spec.md#requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001
		 */
		emitObjectField(field) {
			const trimmed = field.trim()
			this.$emit(
				'update:modelValue',
				trimmed === '' ? null : '@object.' + trimmed,
			)
		},
	},
}
</script>

<style scoped>
.field-default-builder {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.field-default-builder__label {
	display: flex;
	flex-direction: column;
	gap: 2px;
}
</style>
