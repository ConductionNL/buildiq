// SPDX-License-Identifier: EUPL-1.2
/**
 * schemaListCache — mixin for a page-editor builder whose rows each pick a
 * register, a schema in it and a field of that schema.
 *
 * The owning editor passes its register picker (`useRegisterPicker`) as the
 * `picker` prop. Schemas are fetched once per register and kept in
 * `schemasByRegister`, so a builder with several rows on the same register
 * makes one request. The schema list already carries each schema's
 * `properties`, so the field lists need no second request.
 *
 * @spec openspec/changes/pages-detail-related-items/design.md
 */
export const schemaListCacheMixin = {
	props: {
		// The editor's register picker: `fetchSchemas(register)` is used.
		picker: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			schemasByRegister: {},
		}
	},

	methods: {
		/**
		 * Load the schemas of a register into the cache, once.
		 *
		 * @param {string} register - register slug; empty does nothing.
		 * @return {Promise<void>}
		 */
		async loadSchemas(register) {
			if (!register || register in this.schemasByRegister) {
				return
			}
			this.schemasByRegister = { ...this.schemasByRegister, [register]: [] }
			if (!this.picker || typeof this.picker.fetchSchemas !== 'function') {
				return
			}
			let list
			try {
				list = await this.picker.fetchSchemas(register)
			} catch {
				list = []
			}
			this.schemasByRegister = {
				...this.schemasByRegister,
				[register]: Array.isArray(list) ? list : [],
			}
		},

		/**
		 * The cached schemas of a register.
		 *
		 * @param {string} register - register slug.
		 * @return {Array<object>}
		 */
		schemasOf(register) {
			return this.schemasByRegister[register] || []
		},

		/**
		 * The property names of a schema in a register, in declaration order.
		 *
		 * @param {string} register - register slug.
		 * @param {string} schema - schema slug or id.
		 * @return {string[]}
		 */
		fieldsOf(register, schema) {
			if (!schema) {
				return []
			}
			const match = this.schemasOf(register).find(
				(s) => s && (String(s.slug) === String(schema) || String(s.id) === String(schema)),
			)
			const properties = (match && match.properties) || {}
			return Object.keys(properties)
		},
	},
}
