<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DetailPageEditor — register + schema picker, route-param derivation
  - from parent page route, sidebar config (boolean OR object shape both
  - supported), `sidebarProps.tabs` list. Implements REQ-OBPD-005.
  -->
<template>
	<div class="detail-page-editor">
		<h3 class="detail-page-editor__title">
			{{ t('openbuilt', 'Detail page') }}
		</h3>
		<div class="detail-page-editor__group">
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
		</div>

		<p v-if="!routeHasParam" class="detail-page-editor__warn" role="alert">
			{{ t('openbuilt', 'The parent page route has no :param segment — detail pages typically need one (e.g. /messages/:id).') }}
		</p>
		<p v-else class="detail-page-editor__note">
			{{ t('openbuilt', 'Route params detected:') }} {{ routeParams.join(', ') }}
		</p>

		<fieldset class="detail-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'Sidebar') }}</legend>
			<div class="detail-page-editor__sidebar-shape">
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'object'"
						value="object"
						@change="setSidebarShape('object')">
					{{ t('openbuilt', 'Object form (preferred)') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'boolean'"
						value="boolean"
						@change="setSidebarShape('boolean')">
					{{ t('openbuilt', 'Boolean form (legacy)') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'none'"
						value="none"
						@change="setSidebarShape('none')">
					{{ t('openbuilt', 'Not set') }}
				</label>
			</div>
			<label v-if="sidebarShape === 'boolean'" class="detail-page-editor__inline">
				<input
					type="checkbox"
					:checked="config.sidebar === true"
					@change="update('sidebar', $event.target.checked)">
				{{ t('openbuilt', 'Sidebar enabled') }}
			</label>
			<div v-else-if="sidebarShape === 'object'" class="detail-page-editor__sidebar-object">
				<label class="detail-page-editor__inline">
					<input
						type="checkbox"
						:checked="(config.sidebar || {}).enabled !== false"
						@change="updateSidebarKey('enabled', $event.target.checked)">
					{{ t('openbuilt', 'Enabled') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="checkbox"
						:checked="(config.sidebar || {}).show !== false"
						@change="updateSidebarKey('show', $event.target.checked)">
					{{ t('openbuilt', 'Show') }}
				</label>
				<SidebarTabBuilder
					:model-value="(config.sidebar && config.sidebar.tabs) || []"
					@update:modelValue="updateSidebarKey('tabs', $event)" />
			</div>
			<InlineFieldMark :error="markFor('sidebar')" />
		</fieldset>

		<fieldset class="detail-page-editor__fieldset">
			<legend>{{ t('openbuilt', 'sidebarProps.tabs (alternate path)') }}</legend>
			<SidebarTabBuilder
				:model-value="(config.sidebarProps && config.sidebarProps.tabs) || []"
				@update:modelValue="updateSidebarPropsTabs($event)" />
			<InlineFieldMark :error="markFor('sidebarProps')" />
		</fieldset>
	</div>
</template>

<script>
import SidebarTabBuilder from './fields/SidebarTabBuilder.vue'
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'DetailPageEditor',
	components: { SidebarTabBuilder, InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},
		parentRoute: {
			type: String,
			default: '',
		},
		// Current Application slug. Drives the hybrid register model so the
		// register picker hoists `openbuilt-{slug}` to the top of the list.
		appSlug: {
			type: String,
			default: '',
		},
		pageType: {
			type: String,
			default: 'detail',
		},
	},
	emits: ['update:config'],
	/**
	 * Observed behaviour of `setup` (retrofit annotation).
	 *
	 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
	 */
	setup(props) {
		const picker = useRegisterPicker({ appSlug: props.appSlug })
		return { picker }
	},
	data() {
		return {
			registers: [],
			schemas: [],
		}
	},
	computed: {
		/**
		 * Observed behaviour of `validatedConfigKeys` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		validatedConfigKeys() {
			return ['register', 'schema', 'sidebar', 'sidebarProps']
		},
		/**
		 * Observed behaviour of `routeParams` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		routeParams() {
			const matches = this.parentRoute.match(/:([A-Za-z_][A-Za-z0-9_]*)/g) || []
			return matches.map((m) => m.slice(1))
		},
		/**
		 * Observed behaviour of `routeHasParam` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		routeHasParam() {
			return this.routeParams.length > 0
		},
		/**
		 * Observed behaviour of `sidebarShape` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		sidebarShape() {
			const s = this.config.sidebar
			if (s === undefined) {
				return 'none'
			}
			if (typeof s === 'boolean') {
				return 'boolean'
			}
			return 'object'
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
			if (value === '' || value === null) {
				delete next[key]
			} else {
				next[key] = value
			}
			if (key === 'register') {
				delete next.schema
			}
			this.$emit('update:config', next)
		},
		/**
		 * Observed behaviour of `setSidebarShape` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		setSidebarShape(shape) {
			const next = { ...this.config }
			if (shape === 'none') {
				delete next.sidebar
			} else if (shape === 'boolean') {
				next.sidebar = true
			} else {
				next.sidebar = { enabled: true }
			}
			this.$emit('update:config', next)
		},
		/**
		 * Observed behaviour of `updateSidebarKey` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		updateSidebarKey(key, value) {
			const next = { ...this.config }
			const current = (typeof next.sidebar === 'object' && next.sidebar) || { enabled: true }
			next.sidebar = { ...current, [key]: value }
			this.$emit('update:config', next)
		},
		/**
		 * Observed behaviour of `updateSidebarPropsTabs` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		updateSidebarPropsTabs(tabs) {
			const next = { ...this.config }
			if (!tabs || !tabs.length) {
				if (next.sidebarProps) {
					const { tabs: _t, ...rest } = next.sidebarProps
					if (Object.keys(rest).length === 0) {
						delete next.sidebarProps
					} else {
						next.sidebarProps = rest
					}
				}
			} else {
				next.sidebarProps = { ...(next.sidebarProps || {}), tabs }
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
		 * Observed behaviour of `fetchSchemas` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		async fetchSchemas(register) {
			this.schemas = await this.picker.fetchSchemas(register)
		},
	},
}
</script>

<style scoped>
.detail-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.detail-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.detail-page-editor__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.detail-page-editor__group label {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.detail-page-editor__group select {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.detail-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
}

.detail-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.detail-page-editor__inline {
	display: inline-flex;
	gap: 6px;
	align-items: center;
	margin-right: 12px;
}

.detail-page-editor__sidebar-shape {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 8px;
}

.detail-page-editor__sidebar-object {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.detail-page-editor__warn {
	margin: 0;
	font-size: 12px;
	color: var(--color-warning, var(--color-text-maxcontrast));
	font-style: italic;
}

.detail-page-editor__note {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
