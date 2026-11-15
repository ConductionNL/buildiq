<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - SavedViewsBuilder — authors an index page's `config.defaultSavedViews`:
  - views the maker defines for every app user of the page. Each view is
  - `{ slug, name, filters, sort }` (nextcloud-vue CnIndexPage
  - `defaultSavedViews`): `filters` maps a schema property to the value it
  - must have, `sort` is `[{ key, order }]`. The views travel with the app's
  - manifest, so every user sees the same set and none can delete one.
  -->
<template>
	<div class="saved-views-builder">
		<div
			v-for="(view, index) in views"
			:key="view.slug || index"
			class="saved-views-builder__view"
			role="group"
			:aria-label="
				view.name || t('buildiq', 'View {position}', { position: index + 1 })
			">
			<label class="saved-views-builder__field">
				{{ t('buildiq', 'View name') }}
				<input
					type="text"
					:value="view.name || ''"
					@input="setName(index, $event.target.value)" />
			</label>

			<div
				v-for="([field, value], filterIndex) in filterEntries(view)"
				:key="filterIndex"
				class="saved-views-builder__row">
				<label class="saved-views-builder__field">
					{{ t('buildiq', 'Filter on') }}
					<select
						:value="field"
						@change="setFilterField(index, field, $event.target.value)">
						<option v-for="key in propertyKeys" :key="key" :value="key">
							{{ key }}
						</option>
					</select>
				</label>
				<label class="saved-views-builder__field">
					{{ t('buildiq', 'Value') }}
					<input
						type="text"
						:value="
							value === undefined || value === null
								? ''
								: String(value)
						"
						@input="setFilterValue(index, field, $event.target.value)" />
				</label>
				<button
					type="button"
					class="saved-views-builder__remove"
					:title="t('buildiq', 'Remove filter')"
					:aria-label="t('buildiq', 'Remove filter')"
					@click="removeFilter(index, field)">
					✕
				</button>
			</div>
			<button
				type="button"
				:disabled="!unusedKey(view)"
				@click="addFilter(index)">
				+ {{ t('buildiq', 'Add filter') }}
			</button>

			<div class="saved-views-builder__row">
				<label class="saved-views-builder__field">
					{{ t('buildiq', 'Sort by') }}
					<select
						:value="sortKey(view)"
						@change="
							setSort(index, $event.target.value, sortOrder(view))
						">
						<option value="">
							{{ t('buildiq', '— no sort —') }}
						</option>
						<option v-for="key in sortableKeys" :key="key" :value="key">
							{{ key }}
						</option>
					</select>
				</label>
				<label class="saved-views-builder__field">
					{{ t('buildiq', 'Order') }}
					<select
						:value="sortOrder(view)"
						:disabled="!sortKey(view)"
						@change="setSort(index, sortKey(view), $event.target.value)">
						<option value="asc">
							{{ t('buildiq', 'Ascending') }}
						</option>
						<option value="desc">
							{{ t('buildiq', 'Descending') }}
						</option>
					</select>
				</label>
			</div>

			<button
				type="button"
				class="saved-views-builder__remove-view"
				@click="removeView(index)">
				{{ t('buildiq', 'Remove view') }}
			</button>
		</div>
		<button type="button" class="saved-views-builder__add" @click="addView">
			+ {{ t('buildiq', 'Add view for everyone') }}
		</button>
	</div>
</template>

<script>
// Metadata a view may sort on besides the schema's own properties.
const SORTABLE_METADATA = ['@self.created', '@self.updated']

