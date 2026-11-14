/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the DOM interaction paths of
 * src/dialogs/CreateApplicationWizard/Step3Custom.vue that the method-level
 * spec does not drive: pointer drag-and-drop reordering, the Advanced slug
 * panel, the ↑/↓ buttons and the inline validation messages.
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Step3Custom from '../../../src/dialogs/CreateApplicationWizard/Step3Custom.vue'

/**
 * Mount with a three-row chain.
 *
 * @param {Array<object>} versions Seed rows.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountChain(
	versions = [
		{ name: 'Development', slug: 'development' },
		{ name: 'Acceptance', slug: 'acceptance' },
		{ name: 'Production', slug: 'production' },
	],
) {
	return mount(Step3Custom, {
		propsData: { payload: { preset: 'custom', versions } },
	})
}

/**
 * Slugs of the local rows in display order.
 *
 * @param {import('@vue/test-utils').Wrapper} wrapper
 * @return {string[]}
 */
function slugs(wrapper) {
	return wrapper.vm.localVersions.map((r) => r.slug)
}

/** Return the latest 'update:payload' emit. */
function lastEmit(wrapper) {
	const emitted = wrapper.emitted('update:payload')
	return emitted[emitted.length - 1][0]
}

describe('Step3Custom.vue — drag and drop', () => {
	it('dragging the first row onto the last moves it to the bottom', async () => {
		const wrapper = mountChain()
		const rows = wrapper.findAll('li.wizard-step3__row')
		await rows[0].trigger('dragstart')
		expect(wrapper.vm.dragFromIndex).toBe(0)
		await rows[2].trigger('dragover')
		await rows[2].trigger('drop')
		expect(slugs(wrapper)).toEqual(['acceptance', 'production', 'development'])
		expect(wrapper.vm.dragFromIndex).toBeNull()
		expect(lastEmit(wrapper).versions.map((v) => v.slug)).toEqual([
			'acceptance',
			'production',
			'development',
		])
	})

	it('dropping a row onto itself leaves the order unchanged', async () => {
		const wrapper = mountChain()
		const rows = wrapper.findAll('li.wizard-step3__row')
		await rows[1].trigger('dragstart')
		await rows[1].trigger('drop')
		expect(slugs(wrapper)).toEqual(['development', 'acceptance', 'production'])
		expect(wrapper.vm.dragFromIndex).toBe(1)
	})

	it('a drop without a preceding dragstart is ignored', async () => {
		const wrapper = mountChain()
		await wrapper.findAll('li.wizard-step3__row')[0].trigger('drop')
		expect(slugs(wrapper)).toEqual(['development', 'acceptance', 'production'])
	})

	it('dragend cancels a pending drag so a later drop does nothing', async () => {
		const wrapper = mountChain()
		const rows = wrapper.findAll('li.wizard-step3__row')
		await rows[2].trigger('dragstart')
		await rows[2].trigger('dragend')
		expect(wrapper.vm.dragFromIndex).toBeNull()
		await rows[0].trigger('drop')
		expect(slugs(wrapper)).toEqual(['development', 'acceptance', 'production'])
	})
})

describe('Step3Custom.vue — reorder buttons', () => {
	it('disables ↑ on the first row and ↓ on the last row only', () => {
		const wrapper = mountChain()
		const rows = wrapper.findAll('li.wizard-step3__row')
		const btns = (row) => row.findAll('.wizard-step3__row-actions button')
		expect(btns(rows[0])[0].attributes('disabled')).toBeDefined()
		expect(btns(rows[0])[1].attributes('disabled')).toBeUndefined()
		expect(btns(rows[2])[0].attributes('disabled')).toBeUndefined()
		expect(btns(rows[2])[1].attributes('disabled')).toBeDefined()
	})

	it('clicking ↑ on the last row moves it up one place', async () => {
		const wrapper = mountChain()
		const last = wrapper.findAll('li.wizard-step3__row')[2]
		await last.findAll('.wizard-step3__row-actions button')[0].trigger('click')
		expect(slugs(wrapper)).toEqual(['development', 'production', 'acceptance'])
	})

	it('clicking ↓ on the first row moves it down one place', async () => {
		const wrapper = mountChain()
		const first = wrapper.findAll('li.wizard-step3__row')[0]
		await first.findAll('.wizard-step3__row-actions button')[1].trigger('click')
		expect(slugs(wrapper)).toEqual(['acceptance', 'development', 'production'])
	})
})

