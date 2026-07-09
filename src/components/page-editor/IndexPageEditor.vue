<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - IndexPageEditor — register picker (OR REST), schema picker (OR REST),
  - column selector with @self.* options, actions list, sidebar block,
  - optional cardComponent. Implements REQ-OBPD-004.
  -->
<template>
	<div class="index-page-editor">
		<h3 class="index-page-editor__title">
			{{ t('openbuild', 'Index page') }}
		</h3>
		<DataSourceOriginToggle
			:data-source="config.dataSource || {}"
			@update:dataSource="onDataSourceUpdate" />
		<div v-if="!connectorActive" class="index-page-editor__group">
			<label>
				{{ t('openbuild', 'Register') }}
				<select :value="config.register || ''" :aria-invalid="isInvalid('register')" @change="update('register', $event.target.value)">
					<option value="">
						{{ t('openbuild', '— select register —') }}
					</option>
					<option v-for="r in registers" :key="r.slug || r.id" :value="r.slug">
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
					<option v-for="s in schemas" :key="s.slug || s.id" :value="s.slug">
						{{ s.title || s.slug }}
					</option>
				</select>
				<InlineFieldMark :error="markFor('schema')" />
			</label>
			<label>
				{{ t('openbuild', 'Card component (optional)') }}
				<input
					type="text"
					:value="config.cardComponent || ''"
					:placeholder="t('openbuild', 'customComponents key')"
					:aria-invalid="isInvalid('cardComponent')"
					@input="update('cardComponent', $event.target.value)">
				<InlineFieldMark :error="markFor('cardComponent')" />
			</label>
		</div>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Columns') }}</legend>
			<ColumnBuilder
				:model-value="config.columns || []"
				:schema-properties="schemaProperties"
				@update:modelValue="update('columns', $event)" />
			<InlineFieldMark :error="markFor('columns')" />
		</fieldset>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Actions') }}</legend>
			<ActionBuilder
				:model-value="config.actions || []"
				@update:modelValue="update('actions', $event)" />
			<InlineFieldMark :error="markFor('actions')" />
		</fieldset>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('openbuild', 'Sidebar') }}</legend>
			<label class="index-page-editor__inline">
				<input
					type="checkbox"
					:checked="sidebarEnabled"
					@change="onSidebarToggle($event.target.checked)">
				{{ t('openbuild', 'Enabled') }}
			</label>
			<InlineFieldMark :error="markFor('sidebar')" />
			<SidebarSectionBuilder
				v-if="sidebarEnabled"
				:model-value="(config.sidebar && config.sidebar.columnGroups) || []"
				@update:modelValue="updateSidebar('columnGroups', $event)" />
		</fieldset>
	</div>
</template>

<script>
import ColumnBuilder from './fields/ColumnBuilder.vue'
import ActionBuilder from './fields/ActionBuilder.vue'
import SidebarSectionBuilder from './fields/SidebarSectionBuilder.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import DataSourceOriginToggle from './DataSourceOriginToggle.vue'
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
		// register picker hoists `openbuild-{slug}` to the top of the list.
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
	 * Observed behaviour of `setup` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-2.1
	 */
	setup(props) {
		const picker = useRegisterPicker({ appSlug: props.appSlug, dataRegisters: props.dataRegisters })
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
			return ['register', 'schema', 'cardComponent', 'columns', 'actions', 'sidebar']
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
			 * Observed behaviour of `handler` (retrofit annotation).
			 *
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
			 * Observed behaviour of `handler` (retrofit annotation).
			 *
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
		 * Observed behaviour of `update` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		update(key, value) {
			const next = { ...this.config }
			if (value === '' || value === null || (Array.isArray(value) && value.length === 0)) {
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
		 * Observed behaviour of `onSidebarToggle` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		onSidebarToggle(enabled) {
			const next = { ...this.config }
			if (!enabled) {
				delete next.sidebar
			} else {
				const current = (typeof next.sidebar === 'object' && next.sidebar) || {}
				next.sidebar = { ...current, enabled: true }
			}
			this.$emit('update:config', next)
		},
		/**
		 * Observed behaviour of `updateSidebar` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		updateSidebar(key, value) {
			const next = { ...this.config }
			const current = (typeof next.sidebar === 'object' && next.sidebar) || { enabled: true }
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
		 * Observed behaviour of `fetchSchemas` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		async fetchSchemas(register) {
			this.schemas = await this.picker.fetchSchemas(register)
		},
		/**
		 * Observed behaviour of `fetchSchemaProperties` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		async fetchSchemaProperties(register, schema) {
			this.schemaProperties = await this.picker.fetchSchemaProperties(register, schema)
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
