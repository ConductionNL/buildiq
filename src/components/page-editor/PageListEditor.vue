<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - PageListEditor — drag-reorder pages, add/remove, force page-type pick on
  - add (closed enum of 13 — REQ-PEC-002 adds map/roadmap/search/wiki),
  - enforce unique `id`, validate route-pattern grammar.
  - Implements REQ-OBPD-002.
  -->
<template>
	<section class="page-list-editor">
		<header class="page-list-editor__header">
			<h4>{{ t('buildiq', 'Pages') }}</h4>
			<button type="button" class="page-list-editor__add" @click="startAdd">
				+ {{ t('buildiq', 'Add page') }}
			</button>
		</header>
		<div v-if="addingType !== null" class="page-list-editor__add-row">
			<select
				v-model="addingType"
				class="page-list-editor__select"
				:aria-label="t('buildiq', 'Page type')">
				<option value="">
					{{ t('buildiq', 'Select a page type') }}
				</option>
				<option v-for="type in PAGE_TYPES" :key="type" :value="type">
					{{ type }}
				</option>
			</select>
			<input
				v-model="addingTitle"
				type="text"
				class="page-list-editor__field page-list-editor__add-title"
				:placeholder="t('buildiq', 'Title')"
				:aria-label="t('buildiq', 'Title')" />
			<input
				v-model="addingId"
				type="text"
				class="page-list-editor__field page-list-editor__add-id"
				:placeholder="t('buildiq', 'Slug')"
				:aria-label="t('buildiq', 'Slug')" />
			<button type="button" :disabled="!addingType" @click="confirmAdd">
				{{ t('buildiq', 'Confirm') }}
			</button>
			<button type="button" @click="cancelAdd">
				{{ t('buildiq', 'Cancel') }}
			</button>
		</div>
		<!-- vuedraggable v4 (Vue 3): the list is bound with v-model rather than
		     `:value`/`@input`, sortable options are plain props instead of an
		     `:options` object, and rows MUST come from the `#item` scoped slot —
		     a v-for in the default slot throws "draggable element must have an
		     item slot" at render.

		     Keep comments OUT of the `#item` slot: dev builds keep comment nodes
		     (production strips them), so one beside the row makes the slot yield
		     two children and vuedraggable rejects it.

		     The row uses `role="group"`, not `role="button"`, because it contains
		     interactive controls a button role would hide; `@focusin` selects it
		     so keyboard users get what the mouse always had. -->
		<Draggable
			:modelValue="pages"
			handle=".page-list-editor__drag-handle"
			:animation="150"
			itemKey="id"
			class="page-list-editor__list"
			@update:modelValue="onReorder">
			<template #item="{ element: page, index }">
				<div
					class="page-list-editor__row"
					:class="{
						'page-list-editor__row--selected': index === selectedIndex,
						'page-list-editor__row--error': hasError(page, index),
					}"
					role="group"
					:aria-label="
						t('buildiq', 'Page {position}', { position: index + 1 })
					"
					tabindex="-1"
					@click="$emit('select', index)"
					@focusin="$emit('select', index)"
					@keydown.enter="$emit('select', index)">
					<span
						class="page-list-editor__drag-handle"
						:title="t('buildiq', 'Drag to reorder')">
						⠿
					</span>
					<input
						:value="page.title || ''"
						type="text"
						class="page-list-editor__field page-list-editor__title"
						:placeholder="t('buildiq', 'Title')"
						:aria-label="t('buildiq', 'Title')"
						@click.stop
						@input="updateField(index, 'title', $event.target.value)" />
					<input
						:value="page.id || ''"
						type="text"
						class="page-list-editor__field"
						:placeholder="t('buildiq', 'page id')"
						:aria-label="t('buildiq', 'page id')"
						@click.stop
						@input="updateField(index, 'id', $event.target.value)" />
					<input
						:value="page.route || ''"
						type="text"
						class="page-list-editor__field"
						:placeholder="t('buildiq', '/route/:param')"
						:aria-label="t('buildiq', '/route/:param')"
						@click.stop
						@input="updateField(index, 'route', $event.target.value)" />
					<span class="page-list-editor__type-tag">{{ page.type }}</span>
					<!-- `.native` was removed in Vue 3; a plain listener falls
					     through to the component's root element via $attrs. -->
					<PermissionGroupField
						class="page-list-editor__permission"
						:permission="page.permission || ''"
						:knownGroups="knownGroups"
						@click.stop
						@update:permission="
							updateField(index, 'permission', $event || '')
						" />
					<button
						type="button"
						class="page-list-editor__remove"
						:title="t('buildiq', 'Remove page')"
						@click.stop="removePage(index)">
						✕
					</button>
				</div>
			</template>
		</Draggable>
		<p v-if="!pages.length" class="page-list-editor__empty">
			{{ t('buildiq', 'No pages yet. Click "Add page" to start.') }}
		</p>
		<p v-if="duplicateIds.length" class="page-list-editor__error" role="alert">
			{{ t('buildiq', 'Duplicate page ids:') }} {{ duplicateIds.join(', ') }}
		</p>
		<p
			v-if="duplicateRoutes.length"
			class="page-list-editor__error"
			role="alert">
			{{ t('buildiq', 'More than one page uses the route:') }}
			{{ duplicateRoutes.join(', ') }}
		</p>
		<p v-if="invalidRoutes.length" class="page-list-editor__error" role="alert">
			{{ t('buildiq', 'Invalid route(s):') }} {{ invalidRoutes.join(', ') }}
		</p>
	</section>
