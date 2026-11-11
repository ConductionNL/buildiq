/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest characterisation tests for `src/dialogs/IconUploadSection.vue`:
 * uploading and removing the light and dark icons (requests, local state,
 * emitted events, input reset and error messages).
 */
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

import axios from '@nextcloud/axios'
import IconUploadSection from '../../src/dialogs/IconUploadSection.vue'

const BASE = '/apps/openregister/api/objects/buildiq/built-app/app-uuid'
const NOW = 1700000000000

/**
 * Mount the section for an application.
 *
 * @param {object} application The Application record.
 * @return {import('@vue/test-utils').Wrapper}
 */
function mountSection(application = { '@self': { id: 'app-uuid' }, slug: 'shop' }) {
	return mount(IconUploadSection, {
		props: { application },
		global: { stubs: { NcButton: true, NcNoteCard: true } },
	})
}

/**
 * A picked SVG file.
 *
 * @param {string} content The file text.
 * @return {{name: string, text: Function}}
 */
function svg(content = '<svg/>') {
	return { name: 'icon.svg', text: () => Promise.resolve(content) }
}

beforeEach(() => {
	for (const fn of Object.values(axios)) {
		fn.mockReset()
	}
	axios.post.mockResolvedValue({ data: {} })
	axios.patch.mockResolvedValue({ data: {} })
	axios.delete.mockResolvedValue({ data: {} })
	vi.spyOn(Date, 'now').mockReturnValue(NOW)
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('uploadIcon', () => {
	it('does nothing without an object uuid', async () => {
		const wrapper = mountSection({ slug: 'shop' })
		await wrapper.vm.uploadIcon(svg(), 'light')
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.uploading).toBe(false)
	})

	it.each([
		['light', 'app-icon.svg', 'icon', 'lightRef', 'lightNonce', 'lightInput'],
		['dark', 'app-icon-dark.svg', 'iconDark', 'darkRef', 'darkNonce', 'darkInput'],
		['other', 'app-icon.svg', 'icon', 'lightRef', 'lightNonce', 'lightInput'],
	])('uploads the %s icon', async (variant, filename, field, refKey, nonceKey, input) => {
		const wrapper = mountSection()
		wrapper.vm.$refs[input].value = ''
		await wrapper.vm.uploadIcon(svg('<svg id="x"/>'), variant)
		expect(axios.post).toHaveBeenCalledWith(`${BASE}/files`, {
			name: filename,
			content: '<svg id="x"/>',
		})
		expect(axios.patch).toHaveBeenCalledWith(BASE, { [field]: { ref: filename } })
		expect(wrapper.vm[refKey]).toBe(filename)
		expect(wrapper.vm[nonceKey]).toBe(NOW)
		expect(wrapper.emitted('updated')).toEqual([[{ field, ref: filename }]])
		expect(wrapper.vm.uploading).toBe(false)
		expect(wrapper.vm.uploadError).toBe('')
	})

	it.each([
		[{ response: { data: { message: 'Too big' } } }, 'Too big'],
		[new Error('down'), 'Upload failed — please try again'],
	])('reports a failed upload %j', async (err, message) => {
		axios.patch.mockRejectedValueOnce(err)
		const wrapper = mountSection({
			'@self': { id: 'app-uuid' },
			slug: 'shop',
			iconDark: { ref: 'old.svg' },
		})
		await wrapper.vm.uploadIcon(svg(), 'dark')
		expect(wrapper.vm.uploadError).toBe(message)
		expect(wrapper.vm.darkRef).toBe('old.svg')
		expect(wrapper.emitted('updated')).toBeUndefined()
		expect(wrapper.vm.uploading).toBe(false)
	})

	it('clears the file input of the variant afterwards, also on failure', async () => {
		axios.post.mockRejectedValueOnce(new Error('down'))
		const wrapper = mountSection()
		const input = wrapper.vm.$refs.darkInput
		const cleared = vi.spyOn(input, 'value', 'set')
		await wrapper.vm.uploadIcon(svg(), 'dark')
		expect(cleared).toHaveBeenCalledWith('')
	})
})

describe('removeIcon', () => {
	it('does nothing without an object uuid', async () => {
		const wrapper = mountSection({ slug: 'shop' })
		await wrapper.vm.removeIcon('dark')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it.each([
		['light', 'app-icon.svg', 'icon', 'lightRef', 'lightNonce'],
		['dark', 'app-icon-dark.svg', 'iconDark', 'darkRef', 'darkNonce'],
	])('deletes the %s attachment by its numeric id and clears the ref', async (variant, filename, field, refKey, nonceKey) => {
		axios.get.mockResolvedValueOnce({
			data: { results: [{ id: 3, title: 'other.svg' }, { id: 9, title: filename }] },
		})
		const wrapper = mountSection({
			'@self': { id: 'app-uuid' },
			icon: { ref: 'app-icon.svg' },
			iconDark: { ref: 'app-icon-dark.svg' },
		})
		await wrapper.vm.removeIcon(variant)
		expect(axios.get).toHaveBeenCalledWith(`${BASE}/files`)
		expect(axios.delete).toHaveBeenCalledWith(`${BASE}/files/9`)
		expect(axios.patch).toHaveBeenCalledWith(BASE, { [field]: null })
		expect(wrapper.vm[refKey]).toBeNull()
		expect(wrapper.vm[nonceKey]).toBe(NOW)
		expect(wrapper.emitted('updated')).toEqual([[{ field, ref: null }]])
		expect(wrapper.vm.uploading).toBe(false)
	})

	it.each([
		[{ data: { results: [{ id: 0, title: 'app-icon.svg' }] } }],
		[{ data: { results: [null] } }],
		[{ data: {} }],
		[null],
	])('only clears the ref when no attachment is listed (%j)', async (listed) => {
		axios.get.mockResolvedValueOnce(listed)
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const wrapper = mountSection()
		await wrapper.vm.removeIcon('light')
		expect(axios.delete).not.toHaveBeenCalled()
		expect(warn).toHaveBeenCalledWith(
			'[buildiq] no OR attachment named app-icon.svg; clearing the ref only',
		)
		expect(axios.patch).toHaveBeenCalledWith(BASE, { icon: null })
		expect(wrapper.emitted('updated')).toEqual([[{ field: 'icon', ref: null }]])
	})

	it.each([
		[{ response: { data: { message: 'Locked' } } }, 'Locked'],
		[new Error('down'), 'Remove failed — please try again'],
	])('reports a failed removal %j', async (err, message) => {
		axios.get.mockRejectedValueOnce(err)
		const wrapper = mountSection({ '@self': { id: 'app-uuid' }, icon: { ref: 'app-icon.svg' } })
		await wrapper.vm.removeIcon('light')
		expect(wrapper.vm.uploadError).toBe(message)
		expect(wrapper.vm.lightRef).toBe('app-icon.svg')
		expect(axios.patch).not.toHaveBeenCalled()
		expect(wrapper.vm.uploading).toBe(false)
	})
})
