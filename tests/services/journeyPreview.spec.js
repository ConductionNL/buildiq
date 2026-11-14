/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * journeyPreview and JourneyPreviewPane: the stored journey is handed to the
 * real CnJourney with each form step's referenced form, and the preview's run
 * store keeps the run in memory, so advancing through a step with writes[]
 * sends nothing anywhere.
 *
 * @spec openspec/changes/journey-designer/specs/journey-designer/spec.md#requirement-the-preview-must-mount-the-real-renderer
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import JourneyPreviewPane from '../../src/components/journey-editor/JourneyPreviewPane.vue'
import { createPreviewRunStore, PREVIEW_RUN_ID, previewJourney } from '../../src/services/journeyPreview.js'

// The suite aliases '@conduction/nextcloud-vue' to a light stub; hand the pane
// the library's real renderer so the preview is tested against it.
vi.mock('@conduction/nextcloud-vue', async () => ({
	CnJourney: (await import('@conduction/nextcloud-vue/src/components/CnJourney/CnJourney.vue')).default,
}))
const { default: CnJourney } = await import('@conduction/nextcloud-vue/src/components/CnJourney/CnJourney.vue')

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))

const forms = {
	'f-org': { fields: [{ key: 'name', type: 'string', label: 'Name' }], steps: [] },
}
const journey = {
	id: 'j-1',
	title: 'Register',
	steps: [
		{ id: 'org', type: 'form', title: 'Organisation', formId: 'f-org', writes: [{ as: 'org', register: 'crm', schema: 'organisation', mapping: { fields: [{ field: 'name', property: 'name' }] } }] },
		{ id: 'check', type: 'review', title: 'Check' },
		{ id: 'done', type: 'confirmation', title: 'Done', message: 'We have it.' },
	],
}

describe('previewJourney', () => {
	it('gives each form step its referenced form and keeps the rest of the step', () => {
		const out = previewJourney(journey, forms)

		expect(out.id).toBe('j-1')
		expect(out.steps).toHaveLength(2)
		expect(out.steps[0]).toEqual({
			id: 'org',
			type: 'form',
			title: 'Organisation',
			writes: journey.steps[0].writes,
			form: { fields: forms['f-org'].fields, steps: [] },
		})
		expect(out.steps[1]).toEqual({ id: 'check', type: 'review', title: 'Check' })
		expect(journey.steps[0].formId).toBe('f-org')
	})

	it('turns confirmation steps into the success message', () => {
		expect(previewJourney(journey, forms).successMessage).toBe('We have it.')
		expect(previewJourney({ steps: [], successMessage: 'Thanks' }, {}).successMessage).toBe('Thanks')
		expect(previewJourney({ steps: [{ id: 'x', type: 'confirmation' }] }, {}).successMessage).toBeUndefined()
	})

	it('renders a step whose form is gone with no fields rather than dropping it', () => {
		const out = previewJourney({ steps: [{ id: 'a', type: 'form', formId: 'gone' }] }, {})

		expect(out.steps).toEqual([{ id: 'a', type: 'form', form: { fields: [], steps: [] } }])
		expect(out.id).toBe(PREVIEW_RUN_ID)
	})

	it('skips entries that are not steps', () => {
		expect(previewJourney({ steps: [null, 'x', { id: 'r', type: 'review' }] }, {}).steps).toEqual([{ id: 'r', type: 'review' }])
		expect(previewJourney(null, {}).steps).toEqual([])
	})
})

describe('createPreviewRunStore', () => {
	it('keeps answers and position in memory and submits nowhere', async () => {
		const store = createPreviewRunStore('j-1')

		await store.resume('anything')
		expect(store.state.runId).toBeNull()
		await store.save('org', { name: 'Acme' }, 'check')
		await store.save('check', {}, 'check')
		expect(store.state.answers).toEqual({ org: { name: 'Acme' }, check: {} })
		expect(store.state.position).toBe('check')
		expect(store.state.runId).toBe(PREVIEW_RUN_ID)

		expect(await store.submit()).toEqual({ preview: true, answers: { org: { name: 'Acme' }, check: {} } })
		expect(store.state.status).toBe('submitted')

		await store.report(new Error('bad'), { field: 'x' })
		expect(store.state.failures).toEqual([{ message: 'bad', condition: { field: 'x' } }])
	})
})

describe('JourneyPreviewPane', () => {
	let fetchSpy

	beforeEach(() => {
		fetchSpy = vi.fn(() => Promise.reject(new Error('no network in a preview')))
		vi.stubGlobal('fetch', fetchSpy)
		Object.values(http).forEach((fn) => fn.mockReset())
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('mounts the real CnJourney with the rendered journey and its own store', async () => {
		const wrapper = mount(JourneyPreviewPane, { props: { journey, forms } })
		await flushPromises()

		const renderer = wrapper.findComponent(CnJourney)
		expect(renderer.exists()).toBe(true)
		expect(renderer.props('journey')).toEqual(previewJourney(journey, forms))
		expect(renderer.props('store')).toBe(wrapper.vm.store)
		expect(wrapper.find('[data-testid="cn-journey"]').exists()).toBe(true)
	})

	it('advances through a step with writes without any request', async () => {
		const wrapper = mount(JourneyPreviewPane, { props: { journey, forms }, attachTo: document.body })
		await flushPromises()
		const renderer = wrapper.findComponent(CnJourney)

		await renderer.vm.onStepDone({ name: 'Acme' })
		await flushPromises()

		expect(wrapper.vm.store.state.answers).toEqual({ org: { name: 'Acme' } })
		expect(renderer.vm.currentId).toBe('check')
		expect(wrapper.find('[data-testid="cn-journey-review"]').exists()).toBe(true)

		await renderer.vm.submit()
		await flushPromises()
		expect(wrapper.find('[data-testid="journey-preview-submitted"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="cn-journey-done"]').text()).toBe('We have it.')

		expect(fetchSpy).not.toHaveBeenCalled()
		Object.values(http).forEach((fn) => expect(fn).not.toHaveBeenCalled())
		wrapper.unmount()
	})

	it('starts over with a fresh run when the journey changes or on request', async () => {
		const wrapper = mount(JourneyPreviewPane, { props: { journey, forms } })
		await flushPromises()
		const first = wrapper.vm.store
		await first.save('org', { name: 'A' }, 'check')

		await wrapper.setProps({ journey: { ...journey, title: 'Other' } })
		expect(wrapper.vm.store).not.toBe(first)
		expect(wrapper.vm.store.state.answers).toEqual({})

		const second = wrapper.vm.store
		await wrapper.find('[data-testid="journey-preview-restart"]').trigger('click')
		expect(wrapper.vm.store).not.toBe(second)
		expect(wrapper.vm.generation).toBe(2)
	})
})
