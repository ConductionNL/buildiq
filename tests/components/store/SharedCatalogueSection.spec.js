/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest: the app store's "Shared catalogue" section, with only HTTP mocked
 * (REQ-BQRS-001, REQ-BQRS-002, REQ-BQRS-003): a configured catalogue shows its
 * host and cards, an unconfigured one renders nothing and asks once, the
 * search waits 300 ms and sends `q`, an empty result says so, the category
 * narrows the cards, and a catalogue that is down shows one warning without
 * any address.
 *
 * @spec openspec/changes/reuse-remote-store-section/specs/template-catalogue-ui/spec.md#requirement-the-app-store-shows-the-shared-catalogue-as-a-section-req-bqrs-001
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn() },
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))

const realT = globalThis.t
beforeAll(() => {
	globalThis.t = (_app, key, vars = {}) => key.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})
afterAll(() => {
	globalThis.t = realT
})

import SharedCatalogueSection, { SEARCH_DEBOUNCE_MS } from '../../../src/components/store/SharedCatalogueSection.vue'

const STORE = '/apps/buildiq/api/store/templates'
const CARDS = [
	{ slug: 'vergunningen-volgen', title: 'Vergunningen volgen', category: 'government-services', useCase: 'Vergunningen', description: 'Volg aanvragen.', version: '1.2.0' },
	{ slug: 'schouwronde', title: 'Schouwronde', category: 'field-work', description: 'Rondes in de wijk.' },
]

async function mountSection(props = {}) {
	const wrapper = mount(SharedCatalogueSection, {
		props: { categoryLabel: (c) => `label:${c}`, ...props },
		global: {
			mocks: { t: (...args) => globalThis.t(...args) },
			stubs: {
				NcButton: { emits: ['click'], template: '<button class="install" @click="$emit(\'click\')"><slot /></button>' },
				NcTextField: { name: 'NcTextField', props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input class="search" :data-label="label" />' },
				NcLoadingIcon: true,
				NcEmptyContent: { props: ['name'], template: '<div class="empty">{{ name }}</div>' },
				NcNoteCard: { props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('SharedCatalogueSection', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
	})
	afterEach(() => {
		vi.useRealTimers()
	})

	it('shows the catalogue host and one card per template', async () => {
		axiosMock.get.mockResolvedValue({ data: { outcome: 'ok', source: 'store.example.nl', cards: CARDS } })

		const wrapper = await mountSection()

		expect(axiosMock.get).toHaveBeenCalledWith(STORE, { params: {} })
		expect(wrapper.find('h2').text()).toBe('Shared catalogue')
		expect(wrapper.find('[data-testid="shared-catalogue-source"]').text()).toBe('From store.example.nl')
		expect(wrapper.find('.search').attributes('data-label')).toBe('Search the catalogue')
		const cards = wrapper.findAll('[data-testid="shared-catalogue-card"]')
		expect(cards).toHaveLength(2)
		expect(cards[0].text()).toContain('Vergunningen volgen')
		expect(cards[0].text()).toContain('label:government-services')
		expect(cards[0].text()).toContain('Vergunningen')
		expect(cards[0].text()).toContain('Volg aanvragen.')
		expect(cards[0].text()).toContain('v1.2.0')

		await cards[1].find('.install').trigger('click')
		expect(wrapper.emitted('install')[0][0]).toEqual(CARDS[1])
	})

	it('renders nothing when no catalogue is configured, after one request', async () => {
		axiosMock.get.mockResolvedValue({ data: { outcome: 'not_configured', cards: [] } })

		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="shared-catalogue"]').exists()).toBe(false)
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(wrapper.emitted('configured')[0]).toEqual([false])
	})

	it('searches 300 ms after typing stops, with q', async () => {
		vi.useFakeTimers()
		axiosMock.get.mockResolvedValue({ data: { outcome: 'ok', source: 'store.example.nl', cards: CARDS } })
		const wrapper = await mountSection()
		axiosMock.get.mockClear()
		axiosMock.get.mockResolvedValue({ data: { outcome: 'ok', source: 'store.example.nl', cards: [] } })

		const field = wrapper.findComponent({ name: 'NcTextField' })
		field.vm.$emit('update:modelValue', 'verg')
		field.vm.$emit('update:modelValue', 'vergunning')
		vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 1)
		expect(axiosMock.get).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get).toHaveBeenCalledWith(STORE, { params: { q: 'vergunning' } })
		expect(wrapper.find('.empty').text()).toBe('No templates in the catalogue match your search')
	})

	it('narrows the cards to the chosen category', async () => {
		axiosMock.get.mockResolvedValue({ data: { outcome: 'ok', source: 'store.example.nl', cards: CARDS } })

		const wrapper = await mountSection({ category: 'field-work' })

		const titles = wrapper.findAll('[data-testid="shared-catalogue-card"] .template-card__title').map((c) => c.text())
		expect(titles).toEqual(['Schouwronde'])
	})

	it.each(['store_unreachable', 'store_invalid_response'])('shows one warning, without any address, on %s', async (outcome) => {
		axiosMock.get.mockResolvedValue({ data: { outcome, source: 'store.example.nl', cards: [] } })

		const wrapper = await mountSection()

		const note = wrapper.find('[data-testid="shared-catalogue-unreachable"]')
		expect(note.text()).toBe('The shared catalogue could not be reached. Try again later.')
		expect(note.attributes('data-type')).toBe('warning')
		expect(note.text()).not.toMatch(/https?:|store\.example/)
		expect(wrapper.findAll('[data-testid="shared-catalogue-card"]')).toHaveLength(0)
	})

	it('treats a failed request as unreachable', async () => {
		axiosMock.get.mockRejectedValue(new Error('Network Error at https://store.example.nl'))

		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="shared-catalogue-unreachable"]').exists()).toBe(true)
	})
})
