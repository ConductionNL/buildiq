/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for VirtualAppsActions.vue beyond `setFilter` (pinned in
 * VirtualAppsActions.filter.spec.js): the active filter read from the route,
 * the pressed filter button, the Add app button opening the wizard, and
 * `onWizardCreated` navigating only when a uuid is given.
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import VirtualAppsActions from '../../src/components/VirtualAppsActions.vue'

const NcButtonStub = {
	name: 'NcButton',
	props: ['variant', 'pressed'],
	emits: ['click'],
	template: '<button :data-variant="variant" :aria-pressed="pressed ? \'true\' : \'false\'" @click="$emit(\'click\')"><slot /></button>',
}

const WizardStub = {
	name: 'CreateApplicationWizard',
	props: ['show'],
	emits: ['update:show', 'created'],
	template: '<div class="wizard-stub" :data-show="show ? \'yes\' : \'no\'" />',
}

function mountWith(route, router) {
	return mount(VirtualAppsActions, {
		global: {
			mocks: { $route: route, $router: router },
			stubs: { NcButton: NcButtonStub, CreateApplicationWizard: WizardStub },
		},
	})
}

function routerMock() {
	return { replace: vi.fn(() => Promise.resolve()), push: vi.fn(() => Promise.resolve()) }
}

describe('VirtualAppsActions', () => {
	it.each([
		[{ query: { filter: 'virtual' } }, 'virtual'],
		[{ query: { filter: 'hybrid' } }, 'hybrid'],
		[{ query: { filter: 'all' } }, 'all'],
		[{ query: { filter: 'bogus' } }, 'all'],
		[{ query: {} }, 'all'],
		[{}, 'all'],
		[undefined, 'all'],
	])('reads the active filter from %j as %s', (route, expected) => {
		expect(mountWith(route, routerMock()).vm.activeFilter).toBe(expected)
	})

	it('offers all, virtual and hybrid in that order', () => {
		const wrapper = mountWith({ query: {} }, routerMock())
		expect(wrapper.vm.filterOptions.map((o) => o.value)).toEqual(['all', 'virtual', 'hybrid'])
		const labels = wrapper.findAll('.ob-va-actions__filter button').map((b) => b.text())
		expect(labels).toEqual(['All', 'Virtual', 'Hybrid'])
	})

	it('marks only the active filter button as pressed and secondary', () => {
		const buttons = mountWith({ query: { filter: 'hybrid' } }, routerMock()).findAll('.ob-va-actions__filter button')
		expect(buttons.map((b) => b.attributes('aria-pressed'))).toEqual(['false', 'false', 'true'])
		expect(buttons.map((b) => b.attributes('data-variant'))).toEqual(['tertiary', 'tertiary', 'secondary'])
	})

	it('writes the filter query when a filter button is clicked', async () => {
		const router = routerMock()
		const wrapper = mountWith({ query: {} }, router)
		await wrapper.findAll('.ob-va-actions__filter button')[1].trigger('click')
		expect(router.replace).toHaveBeenCalledWith({ query: { filter: 'virtual' } })
	})

	it('opens the wizard from the Add app button', async () => {
		const wrapper = mountWith({ query: {} }, routerMock())
		expect(wrapper.find('.wizard-stub').attributes('data-show')).toBe('no')
		await wrapper.find('[data-walkthrough-id="add-app"]').trigger('click')
		expect(wrapper.vm.showWizard).toBe(true)
		expect(wrapper.find('.wizard-stub').attributes('data-show')).toBe('yes')
	})

	it('closes the wizard and opens the detail page of the created app', async () => {
		const router = routerMock()
		const wrapper = mountWith({ query: {} }, router)
		wrapper.vm.showWizard = true
		wrapper.findComponent(WizardStub).vm.$emit('created', 'uuid-1')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showWizard).toBe(false)
		expect(router.push).toHaveBeenCalledTimes(1)
		expect(router.push).toHaveBeenCalledWith({ name: 'VirtualAppDetail', params: { objectId: 'uuid-1' } })
	})

	it.each([[undefined], [''], [null]])('only closes the wizard for a created event with uuid %j', (uuid) => {
		const router = routerMock()
		const wrapper = mountWith({ query: {} }, router)
		wrapper.vm.showWizard = true
		wrapper.vm.onWizardCreated(uuid)
		expect(wrapper.vm.showWizard).toBe(false)
		expect(router.push).not.toHaveBeenCalled()
	})

	it('closes the wizard without a router', () => {
		const wrapper = mountWith({ query: {} }, undefined)
		wrapper.vm.showWizard = true
		expect(() => wrapper.vm.onWizardCreated('uuid-1')).not.toThrow()
		expect(wrapper.vm.showWizard).toBe(false)
	})
})
