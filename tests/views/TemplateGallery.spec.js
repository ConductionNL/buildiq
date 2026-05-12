/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for `src/views/TemplateGallery.vue`.
 *
 * Covers:
 *   - REQ-OBTC-003: render the four seeded ApplicationTemplate cards
 *   - REQ-OBTC-006: filter by category (government-services) leaves only
 *     `permit-tracker` visible
 *   - REQ-OBTC-006: free-text search over title/useCase/description narrows
 *     the visible set
 *   - REQ-OBTC-004: "Use this template" CTA triggers an axios POST to
 *     `/apps/openbuilt/api/applications/from-template/{slug}` with the
 *     payload from the clone dialog
 *   - REQ-OBTC-008: on successful clone, the gallery navigates to the
 *     page editor surface for the newly cloned application
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/axios', () => ({
	default: axiosMock,
}))

vi.mock('../../src/modals/CloneTemplateDialog.vue', () => ({
	default: {
		name: 'CloneTemplateDialog',
		props: ['open', 'template'],
		emits: ['close', 'submit'],
		methods: { setError(message) { this.lastError = message } },
		render() { return null },
	},
}))

import TemplateGallery from '../../src/views/TemplateGallery.vue'

const seededTemplates = [
	{
		uuid: 'tpl-1',
		slug: 'permit-tracker',
		title: 'Permit Tracker',
		useCase: 'Municipal building-permit workflow',
		description: 'Index + form + kanban for permits.',
		category: 'government-services',
		screenshotUrl: 'img/templates/permit-tracker.svg',
		isSeeded: true,
	},
	{
		uuid: 'tpl-2',
		slug: 'stakeholder-consultation',
		title: 'Stakeholder Consultation',
		useCase: 'Public consultation rounds',
		description: 'Collect citizen feedback on draft policies.',
		category: 'citizen-engagement',
		isSeeded: true,
	},
	{
		uuid: 'tpl-3',
		slug: 'employee-onboarding',
		title: 'Employee Onboarding',
		useCase: 'New-hire checklist tracker',
		description: 'Steps + documents + signoffs.',
		category: 'internal-operations',
		isSeeded: true,
	},
	{
		uuid: 'tpl-4',
		slug: 'incident-reporter',
		title: 'Incident Reporter',
		useCase: 'Field-team incident logging',
		description: 'Capture + triage + route to teams.',
		category: 'field-work',
		isSeeded: true,
	},
]

/**
 * Mount helper that injects a $router stub and the seeded fetch response.
 *
 * @param {object} routerOverrides optional stub overrides
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountGallery(routerOverrides = {}) {
	axiosMock.get.mockResolvedValueOnce({ data: { results: seededTemplates } })

	const $router = {
		resolve: vi.fn().mockReturnValue({ resolved: { matched: [{}], fullPath: '/applications/my-permits' } }),
		push: vi.fn(),
		...routerOverrides,
	}

	const wrapper = mount(TemplateGallery, {
		mocks: {
			$router,
		},
		stubs: {
			NcButton: {
				name: 'NcButton',
				template: '<button class="nc-button-stub" @click="$emit(\'click\', $event)"><slot /></button>',
			},
			NcTextField: {
				name: 'NcTextField',
				props: ['value', 'label', 'placeholder'],
				template: '<input class="nc-textfield-stub" :value="value" @input="$emit(\'update:value\', $event.target.value)" />',
			},
			NcSelect: {
				name: 'NcSelect',
				props: ['value', 'options'],
				template: '<select class="nc-select-stub" @change="$emit(\'input\', { id: $event.target.value })"><option v-for="o in options" :key="o.id" :value="o.id">{{ o.label }}</option></select>',
			},
			NcLoadingIcon: true,
			NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<div class="nc-empty-stub">{{ name }}</div>' },
		},
	})

	// Wait for the mounted() axios call to resolve.
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return { wrapper, $router }
}

describe('TemplateGallery.vue', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('renders the four seeded templates after mount', async () => {
		const { wrapper } = await mountGallery()

		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get.mock.calls[0][0]).toContain('/apps/openregister/api/objects/openbuilt/application-template')

		const cards = wrapper.findAll('.template-card')
		expect(cards.length).toBe(4)
		const titles = cards.wrappers.map((c) => c.find('.template-card__title').text())
		expect(titles).toEqual(
			expect.arrayContaining([
				'Permit Tracker',
				'Stakeholder Consultation',
				'Employee Onboarding',
				'Incident Reporter',
			]),
		)
	})

	it('filters by category — only permit-tracker remains when government-services is selected', async () => {
		const { wrapper } = await mountGallery()

		// The component stores categoryFilter as `{ id }` (from NcSelect). Set it directly.
		wrapper.vm.categoryFilter = { id: 'government-services' }
		await wrapper.vm.$nextTick()

		const cards = wrapper.findAll('.template-card')
		expect(cards.length).toBe(1)
		expect(cards.at(0).find('.template-card__title').text()).toBe('Permit Tracker')
	})

	it('free-text search narrows by title/useCase/description', async () => {
		const { wrapper } = await mountGallery()

		wrapper.vm.search = 'incident'
		await wrapper.vm.$nextTick()

		const cards = wrapper.findAll('.template-card')
		expect(cards.length).toBe(1)
		expect(cards.at(0).find('.template-card__title').text()).toBe('Incident Reporter')
	})

	it('"Use this template" CTA fires axios POST to createFromTemplate endpoint', async () => {
		const { wrapper } = await mountGallery()

		axiosMock.post.mockResolvedValueOnce({ data: { uuid: 'new-app', slug: 'my-permits' } })

		// Trigger the clone flow on the first template (permit-tracker).
		wrapper.vm.openClone(seededTemplates[0])
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.cloneOpen).toBe(true)
		expect(wrapper.vm.cloneTarget.slug).toBe('permit-tracker')

		await wrapper.vm.onCloneSubmit({ name: 'My permits', slug: 'my-permits' })

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, payload] = axiosMock.post.mock.calls[0]
		expect(url).toContain('/apps/openbuilt/api/applications/from-template/permit-tracker')
		expect(payload).toEqual({ name: 'My permits', slug: 'my-permits' })
	})

	it('on clone success, redirects to the page editor surface', async () => {
		const { wrapper, $router } = await mountGallery()

		axiosMock.post.mockResolvedValueOnce({ data: { uuid: 'new-app', slug: 'my-permits' } })

		wrapper.vm.openClone(seededTemplates[0])
		await wrapper.vm.onCloneSubmit({ name: 'My permits', slug: 'my-permits' })

		// PageEditor route resolves (stubbed) → push is invoked with its fullPath.
		expect($router.resolve).toHaveBeenCalled()
		expect($router.push).toHaveBeenCalled()
		const firstResolveArgs = $router.resolve.mock.calls[0][0]
		expect(firstResolveArgs.name).toBe('PageEditor')
		expect(firstResolveArgs.params.slug).toBe('my-permits')
	})
})
