<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - IndexPageEditor — register picker (OR REST), schema picker (OR REST),
  - column selector with @self.* options, actions list, sidebar block,
  - optional cardComponent. Implements REQ-OBPD-004.
  -->
<template>
	<div class="index-page-editor">
		<h3 class="index-page-editor__title">
			{{ t('openbuilt', 'Index page') }}
		</h3>
		<div class="index-page-editor__group">
			<label>
				{{ t('openbuilt', 'Register') }}
				<select :value="config.register || ''" :aria-invalid="isInvalid('register')" @change="update('register', $event.target.value)">
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
			<label>
				{{ t('openbuilt', 'Card component (optional)') }}
				<input
					type="text"
					:value="config.cardComponent || ''"
					:placeholder="t('openbuilt', 'customComponents key')"
					:aria-invalid="isInvalid('cardComponent')"
					@input="update('cardComponent', $event.target.value)">
				<InlineFieldMark :error="markFor('cardComponent')" />
			</label>
		</div>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Columns') }}</legend>
			<ColumnBuilder
				:model-value="config.columns || []"
				:schema-properties="schemaProperties"
				@update:modelValue="update('columns', $event)" />
			<InlineFieldMark :error="markFor('columns')" />
		</fieldset>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Actions') }}</legend>
			<ActionBuilder
				:model-value="config.actions || []"
				@update:modelValue="update('actions', $event)" />
			<InlineFieldMark :error="markFor('actions')" />
		</fieldset>

		<fieldset class="index-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Sidebar') }}</legend>
			<label class="index-page-editor__inline">
				<input
					type="checkbox"
					:checked="sidebarEnabled"
					@change="onSidebarToggle($event.target.checked)">
				{{ t('openbuilt', 'Enabled') }}
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
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'IndexPageEditor',
	components: {
		ColumnBuilder,
		ActionBuilder,
		SidebarSectionBuilder,
		InlineFieldMark,
	},
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		// Current Application slug. Drives the hybrid register model so the
		// register picker hoists `openbuilt-{slug}` to the top of the list.
		appSlug: {
			type: String,
			default: '',
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
			return ['register', 'schema', 'cardComponent', 'columns', 'actions', 'sidebar']
		},
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
			// When register changes, clear schema dependency.
			if (key === 'register') {
				delete next.schema
			}
			this.$emit('update:config', next)
		},
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
		updateSidebar(key, value) {
			const next = { ...this.config }
			const current = (typeof next.sidebar === 'object' && next.sidebar) || { enabled: true }
			next.sidebar = { ...current, [key]: value }
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
