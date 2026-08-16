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
			{{ t('openbuild', 'Logs page') }}
		</h3>

		<fieldset class="logs-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Data source') }}</legend>
			<div class="logs-page-editor__shape">
				<label class="logs-page-editor__inline">
					<input
						type="radio"
						:checked="sourceShape === 'register'"
						value="register"
						@change="setSourceShape('register')" />
					{{ t('openbuild', 'Register + schema') }}
				</label>
				<label class="logs-page-editor__inline">
					<input
						type="radio"
						:checked="sourceShape === 'source'"
						value="source"
						@change="setSourceShape('source')" />
					{{ t('openbuild', 'Source (URL or registry key)') }}
				</label>
			</div>

			<div v-if="sourceShape === 'register'" class="logs-page-editor__group">
				<label>
					{{ t('openbuild', 'Register') }}
					<select
						:value="config.register || ''"
						:aria-invalid="isInvalid('register')"
						@change="update('register', $event.target.value)">
						<option value="">
							{{ t('openbuild', '— select register —') }}
						</option>
						<option
							v-for="r in registers"
							:key="r.slug || r.id"
							:value="r.slug">
							{{ r.title || r.slug }}
						</option>
					</select>
					<InlineFieldMark :error="markFor('register')" />
				</label>
				<label>
					{{ t('openbuild', 'Schema') }}
					<select
						:value="config.schema || ''"
						:disabled="!config.register"
						:aria-invalid="isInvalid('schema')"
						@change="update('schema', $event.target.value)">
						<option value="">
							{{ t('openbuild', '— select schema —') }}
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
			</div>
			<div v-else class="logs-page-editor__group">
				<label>
					{{ t('openbuild', 'Source') }}
					<input
						type="text"
						:value="config.source || ''"
						:placeholder="
							t(
								'openbuild',
								'/api/objects/:slug/audit or a customComponents key',
							)
						"
						:aria-invalid="isInvalid('source')"
						@input="update('source', $event.target.value)" />
					<InlineFieldMark :error="markFor('source')" />
				</label>
			</div>
			<p class="logs-page-editor__hint">
				{{
					t(
						'openbuild',
						'Exactly one of (register + schema) or source must be set.',
					)
				}}
			</p>
		</fieldset>

		<fieldset class="logs-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Columns') }}</legend>
			<ColumnBuilder
				:modelValue="config.columns || []"
				:schemaProperties="schemaProperties"
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

		// The Application's declared `dataRegisters` bindings, forwarded into
		// useRegisterPicker so the register picker labels/hoists them.
		dataRegisters: {
			type: Array,
			default: () => [],
		},

		parentRoute: {
			type: String,
			default: '',
		},
	},

	emits: ['update:config'],
	/**
	 * Build the register/schema picker for this editor. Options-API `data`
	 * cannot see props at construction time, so the picker is created here
	 * from the resolved props and exposed as `this.picker`.
	 *
	 * @param {{appSlug: string, dataRegisters: Array<{register: string, label?: string}>, config: object, pageType: string, parentRoute: string}} props - the resolved component props; only `appSlug` (hoists `openbuild-{slug}` in the register list) and `dataRegisters` (labels/hoists the Application's declared bindings) are read.
	 * @return {{picker: object}} - bindings merged into the instance; `picker` exposes fetchRegisters/fetchSchemas/fetchSchemaProperties.
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-2.1
	 */
	setup(props) {
		const picker = useRegisterPicker({
			appSlug: props.appSlug,
			dataRegisters: props.dataRegisters,
		})
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
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		validatedConfigKeys() {
			return ['register', 'schema', 'source', 'columns']
		},

		/**
		 * Observed behaviour of `sourceShape` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
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
			/**
			 * Reload the schema dropdown whenever the bound register changes
			 * (also fires immediately on mount for an already-bound page).
			 *
			 * @param {string} val - the newly selected register slug; empty when the binding was cleared — including by a switch to the `source` branch — which empties the schema list instead of fetching.
			 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
			 */
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
			/**
			 * Reload the property map that feeds ColumnBuilder's field picker
			 * whenever the bound schema changes.
			 *
			 * @param {string} val - the newly selected schema slug; empty (or a schema without a register) clears the property map so the column picker offers nothing stale.
			 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
			 */
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
		/**
		 * Write one key on the page's `config` block and keep the
		 * (register + schema) XOR source intact: picking a register clears
		 * `source`, typing a source clears `register` + `schema`. Keys this
		 * editor does not surface are left untouched.
		 *
		 * @param {string} key - the config key being written: `register`, `schema`, `source` or `columns`.
		 * @param {string|Array<object>} value - the new value: a slug from a `<select>`, the free-text source URL/registry key, or the rebuilt column list from ColumnBuilder. An empty string, `null` or an empty array deletes the key instead of storing it.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		update(key, value) {
			const next = { ...this.config }
			if (
				value === ''
				|| value === null
				|| (Array.isArray(value) && value.length === 0)
			) {
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

		/**
		 * Switch between the two mutually exclusive data-source branches by
		 * deleting the keys of the branch being left, so the emitted config
		 * never satisfies both halves of the XOR at once.
		 *
		 * @param {'register'|'source'} shape - the radio's value: `source` drops `register` + `schema`, `register` drops `source`.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
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

		/**
		 * Observed behaviour of `fetchRegisters` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		async fetchRegisters() {
			this.registers = await this.picker.fetchRegisters()
		},

		/**
		 * Load the schemas of one register into the schema dropdown.
		 *
		 * @param {string} register - slug of the register to list schemas for, i.e. `config.register`.
		 * @return {Promise<void>} - resolves once `this.schemas` holds the result (`[]` when the request fails).
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		async fetchSchemas(register) {
			this.schemas = await this.picker.fetchSchemas(register)
		},

		/**
		 * Load one schema's JSON-Schema `properties` map, which ColumnBuilder
		 * turns into the column field picker's options.
		 *
		 * @param {string} register - slug of the register the schema lives in.
		 * @param {string} schema - slug of the schema whose properties are wanted.
		 * @return {Promise<void>} - resolves once `this.schemaProperties` holds the map (`{}` when the request fails).
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		async fetchSchemaProperties(register, schema) {
			this.schemaProperties = await this.picker.fetchSchemaProperties(
				register,
				schema,
			)
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
