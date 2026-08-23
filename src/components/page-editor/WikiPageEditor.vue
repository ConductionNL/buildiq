<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - WikiPageEditor — structured editor for `type: "wiki"` pages
  - (REQ-PEC-006).
  -
  - Manifest contract documented key-by-key in the canonical v2 schema's
  - `$defs.page.properties.config` and verified against `CnWikiPage.vue`:
  - **required** `register` + `schema` (the enum description mandates both
  - for wiki pages — marked invalid when empty), article field-mapping
  - dropdowns backed by the bound schema's properties for `contentField`
  - (default `body`), `titleField` (default `title`) and a free-text
  - `idParam` (default `id`), a sidebar fieldset (`sidebarRegister` /
  - `sidebarSchema` picker dropdowns plus `treeField` / `sidebarTitleField`
  - schema-property dropdowns), and empty-state text inputs (`emptyText`,
  - `emptyDescription`, `emptyBodyText`, `emptyBodyDescription`). Defaults
  - are shown as placeholder text, never written, so an untouched field
  - emits no key (lossless minimal config).
  -
  - Lossless round-trip: `update(key, value)` clones `config` and only
  - touches the one key (plus the `schema` partner-clear on a register
  - change), so externally-authored keys this editor doesn't surface
  - survive every edit.
  -->
<template>
	<div class="wiki-page-editor">
		<h3 class="wiki-page-editor__title">
			{{ t('buildiq', 'Wiki page') }}
		</h3>

		<fieldset class="wiki-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Article source (required)') }}</legend>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Register') }}
				<select
					:value="config.register || ''"
					:aria-invalid="registerMark.hasError"
					@change="updateRegister($event.target.value)">
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
				<InlineFieldMark :error="registerMark" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Schema') }}
				<select
					:value="config.schema || ''"
					:disabled="!config.register"
					:aria-invalid="schemaMark.hasError"
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
				<InlineFieldMark :error="schemaMark" />
			</label>
		</fieldset>

		<fieldset class="wiki-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Article field mapping') }}</legend>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Content field') }}
				<select
					v-if="hasBoundSchema"
					:value="config.contentField || ''"
					@change="update('contentField', $event.target.value)">
					<option value="">
						{{ t('buildiq', '— default: body —') }}
					</option>
					<option
						v-for="key in schemaPropertyKeys"
						:key="key"
						:value="key">
						{{ key }}
					</option>
				</select>
				<input
					v-else
					type="text"
					:value="config.contentField || ''"
					:placeholder="t('buildiq', 'body')"
					@input="update('contentField', $event.target.value)" />
				<InlineFieldMark :error="markFor('contentField')" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Title field') }}
				<select
					v-if="hasBoundSchema"
					:value="config.titleField || ''"
					@change="update('titleField', $event.target.value)">
					<option value="">
						{{ t('buildiq', '— default: title —') }}
					</option>
					<option
						v-for="key in schemaPropertyKeys"
						:key="key"
						:value="key">
						{{ key }}
					</option>
				</select>
				<input
					v-else
					type="text"
					:value="config.titleField || ''"
					:placeholder="t('buildiq', 'title')"
					@input="update('titleField', $event.target.value)" />
				<InlineFieldMark :error="markFor('titleField')" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Route id param') }}
				<input
					type="text"
					:value="config.idParam || ''"
					:placeholder="t('buildiq', 'id')"
					@input="update('idParam', $event.target.value)" />
				<InlineFieldMark :error="markFor('idParam')" />
			</label>
		</fieldset>

		<fieldset class="wiki-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Sidebar tree (optional)') }}</legend>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Sidebar register') }}
				<select
					:value="config.sidebarRegister || ''"
					@change="updateSidebarRegister($event.target.value)">
					<option value="">
						{{ t('buildiq', '— defaults to article register —') }}
					</option>
					<option
						v-for="r in registers"
						:key="r.slug || r.id"
						:value="r.slug">
						{{ r.title || r.slug }}
					</option>
				</select>
				<InlineFieldMark :error="markFor('sidebarRegister')" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Sidebar schema') }}
				<select
					:value="config.sidebarSchema || ''"
					:disabled="!(config.sidebarRegister || config.register)"
					@change="update('sidebarSchema', $event.target.value)">
					<option value="">
						{{ t('buildiq', '— select schema —') }}
					</option>
					<option
						v-for="s in sidebarSchemas"
						:key="s.slug || s.id"
						:value="s.slug">
						{{ s.title || s.slug }}
					</option>
				</select>
				<InlineFieldMark :error="markFor('sidebarSchema')" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Tree children field') }}
				<select
					v-if="hasBoundSidebarSchema"
					:value="config.treeField || ''"
					@change="update('treeField', $event.target.value)">
					<option value="">
						{{ t('buildiq', '— default: children —') }}
					</option>
					<option
						v-for="key in sidebarSchemaPropertyKeys"
						:key="key"
						:value="key">
						{{ key }}
					</option>
				</select>
				<input
					v-else
					type="text"
					:value="config.treeField || ''"
					:placeholder="t('buildiq', 'children')"
					@input="update('treeField', $event.target.value)" />
				<InlineFieldMark :error="markFor('treeField')" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Sidebar title field') }}
				<select
					v-if="hasBoundSidebarSchema"
					:value="config.sidebarTitleField || ''"
					@change="update('sidebarTitleField', $event.target.value)">
					<option value="">
						{{ t('buildiq', '— default: title field —') }}
					</option>
					<option
						v-for="key in sidebarSchemaPropertyKeys"
						:key="key"
						:value="key">
						{{ key }}
					</option>
				</select>
				<input
					v-else
					type="text"
					:value="config.sidebarTitleField || ''"
					@input="update('sidebarTitleField', $event.target.value)" />
				<InlineFieldMark :error="markFor('sidebarTitleField')" />
			</label>
		</fieldset>

		<fieldset class="wiki-page-editor__fieldset">
			<legend>{{ t('buildiq', 'Empty states (optional)') }}</legend>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Not-found heading') }}
				<input
					type="text"
					:value="config.emptyText || ''"
					@input="update('emptyText', $event.target.value)" />
				<InlineFieldMark :error="markFor('emptyText')" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'Not-found description') }}
				<input
					type="text"
					:value="config.emptyDescription || ''"
					@input="update('emptyDescription', $event.target.value)" />
				<InlineFieldMark :error="markFor('emptyDescription')" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'No-content heading') }}
				<input
					type="text"
					:value="config.emptyBodyText || ''"
					@input="update('emptyBodyText', $event.target.value)" />
				<InlineFieldMark :error="markFor('emptyBodyText')" />
			</label>
			<label class="wiki-page-editor__group-row">
				{{ t('buildiq', 'No-content description') }}
				<input
					type="text"
					:value="config.emptyBodyDescription || ''"
					@input="update('emptyBodyDescription', $event.target.value)" />
				<InlineFieldMark :error="markFor('emptyBodyDescription')" />
			</label>
		</fieldset>
	</div>
