/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest characterisation of `FormLibraryView`: the GitHub form search
 * (response shapes and an unreachable GitHub) and every outcome of importing
 * a form export (refusals, a duplicate slug, success and a failed create).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const { axiosMock, parseMock, createMock, fetchMock } = vi.hoisted(() => ({
	axiosMock: { get: vi.fn() },
	parseMock: vi.fn(),
	createMock: vi.fn(),
	fetchMock: vi.fn(),
}))

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => path,
}))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('../../../src/services/formExport.js', async (importOriginal) => ({
	...(await importOriginal()),
	parseFormImport: parseMock,
}))
vi.mock('../../../src/services/formLibrary.js', async (importOriginal) => ({
	...(await importOriginal()),
	createLibraryForm: createMock,
	fetchLibraryForms: fetchMock,
}))

import FormLibraryView from '../../../src/components/store/FormLibraryView.vue'
import { FormImportError } from '../../../src/services/formExport.js'

/**
 * Mount the view and let its first load and search settle.
 *
 * @return {Promise<import('@vue/test-utils').Wrapper>}
 */
async function mountView() {
	const wrapper = mount(FormLibraryView, {
		global: { stubs: { UseLibraryFormDialog: true } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.get.mockResolvedValue({ data: { cards: [], outcome: 'ok' } })
	parseMock.mockReset()
	createMock.mockReset()
	createMock.mockResolvedValue({})
	fetchMock.mockReset()
	fetchMock.mockResolvedValue([{ slug: 'taken', name: 'Taken' }])
})

describe('searchGithub', () => {
	it('sends the trimmed query', async () => {
		const wrapper = await mountView()
		wrapper.vm.query = '  subsidie '
		await wrapper.vm.searchGithub()
		expect(axiosMock.get).toHaveBeenLastCalledWith(
			'/apps/buildiq/api/shop/github/forms',
			{ params: { q: 'subsidie' } },
		)
	})

	it.each([
		[{ cards: [{ id: 1 }], outcome: 'rate_limited' }, [{ id: 1 }], 'rate_limited'],
		[{ cards: 'nope' }, [], 'ok'],
		[null, [], 'ok'],
	])('reads %j', async (data, cards, outcome) => {
		const wrapper = await mountView()
		axiosMock.get.mockResolvedValueOnce({ data })
		await wrapper.vm.searchGithub()
		expect(wrapper.vm.githubCards).toEqual(cards)
		expect(wrapper.vm.githubOutcome).toBe(outcome)
		expect(wrapper.vm.githubLoading).toBe(false)
	})

	it('marks GitHub unreachable when the request fails', async () => {
		const wrapper = await mountView()
		wrapper.vm.githubCards = [{ id: 1 }]
		axiosMock.get.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.searchGithub()
		expect(wrapper.vm.githubCards).toEqual([])
		expect(wrapper.vm.githubOutcome).toBe('github_unreachable')
		expect(wrapper.vm.githubLoading).toBe(false)
	})
})

describe('importText', () => {
	it.each([
		[new FormImportError('invalid-form'), 'This file is a form export, but the form in it is not complete.'],
		[new FormImportError('not-a-form'), 'This file is not a form export.'],
		[new SyntaxError('bad json'), 'This file is not a form export.'],
	])('refuses with %s', async (refusal, message) => {
		const wrapper = await mountView()
		wrapper.vm.notice = 'old'
		parseMock.mockImplementationOnce(() => {
			throw refusal
		})
		await wrapper.vm.importText('{}')
		expect(wrapper.vm.error).toBe(message)
		expect(wrapper.vm.notice).toBe('')
		expect(createMock).not.toHaveBeenCalled()
	})

	it('refuses a slug the library already has', async () => {
		const wrapper = await mountView()
		parseMock.mockReturnValueOnce({ slug: 'taken', name: 'Again' })
		await wrapper.vm.importText('{}')
		expect(wrapper.vm.error).toBe(
			'Your library already has a form with the slug {slug}.',
		)
		expect(createMock).not.toHaveBeenCalled()
	})

	it('skips empty library entries when checking the slug', async () => {
		fetchMock.mockResolvedValue([null, { slug: 'other' }])
		const wrapper = await mountView()
		parseMock.mockReturnValueOnce({ slug: 'new', name: 'New' })
		await wrapper.vm.importText('{}')
		expect(createMock).toHaveBeenCalledWith({ slug: 'new', name: 'New' })
	})

	it('creates the form, says so and reloads the library', async () => {
		const wrapper = await mountView()
		wrapper.vm.error = 'old'
		const record = { slug: 'new', name: 'New' }
		parseMock.mockReturnValueOnce(record)
		fetchMock.mockClear()
		await wrapper.vm.importText('{"x":1}')
		expect(parseMock).toHaveBeenCalledWith('{"x":1}')
		expect(createMock).toHaveBeenCalledWith(record)
		expect(wrapper.vm.notice).toBe('{name} is in your form library.')
		expect(wrapper.vm.error).toBe('')
		expect(fetchMock).toHaveBeenCalledTimes(1)
	})

	it('reports a failed create without a notice', async () => {
		const wrapper = await mountView()
		parseMock.mockReturnValueOnce({ slug: 'new', name: 'New' })
		createMock.mockRejectedValueOnce(new Error('down'))
		fetchMock.mockClear()
		await wrapper.vm.importText('{}')
		expect(wrapper.vm.error).toBe('Adding the form to the library failed.')
		expect(wrapper.vm.notice).toBe('')
		expect(fetchMock).not.toHaveBeenCalled()
	})
})
