// SPDX-License-Identifier: EUPL-1.2
import { schemaListCacheMixin } from './schemaListCache.js'

/**
 * entryListMixin — the list mechanics the detail page's entry builders share
 * (RelatedCollectionBuilder, RelationLinkBuilder; pages-detail-related-items):
 * the bound array, the schema lists of every entry's register, and replacing,
 * editing or dropping one entry. Each builder keeps what is its own: its
 * fields, what a register or schema change clears, and its inline mark.
 *
 * Brings schemaListCacheMixin, whose `loadSchemas` it calls.
 *
 * @spec openspec/changes/pages-detail-related-items/tasks.md#task-T01
 */
export const entryListMixin = {
	mixins: [schemaListCacheMixin],

	props: {
		// The entries array (`relatedCollections` or `relationLinks`).
		modelValue: {
			type: Array,
			default: () => [],
		},

		// The registers the editor offers, as loaded by its picker.
		registers: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The entries as an array, whatever the bound value is.
		 *
		 * @return {Array<object>}
		 */
		localEntries() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},
	},

	watch: {
		localEntries: {
			immediate: true,
			handler(entries) {
				entries.forEach((entry) => this.loadSchemas(entry && entry.register))
			},
		},
	},

	methods: {
		/**
		 * Emit the list with one entry replaced.
		 *
		 * @param {number} index - position of the entry.
		 * @param {object} entry - its new value.
		 */
		replace(index, entry) {
			const next = this.localEntries.slice()
			next[index] = entry
			this.$emit('update:modelValue', next)
		},

		/**
		 * Write a text key of an entry; an empty value deletes it.
		 *
		 * @param {number} index - position of the entry.
		 * @param {string} key - the key, e.g. `title` or `fkField`.
		 * @param {string} value - the new value.
		 * @spec openspec/changes/pages-detail-related-items/tasks.md#task-T02
		 */
		updateField(index, key, value) {
			const rest = { ...(this.localEntries[index] || {}) }
			delete rest[key]
			this.replace(index, value === '' ? rest : { ...rest, [key]: value })
		},

		/**
		 * Apply a register or schema pick from the RegisterSchemaPicker.
		 *
		 * @param {number} index - position of the entry.
		 * @param {{key: string, value: string}} pick - what was picked.
		 */
		onPick(index, pick) {
			if (pick.key === 'register') {
				this.updateRegister(index, pick.value)
				return
			}
			this.updateSchema(index, pick.value)
		},

		/**
		 * Append one entry.
		 *
		 * @param {object} entry - the new entry.
		 */
		appendEntry(entry) {
			this.$emit('update:modelValue', [...this.localEntries, entry])
		},

		/**
		 * Drop one entry.
		 *
		 * @param {number} index - position of the entry.
		 */
		removeEntry(index) {
			const next = this.localEntries.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},
	},
}
