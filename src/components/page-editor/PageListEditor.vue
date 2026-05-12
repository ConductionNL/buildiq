<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - PageListEditor — drag-reorder pages, add/remove, force page-type pick on
  - add (closed enum of 9), enforce unique `id`, validate route-pattern grammar.
  - Implements REQ-OBPD-002.
  -->
<template>
	<section class="page-list-editor">
		<header class="page-list-editor__header">
			<h4>{{ t('openbuilt', 'Pages') }}</h4>
			<button type="button" class="page-list-editor__add" @click="startAdd">
				+ {{ t('openbuilt', 'Add page') }}
			</button>
		</header>
		<div v-if="addingType !== null" class="page-list-editor__add-row">
			<select v-model="addingType" class="page-list-editor__select">
				<option value="">
					{{ t('openbuilt', '— select page type —') }}
				</option>
				<option v-for="type in PAGE_TYPES" :key="type" :value="type">
					{{ type }}
				</option>
			</select>
			<button type="button" :disabled="!addingType" @click="confirmAdd">
				{{ t('openbuilt', 'Confirm') }}
			</button>
			<button type="button" @click="cancelAdd">
				{{ t('openbuilt', 'Cancel') }}
			</button>
		</div>
		<Draggable
			:value="pages"
			:options="{ handle: '.page-list-editor__drag-handle', animation: 150 }"
			class="page-list-editor__list"
			@input="onReorder">
			<div
				v-for="(page, index) in pages"
				:key="page.id || `page-${index}`"
				class="page-list-editor__row"
				:class="{
					'page-list-editor__row--selected': index === selectedIndex,
					'page-list-editor__row--error': hasError(page, index),
				}"
				@click="$emit('select', index)">
				<span class="page-list-editor__drag-handle" :title="t('openbuilt', 'Drag to reorder')">
					⠿
				</span>
				<input
					:value="page.id || ''"
					type="text"
					class="page-list-editor__field"
					:placeholder="t('openbuilt', 'page id')"
					@click.stop
					@input="updateField(index, 'id', $event.target.value)">
				<input
					:value="page.route || ''"
					type="text"
					class="page-list-editor__field"
					:placeholder="t('openbuilt', '/route/:param')"
					@click.stop
					@input="updateField(index, 'route', $event.target.value)">
				<span class="page-list-editor__type-tag">{{ page.type }}</span>
				<button
					type="button"
					class="page-list-editor__remove"
					:title="t('openbuilt', 'Remove page')"
					@click.stop="removePage(index)">
					✕
				</button>
			</div>
		</Draggable>
		<p v-if="!pages.length" class="page-list-editor__empty">
			{{ t('openbuilt', 'No pages yet. Click "Add page" to start.') }}
		</p>
		<p v-if="duplicateIds.length" class="page-list-editor__error" role="alert">
			{{ t('openbuilt', 'Duplicate page ids:') }} {{ duplicateIds.join(', ') }}
		</p>
		<p v-if="invalidRoutes.length" class="page-list-editor__error" role="alert">
			{{ t('openbuilt', 'Invalid route(s):') }} {{ invalidRoutes.join(', ') }}
		</p>
	</section>
</template>

<script>
import Draggable from 'vuedraggable'

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
]

const ROUTE_PATTERN = /^\/$|^(\/[A-Za-z0-9_-]+|\/:[A-Za-z_][A-Za-z0-9_]*(\(.*\))?)+$/

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
}

export default {
	name: 'PageListEditor',
	components: { Draggable },
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
		}
	},
	computed: {
		duplicateIds() {
			const counts = new Map()
			for (const p of this.pages) {
				if (p && p.id) {
					counts.set(p.id, (counts.get(p.id) || 0) + 1)
				}
			}
			return Array.from(counts.entries()).filter(([, c]) => c > 1).map(([id]) => id)
		},
		invalidRoutes() {
			return this.pages
				.filter((p) => p && p.route && !ROUTE_PATTERN.test(p.route))
				.map((p) => p.route)
		},
	},
	methods: {
		startAdd() {
			this.addingType = ''
		},
		cancelAdd() {
			this.addingType = null
		},
		confirmAdd() {
			if (!this.addingType) {
				return
			}
			const type = this.addingType
			const next = this.pages.slice()
			const placeholder = {
				id: `${type}-page-${next.length + 1}`,
				route: type === 'index' ? '/' : `/${type}`,
				type,
				title: `${type}.title`,
				config: JSON.parse(JSON.stringify(DEFAULT_CONFIGS[type] || {})),
			}
			next.push(placeholder)
			this.$emit('update:pages', next)
			this.$emit('select', next.length - 1)
			this.addingType = null
		},
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
		removePage(index) {
			const next = this.pages.slice()
			next.splice(index, 1)
			this.$emit('update:pages', next)
			if (index === this.selectedIndex) {
				this.$emit('select', -1)
			}
		},
		onReorder(newOrder) {
			this.$emit('update:pages', newOrder)
		},
		hasError(page, index) {
			if (this.duplicateIds.includes(page && page.id)) {
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
.page-list-editor__row {
	display: flex;
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
	flex: 1 1 auto;
	padding: 4px 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
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
