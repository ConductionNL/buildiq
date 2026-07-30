/*
 * SPDX-FileCopyrightText: 2026 OpenBuild Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest stub for `vuedraggable` (v4 / Vue 3 contract).
 *
 * Real vuedraggable uses SortableJS internally and assumes a live DOM with
 * mouse-event handlers — neither survives the jsdom unit-test environment.
 *
 * The stub must mirror vuedraggable **v4**, not v2, because that is what the
 * components under test are written against after the Vue 3 migration:
 *   - the list is bound with `v-model` (`modelValue` + `update:modelValue`)
 *     rather than Vue 2's `:value` + `@input`;
 *   - rows come from the `#item` scoped slot receiving `{ element, index }`,
 *     not from a `v-for` in the default slot.
 *
 * A v2-shaped stub renders an empty wrapper under a v4 component — the
 * `#item` slot is simply never invoked — which silently drops every row and
 * makes row-level assertions fail with "Cannot read properties of undefined"
 * rather than with anything that names the real cause.
 *
 * Specs that assert reorder semantics call the component's own reorder
 * handler, or emit `update:modelValue` on this stub directly.
 */

import { h } from 'vue'

export default {
	name: 'Draggable',
	props: {
		modelValue: { type: Array, default: () => [] },
		itemKey: { type: [String, Function], default: undefined },
		// Accepted and ignored: SortableJS tuning that has no jsdom meaning.
		handle: { type: String, default: undefined },
		animation: { type: [Number, String], default: undefined },
		group: { type: [String, Object], default: undefined },
	},
	emits: ['update:modelValue', 'change', 'start', 'end'],
	render() {
		const item = this.$slots.item
		const rows = item
			? this.modelValue.map((element, index) => item({ element, index }))
			: (this.$slots.default?.() ?? [])

		return h('div', { class: 'vuedraggable-stub' }, [
			...(this.$slots.header?.() ?? []),
			...rows,
			...(this.$slots.footer?.() ?? []),
		])
	},
}
