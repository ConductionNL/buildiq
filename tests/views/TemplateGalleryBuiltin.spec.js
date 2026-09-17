/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Store lists Buildiq's built-in templates again
 * (store-shows-built-in-templates). Before, the Templates tab listed only
 * GitHub repositories, and the four seeded templates appeared nowhere.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

vi.mock('../../src/modals/CloneTemplateDialog.vue', () => ({
	default: {
		name: 'CloneTemplateDialog',
		props: ['open', 'template', 'github', 'githubRepo'],
		emits: ['close', 'installed', 'submit'],
		methods: {
			setError(message) {
				this.lastError = message
			},
		},
		render() {
			return null
		},
	},
}))

import TemplateGallery from '../../src/views/TemplateGallery.vue'

const templates = [
	{
		id: 'o1',
		slug: 'aaba',
		title: 'Voorbeeld Title 1',
		category: 'government-services',
		isSeeded: false,
		description: 'Example',
	},
	{
		id: 't1',
		slug: 'permit-tracker',
		title: 'Permit Tracker',
		category: 'government-services',
		useCase: 'Municipal building-permit workflow',
		description: 'Permits.',
		isSeeded: true,
	},
	{
		id: 't2',
		slug: 'stakeholder-consultation',
		title: 'Stakeholder Consultation',
		category: 'citizen-engagement',
		description: 'Consultations.',
		isSeeded: true,
	},
	{
		id: 't3',
		slug: 'employee-onboarding',
		title: 'Employee Onboarding',
		category: 'internal-operations',
		description: 'Onboarding.',
		isSeeded: true,
	},
	{
		id: 't4',
		slug: 'incident-reporter',
		title: 'Incident Reporter',
		category: 'field-work',
		description: 'Incidents.',
		isSeeded: true,
	},
]

const githubCard = {
	owner: 'conduction',
	repo: 'petstore',
	slug: 'petstore',
	name: 'Pet Store',
	installable: true,
	unparseable: false,
	credentials: [],
}

