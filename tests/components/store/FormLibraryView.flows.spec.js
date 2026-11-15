/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for FormLibraryView's remaining flows, with only HTTP mocked:
 * a library that cannot be read, category filtering of GitHub cards and their
 * chips, the debounced GitHub search, importing a form from a file, adding a
 * GitHub card to the library, opening the use dialog and the notice after use,
 * and the JSON download of an export.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn(), post: vi.fn() },
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

import FormLibraryView from '../../../src/components/store/FormLibraryView.vue'

const LIBRARY = '/apps/openregister/api/objects/buildiq/form-template'
const GITHUB = '/apps/buildiq/api/shop/github/forms'
const CATEGORIES = [
	{ id: 'field-work', label: 'Field work' },
	{ id: 'internal-operations', label: 'Internal operations' },
]

let libraryResponse
let githubResponse

function exportOf(form) {
	return JSON.stringify({ schemaVersion: '1.0', kind: 'form-template', form })
}

const VALID = {
	slug: 'site-visit',
	name: 'Site visit',
	kind: 'form-page',
	category: 'field-work',
	form: { fields: [] },
	schemaFragment: { properties: {} },
}

async function mountView(props = {}) {
	const wrapper = mount(FormLibraryView, {
		props: { categoryOptions: CATEGORIES, ...props },
		global: { stubs: { UseLibraryFormDialog: true } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	libraryResponse = () => Promise.resolve({ data: { results: [{ slug: 'taken', name: 'Taken' }] } })
	githubResponse = () => Promise.resolve({ data: { cards: [], outcome: 'ok' } })
	axiosMock.get.mockReset().mockImplementation((url) => (url === LIBRARY ? libraryResponse() : githubResponse()))
	axiosMock.post.mockReset().mockResolvedValue({ data: {} })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('load', () => {
	it('reads the library with a 200 limit', async () => {
		const wrapper = await mountView()
		expect(axiosMock.get).toHaveBeenCalledWith(LIBRARY, { params: { _limit: 200 } })
		expect(wrapper.vm.forms).toEqual([{ slug: 'taken', name: 'Taken' }])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('empties the list and reports a library that cannot be read', async () => {
		libraryResponse = () => Promise.reject(new Error('down'))
		const wrapper = await mountView()
		expect(wrapper.vm.forms).toEqual([])
		expect(wrapper.vm.error).toBe('The form library could not be read.')
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('GitHub cards and categories', () => {
	const cards = [
		{ owner: 'a', repo: 'one', name: 'One', category: 'field-work', publisher: 'A', installable: true },
		{ owner: 'b', repo: 'two', name: 'Two', category: 'unknown-cat', publisher: 'B', installable: false },
	]

	beforeEach(() => {
		githubResponse = () => Promise.resolve({ data: { cards, outcome: 'ok' } })
	})

	it('shows every card without a category, with the chip label or Other', async () => {
		const wrapper = await mountView()
		const rendered = wrapper.findAll('[data-testid="github-form-card"]')
		expect(rendered).toHaveLength(2)
		expect(rendered[0].find('.bq-form-library__chip').text()).toBe('Field work')
		expect(rendered[1].find('.bq-form-library__chip').text()).toBe('Other')
	})

	it('keeps only the cards of the selected category', async () => {
		const wrapper = await mountView({ category: 'field-work' })
		expect(wrapper.vm.visibleGithubCards.map((c) => c.repo)).toEqual(['one'])
		expect(wrapper.vm.categoryOption).toEqual(CATEGORIES[0])
	})

	it('has no category option for an unknown category id', async () => {
		const wrapper = await mountView({ category: 'nope' })
		expect(wrapper.vm.categoryOption).toBeNull()
		expect(wrapper.vm.visibleGithubCards).toEqual([])
	})

	it('adds an installable card to the library from its export', async () => {
		const wrapper = await mountView()
		await wrapper.vm.install({ export: exportOf(VALID) })
		expect(axiosMock.post).toHaveBeenCalledWith(LIBRARY, VALID)
		expect(wrapper.vm.notice).toBe('Site visit is in your form library.')
	})

	it('refuses a card without an export as not a form export', async () => {
		const wrapper = await mountView()
		await wrapper.vm.install(null)
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('This file is not a form export.')
	})
})

describe('onQuery', () => {
	it('searches GitHub once, 400 ms after the last keystroke', async () => {
		vi.useFakeTimers()
		const wrapper = await mountView()
		axiosMock.get.mockClear()
		wrapper.vm.onQuery('sub')
		wrapper.vm.onQuery('subsidie')
		vi.advanceTimersByTime(399)
		expect(axiosMock.get).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(axiosMock.get).toHaveBeenCalledTimes(1)
		expect(axiosMock.get).toHaveBeenCalledWith(GITHUB, { params: { q: 'subsidie' } })
		expect(wrapper.vm.query).toBe('subsidie')
	})

	it('cancels a pending search on unmount', async () => {
		vi.useFakeTimers()
		const wrapper = await mountView()
		axiosMock.get.mockClear()
		wrapper.vm.onQuery('x')
		wrapper.unmount()
		vi.advanceTimersByTime(1000)
		expect(axiosMock.get).not.toHaveBeenCalled()
	})
})

describe('onImportFile', () => {
	it('ignores a change without a file', async () => {
		const wrapper = await mountView()
		await wrapper.vm.onImportFile({ target: { files: [] } })
		await wrapper.vm.onImportFile(null)
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('')
	})

	it('imports the chosen file, clears the input and reloads the library', async () => {
		const wrapper = await mountView()
		axiosMock.get.mockClear()
		const target = { files: [{ text: async () => exportOf(VALID) }], value: 'C:\\fake\\form.json' }
		await wrapper.vm.onImportFile({ target })
		expect(target.value).toBe('')
		expect(axiosMock.post).toHaveBeenCalledWith(LIBRARY, VALID)
		expect(axiosMock.get).toHaveBeenCalledWith(LIBRARY, { params: { _limit: 200 } })
		expect(wrapper.vm.notice).toBe('Site visit is in your form library.')
	})

	it('reports an incomplete form in a form export', async () => {
		const wrapper = await mountView()
		const target = { files: [{ text: async () => exportOf({ ...VALID, form: null }) }], value: 'x' }
		await wrapper.vm.onImportFile({ target })
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('This file is a form export, but the form in it is not complete.')
	})
})

describe('use and export', () => {
	it('opens the use dialog for a form and reports where it was added', async () => {
		const wrapper = await mountView()
		wrapper.vm.openUse({ slug: 'taken' })
		expect(wrapper.vm.useTarget).toEqual({ slug: 'taken' })
		expect(wrapper.vm.useOpen).toBe(true)
		wrapper.vm.onUsed({ appSlug: 'permits' })
		expect(wrapper.vm.notice).toBe('The form was added to permits.')
	})

	it('downloads a form as <slug>.json', async () => {
		const wrapper = await mountView()
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		const created = []
		const origCreate = URL.createObjectURL
		const origRevoke = URL.revokeObjectURL
		URL.createObjectURL = vi.fn(() => 'blob:x')
		URL.revokeObjectURL = vi.fn()
		const origEl = document.createElement.bind(document)
		const spy = vi.spyOn(document, 'createElement').mockImplementation((tag) => {
			const el = origEl(tag)
			created.push(el)
			return el
		})
		try {
			wrapper.vm.exportForm({ slug: 'taken', uuid: 'u1', name: 'Taken' })
			const link = created.find((el) => el.tagName === 'A')
			expect(link.download).toBe('taken.json')
			expect(click).toHaveBeenCalledTimes(1)
			expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:x')
		} finally {
			spy.mockRestore()
			click.mockRestore()
			URL.createObjectURL = origCreate
			URL.revokeObjectURL = origRevoke
		}
	})
})
