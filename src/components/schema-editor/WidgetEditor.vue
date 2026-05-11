<!--
  - SPDX-License-Identifier: EUPL-1.2
  -
  - WidgetEditor — authors `x-openregister-widgets`
  - (REQ-OBSD-005 widgets slice — v1). Until chain #5 publishes the
  - widget catalogue (design OQ-3), `widget` is a free-text input with
  - a visible "no catalogue registered yet" warning. `slot` is
  - free-text; `config` is captured as raw JSON (read-only in v1).
  -->
<template>
	<section class="openbuilt-widget-editor">
		<header class="openbuilt-widget-editor__header">
			<h3>{{ t('openbuilt', 'Widgets') }}</h3>
			<NcButton @click="addWidget">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('openbuilt', 'Add widget') }}
			</NcButton>
		</header>

		<NcNoteCard type="warning">
			{{ t('openbuilt', 'No widget catalogue registered yet — widget IDs are free-text. The page editor (chain spec #5) will narrow this to a picker once it ships.') }}
		</NcNoteCard>

		<p v-if="widgets.length === 0" class="openbuilt-widget-editor__empty">
			{{ t('openbuilt', 'No widgets yet.') }}
		</p>

		<ul v-else class="openbuilt-widget-editor__rows">
			<li
				v-for="(widget, index) in widgets"
				:key="widget._key"
				class="openbuilt-widget-editor__row">
				<NcTextField
					:value="widget.slot"
					:label="t('openbuilt', 'Slot')"
					@update:value="updateWidget(index, 'slot', $event)" />
				<NcTextField
					:value="widget.widget"
					:label="t('openbuilt', 'Widget id')"
					@update:value="updateWidget(index, 'widget', $event)" />
				<NcTextField
					:value="widget.configJson"
					:label="t('openbuilt', 'Config (JSON)')"
					:error="!!widget.configError"
					:helper-text="widget.configError"
					@update:value="updateConfig(index, $event)" />
				<NcButton type="error" @click="removeWidget(index)">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script>
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'

let keyCounter = 0
function nextKey() {
	keyCounter += 1
	return `widget-${keyCounter}`
}

export default {
	name: 'WidgetEditor',
	components: { DeleteIcon, NcButton, NcNoteCard, NcTextField, PlusIcon },
	props: {
		widgets: { type: Array, default: () => [] },
	},
	emits: ['update:widgets'],
	methods: {
		emitWidgets(next) {
			this.$emit('update:widgets', next)
		},
		addWidget() {
			const next = this.widgets.slice()
			next.push({
				_key: nextKey(),
				slot: '',
				widget: '',
				configJson: '{}',
				configError: '',
			})
			this.emitWidgets(next)
		},
		updateWidget(index, key, value) {
			const next = this.widgets.slice()
			next[index] = { ...next[index], [key]: value }
			this.emitWidgets(next)
		},
		updateConfig(index, value) {
			const next = this.widgets.slice()
			let error = ''
			try {
				JSON.parse(value || '{}')
			} catch (e) {
				error = this.t('openbuilt', 'Config must be valid JSON.')
			}
			next[index] = { ...next[index], configJson: value, configError: error }
			this.emitWidgets(next)
		},
		removeWidget(index) {
			const next = this.widgets.slice()
			next.splice(index, 1)
			this.emitWidgets(next)
		},
	},
}

/**
 * Convert an `x-openregister-widgets` block into editor rows.
 *
 * @param {Array} block Existing widgets block (array of typed records).
 * @return {Array} Editor widget rows.
 */
export function widgetsToEditor(block) {
	if (!Array.isArray(block)) {
		return []
	}
	return block.map((w) => ({
		_key: nextKey(),
		slot: w.slot || '',
		widget: w.widget || '',
		configJson: JSON.stringify(w.config || {}, null, 2),
		configError: '',
	}))
}

/**
 * Reduce editor widget rows back into an `x-openregister-widgets` block.
 *
 * @param {Array} widgets Editor widget rows.
 * @return {Array|null} The serialised block, or null when empty.
 */
export function editorToWidgets(widgets) {
	if (!widgets || widgets.length === 0) {
		return null
	}
	return widgets
		.filter((w) => w.slot && w.widget && !w.configError)
		.map((w) => {
			let config = {}
			try {
				config = JSON.parse(w.configJson || '{}')
			} catch {
				config = {}
			}
			return {
				slot: w.slot,
				widget: w.widget,
				config,
			}
		})
}
</script>

<style scoped>
.openbuilt-widget-editor {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-widget-editor__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.openbuilt-widget-editor__header h3 {
	margin: 0;
	font-size: 18px;
	font-weight: 600;
}

.openbuilt-widget-editor__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.openbuilt-widget-editor__rows {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.openbuilt-widget-editor__row {
	display: grid;
	grid-template-columns: 1fr 1fr 2fr auto;
	gap: 8px;
	align-items: center;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}
</style>
