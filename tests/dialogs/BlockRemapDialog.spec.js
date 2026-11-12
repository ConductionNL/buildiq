/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BlockRemapDialog maps each mismatched schema dependency of an inserted block
 * to a schema of the target app. A dependency left unmapped must come back as
 * unresolved, never silently dropped.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BlockRemapDialog from '../../src/dialogs/BlockRemapDialog.vue'

const stubs = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { emits: ['click'], template: '<button @click="$emit(\'click\')"><slot /></button>' },
	NcSelect: { props: ['options', 'modelValue'], template: '<div class="select" />' },
}

function mountDialog(props = {}) {
	return mount(BlockRemapDialog, {
		props: { open: true, dependencies: ['pet', 'owner'], targetSchemaSlugs: ['animal', 'person'], ...props },
		global: { stubs },
	})
}

describe('BlockRemapDialog', () => {
	it('starts every dependency unselected when created open', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.selections).toEqual({ pet: null, owner: null })
	})

	it('offers the target schema slugs as select options', () => {
		const wrapper = mountDialog()
		expect(wrapper.vm.schemaOptions).toEqual([
			{ id: 'animal', label: 'animal' },
			{ id: 'person', label: 'person' },
		])
		expect(wrapper.findAll('.select')).toHaveLength(2)
	})

	it('shows the nothing-to-resolve note when there are no dependencies', () => {
		const wrapper = mountDialog({ dependencies: [] })
		expect(wrapper.text()).toContain('No schema references need resolving.')
	})

	it('emits the mapped dependencies and lists the rest as unresolved on confirm', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({ selections: { pet: { id: 'animal', label: 'animal' }, owner: null } })

		wrapper.vm.confirm()

		expect(wrapper.emitted('resolved')).toEqual([[{ remapMap: { pet: 'animal' }, unresolvedDependencies: ['owner'] }]])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('accepts a bare slug as a selection', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({ selections: { pet: 'animal', owner: 'person' } })

		wrapper.vm.confirm()

		expect(wrapper.emitted('resolved')[0][0]).toEqual({
			remapMap: { pet: 'animal', owner: 'person' },
			unresolvedDependencies: [],
		})
	})

	it('resets the selections when it is reopened', async () => {
		const wrapper = mountDialog({ open: false })
		expect(wrapper.vm.selections).toEqual({})
		await wrapper.setData({ selections: { pet: 'animal' } })

		await wrapper.setProps({ open: true })

		expect(wrapper.vm.selections).toEqual({ pet: null, owner: null })
	})

	it('does not reset the selections when it closes', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({ selections: { pet: 'animal', owner: null } })

		await wrapper.setProps({ open: false })

		expect(wrapper.vm.selections).toEqual({ pet: 'animal', owner: null })
	})

	it('resets the selections to the new dependencies when they change', async () => {
		const wrapper = mountDialog()
		await wrapper.setData({ selections: { pet: 'animal', owner: null } })

		await wrapper.setProps({ dependencies: ['invoice'] })

		expect(wrapper.vm.selections).toEqual({ invoice: null })
	})

	it('only closes, without resolving, on cancel', async () => {
		const wrapper = mountDialog()

		await wrapper.findAll('button')[0].trigger('click')

		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.emitted('resolved')).toBeUndefined()
	})
})
