<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - CodeWidgetBuilder — authors the `code` widgets of a detail page
  - (pages-qr-and-barcodes, REQ-BQQR-003): a QR code, Code 128 or EAN-13 of a
  - record field or of the record's address in the app. Widgets of any other
  - type in the list are kept as they are and in place.
  -->
<template>
	<div class="code-widget-builder">
		<div
			v-for="entry in codeWidgets"
			:key="entry.index"
			class="code-widget-builder__row"
			data-testid="code-widget-row">
			<input
				:value="entry.widget.title || ''"
				type="text"
				class="code-widget-builder__field"
				:placeholder="t('buildiq', 'Title')"
				:aria-label="t('buildiq', 'Title')"
				@input="updateWidget(entry.index, { title: $event.target.value })" />
			<select
				:value="entry.widget.format || 'qr'"
				class="code-widget-builder__field"
				:aria-label="t('buildiq', 'Code format')"
				data-testid="code-widget-format"
				@change="updateWidget(entry.index, { format: $event.target.value })">
				<option value="qr">
					{{ t('buildiq', 'QR code') }}
				</option>
				<option value="code128">
					Code 128
				</option>
				<option value="ean13">
					EAN-13
				</option>
			</select>
			<select
				:value="entry.widget.source || 'address'"
				class="code-widget-builder__field"
				:aria-label="t('buildiq', 'What the code holds')"
				data-testid="code-widget-source"
				@change="updateWidget(entry.index, { source: $event.target.value })">
				<option value="address">
					{{ t('buildiq', 'The record\'s address in the app') }}
				</option>
				<option value="field">
					{{ t('buildiq', 'A field of the record') }}
				</option>
			</select>
			<input
				v-if="entry.widget.source === 'field'"
				:value="entry.widget.field || ''"
				type="text"
				class="code-widget-builder__field"
				:placeholder="t('buildiq', 'Field')"
				:aria-label="t('buildiq', 'Field')"
				data-testid="code-widget-field"
				@input="updateWidget(entry.index, { field: $event.target.value })" />
			<button
				type="button"
				class="code-widget-builder__remove"
				:title="t('buildiq', 'Remove code')"
				:aria-label="t('buildiq', 'Remove code')"
				@click="removeWidget(entry.index)">
				✕
			</button>
		</div>
		<button
			type="button"
			class="code-widget-builder__add"
			data-testid="code-widget-add"
			@click="addWidget">
			+ {{ t('buildiq', 'Add code') }}
		</button>
	</div>
</template>

<script>
/**
 * Edits the `code` widgets inside a detail page's `config.widgets`.
 *
 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-detail-pages-can-show-a-code-req-bqqr-003
 */
export default {
	name: 'CodeWidgetBuilder',
	props: {
		modelValue: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:modelValue'],

	computed: {
		/**
		 * The code widgets with their place in the whole list.
		 *
		 * @return {Array<{index: number, widget: object}>}
		 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-detail-pages-can-show-a-code-req-bqqr-003
		 */
		codeWidgets() {
			return (Array.isArray(this.modelValue) ? this.modelValue : [])
				.map((widget, index) => ({ index, widget }))
				.filter((entry) => entry.widget && entry.widget.type === 'code')
		},
	},

	methods: {
		/**
		 * Add a QR code of the record's address, the one source every format allows.
		 *
		 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-detail-pages-can-show-a-code-req-bqqr-003
		 */
		addWidget() {
			const widgets = Array.isArray(this.modelValue) ? this.modelValue.slice() : []
			const taken = new Set(widgets.map((widget) => widget && widget.id))
			let number = 1
			while (taken.has(`code-${number}`)) {
				number++
			}
			widgets.push({ id: `code-${number}`, type: 'code', format: 'qr', source: 'address' })
			this.$emit('update:modelValue', widgets)
		},

		/**
		 * Change one code widget. Leaving the field source drops its field.
		 *
		 * @param {number} index - the widget's place in the whole list.
		 * @param {object} change - the keys to set.
		 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-detail-pages-can-show-a-code-req-bqqr-003
		 */
		updateWidget(index, change) {
			const widgets = this.modelValue.slice()
			const next = { ...widgets[index], ...change }
			if (next.source !== 'field') {
				delete next.field
			}
			widgets[index] = next
			this.$emit('update:modelValue', widgets)
		},

		/**
		 * Remove one code widget.
		 *
		 * @param {number} index - the widget's place in the whole list.
		 * @spec openspec/changes/pages-qr-and-barcodes/specs/page-codes/spec.md#requirement-detail-pages-can-show-a-code-req-bqqr-003
		 */
		removeWidget(index) {
			this.$emit('update:modelValue', this.modelValue.filter((widget, at) => at !== index))
		},
	},
}
</script>

<style scoped lang="scss">
.code-widget-builder {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.code-widget-builder__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.code-widget-builder__field {
	flex: 1 1 140px;
}

.code-widget-builder__add {
	align-self: flex-start;
}
</style>