async function mountGallery({ templatesResponse } = {}) {
	axiosMock.get.mockImplementation((url) => {
		const u = String(url)
		if (u.includes('application-template')) {
			return (
				templatesResponse
				|| Promise.resolve({ data: { results: templates } })
			)
		}
		if (u.includes('shop/github/search')) {
			return Promise.resolve({ data: { outcome: 'ok', cards: [githubCard] } })
		}
		return Promise.resolve({ data: [] })
	})
	const $router = {
		options: { routes: [{ name: 'PageEditor' }, { name: 'VirtualApps' }] },
		push: vi.fn(),
	}
	const wrapper = mount(TemplateGallery, {
		global: {
			mocks: { $router },
			stubs: {
				NcButton: {
					template:
						'<button class="nc-button-stub" @click="$emit(\'click\')"><slot /></button>',
				},
				NcTextField: true,
				NcSelect: true,
				NcLoadingIcon: true,
				NcEmptyContent: {
					props: ['name'],
					template: '<div class="nc-empty-stub">{{ name }}</div>',
				},
				NcNoteCard: { template: '<div class="nc-note-stub"><slot /></div>' },
			},
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return { wrapper, $router }
}

describe('TemplateGallery built-in templates', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('reads the application-template records of the buildiq register', async () => {
		await mountGallery()
		const urls = axiosMock.get.mock.calls.map((c) => String(c[0]))
		expect(urls).toContain(
			'/apps/openregister/api/objects/buildiq/application-template',
		)
	})

	it('lists the four seeded templates first, each with its category badge and a Use this template action', async () => {
		const { wrapper } = await mountGallery()
		const cards = wrapper.findAll('[data-testid="builtin-template-card"]')

		expect(cards.map((c) => c.find('.template-card__title').text())).toEqual([
			'Permit Tracker',
			'Stakeholder Consultation',
			'Employee Onboarding',
			'Incident Reporter',
			'Voorbeeld Title 1',
		])
		expect(cards[0].find('.template-card__category').text()).toBe(
			'Government services',
		)
		expect(cards[3].find('.template-card__category').text()).toBe('Field work')
		expect(cards[0].text()).toContain('Use this template')
	})

	it('badges organisation templates, and only those', async () => {
		const { wrapper } = await mountGallery()
		const cards = wrapper.findAll('[data-testid="builtin-template-card"]')

		expect(cards[4].find('.template-card__badge').text()).toBe(
			'Organisation template',
		)
		expect(cards[0].find('.template-card__badge').exists()).toBe(false)
	})

	it('keeps the GitHub cards below the built-in section', async () => {
		const { wrapper } = await mountGallery()
		const html = wrapper.html()

		expect(html.indexOf('Permit Tracker')).toBeLessThan(
			html.indexOf('Pet Store'),
		)
	})

	it('says so when no template is seeded', async () => {
		const { wrapper } = await mountGallery({
			templatesResponse: Promise.resolve({ data: { results: [] } }),
		})
		expect(
			wrapper.find('[data-testid="builtin-templates"] .nc-empty-stub').text(),
		).toBe('No built-in templates yet')
	})

	it('shows a warning, and still the GitHub cards, when the templates fail to load', async () => {
		const { wrapper } = await mountGallery({
			templatesResponse: Promise.reject(new Error('boom')),
		})
		expect(
			wrapper.find('[data-testid="builtin-templates"] .nc-note-stub').exists(),
		).toBe(true)
		expect(wrapper.text()).toContain('Pet Store')
	})

	it('Use this template opens the dialog in local mode for that template', async () => {
		const { wrapper } = await mountGallery()
		await wrapper
			.findAll('[data-testid="builtin-template-card"]')[0]
			.find('button')
			.trigger('click')

		const dialog = wrapper.findComponent({ name: 'CloneTemplateDialog' })
		expect(dialog.props('open')).toBe(true)
		expect(dialog.props('github')).toBe(false)
		expect(dialog.props('template').slug).toBe('permit-tracker')
	})

	it('creates the app from the template and opens it', async () => {
		axiosMock.post.mockResolvedValue({
			data: { uuid: 'u1', slug: 'my-permits', warnings: [] },
		})
		const { wrapper, $router } = await mountGallery()
		wrapper.vm.openClone(templates[1])
		await wrapper.vm.$nextTick()

		const payload = {
			name: 'My permits',
			slug: 'my-permits',
			description: 'North district',
		}
		await wrapper
			.findComponent({ name: 'CloneTemplateDialog' })
			.vm.$emit('submit', payload)
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(axiosMock.post).toHaveBeenCalledWith(
			'/apps/buildiq/api/applications/from-template/permit-tracker',
			payload,
		)
		expect(wrapper.vm.cloneOpen).toBe(false)
		expect($router.push).toHaveBeenCalledWith({
			name: 'PageEditor',
			params: { slug: 'my-permits' },
		})
	})

	it('tells a non-admin that only administrators can create from a template', async () => {
		axiosMock.post.mockRejectedValue({
			response: { status: 403, data: { error: 'forbidden' } },
		})
		const { wrapper } = await mountGallery()
		wrapper.vm.openClone(templates[1])
		await wrapper.vm.$nextTick()

		const dialog = wrapper.findComponent({ name: 'CloneTemplateDialog' })
		await dialog.vm.$emit('submit', { name: 'A', slug: 'a-b', description: '' })
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(dialog.vm.lastError).toBe(
			'Only administrators can create an app from a template.',
		)
	})

	it('keeps the dialog open with a clear message when the slug is taken', async () => {
		const tSpy = vi.spyOn(globalThis, 't')
		axiosMock.post.mockRejectedValue({
			response: { data: { error: 'slug_collision', detail: 'my-permits' } },
		})
		const { wrapper, $router } = await mountGallery()
		wrapper.vm.openClone(templates[1])
		await wrapper.vm.$nextTick()

		const dialog = wrapper.findComponent({ name: 'CloneTemplateDialog' })
		await dialog.vm.$emit('submit', {
			name: 'My permits',
			slug: 'my-permits',
			description: '',
		})
		await new Promise((resolve) => setTimeout(resolve, 0))

		expect(wrapper.vm.cloneOpen).toBe(true)
		// The test t() stub returns the key; the slug arrives as a variable.
		expect(dialog.vm.lastError).toBe(
			'An app with the slug {slug} already exists. Choose another slug.',
		)
		expect(tSpy).toHaveBeenCalledWith(
			'buildiq',
			'An app with the slug {slug} already exists. Choose another slug.',
			{ slug: 'my-permits' },
		)
		expect($router.push).not.toHaveBeenCalled()
	})
})
