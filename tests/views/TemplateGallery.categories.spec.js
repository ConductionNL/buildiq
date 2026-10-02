/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: the app store's Templates view by category (REQ-BQGL-001).
 *
 * @spec openspec/changes/reuse-gallery-categories-and-form-library/specs/template-catalogue-ui/spec.md#requirement-templates-can-be-filtered-and-browsed-by-category-req-bqgl-001
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

vi.mock('../../src/modals/CloneTemplateDialog.vue', () => ({
	default: {
		name: 'CloneTemplateDialog',
		props: ['open', 'template', 'github', 'githubRepo'],
		render() {
			return null
		},
	},
}))

import TemplateGallery from '../../src/views/TemplateGallery.vue'

const templates = [
	{
		id: 't1',
		slug: 'permit-tracker',
		title: 'Permit Tracker',
		category: 'government-services',
		isSeeded: true,
	},
	{
		id: 't4',
		slug: 'incident-reporter',
		title: 'Incident Reporter',
		category: 'field-work',
		isSeeded: true,
	},
	{
		id: 'o1',
		slug: 'meldingen',
		title: 'Meldingen',
		category: 'field-work',
		isSeeded: false,
	},
	{ id: 'o2', slug: 'loose', title: 'Loose', isSeeded: false },
]

const githubCards = [
	{
		owner: 'a',
		repo: 'one',
		name: 'Street lights',
		category: 'field-work',
		installable: true,
	},
	{
		owner: 'a',
		repo: 'two',
		name: 'Permits plus',
		category: 'government-services',
		installable: true,
	},
	{
		owner: 'a',
		repo: 'three',
		name: 'Hackathon',
		category: 'internal-operations',
		installable: true,
	},
]

const libraryForms = [
	{
		slug: 'aanvraag-energiesubsidie',
		name: 'Aanvraag energiesubsidie',
		category: 'citizen-engagement',
		publisher: 'Gemeente Voorbeeld',
	},
	{
		slug: 'melding-openbare-ruimte',
		name: 'Melding openbare ruimte',
		category: 'field-work',
		publisher: 'Gemeente Elders',
	},
	{
		slug: 'verlofaanvraag',
		name: 'Verlofaanvraag',
		category: 'internal-operations',
		publisher: 'HR',
	},
]

const githubForms = [
	{
		owner: 'b',
		repo: 'parkeren',
		name: 'Parkeervergunning',
		category: 'government-services',
		publisher: 'b',
		installable: true,
		htmlUrl: '',
	},
	{
		owner: 'b',
		repo: 'schouw',
		name: 'Schouwronde',
		category: 'field-work',
		publisher: 'b',
		installable: true,
		htmlUrl: '',
	},
]

/**
 * Mount the gallery with a route query and a router double.
 *
 * @param {object} query The route query.
 * @return {Promise<{wrapper: object, $router: object}>}
 */
async function mountGallery(query = {}) {
	axiosMock.get.mockImplementation((url) => {
		const u = String(url)
		if (u.includes('application-template')) {
			return Promise.resolve({ data: { results: templates } })
		}
		if (u.includes('shop/github/search')) {
			return Promise.resolve({ data: { outcome: 'ok', cards: githubCards } })
		}
		if (u.includes('form-template')) {
			return Promise.resolve({ data: { results: libraryForms } })
		}
		if (u.includes('shop/github/forms')) {
			return Promise.resolve({ data: { outcome: 'ok', cards: githubForms } })
		}
		return Promise.resolve({ data: [] })
	})
	const $route = { query }
	const $router = {
		options: { routes: [] },
		push: vi.fn(),
		replace: vi.fn((to) => {
			$route.query = to.query
			return Promise.resolve()
		}),
	}
	const wrapper = mount(TemplateGallery, {
		global: {
			mocks: { $route, $router },
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcTextField: true,
				NcLoadingIcon: true,
				NcEmptyContent: {
					props: ['name'],
					template: '<div class="nc-empty-stub">{{ name }}</div>',
				},
				NcNoteCard: { template: '<div><slot /></div>' },
				NcSelect: {
					name: 'NcSelect',
					props: ['modelValue', 'options', 'inputLabel'],
					emits: ['update:modelValue'],
					template:
						'<div class="nc-select-stub" :data-label="inputLabel"></div>',
				},
			},
		},
	})
	await new Promise((resolve) => setTimeout(resolve, 0))
	await wrapper.vm.$nextTick()
	return { wrapper, $router }
}

/**
 * The visible built-in card titles per category heading.
 *
 * @param {object} wrapper The mounted gallery.
 * @return {Array<[string, Array<string>]>}
 */
function groups(wrapper) {
	return wrapper
		.findAll('[data-testid="template-category-group"]')
		.map((g) => [
			g.find('.template-gallery__category-title').text(),
			g.findAll('.template-card__title').map((c) => c.text()),
		])
}

/**
 * The visible GitHub card titles.
 *
 * @param {object} wrapper The mounted gallery.
 * @return {Array<string>}
 */
function githubTitles(wrapper) {
	return wrapper
		.findAll('[data-testid="github-card"] .template-card__title')
		.map((c) => c.text())
}

/**
 * The category filter of the Templates view.
 *
 * @param {object} wrapper The mounted gallery.
 * @return {object}
 */
function filter(wrapper) {
	return wrapper
		.findAllComponents({ name: 'NcSelect' })
		.find((s) => s.attributes('data-testid') === 'template-category-filter')
}

