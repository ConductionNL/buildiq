<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - FieldSelect — one labelled select of a schema's fields, as the entry
  - builders use for the link field and the picker label field
  - (pages-detail-related-items). Emits the picked field, '' for none.
  -->
<template>
	<label class="field-select">
		{{ label }}
		<select
			:value="value || ''"
			:disabled="disabled"
			:aria-invalid="invalid ? 'true' : undefined"
			@change="$emit('change', $event.target.value)">
			<option value="">
				{{ emptyText }}
			</option>
			<option
				v-for="field in fields"
				:key="field"
				:value="field">
				{{ field }}
			</option>
		</select>
	</label>
</template>

<script>
export default {
	name: 'FieldSelect',
	props: {
		// The visible label.
		label: {
			type: String,
			required: true,
		},

		// The picked field, '' for none.
		value: {
			type: String,
			default: '',
		},

		// The fields to pick from.
		fields: {
			type: Array,
			default: () => [],
		},

		// The text of the empty option.
		emptyText: {
			type: String,
			default: '',
		},

		disabled: {
			type: Boolean,
			default: false,
		},

		// Marks the select invalid (a required field left empty).
		invalid: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['change'],
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.field-select {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.field-select select {
	@include c.control;
}
</style>
