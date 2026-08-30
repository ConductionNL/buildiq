<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - DetailPageEditor — register + schema picker, route-param derivation
  - from parent page route, sidebar config (boolean OR object shape both
  - supported), `sidebarProps.tabs` list. Implements REQ-OBPD-005.
  -->
<template>
	<div class="detail-page-editor">
		<h3 class="detail-page-editor__title">
			{{ t('buildiq', 'Detail page') }}
		</h3>
		<div class="detail-page-editor__group">
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
		</div>

		<p v-if="!routeHasParam" class="detail-page-editor__warn" role="alert">
			{{
				t(
					'buildiq',
					'The parent page route has no :param segment — detail pages typically need one (e.g. /messages/:id).',
				)
			}}
		</p>
		<p v-else class="detail-page-editor__note">
			{{ t('buildiq', 'Route params detected:') }}
			{{ routeParams.join(', ') }}
		</p>

		<fieldset class="detail-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Sidebar') }}</legend>
			<div class="detail-page-editor__sidebar-shape">
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'object'"
						value="object"
						@change="setSidebarShape('object')" />
					{{ t('buildiq', 'Object form (preferred)') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'boolean'"
						value="boolean"
						@change="setSidebarShape('boolean')" />
					{{ t('buildiq', 'Boolean form (legacy)') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="radio"
						:checked="sidebarShape === 'none'"
						value="none"
						@change="setSidebarShape('none')" />
					{{ t('buildiq', 'Not set') }}
				</label>
			</div>
			<label
				v-if="sidebarShape === 'boolean'"
				class="detail-page-editor__inline">
				<input
					type="checkbox"
					:checked="config.sidebar === true"
					@change="update('sidebar', $event.target.checked)" />
				{{ t('buildiq', 'Sidebar enabled') }}
			</label>
			<div
				v-else-if="sidebarShape === 'object'"
				class="detail-page-editor__sidebar-object">
				<label class="detail-page-editor__inline">
					<input
						type="checkbox"
						:checked="(config.sidebar || {}).enabled !== false"
						@change="
							updateSidebarKey('enabled', $event.target.checked)
						" />
					{{ t('buildiq', 'Enabled') }}
				</label>
				<label class="detail-page-editor__inline">
					<input
						type="checkbox"
						:checked="(config.sidebar || {}).show !== false"
						@change="updateSidebarKey('show', $event.target.checked)" />
					{{ t('buildiq', 'Show') }}
				</label>
				<SidebarTabBuilder
					:modelValue="(config.sidebar && config.sidebar.tabs) || []"
					@update:modelValue="updateSidebarKey('tabs', $event)" />
			</div>
			<InlineFieldMark :error="markFor('sidebar')" />
		</fieldset>

		<fieldset class="detail-page-editor__fieldset">
			<legend>
				{{ t('buildiq', 'sidebarProps.tabs (alternate path)') }}
			</legend>
			<SidebarTabBuilder
				:modelValue="(config.sidebarProps && config.sidebarProps.tabs) || []"
				@update:modelValue="updateSidebarPropsTabs($event)" />
			<InlineFieldMark :error="markFor('sidebarProps')" />
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import SidebarTabBuilder from './fields/SidebarTabBuilder.vue'
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
			default: 'detail',
		},
	},

	emits: ['update:config'],
	/**
	 * Build the register/schema picker for this editor. Options-API `data`
	 * cannot see props at construction time, so the picker is created here
	 * from the resolved props and exposed as `this.picker`.
	 *
	 * @param {{appSlug: string, dataRegisters: Array<{register: string, label?: string}>, config: object, pageType: string, parentRoute: string}} props - the resolved component props; only `appSlug` (hoists `buildiq-{slug}` in the register list) and `dataRegisters` (labels/hoists the Application's declared bindings) are read.
	 * @return {{picker: object}} - bindings merged into the instance; `picker` exposes fetchRegisters/fetchSchemas.
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
			const matches =
				this.parentRoute.match(/:([A-Za-z_][A-Za-z0-9_]*)/g) || []
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
		 * @param {string} key - the config key being written: `register`, `schema`, or `sidebar` in its legacy boolean form. Writing `register` also drops `schema`, since a schema is only meaningful inside its register.
		 * @param {string|boolean} value - the new value: a slug from a `<select>`, or the checkbox state for the boolean-shaped sidebar. An empty string or `null` deletes the key; `false` is stored, so `sidebar: false` survives as an explicit "off".
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
		 * Switch `config.sidebar` between the three shapes the manifest
		 * accepts. Switching discards whatever the previous shape held — the
		 * object form's keys do not survive a trip through `boolean`/`none`.
		 *
		 * @param {'none'|'boolean'|'object'} shape - the radio's value: `none` deletes `sidebar`, `boolean` writes the legacy `true`, `object` seeds the preferred `{ enabled: true }`.
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
		 * Write one key inside the object-shaped sidebar, promoting a legacy
		 * boolean `sidebar: true` to `{ enabled: true }` on the way.
		 *
		 * @param {string} key - the sidebar key being written: `enabled`, `show` or `tabs`.
		 * @param {boolean|Array<object>} value - the checkbox state for `enabled`/`show`, or the rebuilt tab list from SidebarTabBuilder for `tabs`. Falsy values are stored, not deleted, so `enabled: false` is preserved as an explicit "off".
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
		 */
		updateSidebarKey(key, value) {
			const next = { ...this.config }
			const current = (typeof next.sidebar === 'object' && next.sidebar) || {
				enabled: true,
			}
			next.sidebar = { ...current, [key]: value }
			this.$emit('update:config', next)
		},

		/**
		 * Write the alternate `config.sidebarProps.tabs` path. Emptying the
		 * list removes just the `tabs` key, and removes `sidebarProps`
		 * entirely when nothing else lived there — so a manifest that never
		 * used this path round-trips byte-identically.
		 *
		 * @param {Array<{id: string, label: string, icon?: string, component?: string}>} tabs - the rebuilt tab list from SidebarTabBuilder; empty or missing means "no tabs".
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
		 * Load the schemas of one register into the schema dropdown.
		 *
		 * @param {string} register - slug of the register to list schemas for, i.e. `config.register`.
		 * @return {Promise<void>} - resolves once `this.schemas` holds the result (`[]` when the request fails).
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