describe('TemplateGallery: templates by category (REQ-BQGL-001)', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})

	it('groups the built-in and organisation templates under a heading per category, with uncategorised ones under Other', async () => {
		const { wrapper } = await mountGallery()

		expect(groups(wrapper)).toEqual([
			['Government services', ['Permit Tracker']],
			['Field work', ['Incident Reporter', 'Meldingen']],
			['Other', ['Loose']],
		])
		expect(githubTitles(wrapper)).toEqual([
			'Street lights',
			'Permits plus',
			'Hackathon',
		])
	})

	it('narrows templates and GitHub apps to the picked category and keeps it in ?category=', async () => {
		const { wrapper, $router } = await mountGallery()

		filter(wrapper).vm.$emit('update:modelValue', {
			id: 'field-work',
			label: 'Field work',
		})
		await wrapper.vm.$nextTick()

		expect(groups(wrapper)).toEqual([
			['Field work', ['Incident Reporter', 'Meldingen']],
		])
		expect(githubTitles(wrapper)).toEqual(['Street lights'])
		expect($router.replace).toHaveBeenCalledWith({
			query: { category: 'field-work' },
		})
	})

	it('opens a shared link on the category it names', async () => {
		const { wrapper } = await mountGallery({ category: 'government-services' })

		expect(filter(wrapper).props('modelValue')).toEqual({
			id: 'government-services',
			label: 'Government services',
		})
		expect(groups(wrapper)).toEqual([
			['Government services', ['Permit Tracker']],
		])
		expect(githubTitles(wrapper)).toEqual(['Permits plus'])
	})

	it('clearing the filter shows everything again and drops the query', async () => {
		const { wrapper, $router } = await mountGallery({
			category: 'field-work',
			tab: 'x',
		})

		filter(wrapper).vm.$emit('update:modelValue', null)
		await wrapper.vm.$nextTick()

		expect(groups(wrapper).map(([heading]) => heading)).toEqual([
			'Government services',
			'Field work',
			'Other',
		])
		expect($router.replace).toHaveBeenCalledWith({ query: { tab: 'x' } })
	})

	it('ignores a category in the link that is not one of the four', async () => {
		const { wrapper } = await mountGallery({ category: 'nonsense' })

		expect(filter(wrapper).props('modelValue')).toBe(null)
		expect(groups(wrapper)).toHaveLength(3)
	})
})

describe('TemplateGallery forms view (REQ-BQGL-003, REQ-BQGL-005)', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	/**
	 * Open the "Forms" tab.
	 *
	 * @param {object} query The route query.
	 * @return {Promise<object>}
	 */
	async function openForms(query = {}) {
		const { wrapper, $router } = await mountGallery(query)
		await wrapper.find('[data-testid="forms-tab"]').trigger('click')
		await new Promise((resolve) => setTimeout(resolve, 0))
		await wrapper.vm.$nextTick()
		return { wrapper, $router }
	}

	/**
	 * The names on the library form cards.
	 *
	 * @param {object} wrapper The mounted gallery.
	 * @return {Array<string>}
	 */
	function formNames(wrapper) {
		return wrapper
			.findAll('[data-testid="library-form-card"] h3')
			.map((h) => h.text())
	}

	it('lists the library forms with their category and publisher', async () => {
		const { wrapper } = await openForms()

		expect(formNames(wrapper)).toEqual([
			'Aanvraag energiesubsidie',
			'Melding openbare ruimte',
			'Verlofaanvraag',
		])
		const card = wrapper.find('[data-testid="library-form-card"]')
		expect(card.text()).toContain('Citizen engagement')
		expect(card.text()).toContain('Published by {publisher}')
	})

	it('finds a form by name', async () => {
		const { wrapper } = await openForms()
		const view = wrapper.findComponent({ name: 'FormLibraryView' })

		view.vm.onQuery('subsidie')
		await wrapper.vm.$nextTick()

		expect(formNames(wrapper)).toEqual(['Aanvraag energiesubsidie'])
	})

	it('narrows library and GitHub forms by the category in the link', async () => {
		const { wrapper } = await openForms({ category: 'field-work' })

		expect(formNames(wrapper)).toEqual(['Melding openbare ruimte'])
		expect(
			wrapper
				.findAll('[data-testid="github-form-card"] h3')
				.map((h) => h.text()),
		).toEqual(['Schouwronde'])
	})

	it('refuses a file that is not a form export and creates nothing', async () => {
		const { wrapper } = await openForms()
		const view = wrapper.findComponent({ name: 'FormLibraryView' })

		await view.vm.importText(
			JSON.stringify({
				schemaVersion: '1.0',
				kind: 'component-block',
				block: {},
			}),
		)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[role="alert"]').text()).toBe(
			'This file is not a form export.',
		)
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('imports a form export into the library', async () => {
		axiosMock.post.mockResolvedValue({ data: {} })
		const { wrapper } = await openForms()
		const view = wrapper.findComponent({ name: 'FormLibraryView' })
		const exported = {
			schemaVersion: '1.0',
			kind: 'form-template',
			form: {
				slug: 'nieuw-formulier',
				name: 'Nieuw formulier',
				category: 'citizen-engagement',
				kind: 'registration-form',
				publisher: 'Gemeente Ander',
				form: { fields: [{ name: 'naam' }] },
				schemaFragment: { naam: { type: 'string' } },
			},
		}

		await view.vm.importText(JSON.stringify(exported))

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, record] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/apps/openregister/api/objects/buildiq/form-template')
		expect(record.publisher).toBe('Gemeente Ander')
		expect(record.category).toBe('citizen-engagement')
	})
})
