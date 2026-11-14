<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - RegisterSchemaPicker — the register and schema selects an entry builder
  - row starts with (pages-detail-related-items). The schema select waits for a
  - register; both mark themselves invalid while empty. It emits `pick` with
  - `{ key: 'register' | 'schema', value }`; the builder decides what a change
  - clears.
  -->
<template>
	<div class="register-schema-picker">
		<label>
			{{ t('buildiq', 'Register') }}
			<select
				:value="entry.register || ''"
				:aria-invalid="!entry.register"
				@change="$emit('pick', { key: 'register', value: $event.target.value })">
				<option value="">
					{{ t('buildiq', '— select register —') }}
				</option>
				<option
					v-for="r in registers"
					:key="r.slug || r.id"
					:value="r.slug">
					{{ r.title || r.slug }}
				</option>
			</select>
		</label>
		<label>
			{{ t('buildiq', 'Schema') }}
			<select
				:value="entry.schema || ''"
				:disabled="!entry.register"
				:aria-invalid="!entry.schema"
				@change="$emit('pick', { key: 'schema', value: $event.target.value })">
				<option value="">
					{{ t('buildiq', '— select schema —') }}
				</option>
				<option
					v-for="s in schemas"
					:key="s.slug || s.id"
					:value="s.slug">
					{{ s.title || s.slug }}
				</option>
			</select>
		</label>
	</div>
</template>

<script>
export default {
	name: 'RegisterSchemaPicker',
	props: {
		// The entry whose `register` and `schema` are shown.
		entry: {
			type: Object,
			default: () => ({}),
		},

		// The registers to pick from.
		registers: {
			type: Array,
			default: () => [],
		},

		// The schemas of the picked register.
		schemas: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['pick'],
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

// The two labels take their own cells in the builder's grid.
.register-schema-picker {
	display: contents;
}

.register-schema-picker label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.register-schema-picker select {
	@include c.control;
}
</style>
