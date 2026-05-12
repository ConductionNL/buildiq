/*
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `vuedraggable`.
 *
 * Real vuedraggable uses SortableJS internally and assumes a live DOM
 * with mouse-event handlers — neither survives the jsdom unit-test
 * environment. The stub renders children inline (via the default slot)
 * and forwards `:value` to a `value` prop without ever wiring drag
 * behaviour. Specs that need to assert reorder semantics emit an
 * `@input` event on the rendered stub directly via `wrapper.vm.$emit`.
 */

export default {
	name: 'Draggable',
	props: {
		value: { type: Array, default: () => [] },
		options: { type: Object, default: () => ({}) },
	},
	render(h) {
		return h('div', { staticClass: 'vuedraggable-stub' }, this.$slots.default)
	},
}