describe('Step3Custom.vue — Advanced slug panel', () => {
	it('toggles the slug field open and closed and relabels the button', async () => {
		const wrapper = mountChain()
		const row = () => wrapper.findAll('li.wizard-step3__row')[1]
		expect(row().find('#wizard-version-slug-1').exists()).toBe(false)
		expect(row().find('.wizard-step3__advanced-toggle').text()).toBe('Advanced')

		await row().find('.wizard-step3__advanced-toggle').trigger('click')
		expect(row().find('#wizard-version-slug-1').exists()).toBe(true)
		expect(row().find('.wizard-step3__advanced-toggle').text()).toBe('Hide')
		expect(wrapper.find('#wizard-version-slug-0').exists()).toBe(false)

		await row().find('.wizard-step3__advanced-toggle').trigger('click')
		expect(row().find('#wizard-version-slug-1').exists()).toBe(false)
	})

	it('typing a slug stores it verbatim and detaches it from the name', async () => {
		const wrapper = mountChain()
		await wrapper.findAll('.wizard-step3__advanced-toggle')[0].trigger('click')
		await wrapper.find('#wizard-version-slug-0').setValue('dev')
		expect(wrapper.vm.localVersions[0].slug).toBe('dev')
		expect(wrapper.vm.localVersions[0]._slugManual).toBe(true)

		await wrapper.find('#wizard-version-name-0').setValue('Something Else')
		expect(wrapper.vm.localVersions[0].name).toBe('Something Else')
		expect(wrapper.vm.localVersions[0].slug).toBe('dev')
		expect(wrapper.find('.wizard-step3__row code').text()).toBe('dev')
	})

	it('shows the duplicate-slug message in preference to the pattern error', async () => {
		const wrapper = mountChain()
		await wrapper.findAll('.wizard-step3__advanced-toggle')[2].trigger('click')
		await wrapper.find('#wizard-version-slug-2').setValue('acceptance')

		const alert = wrapper.findAll('li.wizard-step3__row')[2].find('[role="alert"]')
		expect(alert.text()).toBe('Slug `acceptance` is already used in this chain')
		expect(
			wrapper.find('#wizard-version-slug-2').classes(),
		).toContain('wizard-step3__input--error')
		expect(lastEmit(wrapper)._step3Valid).toBe(false)
	})

	it('shows the pattern error for an invalid manual slug and clears it once fixed', async () => {
		const wrapper = mountChain()
		await wrapper.findAll('.wizard-step3__advanced-toggle')[0].trigger('click')
		const input = wrapper.find('#wizard-version-slug-0')

		await input.setValue('Bad Slug')
		const row = () => wrapper.findAll('li.wizard-step3__row')[0]
		expect(row().find('[role="alert"]').exists()).toBe(true)
		expect(row().find('code').classes()).toContain('wizard-step3__slug-chip--error')
		expect(lastEmit(wrapper)._step3Valid).toBe(false)

		await input.setValue('dev')
		expect(row().find('[role="alert"]').exists()).toBe(false)
		expect(lastEmit(wrapper)._step3Valid).toBe(true)
	})

	it('reports an empty version name as the row error', async () => {
		const wrapper = mountChain()
		await wrapper.findAll('.wizard-step3__advanced-toggle')[1].trigger('click')
		await wrapper.find('#wizard-version-name-1').setValue('')

		expect(wrapper.vm.localVersions[1].slug).toBe('')
		const alert = wrapper.findAll('li.wizard-step3__row')[1].find('[role="alert"]')
		expect(alert.text()).toBe('Version name must not be empty.')
		expect(wrapper.find('.wizard-step3__row:nth-child(2) code').text()).toBe('—')
	})
})

describe('Step3Custom.vue — min-row error lifecycle', () => {
	it('clears the min-row error when a row is added afterwards', async () => {
		const wrapper = mount(Step3Custom, {
			propsData: { payload: { preset: 'custom', versions: [] } },
		})
		await wrapper.find('.wizard-step3__btn-remove').trigger('click')
		expect(wrapper.find('p.wizard-step3__error-msg').text()).toBe(
			'At least one version is required',
		)
		await wrapper.find('.wizard-step3__add-btn').trigger('click')
		expect(wrapper.vm.minRowError).toBeNull()
		expect(wrapper.find('p.wizard-step3__error-msg').exists()).toBe(false)
		expect(wrapper.vm.localVersions).toHaveLength(2)
	})

	it('clears the min-row error when the remaining row is renamed', async () => {
		const wrapper = mount(Step3Custom, {
			propsData: { payload: { preset: 'custom', versions: [] } },
		})
		await wrapper.find('.wizard-step3__btn-remove').trigger('click')
		await wrapper.find('#wizard-version-name-0').setValue('Live')
		expect(wrapper.vm.minRowError).toBeNull()
		expect(wrapper.vm.localVersions[0].slug).toBe('live')
	})
})
