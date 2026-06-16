// SPDX-License-Identifier: EUPL-1.2
/**
 * pageEditorValidation — mixin every page-type sub-editor uses to wire
 * itself into `useManifestValidator`'s inline-mark machinery (task 5.5 /
 * REQ-OBPD-011).
 *
 * PageDesigner `provide()`s a `pageEditorValidator` object shaped:
 *   {
 *     register(configKey)   — register the JSON-Pointer prefix
 *                             `/pages/<selected>/config/<configKey>`,
 *     unregister(configKey) — drop that prefix,
 *     errorFor(configKey)   — { hasError, message } for that prefix,
 *   }
 *
 * The sub-editor declares `validatedConfigKeys` (an array of the
 * top-level config keys it surfaces); on `mounted` the mixin registers
 * them all, on `beforeDestroy` it unregisters them. Templates call
 * `markFor(key)` to get the `{ hasError, message }` bag for an
 * `<InlineFieldMark>` and `isInvalid(key)` for the `aria-invalid` binding.
 *
 * The injection is OPTIONAL (`default: null`) so each sub-editor still
 * mounts standalone in its unit spec without a provider.
 */
export const pageEditorValidationMixin = {
	inject: {
		pageEditorValidator: {
			default: null,
		},
	},
	mounted() {
		const v = this.pageEditorValidator
		if (!v || typeof v.register !== 'function') {
			return
		}
		for (const key of this.validatedConfigKeys || []) {
			v.register(key)
		}
	},
	beforeDestroy() {
		const v = this.pageEditorValidator
		if (!v || typeof v.unregister !== 'function') {
			return
		}
		for (const key of this.validatedConfigKeys || []) {
			v.unregister(key)
		}
	},
	computed: {
		// Subclasses override this list with the config keys they edit.
		validatedConfigKeys() {
			return []
		},
	},
	methods: {
		/**
		 * The `{ hasError, message }` bag for a config key, or null when
		 * there is no provider (standalone test mount) or no error.
		 *
		 * @param {string} key - top-level config key.
		 * @return {{hasError: boolean, message: string}|null}
		 */
		markFor(key) {
			const v = this.pageEditorValidator
			if (!v || typeof v.errorFor !== 'function') {
				return null
			}
			return v.errorFor(key)
		},
		/**
		 * Boolean helper for the `aria-invalid` attribute binding.
		 *
		 * @param {string} key - top-level config key.
		 * @return {boolean}
		 */
		isInvalid(key) {
			const m = this.markFor(key)
			return !!(m && m.hasError)
		},
	},
}