export default {
	name: 'SavedViewsBuilder',
	props: {
		/** The page's `config.defaultSavedViews`. */
		modelValue: { type: Array, default: () => [] },
		/** The bound schema's JSON-Schema `properties` map. */
		schemaProperties: { type: Object, default: () => ({}) },
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The views as authored, without entries that are not objects.
		 *
		 * @return {Array<object>}
		 */
		views() {
			return (Array.isArray(this.modelValue) ? this.modelValue : []).filter(
				(view) => view && typeof view === 'object',
			)
		},

		/**
		 * The schema's property keys a view can filter on.
		 *
		 * @return {string[]}
		 */
		propertyKeys() {
			return Object.keys(this.schemaProperties || {})
		},

		/**
		 * The keys a view can sort on: the schema's properties and the
		 * record's creation and change dates.
		 *
		 * @return {string[]}
		 */
		sortableKeys() {
			return [...this.propertyKeys, ...SORTABLE_METADATA]
		},
	},

	methods: {
		/**
		 * A view's filters as `[field, value]` pairs, in stored order.
		 *
		 * @param {object} view - one view.
		 * @return {Array<Array>}
		 */
		filterEntries(view) {
			const filters = view.filters
			return filters && typeof filters === 'object' && !Array.isArray(filters)
				? Object.entries(filters)
				: []
		},

		/**
		 * The first property a view does not filter on yet.
		 *
		 * @param {object} view - one view.
		 * @return {string} the key, or '' when every property is used.
		 */
		unusedKey(view) {
			const used = new Set(this.filterEntries(view).map(([field]) => field))
			return this.propertyKeys.find((key) => !used.has(key)) || ''
		},

		/**
		 * The key a view sorts on first, or ''.
		 *
		 * @param {object} view - one view.
		 * @return {string}
		 */
		sortKey(view) {
			const first = Array.isArray(view.sort) ? view.sort[0] : null
			return (first && first.key) || ''
		},

		/**
		 * The direction a view sorts in.
		 *
		 * @param {object} view - one view.
		 * @return {string} `asc` or `desc`.
		 */
		sortOrder(view) {
			const first = Array.isArray(view.sort) ? view.sort[0] : null
			return first && first.order === 'desc' ? 'desc' : 'asc'
		},

		/**
		 * Emit the list with one view replaced.
		 *
		 * @param {number} index - the view's position.
		 * @param {object} view - its new value.
		 * @return {void}
		 */
		replace(index, view) {
			const next = this.views.slice()
			next[index] = view
			this.$emit('update:modelValue', next)
		},

		/**
		 * Add an unnamed view with a slug no other view of the page has.
		 *
		 * @return {void}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t05
		 */
		addView() {
			const taken = new Set(this.views.map((view) => view.slug))
			let n = this.views.length + 1
			while (taken.has(`view-${n}`)) {
				n++
			}
			this.$emit('update:modelValue', [
				...this.views,
				{ slug: `view-${n}`, name: '', filters: {}, sort: [] },
			])
		},

		/**
		 * Drop a view.
		 *
		 * @param {number} index - the view's position.
		 * @return {void}
		 */
		removeView(index) {
			const next = this.views.slice()
			next.splice(index, 1)
			this.$emit('update:modelValue', next)
		},

		/**
		 * Rename a view. Its slug stays, so a rename keeps the view the same.
		 *
		 * @param {number} index - the view's position.
		 * @param {string} name - the new name.
		 * @return {void}
		 */
		setName(index, name) {
			this.replace(index, { ...this.views[index], name })
		},

		/**
		 * Add a filter on the first property the view does not filter on.
		 *
		 * @param {number} index - the view's position.
		 * @return {void}
		 */
		addFilter(index) {
			const view = this.views[index]
			const key = this.unusedKey(view)
			if (!key) {
				return
			}
			this.replace(index, {
				...view,
				filters: { ...(view.filters || {}), [key]: '' },
			})
		},

		/**
		 * Move a filter to another property, keeping its value and its place.
		 *
		 * @param {number} index - the view's position.
		 * @param {string} from - the property it filtered on.
		 * @param {string} to - the property it filters on now.
		 * @return {void}
		 */
		setFilterField(index, from, to) {
			const view = this.views[index]
			const filters = {}
			for (const [field, value] of this.filterEntries(view)) {
				if (field === from) {
					filters[to] = value
				} else if (field !== to) {
					filters[field] = value
				}
			}
			this.replace(index, { ...view, filters })
		},

		/**
		 * Set the value a filter requires.
		 *
		 * @param {number} index - the view's position.
		 * @param {string} field - the filtered property.
		 * @param {string} value - the value.
		 * @return {void}
		 */
		setFilterValue(index, field, value) {
			const view = this.views[index]
			this.replace(index, {
				...view,
				filters: { ...(view.filters || {}), [field]: value },
			})
		},

		/**
		 * Drop one filter of a view.
		 *
		 * @param {number} index - the view's position.
		 * @param {string} field - the filtered property.
		 * @return {void}
		 */
		removeFilter(index, field) {
			const view = this.views[index]
			const filters = { ...(view.filters || {}) }
			delete filters[field]
			this.replace(index, { ...view, filters })
		},

		/**
		 * Set a view's sort; an empty key removes it.
		 *
		 * @param {number} index - the view's position.
		 * @param {string} key - the key to sort on, or ''.
		 * @param {string} order - `asc` or `desc`.
		 * @return {void}
		 * @spec openspec/changes/data-index-grid-and-saved-views/tasks.md#t05
		 */
		setSort(index, key, order) {
			const sort = key
				? [{ key, order: order === 'desc' ? 'desc' : 'asc' }]
				: []
			this.replace(index, { ...this.views[index], sort })
		},
	},
}
</script>

<style scoped lang="scss">
@use '../../../assets/editor-controls.scss' as c;

.saved-views-builder {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.saved-views-builder__view {
	display: flex;
	flex-direction: column;
	gap: 6px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.saved-views-builder__row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: flex-end;
}

.saved-views-builder__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: 13px;
}

.saved-views-builder__field input,
.saved-views-builder__field select {
	@include c.control;
}

.saved-views-builder__remove-view {
	align-self: flex-start;
}
</style>
