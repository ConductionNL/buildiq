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
