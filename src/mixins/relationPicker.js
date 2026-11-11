// SPDX-License-Identifier: EUPL-1.2

const CARDINALITIES = ['one', 'many']

/**
 * The relation pickers of the schema editors (a field of type relation in
 * FieldEditor, a schema-level relation in RelationEditor): the `schemaSlugs`
 * prop a relation can target, the target-schema options over it, the
 * translated cardinality options, and the option a stored value selects.
 *
 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-4
 */
export const relationPickerMixin = {
	props: {
		// The schema slugs a relation can target.
		schemaSlugs: { type: Array, default: () => [] },
	},

	computed: {
		/**
		 * Build target-schema picker options from the available slugs.
		 *
		 * @return {Array} Option objects.
		 */
		schemaOptions() {
			return this.schemaSlugs.map((slug) => ({ value: slug, label: slug }))
		},

		/**
		 * Build cardinality picker options (one/many) with translated labels.
		 *
		 * @return {Array} Option objects.
		 */
		cardinalityOptions() {
			return CARDINALITIES.map((value) => ({
				value,
				label:
					value === 'one'
						? this.t('buildiq', 'One')
						: this.t('buildiq', 'Many'),
			}))
		},
	},

	methods: {
		/**
		 * Resolve the selected target-schema option for a value.
		 *
		 * @param {string} value Schema slug.
		 * @return {object|null} The matching option.
		 */
		schemaOption(value) {
			return this.schemaOptions.find((o) => o.value === value) || null
		},

		/**
		 * Resolve the selected cardinality option for a value.
		 *
		 * @param {string} value Cardinality.
		 * @return {object} The matching option (defaults to first).
		 */
		cardinalityOption(value) {
			return (
				this.cardinalityOptions.find((o) => o.value === value)
				|| this.cardinalityOptions[0]
			)
		},
	},
}
