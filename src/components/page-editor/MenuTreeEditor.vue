<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - MenuTreeEditor — drag-reorder top-level + child entries, depth-2 cap,
  - i18n-key `label`, `target` enum, `action` enum, disable `route`/`href`
  - when `action` is set. Implements REQ-OBPD-001.
  -->
<template>
	<section class="menu-tree-editor">
		<header class="menu-tree-editor__header">
			<h4>{{ t('openbuild', 'Menu') }}</h4>
			<button type="button" class="menu-tree-editor__add" @click="addEntry()">
				+ {{ t('openbuild', 'Add menu entry') }}
			</button>
		</header>
		<p v-if="depthError" class="menu-tree-editor__error" role="alert">
			{{ t('openbuild', 'Maximum nesting depth is two levels.') }}
		</p>
		<!-- vuedraggable v4 (Vue 3): v-model instead of :value/@input, sortable
		     options as plain props, and rows from the `#item` scoped slot — a
		     v-for in the default slot throws "draggable element must have an
		     item slot" at render. -->
		<Draggable
			:modelValue="menu"
			handle=".menu-tree-editor__drag-handle"
			:animation="150"
			itemKey="id"
			class="menu-tree-editor__list"
			@update:modelValue="onTopLevelReorder">
			<template #item="{ element: entry, index }">
				<div class="menu-tree-editor__entry">
					<div class="menu-tree-editor__row">
						<span
							class="menu-tree-editor__drag-handle"
							:title="t('openbuild', 'Drag to reorder')">
							⠿
						</span>
						<input
							:value="entry.id || ''"
							type="text"
							class="menu-tree-editor__field"
							:placeholder="t('openbuild', 'id (e.g. inbox)')"
							:aria-label="t('openbuild', 'id (e.g. inbox)')"
							@input="updateField(index, 'id', $event.target.value)" />
						<input
							:value="entry.label || ''"
							type="text"
							class="menu-tree-editor__field"
							:placeholder="t('openbuild', 'label (i18n key)')"
							:aria-label="t('openbuild', 'label (i18n key)')"
							@input="
								updateField(index, 'label', $event.target.value)
							" />
						<input
							:value="entry.icon || ''"
							type="text"
							class="menu-tree-editor__field menu-tree-editor__field--narrow"
							:placeholder="t('openbuild', 'icon')"
							:aria-label="t('openbuild', 'icon')"
							@input="
								updateField(index, 'icon', $event.target.value)
							" />
						<input
							:value="entry.route || ''"
							type="text"
							class="menu-tree-editor__field"
							:placeholder="t('openbuild', 'route name')"
							:aria-label="t('openbuild', 'route name')"
							:disabled="!!entry.action"
							@input="
								updateField(index, 'route', $event.target.value)
							" />
						<input
							:value="entry.href || ''"
							type="text"
							class="menu-tree-editor__field"
							:placeholder="t('openbuild', 'href URL')"
							:aria-label="t('openbuild', 'href URL')"
							:disabled="!!entry.action"
							@input="
								updateField(index, 'href', $event.target.value)
							" />
						<select
							:value="entry.target || 'main'"
							class="menu-tree-editor__field menu-tree-editor__field--narrow"
							@change="
								updateField(index, 'target', $event.target.value)
							">
							<option value="main">main</option>
							<option value="settings">settings</option>
						</select>
						<select
							:value="entry.action || ''"
							class="menu-tree-editor__field menu-tree-editor__field--narrow"
							@change="updateActionField(index, $event.target.value)">
							<option value="">
								{{ t('openbuild', '— action —') }}
							</option>
							<option value="user-settings">user-settings</option>
						</select>
						<button
							type="button"
							class="menu-tree-editor__icon-btn"
							:title="t('openbuild', 'Add child')"
							@click="addChild(index)">
							⤵
						</button>
						<button
							type="button"
							class="menu-tree-editor__icon-btn menu-tree-editor__icon-btn--remove"
							:title="t('openbuild', 'Remove entry')"
							@click="removeEntry(index)">
							✕
						</button>
					</div>
					<p v-if="entry.action" class="menu-tree-editor__note">
						{{
							t(
								'openbuild',
								'Route and href are ignored when an action is set.',
							)
						}}
					</p>
					<PermissionGroupField
						:permission="entry.permission || ''"
						:knownGroups="knownGroups"
						@update:permission="
							updateField(index, 'permission', $event || '')
						" />
					<Draggable
						v-if="entry.children && entry.children.length"
						:modelValue="entry.children"
						handle=".menu-tree-editor__drag-handle"
						:animation="150"
						itemKey="id"
						class="menu-tree-editor__children"
						@update:modelValue="onChildrenReorder(index, $event)">
						<template #item="{ element: child, index: cIndex }">
							<div
								class="menu-tree-editor__row menu-tree-editor__row--child">
								<span class="menu-tree-editor__drag-handle">
									⠿
								</span>
								<input
									:value="child.id || ''"
									type="text"
									class="menu-tree-editor__field"
									:placeholder="t('openbuild', 'child id')"
									:aria-label="t('openbuild', 'child id')"
									@input="
										updateChildField(
											index,
											cIndex,
											'id',
											$event.target.value,
										)
									" />
								<input
									:value="child.label || ''"
									type="text"
									class="menu-tree-editor__field"
									:placeholder="t('openbuild', 'label (i18n key)')"
									:aria-label="t('openbuild', 'label (i18n key)')"
									@input="
										updateChildField(
											index,
											cIndex,
											'label',
											$event.target.value,
										)
									" />
								<input
									:value="child.icon || ''"
									type="text"
									class="menu-tree-editor__field menu-tree-editor__field--narrow"
									:placeholder="t('openbuild', 'icon')"
									:aria-label="t('openbuild', 'icon')"
									@input="
										updateChildField(
											index,
											cIndex,
											'icon',
											$event.target.value,
										)
									" />
								<input
									:value="child.route || ''"
									type="text"
									class="menu-tree-editor__field"
									:placeholder="t('openbuild', 'route name')"
									:aria-label="t('openbuild', 'route name')"
									@input="
										updateChildField(
											index,
											cIndex,
											'route',
											$event.target.value,
										)
									" />
								<button
									type="button"
									class="menu-tree-editor__icon-btn menu-tree-editor__icon-btn--remove"
									:title="t('openbuild', 'Remove child')"
									@click="removeChild(index, cIndex)">
									✕
								</button>
							</div>
						</template>
					</Draggable>
				</div>
			</template>
		</Draggable>
		<p v-if="!menu.length" class="menu-tree-editor__empty">
			{{
				t(
					'openbuild',
					'No menu entries yet. Click "Add menu entry" to start.',
				)
			}}
		</p>
	</section>
