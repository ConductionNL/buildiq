<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - LogsPageEditor — structured editor for `type: "logs"` pages (task 4.4).
  -
  - Manifest contract: `{ register?, schema?, source?, columns? }` where
  - EXACTLY ONE of (register + schema) OR `source` MUST be set. UI:
  -   - a one-of radio between "register + schema" and "source URL/key";
  -   - register / schema dropdowns via the same OR-REST pickers
  -     IndexPageEditor uses (`useRegisterPicker`);
  -   - a free-text `source` input for the other branch;
  -   - a columns list reusing `ColumnBuilder` (schema-property options
  -     when bound to a register+schema).
  -
  - Lossless round-trip: `update(key, value)` clones `config` and only
  - touches the one key (plus the mutually-exclusive partner on a branch
  - switch), so externally-authored keys this editor doesn't surface
  - survive every edit.
  -->
<template>
	<div class="logs-page-editor">
		<h3 class="logs-page-editor__title">
			{{ t('openbuilt', 'Logs page') }}
		</h3>

		<fieldset class="logs-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Data source') }}</legend>
			<div class="logs-page-editor__shape">
				<label class="logs-page-editor__inline">
					<input
						type="radio"
						:checked="sourceShape === 'register'"
						value="register"
						@change="setSourceShape('register')">
					{{ t('openbuilt', 'Register + schema') }}
				</label>
				<label class="logs-page-editor__inline">
					<input
						type="radio"
						:checked="sourceShape === 'source'"
						value="source"
						@change="setSourceShape('source')">
					{{ t('openbuilt', 'Source (URL or registry key)') }}
				</label>
			</div>

			<div v-if="sourceShape === 'register'" class="logs-page-editor__group">
				<label>
					{{ t('openbuilt', 'Register') }}
					<select
						:value="config.register || ''"
						:aria-invalid="isInvalid('register')"
						@change="update('register', $event.target.value)">
						<option value="">
							{{ t('openbuilt', '— select register —') }}
						</option>
						<option v-for="r in registers" :key="r.slug || r.id" :value="r.slug">
							{{ r.title || r.slug }}
						</option>
					</select>
					<InlineFieldMark :error="markFor('register')" />
				</label>
				<label>
					{{ t('openbuilt', 'Schema') }}
					<select
						:value="config.schema || ''"
						:disabled="!config.register"
						:aria-invalid="isInvalid('schema')"
						@change="update('schema', $event.target.value)">
						<option value="">
							{{ t('openbuilt', '— select schema —') }}
						</option>
						<option v-for="s in schemas" :key="s.slug || s.id" :value="s.slug">
							{{ s.title || s.slug }}
						</option>
					</select>
					<InlineFieldMark :error="markFor('schema')" />
				</label>
			</div>
			<div v-else class="logs-page-editor__group">
				<label>
					{{ t('openbuilt', 'Source') }}
					<input
						type="text"
						:value="config.source || ''"
						:placeholder="t('openbuilt', '/api/objects/:slug/audit or a customComponents key')"
						:aria-invalid="isInvalid('source')"
						@input="update('source', $event.target.value)">
					<InlineFieldMark :error="markFor('source')" />
				</label>
			</div>
			<p class="logs-page-editor__hint">
				{{ t('openbuilt', 'Exactly one of (register + schema) or source must be set.') }}
			</p>
		</fieldset>

		<fieldset class="logs-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Columns') }}</legend>
			<ColumnBuilder
				:model-value="config.columns || []"
				:schema-properties="schemaProperties"
				@update:modelValue="update('columns', $event)" />
			<InlineFieldMark :error="markFor('columns')" />
		</fieldset>
	</div>
</template>

<script>
import ColumnBuilder from './fields/ColumnBuilder.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'LogsPageEditor',
	components: { ColumnBuilder, InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		pageType: {
			type: String,
			default: 'logs',
		},
		appSlug: {
			type: String,
			default: '',
		},
		parentRoute: {
			type: String,
			default: '',
		},
	},
	emits: ['update:config'],
	setup(props) {
		const picker = useRegisterPicker({ appSlug: props.appSlug })
		return { picker }
	},
	data() {
		return {
			registers: [],
			schemas: [],
			schemaProperties: {},
		}
	},
	computed: {
		validatedConfigKeys() {
			return ['register', 'schema', 'source', 'columns']
		},
		sourceShape() {
			// `source` wins only when there is no register binding so a
			// half-edited config never silently flips branches.
			if (this.config.source && !this.config.register) {
				return 'source'
			}
			return 'register'
		},
	},
	watch: {
		'config.register': {
			immediate: true,
			handler(val) {
				if (val) {
					this.fetchSchemas(val)
				} else {
					this.schemas = []
				}
			},
		},
		'config.schema': {
			immediate: true,
			handler(val) {
				if (val && this.config.register) {
					this.fetchSchemaProperties(this.config.register, val)
				} else {
					this.schemaProperties = {}
				}
			},
		},
	},
	async mounted() {
		await this.fetchRegisters()
	},
	methods: {
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null || (Array.isArray(value) && value.length === 0)) {
				delete next[key]
			} else {
				next[key] = value
			}
			if (key === 'register') {
				delete next.schema
				if (value) {
					delete next.source
				}
			}
			if (key === 'source' && value) {
				delete next.register
				delete next.schema
			}
			this.$emit('update:config', next)
		},
		setSourceShape(shape) {
			const next = { ...this.config }
			if (shape === 'source') {
				delete next.register
				delete next.schema
			} else {
				delete next.source
			}
			this.$emit('update:config', next)
		},
		async fetchRegisters() {
			this.registers = await this.picker.fetchRegisters()
		},
		async fetchSchemas(register) {
			this.schemas = await this.picker.fetchSchemas(register)
		},
		async fetchSchemaProperties(register, schema) {
			this.schemaProperties = await this.picker.fetchSchemaProperties(register, schema)
		},
	},
}
</script>

<style scoped>
.logs-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}
.logs-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}
.logs-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.logs-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}
.logs-page-editor__shape {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}
.logs-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
}
.logs-page-editor__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.logs-page-editor__group label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}
.logs-page-editor__group input,
.logs-page-editor__group select {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
.logs-page-editor__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
