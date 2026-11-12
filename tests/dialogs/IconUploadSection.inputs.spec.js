import axios from '@nextcloud/axios'
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The inputs of IconUploadSection: which object it addresses, the preview
 * URLs, the refs taken from the application, the .svg-only file check and
 * the per-variant remove buttons.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
}))

const IconUploadSection = (await import('../../src/dialogs/IconUploadSection.vue')).default

const BASE = '/apps/openregister/api/objects/buildiq/built-app/app-uuid'

function mountSection(application = { '@self': { id: 'app-uuid' }, slug: 'shop' }) {
	return mount(IconUploadSection, {
		props: { application },
		global: { stubs: { NcButton: true, NcNoteCard: true } },
	})
}

const fileEvent = (file) => ({ target: { files: file ? [file] : [] } })
const svg = { name: 'Logo.SVG', text: () => Promise.resolve('<svg/>') }

describe('IconUploadSection — inputs', () => {
	beforeEach(() => {
		for (const fn of Object.values(axios)) {
			fn.mockReset().mockResolvedValue({ data: {} })
		}
	})

	it('addresses the object by @self id, then uuid, then id', () => {
		expect(mountSection({ '@self': { id: 'a' }, uuid: 'b', id: 'c' }).vm.objectUuid).toBe('a')
		expect(mountSection({ uuid: 'b', id: 'c' }).vm.objectUuid).toBe('b')
		expect(mountSection({ id: 'c' }).vm.objectUuid).toBe('c')
		expect(mountSection({}).vm.objectUuid).toBe('')
	})

	it('builds cache-busted preview URLs from the slug, and none without an object', async () => {
		const wrapper = mountSection()
		await wrapper.setData({ lightNonce: 5, darkNonce: 6 })
		expect(wrapper.vm.iconLightUrl).toBe('/index.php/apps/buildiq/icons/shop.svg?v=5')
		expect(wrapper.vm.iconDarkUrl).toBe('/index.php/apps/buildiq/icons/shop-dark.svg?v=6')

		const bare = mountSection({ slug: 'shop' })
		expect(bare.vm.iconLightUrl).toBeNull()
		expect(bare.vm.iconDarkUrl).toBeNull()
		expect(bare.find('img').exists()).toBe(false)
	})

	it('takes the icon refs from the application and follows it when it changes', async () => {
		const wrapper = mountSection({ id: 'x', slug: 's', icon: { ref: 'app-icon.svg' } })
		expect(wrapper.vm.lightRef).toBe('app-icon.svg')
		expect(wrapper.vm.darkRef).toBeNull()

		await wrapper.setProps({ application: { id: 'x', slug: 's', iconDark: { ref: 'app-icon-dark.svg' } } })

		expect(wrapper.vm.lightRef).toBeNull()
		expect(wrapper.vm.darkRef).toBe('app-icon-dark.svg')
	})

	it('accepts only files ending in .svg, case-insensitively', () => {
		const vm = mountSection().vm
		expect(vm.validateSvgFile(svg)).toBe(true)
		expect(vm.validateSvgFile({ name: 'logo.png' })).toBe(false)
		expect(vm.validateSvgFile(undefined)).toBe(false)
	})

	it.each([
		['light', 'onLightFileChange', 'lightError', 'lightInput'],
		['dark', 'onDarkFileChange', 'darkError', 'darkInput'],
	])('refuses a non-svg %s file without uploading and clears the input', async (_v, handler, errorKey, input) => {
		const wrapper = mountSection()
		wrapper.vm.$refs[input].value = ''

		await wrapper.vm[handler](fileEvent({ name: 'logo.png' }))

		expect(wrapper.vm[errorKey]).toBe('Only .svg files are accepted')
		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.find('.ob-icon-section__error').text()).toBe('Only .svg files are accepted')
	})

	it('refuses an empty file selection', async () => {
		const wrapper = mountSection()
		await wrapper.vm.onLightFileChange(fileEvent(null))
		expect(wrapper.vm.lightError).toBe('Only .svg files are accepted')
	})

	it.each([
		['onLightFileChange', 'app-icon.svg', 'icon'],
		['onDarkFileChange', 'app-icon-dark.svg', 'iconDark'],
	])('%s uploads an svg into its own slot and clears an earlier error', async (handler, filename, field) => {
		const wrapper = mountSection()
		await wrapper.setData({ lightError: 'old', darkError: 'old' })

		await wrapper.vm[handler](fileEvent(svg))
		await flushPromises()

		expect(axios.post).toHaveBeenCalledWith(`${BASE}/files`, { name: filename, content: '<svg/>' })
		expect(axios.patch).toHaveBeenCalledWith(BASE, { [field]: { ref: filename } })
		expect(wrapper.emitted('updated')).toEqual([[{ field, ref: filename }]])
		expect(handler === 'onLightFileChange' ? wrapper.vm.lightError : wrapper.vm.darkError).toBe('')
	})

	it.each([
		['removeLightIcon', 'icon'],
		['removeDarkIcon', 'iconDark'],
	])('%s clears the ref of its own slot', async (method, field) => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = mountSection()
		vi.spyOn(console, 'warn').mockImplementation(() => {})

		await wrapper.vm[method]()
		vi.restoreAllMocks()

		expect(axios.delete).not.toHaveBeenCalled()
		expect(axios.patch).toHaveBeenCalledWith(BASE, { [field]: null })
		expect(wrapper.emitted('updated')).toEqual([[{ field, ref: null }]])
	})

	it('hides a preview that fails to load', () => {
		const vm = mountSection().vm
		const light = { target: { style: {} } }
		const dark = { target: { style: {} } }
		vm.onLightPreviewError(light)
		vm.onDarkPreviewError(dark)
		expect(light.target.style.display).toBe('none')
		expect(dark.target.style.display).toBe('none')
	})
})
