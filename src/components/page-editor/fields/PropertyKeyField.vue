<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - PropertyKeyField — the control a page-type sub-editor shows for a config
  - key that names a schema property: a select over the bound schema's
  - property keys once a schema is bound, a free-text input otherwise.
  -
  - One root element (the select or the input), so it sits in the owning
  - editor's label and picks up that editor's control styles.
  -->
<template>
	<select
		v-if="bound"
		:value="value"
		@change="$emit('update', $event.target.value)">
		<option value="">
			{{ emptyLabel }}
		</option>
		<option
			v-for="key in keys"
			:key="key"
			:value="key">
			{{ key }}
		</option>
	</select>
	<input
		v-else
		type="text"
		:value="value"
		:placeholder="placeholder"
		@input="$emit('update', $event.target.value)" />
</template>

<script>
export default {
	name: 'PropertyKeyField',
	props: {
		// The current property key ('' when unset).
		value: { type: String, default: '' },
		// The bound schema's property keys.
		keys: { type: Array, default: () => [] },
		// Whether a schema is bound (select) or not (free text).
		bound: { type: Boolean, default: false },
		// The label of the select's empty option.
		emptyLabel: { type: String, default: '' },
		// The free-text input's placeholder.
		placeholder: { type: String, default: undefined },
	},

	emits: ['update'],
}
</script>
