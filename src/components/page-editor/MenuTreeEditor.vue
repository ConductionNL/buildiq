<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - MenuTreeEditor — drag-reorder top-level + child entries, depth-2 cap,
  - i18n-key `label`, `target` enum, `action` enum, disable `route`/`href`
  - when `action` is set. Implements REQ-OBPD-001.
  -->
<template>
	<section class="menu-tree-editor">
		<header class="menu-tree-editor__header">
			<h4>{{ t('openbuilt', 'Menu') }}</h4>
			<button type="button" class="menu-tree-editor__add" @click="addEntry()">
				+ {{ t('openbuilt', 'Add menu entry') }}
			</button>
		</header>
		<p v-if="depthError" class="menu-tree-editor__error" role="alert">
			{{ t('openbuilt', 'Maximum nesting depth is two levels.') }}
		</p>
		<Draggable
			:value="menu"
			:options="{ handle: '.menu-tree-editor__drag-handle', animation: 150 }"
			class="menu-tree-editor__list"
			@input="onTopLevelReorder">
			<div
				v-for="(entry, index) in menu"
				:key="entry.id || `entry-${index}`"
				class="menu-tree-editor__entry">
				<div class="menu-tree-editor__row">
					<span class="menu-tree-editor__drag-handle" :title="t('openbuilt', 'Drag to reorder')">
						⠿
					</span>
					<input
						:value="entry.id || ''"
						type="text"
						class="menu-tree-editor__field"
						:placeholder="t('openbuilt', 'id (e.g. inbox)')"
						@input="updateField(index, 'id', $event.target.value)">
					<input
						:value="entry.label || ''"
						type="text"
						class="menu-tree-editor__field"
						:placeholder="t('openbuilt', 'label (i18n key)')"
						@input="updateField(index, 'label', $event.target.value)">
					<input
						:value="entry.icon || ''"
						type="text"
						class="menu-tree-editor__field menu-tree-editor__field--narrow"
						:placeholder="t('openbuilt', 'icon')"
						@input="updateField(index, 'icon', $event.target.value)">
					<input
						:value="entry.route || ''"
						type="text"
						class="menu-tree-editor__field"
						:placeholder="t('openbuilt', 'route name')"
						:disabled="!!entry.action"
						@input="updateField(index, 'route', $event.target.value)">
					<input
						:value="entry.href || ''"
						type="text"
						class="menu-tree-editor__field"
						:placeholder="t('openbuilt', 'href URL')"
						:disabled="!!entry.action"
						@input="updateField(index, 'href', $event.target.value)">
					<select
						:value="entry.target || 'main'"
						class="menu-tree-editor__field menu-tree-editor__field--narrow"
						@change="updateField(index, 'target', $event.target.value)">
						<option value="main">
							main
						</option>
						<option value="settings">
							settings
						</option>
					</select>
					<select
						:value="entry.action || ''"
						class="menu-tree-editor__field menu-tree-editor__field--narrow"
						@change="updateActionField(index, $event.target.value)">
						<option value="">
							{{ t('openbuilt', '— action —') }}
						</option>
						<option value="user-settings">
							user-settings
						</option>
					</select>
					<button
						type="button"
						class="menu-tree-editor__icon-btn"
						:title="t('openbuilt', 'Add child')"
						@click="addChild(index)">
						⤵
					</button>
					<button
						type="button"
						class="menu-tree-editor__icon-btn menu-tree-editor__icon-btn--remove"
						:title="t('openbuilt', 'Remove entry')"
						@click="removeEntry(index)">
						✕
					</button>
				</div>
				<p v-if="entry.action" class="menu-tree-editor__note">
					{{ t('openbuilt', 'Route and href are ignored when an action is set.') }}
				</p>
				<Draggable
					v-if="entry.children && entry.children.length"
					:value="entry.children"
					:options="{ handle: '.menu-tree-editor__drag-handle', animation: 150 }"
					class="menu-tree-editor__children"
					@input="onChildrenReorder(index, $event)">
					<div
						v-for="(child, cIndex) in entry.children"
						:key="child.id || `child-${cIndex}`"
						class="menu-tree-editor__row menu-tree-editor__row--child">
						<span class="menu-tree-editor__drag-handle">
							⠿
						</span>
						<input
							:value="child.id || ''"
							type="text"
							class="menu-tree-editor__field"
							:placeholder="t('openbuilt', 'child id')"
							@input="updateChildField(index, cIndex, 'id', $event.target.value)">
						<input
							:value="child.label || ''"
							type="text"
							class="menu-tree-editor__field"
							:placeholder="t('openbuilt', 'label (i18n key)')"
							@input="updateChildField(index, cIndex, 'label', $event.target.value)">
						<input
							:value="child.icon || ''"
							type="text"
							class="menu-tree-editor__field menu-tree-editor__field--narrow"
							:placeholder="t('openbuilt', 'icon')"
							@input="updateChildField(index, cIndex, 'icon', $event.target.value)">
						<input
							:value="child.route || ''"
							type="text"
							class="menu-tree-editor__field"
							:placeholder="t('openbuilt', 'route name')"
							@input="updateChildField(index, cIndex, 'route', $event.target.value)">
						<button
							type="button"
							class="menu-tree-editor__icon-btn menu-tree-editor__icon-btn--remove"
							:title="t('openbuilt', 'Remove child')"
							@click="removeChild(index, cIndex)">
							✕
						</button>
					</div>
				</Draggable>
			</div>
		</Draggable>
		<p v-if="!menu.length" class="menu-tree-editor__empty">
			{{ t('openbuilt', 'No menu entries yet. Click "Add menu entry" to start.') }}
		</p>
	</section>
</template>

<script>
import Draggable from 'vuedraggable'

export default {
	name: 'MenuTreeEditor',
	components: { Draggable },
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
	methods: {
		emit(menu) {
			// Re-assign monotonic `order` integers per top-level entry.
			const next = menu.map((e, i) => ({ ...e, order: i }))
			this.$emit('update:menu', next)
		},
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
		addEntry() {
			const next = this.menu.slice()
			next.push({ id: '', label: '', target: 'main' })
			this.emit(next)
		},
		removeEntry(index) {
			const next = this.menu.slice()
			next.splice(index, 1)
			this.emit(next)
		},
		addChild(index) {
			const next = this.menu.slice()
			const current = { ...next[index] }
			const children = Array.isArray(current.children) ? current.children.slice() : []
			children.push({ id: '', label: '' })
			current.children = children
			next[index] = current
			this.emit(next)
		},
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
		onTopLevelReorder(newOrder) {
			this.emit(newOrder)
		},
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