</template>

<script>
import Draggable from 'vuedraggable'
import PermissionGroupField from './fields/PermissionGroupField.vue'

export const PAGE_TYPES = [
	'index',
	'detail',
	'dashboard',
	'logs',
	'settings',
	'chat',
	'files',
	'form',
	'custom',
	'map',
	'roadmap',
	'search',
	'wiki',
]

export const ROUTE_PATTERN =
	/^\/$|^(\/[A-Za-z0-9_-]+|\/:[A-Za-z_][A-Za-z0-9_]*(\(.*\))?)+$/

const DEFAULT_CONFIGS = {
	index: { register: '', schema: '', columns: [], actions: [] },
	detail: { register: '', schema: '' },
	dashboard: { widgets: [], layout: [] },
	logs: { register: '', schema: '', columns: [] },
	settings: { sections: [] },
	chat: { conversationSource: '' },
	files: { folder: '' },
	form: { fields: [], submitMethod: 'POST', mode: 'public' },
	custom: {},
	map: { center: [52.1326, 5.2913], zoom: 7, layers: [], markers: {} },
	roadmap: {},
	search: { register: '', schema: '', facets: [] },
	wiki: { register: '', schema: '' },
}

/**
 * A page slug: lower case, words joined by dashes, route-safe.
 *
 * @param {string} value - what the user typed.
 * @return {string} the slug, or '' when nothing usable is left.
 */
