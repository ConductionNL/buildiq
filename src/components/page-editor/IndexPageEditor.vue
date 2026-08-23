<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - IndexPageEditor — register picker (OR REST), schema picker (OR REST),
  - column selector with @self.* options, actions list, sidebar block,
  - optional cardComponent. Implements REQ-OBPD-004.
  -->
<template>
	<div class="index-page-editor">
		<h3 class="index-page-editor__title">
			{{ t('buildiq', 'Index page') }}
		</h3>
		<DataSourceOriginToggle
			:data-source="config.dataSource || {}"
			@update:dataSource="onDataSourceUpdate" />
		<div v-if="!connectorActive" class="index-page-editor__group">
			<label>
				{{ t('buildiq', 'Register') }}
				<select
					:value="config.register || ''"
					:aria-invalid="isInvalid('register')"
					@change="update('register', $event.target.value)">
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
				<InlineFieldMark :error="markFor('register')" />
			</label>
			<label>
				{{ t('buildiq', 'Schema') }}
				<select
					:value="config.schema || ''"
					:disabled="!config.register"
					:aria-invalid="isInvalid('schema')"
					@change="update('schema', $event.target.value)">
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
			<label>
				{{ t('buildiq', 'Card component (optional)') }}
				<input
					type="text"
					:value="config.cardComponent || ''"
					:placeholder="t('buildiq', 'customComponents key')"
					:aria-invalid="isInvalid('cardComponent')"
					@input="update('cardComponent', $event.target.value)" />
				<InlineFieldMark :error="markFor('cardComponent')" />
			</label>
		</div>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Columns') }}</legend>
			<ColumnBuilder
				:modelValue="config.columns || []"
				:schemaProperties="schemaProperties"
				@update:modelValue="update('columns', $event)" />
			<InlineFieldMark :error="markFor('columns')" />
		</fieldset>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Actions') }}</legend>
			<ActionBuilder
				:modelValue="config.actions || []"
				@update:modelValue="update('actions', $event)" />
			<InlineFieldMark :error="markFor('actions')" />
		</fieldset>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Sidebar') }}</legend>
			<label class="index-page-editor__inline">
				<input
					type="checkbox"
					:checked="sidebarEnabled"
					@change="onSidebarToggle($event.target.checked)" />
				{{ t('buildiq', 'Enabled') }}
			</label>
			<InlineFieldMark :error="markFor('sidebar')" />
			<SidebarSectionBuilder
				v-if="sidebarEnabled"
				:modelValue="(config.sidebar && config.sidebar.columnGroups) || []"
				@update:modelValue="updateSidebar('columnGroups', $event)" />
		</fieldset>
	</div>
</template>

<script>
import DataSourceOriginToggle from './DataSourceOriginToggle.vue'
import ActionBuilder from './fields/ActionBuilder.vue'
import ColumnBuilder from './fields/ColumnBuilder.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import SidebarSectionBuilder from './fields/SidebarSectionBuilder.vue'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'IndexPageEditor',
	components: {
		ColumnBuilder,
		ActionBuilder,
		SidebarSectionBuilder,
		InlineFieldMark,
		DataSourceOriginToggle,
	},

	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},

		// Current Application slug. Drives the hybrid register model so the
		// register picker hoists `buildiq-{slug}` to the top of the list.
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

		pageType: {
			type: String,
			default: 'index',
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
	 * @param {{appSlug: string, dataRegisters: Array<{register: string, label?: string}>, config: object, pageType: string, parentRoute: string}} props - the resolved component props; only `appSlug` (hoists `buildiq-{slug}` in the register list) and `dataRegisters` (labels/hoists the Application's declared bindings) are read.
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
			return [
				'register',
				'schema',
				'cardComponent',
				'columns',
				'actions',
				'sidebar',
			]
		},

		/**
		 * Whether this page binds an OpenConnector data source (hides the
		 * OpenRegister register/schema pickers). REQ-OCAS-002.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.2
		 */
		connectorActive() {
			return !!(this.config.dataSource && this.config.dataSource.connector)
		},

		/**
		 * Observed behaviour of `sidebarEnabled` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		sidebarEnabled() {
			const s = this.config.sidebar
			if (s == null) {
				return false
			}
			if (typeof s === 'boolean') {
				return s
			}
			return s && s.enabled !== false
		},
	},

	watch: {
		'config.register': {
			immediate: true,
			/**
			 * Reload the schema dropdown whenever the bound register changes
			 * (also fires immediately on mount for an already-bound page).
			 *
			 * @param {string} val - the newly selected register slug; empty when the binding was cleared, which empties the schema list instead of fetching.
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
		 * Write one key on the page's `config` block and emit the whole block
		 * back to PageDesigner. Only the named key is touched, so config keys
		 * this editor does not surface round-trip losslessly.
		 *
		 * @param {string} key - the config key being written: `register`, `schema`, `cardComponent`, `columns` or `actions`. Writing `register` also drops `schema`, since a schema is only meaningful inside its register.
		 * @param {string|Array<object>} value - the new value: a slug/component key from a `<select>`/`<input>`, or the rebuilt array from ColumnBuilder / ActionBuilder. An empty string, `null` or an empty array deletes the key instead of storing it.
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
			// When register changes, clear schema dependency.
			if (key === 'register') {
				delete next.schema
			}
			this.$emit('update:config', next)
		},

		/**
		 * Turn the sidebar block on or off. Switching it off deletes
		 * `config.sidebar` outright — including any column groups authored
		 * under it — rather than leaving `{ enabled: false }` behind.
		 *
		 * @param {boolean} enabled - the checkbox's new `checked` state.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		onSidebarToggle(enabled) {
			const next = { ...this.config }
			if (!enabled) {
				delete next.sidebar
			} else {
				const current =
					(typeof next.sidebar === 'object' && next.sidebar) || {}
				next.sidebar = { ...current, enabled: true }
			}
			this.$emit('update:config', next)
		},

		/**
		 * Write one key inside the sidebar block, promoting a legacy boolean
		 * `sidebar: true` to the object form `{ enabled: true }` on the way.
		 *
		 * @param {string} key - the sidebar key being written; currently only `columnGroups`.
		 * @param {Array<object>} value - the rebuilt column-group list from SidebarSectionBuilder. Stored as-is, including when empty, so the sidebar stays enabled with no groups.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		updateSidebar(key, value) {
			const next = { ...this.config }
			const current = (typeof next.sidebar === 'object' && next.sidebar) || {
				enabled: true,
			}
			next.sidebar = { ...current, [key]: value }
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

		/**
		 * Persist a `dataSource` change from the origin toggle onto the page
		 * config. Clearing the connector block deletes `dataSource` entirely
		 * when nothing else lives there, so a register-bound page round-trips
		 * byte-identically (REQ-OCAS-002 regression guard).
		 *
		 * @param {object} dataSource - the updated dataSource object.
		 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-3.2
		 */
		onDataSourceUpdate(dataSource) {
			const next = { ...this.config }
			if (!dataSource || Object.keys(dataSource).length === 0) {
				delete next.dataSource
			} else {
				next.dataSource = dataSource
			}
			this.$emit('update:config', next)
		},
	},
}
</script>

<style scoped>
.index-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.index-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.index-page-editor__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.index-page-editor__group label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.index-page-editor__group input,
.index-page-editor__group select {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.index-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.index-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.index-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	margin-bottom: 6px;
}
</style>
