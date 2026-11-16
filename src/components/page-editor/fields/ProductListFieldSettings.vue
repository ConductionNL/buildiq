<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!--
  - ProductListFieldSettings — the settings of a "Product list" form field
  - (forms-multi-product-request D4): the products a resident can choose in the
  - first column, the detail columns asked per product and how many rows one
  - submission may hold (at most 25). The field is stored as the sub-objects
  - widget; a repeating journey write names its key.
  -
  - @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
  -->
<template>
	<div class="product-list-settings">
		<label class="product-list-settings__row">
			<span>{{ t('buildiq', 'Products, separated by commas') }}</span>
			<input
				:value="products.join(', ')"
				type="text"
				data-testid="product-list-products"
				@change="emitWith({ products: split($event.target.value) })">
		</label>
		<label class="product-list-settings__row">
			<span>{{ t('buildiq', 'Details per product, separated by commas') }}</span>
			<input
				:value="details.join(', ')"
				type="text"
				data-testid="product-list-details"
				@change="emitWith({ details: split($event.target.value) })">
		</label>
		<label class="product-list-settings__row">
			<span>{{ t('buildiq', 'Most rows (at most {max})', { max: maxAllowed }) }}</span>
			<input
				:value="current.maxItems"
				type="number"
				min="1"
				:max="maxAllowed"
				data-testid="product-list-max-items"
				@change="emitWith({ maxItems: $event.target.value })">
		</label>
	</div>
</template>

<script>
import { PRODUCT_LIST_MAX_ITEMS, productListField } from './fieldTypes.js'

export default {
	name: 'ProductListFieldSettings',

	props: {
		field: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update'],

	computed: {
		/**
		 * @return {object} The field with its settings filled in.
		 *
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
		 */
		current() {
			return productListField(this.field)
		},

		/**
		 * @return {Array<string>} The products of the first column.
		 *
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
		 */
		products() {
			return this.current.columns[0].options
		},

		/**
		 * @return {Array<string>} The detail column keys.
		 *
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
		 */
		details() {
			return this.current.columns.slice(1).map((col) => col.key)
		},

		/**
		 * @return {number} The most rows a product list may hold.
		 *
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
		 */
		maxAllowed() {
			return PRODUCT_LIST_MAX_ITEMS
		},
	},

	methods: {
		/**
		 * A comma-separated list as its trimmed, non-empty parts.
		 *
		 * @param {string} text - the input.
		 * @return {Array<string>}
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
		 */
		split(text) {
			return String(text).split(',').map((part) => part.trim()).filter((part) => part !== '')
		},

		/**
		 * Emit the field with changed settings.
		 *
		 * @param {object} patch - `products`, `details` or `maxItems`.
		 * @spec openspec/changes/forms-multi-product-request/specs/journey-designer/spec.md#requirement-the-parts-can-hang-under-one-bundle-req-bqmp-003
		 */
		emitWith(patch) {
			this.$emit('update', productListField(this.field, patch))
		},
	},
}
</script>

<style scoped>
.product-list-settings {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.product-list-settings__row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
</style>
