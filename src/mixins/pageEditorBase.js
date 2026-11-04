// SPDX-License-Identifier: EUPL-1.2
/**
 * What every page-type sub-editor shares: the `config` it edits, the host's
 * `parentRoute`, the app's slug, the `update:config` event and the lossless single-key update.
 *
 * @spec openspec/specs/form-editor-logic/spec.md
 */
export const pageEditorBaseMixin = {
	props: {
		config: {
			type: Object,
			default: () => ({}),
		},

		parentRoute: {
			type: String,
			default: '',
		},

		appSlug: {
			type: String,
			default: '',
		},
	},

	emits: ['update:config'],

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
	},
}

/**
 * The props of an editor that binds registers: the app's own register and the
 * registers its version declares.
 *
 * @spec openspec/specs/form-editor-logic/spec.md
 */
export const pageEditorRegisterPropsMixin = {
	props: {
		// The app's own register, read off the version being edited.
		appRegister: {
			type: String,
			default: '',
		},

		dataRegisters: {
			type: Array,
			default: () => [],
		},
	},
}

/**
 * The register and schema lists of an editor with a register picker: loaded through
 * `this.picker`, the registers once on mount.
 *
 * @spec openspec/specs/form-editor-logic/spec.md
 */
export const pageEditorPickerMixin = {
	data() {
		return {
			registers: [],
			schemas: [],
			schemaProperties: {},
		}
	},

	async mounted() {
		await this.fetchRegisters()
	},

	methods: {
		/**
		 * Fetch the registers list for the picker dropdown.
		 */
		async fetchRegisters() {
			this.registers = await this.picker.fetchRegisters()
		},

		/**
		 * Fetch the schemas in the bound register.
		 *
		 * @param {string} register - register slug.
		 */
		async fetchSchemas(register) {
			this.schemas = await this.picker.fetchSchemas(register)
		},

		/**
		 * Fetch schema properties for the field-mapping dropdowns.
		 *
		 * @param {string} register - register slug.
		 * @param {string} schema - schema slug.
		 */
		async fetchSchemaProperties(register, schema) {
			this.schemaProperties = await this.picker.fetchSchemaProperties(register, schema)
		},
	},
}

/**
 * Keeps the schema list on `config.register`: reloads when the bound register changes
 * (also on mount for an already-bound page), and empties it when the binding is cleared.
 *
 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
 */
export const pageEditorRegisterWatchMixin = {
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
	},
}

/**
 * Keeps the schema properties on `config.schema`: reloads for a bound register and
 * schema, and empties them otherwise.
 *
 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
 */
export const pageEditorSchemaWatchMixin = {
	watch: {
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
}

/**
 * Everything an editor bound to one register and schema needs on top of the base:
 * the register props, the picker lists and both watchers.
 *
 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-3
 */
export const pageEditorSchemaBoundMixin = {
	mixins: [
		pageEditorRegisterPropsMixin,
		pageEditorPickerMixin,
		pageEditorRegisterWatchMixin,
		pageEditorSchemaWatchMixin,
	],
}