function slugify(value) {
	return String(value || '')
		.trim()
		.toLowerCase()
		.replace(/[^a-z0-9_-]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

/**
 * `value`, or `value-2`, `value-3`… when `value` is already taken.
 *
 * @param {string} value - the preferred value.
 * @param {Set<string>} taken - values already in use.
 * @return {string} a value not in `taken`.
 */
function uniqueValue(value, taken) {
	if (!taken.has(value)) {
		return value
	}
	let n = 2
	while (taken.has(`${value}-${n}`)) {
		n++
	}
	return `${value}-${n}`
}

/**
 * A readable title for a new page of `type`, used when none is typed. The
 * running app shows a page title as written, so it must be words, not a
 * translation key.
 *
 * @param {string} type - the page type.
 * @return {string} the title.
 */
function defaultTitle(type) {
	return type.charAt(0).toUpperCase() + type.slice(1)
}

export default {
	name: 'PageListEditor',
	components: { Draggable, PermissionGroupField },
	props: {
		pages: {
			type: Array,
			default: () => [],
		},

		selectedIndex: {
			type: Number,
			default: -1,
		},
	},

	emits: ['update:pages', 'select'],
	data() {
		return {
			PAGE_TYPES,
			addingType: null,
			addingTitle: '',
			addingId: '',
		}
	},

	computed: {
		/**
		 * Group ids already referenced by any page's `permission` (spec
		 * `runtime-group-scoped-access`) — offered as quick-pick options.
		 *
		 * @return {string[]}
		 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
		 */
		knownGroups() {
			const gids = new Set()
			for (const page of this.pages) {
				const value =
					page && typeof page.permission === 'string'
						? page.permission
						: ''
				if (value.startsWith('group:')) {
					gids.add(value.slice('group:'.length))
				}
			}
			return Array.from(gids)
		},

		/**
		 * Observed behaviour of `duplicateIds` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		duplicateIds() {
			const counts = new Map()
			for (const p of this.pages) {
				if (p && p.id) {
					counts.set(p.id, (counts.get(p.id) || 0) + 1)
				}
			}
			return Array.from(counts.entries())
				.filter(([, c]) => c > 1)
				.map(([id]) => id)
		},

		/**
		 * Routes more than one page uses. Two pages on one route means only
		 * one of them can ever be reached.
		 *
		 * @return {string[]}
		 * @spec openspec/specs/openbuild-page-designer/spec.md#requirement-page-list-editor-with-uniqueness-and-route-pattern-validation
		 */
		duplicateRoutes() {
			const counts = new Map()
			for (const p of this.pages) {
				if (p && p.route) {
					counts.set(p.route, (counts.get(p.route) || 0) + 1)
				}
			}
			return Array.from(counts.entries())
				.filter(([, c]) => c > 1)
				.map(([route]) => route)
		},

		/**
		 * Observed behaviour of `invalidRoutes` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		invalidRoutes() {
			return this.pages
				.filter((p) => p && p.route && !ROUTE_PATTERN.test(p.route))
				.map((p) => p.route)
		},
	},

	methods: {
		/**
		 * Observed behaviour of `startAdd` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		startAdd() {
			this.addingType = ''
			this.addingTitle = ''
			this.addingId = ''
		},

		/**
		 * Observed behaviour of `cancelAdd` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		cancelAdd() {
			this.addingType = null
			this.addingTitle = ''
			this.addingId = ''
		},

		/**
		 * Observed behaviour of `confirmAdd` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		confirmAdd() {
			if (!this.addingType) {
				return
			}
			const type = this.addingType
			const next = this.pages.slice()
			const takenIds = new Set(next.map((p) => p && p.id))
			const takenRoutes = new Set(next.map((p) => p && p.route))
			const id = uniqueValue(
				slugify(this.addingId) || `${type}-page-${next.length + 1}`,
				takenIds,
			)
			// The first page on "/" is the home page; any later page gets its
			// own route, so it does not hide behind the one already there.
			const typedSlug = slugify(this.addingId)
			let route = uniqueValue(`/${typedSlug || type}`, takenRoutes)
			if (type === 'index' && !typedSlug && !takenRoutes.has('/')) {
				route = '/'
			}
			const title = this.addingTitle.trim() || defaultTitle(type)
			const placeholder = {
				id,
				route,
				type,
				title,
				config: JSON.parse(JSON.stringify(DEFAULT_CONFIGS[type] || {})),
			}
			next.push(placeholder)
			this.$emit('update:pages', next)
			this.$emit('select', next.length - 1)
			this.cancelAdd()
		},

		/**
		 * Write one scalar key on one page. Clearing a field deletes the key
		 * rather than storing `''`, so `manifest.pages[n]` never carries
		 * empty-string noise. `type` and `config` are never written here —
		 * `type` is fixed at add time and `config` belongs to the sub-editor.
		 *
		 * @param {number} index - position of the page in the `pages` prop, taken from the vuedraggable `#item` slot.
		 * @param {string} key - the page key being written: `title`, `id`, `route` or `permission`.
		 * @param {string} value - the new value from the bound input (for `permission` the `group:<gid>` string from PermissionGroupField); `''` deletes the key.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateField(index, key, value) {
			const next = this.pages.slice()
			const current = { ...next[index] }
			if (value === '') {
				delete current[key]
			} else {
				current[key] = value
			}
			next[index] = current
			this.$emit('update:pages', next)
		},

		/**
		 * Drop a page from the manifest. When the removed page was the
		 * selected one, `select` is re-emitted with -1 so PageDesigner clears
		 * the centre pane instead of pointing at a shifted neighbour.
		 *
		 * @param {number} index - position of the page to remove in the `pages` prop.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removePage(index) {
			const next = this.pages.slice()
			next.splice(index, 1)
			this.$emit('update:pages', next)
			if (index === this.selectedIndex) {
				this.$emit('select', -1)
			}
		},

		/**
		 * Drag-reorder of the page list. vuedraggable v4 hands the whole
		 * reordered array through `update:modelValue` rather than mutating
		 * the `pages` prop, so it is forwarded up unchanged — pages carry no
		 * order key, their array position IS their order in the manifest.
		 *
		 * @param {Array<{id: string, route: string, type: string, config: object}>} newOrder - the pages in their new visual order, as emitted by vuedraggable.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		onReorder(newOrder) {
			this.$emit('update:pages', newOrder)
		},

		/**
		 * Whether a row should be outlined red: its `id` collides with another
		 * page's, its `route` is shared with another page, or its `route` fails the
		 * route-pattern grammar.
		 *
		 * @param {{id?: string, route?: string, type: string, config?: object}} page - the page record for this row, from the vuedraggable `#item` slot.
		 * @param {number} index - position of the page in the `pages` prop. It is only ever compared against -1, a value the `#item` slot cannot produce, so in practice it never makes a row invalid.
		 * @return {boolean} - true when the row is invalid.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		hasError(page, index) {
			if (this.duplicateIds.includes(page && page.id)) {
				return true
			}
			if (page && page.route && this.duplicateRoutes.includes(page.route)) {
				return true
			}
			if (page && page.route && !ROUTE_PATTERN.test(page.route)) {
				return true
			}
			return index === -1
		},
	},
}
</script>

<style scoped>
.page-list-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.page-list-editor__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.page-list-editor__header h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.page-list-editor__add,
.page-list-editor__add-row button {
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.page-list-editor__add-row {
	display: flex;
	gap: 6px;
	align-items: center;
}

.page-list-editor__select {
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.page-list-editor__list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

/* This row must stay inside the page designer's LEFT pane, a 280–320px grid
   column whose panes are `overflow: visible`. Anything that spills out is not
   clipped — it is PAINTED OVER THE CENTRE PANE and, being later in paint order,
   swallows every pointer event aimed at the sub-editor underneath.

   The row used to be a single `nowrap` line holding a drag handle, two text
   inputs, a type tag, a group picker and a remove button. Measured on
   /builder/hello-world/pages at a 1280x720 viewport, before this fix:

     row                     x=355..639   (its pane ends at 648)
     input (route)           x=517..647   already 8px past the row
     span.type-tag           x=656..698   17px past — and the CENTRE PANE
                                          starts at x=669, so this overlaps it
     div.permission-group-field x=704, width 0, height 538
     button.remove           x=710..741   entirely inside the centre pane

   ~386px of content in a 284px row. Two consequences, both live-verified:
   clicking a page row did nothing (the row's geometric centre landed on
   overflowed children, or outside the viewport — the row was 550px tall
   because the collapsed picker stacked its label and hint vertically), and
   centre-pane controls were unclickable because `.page-designer__left`
   intercepted their pointer events.

   `flex-wrap` plus shrinkable items is the fix: the row can now use a second
   line instead of leaving the pane. Note the previously-applied `min-width: 0`
   on the picker was necessary but NOT sufficient — it let that one item
   collapse to 0px while the inputs, which carry their own intrinsic minimum,
   still pushed the tag and the remove button out. */
.page-list-editor__row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
	padding: 6px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.page-list-editor__row:hover {
	background: var(--color-background-hover);
}

.page-list-editor__row--selected {
	background: var(--color-primary-element-light);
}

.page-list-editor__row--error {
	outline: 1px solid var(--color-error);
}

.page-list-editor__drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	user-select: none;
}

.page-list-editor__field {
	/* Small basis on purpose: the two inputs share whatever the fixed-width
	   handle, type tag and remove button leave, so all five stay on the first
	   line and only the picker below wraps. */
	flex: 1 1 60px;
	/* An `<input>`'s automatic minimum size comes from its intrinsic width
	   (~130px here), and `min-width: auto` forbids a flex item from shrinking
	   below it. Two such inputs alone exceeded the 284px row, which is how the
	   type tag and the remove button ended up outside the pane. */
	min-width: 0;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.page-list-editor__permission {
	/* Its own full-width line. This picker ships a wrapped hint paragraph, so
	   it is several times taller than the 34px controls it used to sit beside;
	   squeezing it into the same line is what stretched the row to 550px and
	   pushed the centre point off screen. */
	flex: 1 1 100%;
	/* Flex wraps in DOM order, and a 100% basis always starts a new line — so
	   without this the remove button that follows in markup would be pushed
	   onto a third line. `order` moves the picker last visually while keeping
	   the markup (and therefore the tab order of the two text fields) intact. */
	order: 1;
	/* Let this flex item shrink below its content's intrinsic width. Without
	   it, `min-width: auto` keeps the item at the NcSelect's minimum and the
	   row overflows instead of compressing. */
	min-width: 0;
}

/* @nextcloud/vue sets `.v-select.select { min-width: 260px }` with no prop or
   modifier to unset it on the root element (`select--no-wrap` only unsets it
   on `.vs__selected-options`). This row lives in the page designer's LEFT
   pane, a 280–320px grid column, so a 260px floor plus the drag handle, name
   and type tag cannot fit: the select was laid out past the row's right edge
   and, because the panes are `overflow: visible`, painted straight over the
   centre pane.

   Measured on /builder/:slug/pages before this fix: the row spanned
   x=355..639 while the select rendered at x=704..964 — starting 65px BEYOND
   its own row and 35px into the centre pane (which begins at x=669). It
   swallowed every pointer event aimed at centre-pane controls, so clicking
   "Add layer" and friends retried against `aside.page-designer__left` until
   the test budget expired. Three page-editor-coverage specs failed on it.

   Drop the floor for this narrow context only; the select still fills the
   space its flex basis gives it. */
.page-list-editor__permission :deep(.v-select.select) {
	min-width: 0;
}

.page-list-editor__type-tag {
	flex: 0 0 auto;
	padding: 2px 6px;
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.page-list-editor__remove {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-error, var(--color-main-text));
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.page-list-editor__empty {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.page-list-editor__error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