</template>

<script>
import InlineFieldMark from './fields/InlineFieldMark.vue'
import { useRegisterPicker } from '../../composables/useRegisterPicker.js'
import { pageEditorValidationMixin } from '../../mixins/pageEditorValidation.js'

export default {
	name: 'WikiPageEditor',
	components: { InlineFieldMark },
	mixins: [pageEditorValidationMixin],
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},

		pageType: {
			type: String,
			default: 'wiki',
		},

		appSlug: {
			type: String,
			default: '',
		},

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
			sidebarSchemas: [],
			sidebarSchemaProperties: {},
		}
	},

	computed: {
		validatedConfigKeys() {
			return [
				'register',
				'schema',
				'contentField',
				'titleField',
				'idParam',
				'treeField',
				'sidebarTitleField',
				'sidebarRegister',
				'sidebarSchema',
				'emptyText',
				'emptyDescription',
				'emptyBodyText',
				'emptyBodyDescription',
			]
		},

		hasBoundSchema() {
			return !!(this.config.register && this.config.schema)
		},

		schemaPropertyKeys() {
			return Object.keys(this.schemaProperties || {})
		},

		effectiveSidebarRegister() {
			return this.config.sidebarRegister || this.config.register || ''
		},

		hasBoundSidebarSchema() {
			return !!(this.effectiveSidebarRegister && this.config.sidebarSchema)
		},

		sidebarSchemaPropertyKeys() {
			return Object.keys(this.sidebarSchemaProperties || {})
		},

		/**
		 * `register` is REQUIRED per the canonical v2 enum description —
		 * mark invalid when empty even before the shared validator has a
		 * chance to register a real error for it (REQ-PEC-007 scenario
		 * "Wiki register and schema are marked invalid when empty").
		 *
		 * @return {{hasError: boolean, message: string}}
		 *
		 * @spec openspec/specs/form-editor-logic/spec.md
		 */
		registerMark() {
			const shared = this.markFor('register')
			if (shared && shared.hasError) {
				return shared
			}
			if (!this.config.register) {
				return {
					hasError: true,
					message: this.t(
						'buildiq',
						'A register is required for wiki pages.',
					),
				}
			}
			return shared || { hasError: false, message: '' }
		},

		/**
		 * Same required-field treatment as `registerMark`, for `schema`.
		 *
		 * @return {{hasError: boolean, message: string}}
		 *
		 * @spec openspec/specs/form-editor-logic/spec.md
		 */
		schemaMark() {
			const shared = this.markFor('schema')
			if (shared && shared.hasError) {
				return shared
			}
			if (!this.config.schema) {
				return {
					hasError: true,
					message: this.t(
						'buildiq',
						'A schema is required for wiki pages.',
					),
				}
			}
			return shared || { hasError: false, message: '' }
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

		effectiveSidebarRegister: {
			immediate: true,
			handler(val) {
				if (val) {
					this.fetchSidebarSchemas(val)
				} else {
					this.sidebarSchemas = []
				}
			},
		},

		'config.sidebarSchema': {
			immediate: true,
			handler(val) {
				if (val && this.effectiveSidebarRegister) {
					this.fetchSidebarSchemaProperties(
						this.effectiveSidebarRegister,
						val,
					)
				} else {
					this.sidebarSchemaProperties = {}
				}
			},
		},
	},

	async mounted() {
		await this.fetchRegisters()
	},

	methods: {
		/**
		 * Lossless top-level `config` key update: clone, delete-on-empty,
		 * touch only the edited key.
		 *
		 * @param {string} key - top-level config key.
		 * @param {*} value - new value.
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
			this.$emit('update:config', next)
		},

		/**
		 * Register-picker change handler: writes `register` and resets the
		 * dependent `schema` dropdown (same partner-clear as LogsPageEditor).
		 *
		 * @param {string} value - register slug.
		 */
		updateRegister(value) {
			const next = { ...this.config }
			delete next.schema
			if (value === '' || value === null) {
				delete next.register
			} else {
				next.register = value
			}
			this.$emit('update:config', next)
		},

		/**
		 * Sidebar-register change handler: writes `sidebarRegister` and
		 * resets the dependent `sidebarSchema` dropdown.
		 *
		 * @param {string} value - register slug.
		 */
		updateSidebarRegister(value) {
			const next = { ...this.config }
			delete next.sidebarSchema
			if (value === '' || value === null) {
				delete next.sidebarRegister
			} else {
				next.sidebarRegister = value
			}
			this.$emit('update:config', next)
		},

		/**
		 * Fetch the registers list for the picker dropdowns.
		 */
		async fetchRegisters() {
			this.registers = await this.picker.fetchRegisters()
		},

		/**
		 * Fetch the schemas in the bound article register.
		 *
		 * @param {string} register - register slug.
		 */
		async fetchSchemas(register) {
			this.schemas = await this.picker.fetchSchemas(register)
		},

		/**
		 * Fetch schema properties for the article field-mapping dropdowns.
		 *
		 * @param {string} register - register slug.
		 * @param {string} schema - schema slug.
		 */
		async fetchSchemaProperties(register, schema) {
			this.schemaProperties = await this.picker.fetchSchemaProperties(
				register,
				schema,
			)
		},

		/**
		 * Fetch the schemas in the (effective) sidebar register.
		 *
		 * @param {string} register - register slug.
		 */
		async fetchSidebarSchemas(register) {
			this.sidebarSchemas = await this.picker.fetchSchemas(register)
		},

		/**
		 * Fetch schema properties for the sidebar field-mapping dropdowns.
		 *
		 * @param {string} register - register slug.
		 * @param {string} schema - schema slug.
		 */
		async fetchSidebarSchemaProperties(register, schema) {
			this.sidebarSchemaProperties = await this.picker.fetchSchemaProperties(
				register,
				schema,
			)
		},
	},
}
</script>

<style scoped>
.wiki-page-editor {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.wiki-page-editor__title {
	margin: 0;
	font-size: 16px;
	font-weight: 600;
}

.wiki-page-editor__fieldset {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.wiki-page-editor__fieldset legend {
	padding: 0 6px;
	font-weight: 600;
	font-size: 13px;
}

.wiki-page-editor__group-row {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.wiki-page-editor__group-row input,
.wiki-page-editor__group-row select {
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}
</style>
