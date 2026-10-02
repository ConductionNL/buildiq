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
