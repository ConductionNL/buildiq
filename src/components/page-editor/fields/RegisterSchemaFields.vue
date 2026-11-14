<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - RegisterSchemaFields — the register + schema picker pair a page-type
  - sub-editor shows for a register-bound page: two labelled selects over the
  - registers and schemas the editor loaded, each with its inline validation
  - mark. The schema select stays disabled until a register is chosen.
  -
  - Emits the picked slug as `register` / `schema` and leaves writing it to
  - the owning editor, which knows its own partner-clear rules.
  -->
<template>
	<label>
		{{ t('buildiq', 'Register') }}
		<select
			:value="config.register || ''"
			:aria-invalid="isInvalid('register')"
			@change="$emit('register', $event.target.value)">
			<option value="">
				{{ t('buildiq', '— select register —') }}
			</option>
			<option
				v-for="r in registers"
				:key="r.slug || r.id"
				:value="r.slug">
				{{ registerLabel(r) }}
			</option>
		</select>
		<InlineFieldMark :error="markFor('register')" />
	</label>
	<label>
		{{ t('buildiq', 'Schema') }}
		<select
			:value="config.schema || ''"
			:disabled="!config.register"
			:aria-invalid="isInvalid('schema')"
			@change="$emit('schema', $event.target.value)">
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
		<InlineFieldMark :error="markFor('schema')" />
	</label>
</template>

<script>
import InlineFieldMark from './InlineFieldMark.vue'
import { pageEditorValidationMixin } from '../../../mixins/pageEditorValidation.js'

export default {
	name: 'RegisterSchemaFields',
	components: { InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		// The page config; only `register` and `schema` are read.
		config: { type: Object, default: () => ({}) },
		// The registers and schemas the owning editor loaded.
		registers: { type: Array, default: () => [] },
		schemas: { type: Array, default: () => [] },
		// The registers served from a connected database (REQ-BQDB-002).
		databaseRegisters: { type: Array, default: () => [] },
	},

	emits: ['register', 'schema'],

	methods: {
		/**
		 * A register's option text, marked when a connected database serves it.
		 *
		 * @param {{slug?: string, title?: string}} register The register.
		 * @return {string}
		 * @spec openspec/changes/data-external-database-sources/specs/external-database-sources/spec.md#requirement-pages-follow-the-databases-write-setting-req-bqdb-002
		 */
		registerLabel(register) {
			const name = register.title || register.slug
			if (!this.databaseRegisters.includes(register.slug)) {
				return name
			}
			return name + ' — ' + this.t('buildiq', 'Connected database')
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

select {
	@include c.control;
}
</style>