</template>

<script>
import Draggable from 'vuedraggable'
import PermissionGroupField from './fields/PermissionGroupField.vue'

export default {
	name: 'MenuTreeEditor',
	components: { Draggable, PermissionGroupField },
	props: {
		menu: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:menu', 'depth-violation'],
	data() {
		return {
			depthError: false,
		}
	},

	computed: {
		/**
		 * Group ids already referenced by any menu entry's `permission`
		 * (spec `runtime-group-scoped-access`) — offered as quick-pick
		 * options in {@see PermissionGroupField} so authors do not need to
		 * retype a group id for every gated entry.
		 *
		 * @return {string[]}
		 * @spec openspec/specs/openbuild-runtime/spec.md#requirement-menu-items-and-pages-must-be-filterable-by-permission
		 */
		knownGroups() {
			const gids = new Set()
			for (const entry of this.menu) {
				const value =
					entry && typeof entry.permission === 'string'
						? entry.permission
						: ''
				if (value.startsWith('group:')) {
					gids.add(value.slice('group:'.length))
				}
			}
			return Array.from(gids)
		},
	},

	methods: {
		/**
		 * Single write-path out of this component: renumber `order` and hand
		 * the whole menu array up to PageDesigner, which merges it onto
		 * `manifest.menu`. Every mutator below funnels through here so the
		 * `order` integers can never drift from the visual order.
		 *
		 * @param {Array<{id: string, label: string, icon?: string, route?: string, href?: string, target?: string, action?: string, permission?: string, order?: number, children?: object[]}>} menu - the complete replacement list of top-level menu entries; any `order` already on an entry is overwritten with its array position.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		emit(menu) {
			// Re-assign monotonic `order` integers per top-level entry.
			const next = menu.map((e, i) => ({ ...e, order: i }))
			this.$emit('update:menu', next)
		},

		/**
		 * Write one scalar key on one top-level menu entry. Clearing a field
		 * removes the key entirely rather than storing `''`, so the emitted
		 * manifest never carries empty-string noise.
		 *
		 * @param {number} index - position of the entry in the `menu` prop, taken from the vuedraggable `#item` slot.
		 * @param {string} key - the entry key being written: `id`, `label`, `icon`, `route`, `href`, `target` or `permission`.
		 * @param {string} value - the new value from the bound input/select (for `permission` the `group:<gid>` string from PermissionGroupField); `''` deletes the key.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateField(index, key, value) {
			const next = this.menu.slice()
			const current = { ...next[index] }
			if (value === '') {
				delete current[key]
			} else {
				current[key] = value
			}
			next[index] = current
			this.emit(next)
		},

		/**
		 * Write the `action` enum on a top-level entry. Canonical rule: an
		 * entry that triggers an action MUST NOT also carry navigation, so
		 * setting an action deletes `route` and `href` (the template disables
		 * those two inputs to match).
		 *
		 * @param {number} index - position of the entry in the `menu` prop.
		 * @param {string} value - the selected action id (currently only `user-settings`); `''` means "no action" and only deletes `action`, leaving `route`/`href` editable again.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateActionField(index, value) {
			const next = this.menu.slice()
			const current = { ...next[index] }
			if (value === '') {
				delete current.action
			} else {
				current.action = value
				// Canonical rule: action set => clear route + href.
				delete current.route
				delete current.href
			}
			next[index] = current
			this.emit(next)
		},

		/**
		 * Observed behaviour of `addEntry` (retrofit annotation).
		 *
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addEntry() {
			const next = this.menu.slice()
			next.push({ id: '', label: '', target: 'main' })
			this.emit(next)
		},

		/**
		 * Drop a top-level entry (and, with it, any children it owns).
		 *
		 * @param {number} index - position of the entry to remove in the `menu` prop.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removeEntry(index) {
			const next = this.menu.slice()
			next.splice(index, 1)
			this.emit(next)
		},

		/**
		 * Append an empty second-level entry under a top-level entry. Only
		 * top-level rows expose the "Add child" button, which is what keeps
		 * the tree at the depth-2 cap.
		 *
		 * @param {number} index - position of the parent entry in the `menu` prop.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		addChild(index) {
			const next = this.menu.slice()
			const current = { ...next[index] }
			const children = Array.isArray(current.children)
				? current.children.slice()
				: []
			children.push({ id: '', label: '' })
			current.children = children
			next[index] = current
			this.emit(next)
		},

		/**
		 * Write one scalar key on a second-level entry. Also the depth guard:
		 * a `children` key on a child would make a third level, so that call
		 * is refused — it raises `depthError` and emits `depth-violation`
		 * instead of touching the menu.
		 *
		 * @param {number} index - position of the parent entry in the `menu` prop.
		 * @param {number} cIndex - position of the child inside that parent's `children` array.
		 * @param {string} key - the child key being written: `id`, `label`, `icon` or `route`; the literal `children` is rejected as a depth violation.
		 * @param {string} value - the new value from the bound input; `''` deletes the key.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		updateChildField(index, cIndex, key, value) {
			const next = this.menu.slice()
			const parent = { ...next[index] }
			const children = (parent.children || []).slice()
			const child = { ...children[cIndex] }
			// Enforce depth-2: a child MUST NOT itself declare `children[]`.
			if (key === 'children') {
				this.depthError = true
				this.$emit('depth-violation')
				return
			}
			if (value === '') {
				delete child[key]
			} else {
				child[key] = value
			}
			children[cIndex] = child
			parent.children = children
			next[index] = parent
			this.emit(next)
		},

		/**
		 * Drop one second-level entry. Removing the last child deletes the
		 * `children` key altogether so a childless entry round-trips without
		 * an empty array.
		 *
		 * @param {number} index - position of the parent entry in the `menu` prop.
		 * @param {number} cIndex - position of the child to remove inside that parent's `children` array.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		removeChild(index, cIndex) {
			const next = this.menu.slice()
			const parent = { ...next[index] }
			const children = (parent.children || []).slice()
			children.splice(cIndex, 1)
			if (children.length === 0) {
				delete parent.children
			} else {
				parent.children = children
			}
			next[index] = parent
			this.emit(next)
		},

		/**
		 * Drag-reorder of the top-level list. vuedraggable v4 hands the whole
		 * reordered array through `update:modelValue` (it does not mutate the
		 * `menu` prop), so this just renumbers `order` and emits.
		 *
		 * @param {Array<object>} newOrder - the top-level entries in their new visual order, as emitted by vuedraggable.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		onTopLevelReorder(newOrder) {
			this.emit(newOrder)
		},

		/**
		 * Drag-reorder inside one parent's child list. Children carry no
		 * `order` key of their own — array position is their order — so the
		 * new array is stored verbatim on the parent.
		 *
		 * @param {number} index - position of the parent entry whose children were reordered.
		 * @param {Array<object>} newOrder - that parent's children in their new visual order, as emitted by vuedraggable.
		 * @spec openspec/changes/retrofit-2026-05-26-page-designer-ui/tasks.md#task-4
		 */
		onChildrenReorder(index, newOrder) {
			const next = this.menu.slice()
			const parent = { ...next[index] }
			parent.children = newOrder
			next[index] = parent
			this.emit(next)
		},
	},
}
</script>

<style scoped>
.menu-tree-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.menu-tree-editor__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.menu-tree-editor__header h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 600;
}

.menu-tree-editor__add {
	background: var(--color-primary-element-light);
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.menu-tree-editor__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.menu-tree-editor__entry {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 6px;
	border: 1px dashed var(--color-border);
	border-radius: var(--border-radius);
}

.menu-tree-editor__row {
	display: flex;
	gap: 6px;
	align-items: center;
	flex-wrap: wrap;
}

.menu-tree-editor__row--child {
	margin-left: 28px;
	padding: 4px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.menu-tree-editor__drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	user-select: none;
}

.menu-tree-editor__field {
	flex: 1 1 110px;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.menu-tree-editor__field--narrow {
	flex: 0 0 100px;
}

.menu-tree-editor__field[disabled] {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.menu-tree-editor__icon-btn {
	background: transparent;
	border: 1px solid var(--color-border);
	color: var(--color-main-text);
	padding: 4px 8px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.menu-tree-editor__icon-btn--remove {
	color: var(--color-error, var(--color-main-text));
}

.menu-tree-editor__note {
	margin: 0;
	margin-left: 28px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.menu-tree-editor__children {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.menu-tree-editor__empty {
	margin: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.menu-tree-editor__error {
	margin: 0;
	color: var(--color-error);
	font-size: 13px;
}
</style>
