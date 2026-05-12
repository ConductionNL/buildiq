/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Vitest unit tests for `src/modals/PermissionsModal.vue` — the owner-only
 * permissions panel. Covers REQ-OBRBAC-005 (transfer-ownership flow with
 * empty-owners orphan-check) and REQ-OBRBAC-007 (permissions changes
 * surfacing as a save event the parent translates into the OR PUT).
 *
 * The modal emits a `save` event the parent (ApplicationEditor.vue)
 * consumes — there is no direct PUT in the modal itself per single-
 * responsibility. These specs assert the emit contract + the orphan-
 * check guard.
 *
 * The `@conduction/nextcloud-vue` package is stubbed at the alias layer
 * (tests/vitest/stubs/conduction-nextcloud-vue.js); we override NcSelect
 * with a controllable double so we can simulate the user picking options
 * via v-model. NcDialog is stubbed as a transparent wrapper so the
 * mounted markup includes the slot content (otherwise the `:open` state
 * gates rendering and we can't probe the inner DOM).
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

// Inline mocks for NcDialog + NcSelect — vi.mock is hoisted above the
// imports below. We deliberately stub NcDialog as a transparent slot
// passthrough so the modal renders its inner controls regardless of the
// `:open` prop (real NcDialog skips the slot when closed).
//
// All stubs use render functions (not template strings) because the
// runtime-only Vue 2 build vite ships doesn't include the template
// compiler — template strings throw at mount time.
vi.mock('@nextcloud/vue/dist/Components/NcDialog.js', () => ({
	default: {
		name: 'NcDialog',
		props: ['name', 'open', 'size'],
		render(h) {
			return h('div', { class: 'nc-dialog-stub' }, this.$slots.default)
		},
	},
}))

vi.mock('@nextcloud/vue/dist/Components/NcButton.js', () => ({
	default: {
		name: 'NcButton',
		props: ['type', 'disabled'],
		render(h) {
			return h(
				'button',
				{
					attrs: { disabled: this.disabled, 'data-type': this.type },
					on: { click: (e) => this.$emit('click', e) },
				},
				this.$slots.default,
			)
		},
	},
}))

vi.mock('@nextcloud/vue/dist/Components/NcSelect.js', () => ({
	default: {
		name: 'NcSelect',
		props: {
			value: { default: () => [] },
			options: { default: () => [] },
			multiple: Boolean,
			inputLabel: String,
			label: String,
			trackBy: String,
		},
		// Render the input-label as a data-attribute probe surface + a
		// span listing the currently selected option values so tests can
		// read them.
		render(h) {
			const values = (this.value || []).map(v => v.value).join(',')
			return h(
				'div',
				{ class: 'nc-select-stub', attrs: { 'data-label': this.inputLabel } },
				[
					h('label', this.inputLabel),
					h('span', { class: 'nc-select-stub__values' }, values),
				],
			)
		},
	},
}))

import PermissionsModal from '../../../src/modals/PermissionsModal.vue'

/**
 * Convenience helper — find an NcSelect stub by its input-label prop.
 *
 * @param wrapper The vue-test-utils mount() wrapper.
 * @param label The exact inputLabel text to match.
 * @return The first matching wrapper (or an empty wrapper if absent).
 */
function findSelectByLabel(wrapper, label) {
	return wrapper.findAll('.nc-select-stub').wrappers.find(w => w.attributes('data-label') === label)
}

describe('PermissionsModal — REQ-OBRBAC-005 / REQ-OBRBAC-007', () => {
	let application
	let availableGroups

	beforeEach(() => {
		application = {
			uuid: 'app-uuid-1',
			slug: 'hello-world',
			permissions: {
				owners: ['team-alpha'],
				editors: ['team-beta'],
				viewers: ['team-gamma'],
			},
		}
		availableGroups = ['team-alpha', 'team-beta', 'team-gamma', 'team-delta']
	})

	describe('initial render', () => {
		it('renders all three NcSelects with the correct input-label per ADR-004 (gate-nc-input-labels)', () => {
			const wrapper = mount(PermissionsModal, {
				propsData: { open: true, application, availableGroups },
			})
			expect(findSelectByLabel(wrapper, 'Owners (full control)')).toBeTruthy()
			expect(findSelectByLabel(wrapper, 'Editors (can save drafts)')).toBeTruthy()
			expect(findSelectByLabel(wrapper, 'Viewers (read-only)')).toBeTruthy()
		})

		it('seeds each picker from the application permissions block', () => {
			const wrapper = mount(PermissionsModal, {
				propsData: { open: true, application, availableGroups },
			})
			expect(findSelectByLabel(wrapper, 'Owners (full control)').text()).toContain('team-alpha')
			expect(findSelectByLabel(wrapper, 'Editors (can save drafts)').text()).toContain('team-beta')
			expect(findSelectByLabel(wrapper, 'Viewers (read-only)').text()).toContain('team-gamma')
		})

		it('renders zero entries when the application has no permissions block', () => {
			const wrapper = mount(PermissionsModal, {
				propsData: {
					open: true,
					application: { uuid: 'fresh', slug: 'fresh', permissions: undefined },
					availableGroups,
				},
			})
			expect(findSelectByLabel(wrapper, 'Owners (full control)').text()).not.toContain('team-')
		})
	})

	describe('save — happy path (REQ-OBRBAC-007)', () => {
		it("emits 'save' with the three permission arrays when owners is non-empty", async () => {
			const wrapper = mount(PermissionsModal, {
				propsData: { open: true, application, availableGroups },
			})
			// Click the primary Save button (second NcButton stub).
			const buttons = wrapper.findAll('button')
			await buttons.at(buttons.length - 1).trigger('click')

			expect(wrapper.emitted('save')).toBeTruthy()
			const payload = wrapper.emitted('save')[0][0]
			expect(payload).toEqual({
				owners: ['team-alpha'],
				editors: ['team-beta'],
				viewers: ['team-gamma'],
			})
		})

		it('transmits owner changes through to the save payload', async () => {
			const wrapper = mount(PermissionsModal, {
				propsData: { open: true, application, availableGroups },
			})
			// Simulate the user adding a second owner via the picker —
			// we mutate the v-model directly because the NcSelect stub
			// does not implement two-way binding. The component's `save`
			// method maps the model array → string values.
			await wrapper.setData({
				ownersModel: [
					{ label: 'team-alpha', value: 'team-alpha' },
					{ label: 'team-delta', value: 'team-delta' },
				],
			})
			const buttons = wrapper.findAll('button')
			await buttons.at(buttons.length - 1).trigger('click')
			expect(wrapper.emitted('save')[0][0].owners).toEqual(['team-alpha', 'team-delta'])
		})
	})

	describe('orphan-check guard — REQ-OBRBAC-005', () => {
		it('rejects an owners = [] save and surfaces the inline error', async () => {
			const wrapper = mount(PermissionsModal, {
				propsData: { open: true, application, availableGroups },
			})
			// Clear the owners model.
			await wrapper.setData({ ownersModel: [] })

			const buttons = wrapper.findAll('button')
			await buttons.at(buttons.length - 1).trigger('click')

			// No save event must be emitted.
			expect(wrapper.emitted('save')).toBeFalsy()
			// The inline orphan-error must be visible.
			expect(wrapper.find('.openbuilt-permissions-modal__error').exists()).toBe(true)
			expect(wrapper.find('.openbuilt-permissions-modal__error').text()).toMatch(/owner/i)
		})

		it('clears the orphan error when the application prop is re-supplied', async () => {
			const wrapper = mount(PermissionsModal, {
				propsData: { open: true, application, availableGroups },
			})
			await wrapper.setData({ ownersModel: [] })
			const buttons = wrapper.findAll('button')
			await buttons.at(buttons.length - 1).trigger('click')
			expect(wrapper.find('.openbuilt-permissions-modal__error').exists()).toBe(true)

			// Re-supply application — the watcher re-syncs from props and
			// resets `orphanError` to false.
			await wrapper.setProps({
				application: {
					uuid: 'app-uuid-2',
					slug: 'fresh',
					permissions: { owners: ['team-omega'], editors: [], viewers: [] },
				},
			})
			expect(wrapper.find('.openbuilt-permissions-modal__error').exists()).toBe(false)
		})
	})

	describe('close emits update:open', () => {
		it("emits 'update:open' with false when Cancel is clicked", async () => {
			const wrapper = mount(PermissionsModal, {
				propsData: { open: true, application, availableGroups },
			})
			const cancelBtn = wrapper.findAll('button').at(0)
			await cancelBtn.trigger('click')
			expect(wrapper.emitted('update:open')).toBeTruthy()
			expect(wrapper.emitted('update:open')[0]).toEqual([false])
		})
	})
})
