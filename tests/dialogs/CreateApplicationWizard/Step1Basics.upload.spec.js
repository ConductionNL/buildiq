/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest unit tests for the AI entry point and SVG upload of
 * src/dialogs/CreateApplicationWizard/Step1Basics.vue:
 *   - "Generate with AI" renders when the copilot probe succeeds and opens its dialog
 *   - the muted admin hint renders only for an admin when the probe failed
 *   - a copilot-created app bubbles up as `ai-app-created`
 *   - an uploaded SVG becomes the icon value; a non-SVG file, an SVG-named
 *     file without <svg> markup and an unreadable file each set their error
 *     and clear the file input
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))

import Step1Basics from '../../../src/dialogs/CreateApplicationWizard/Step1Basics.vue'
import { clearCopilotHealthCache } from '../../../src/composables/useCopilot.js'

/**
 * Mount Step1 with an empty payload once the copilot probe has settled.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>}
 */
async function mountStep1() {
	const wrapper = mount(Step1Basics, {
		propsData: {
			payload: { name: '', slug: '', description: '', icon: null, iconDark: null, versions: [] },
		},
		global: { stubs: { CopilotGenerateDialog: { props: ['open'], template: '<div class="copilot-dialog" :data-open="String(open)" />' } } },
	})
	await flushPromises()
	return wrapper
}

/**
 * A file-input change event carrying one file.
 *
 * @param {object|undefined} file The file, or undefined for none.
 * @return {object} The event.
 */
const changeEvent = (file) => ({ target: { files: file ? [file] : [] } })

describe('Step1Basics — AI entry point', () => {
	beforeEach(() => {
		clearCopilotHealthCache()
		get.mockReset()
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('shows "Generate with AI" when the copilot is available and opens its dialog', async () => {
		get.mockResolvedValue({ data: {} })
		const wrapper = await mountStep1()

		expect(get).toHaveBeenCalledWith('/apps/buildiq/api/copilot/health')
		const button = wrapper.find('[data-testid="copilot-generate-button"]')
		expect(button.exists()).toBe(true)
		expect(wrapper.find('[data-testid="copilot-admin-hint"]').exists()).toBe(false)

		await button.trigger('click')
		expect(wrapper.vm.showCopilotDialog).toBe(true)
	})

	it('shows the admin hint to an admin when the copilot is unavailable', async () => {
		get.mockRejectedValue({ response: { data: { reason: 'no_provider' } } })
		vi.stubGlobal('OC', { isUserAdmin: () => true })
		const wrapper = await mountStep1()

		expect(wrapper.find('[data-testid="copilot-generate-button"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="copilot-admin-hint"]').exists()).toBe(true)
		expect(wrapper.vm.aiSettingsUrl).toBe('/settings/admin/ai')
	})

	it('hides the hint from a non-admin', async () => {
		get.mockRejectedValue({ response: { data: {} } })
		vi.stubGlobal('OC', { isUserAdmin: () => false })
		const wrapper = await mountStep1()

		expect(wrapper.vm.showAdminHint).toBe(false)
		expect(wrapper.find('[data-testid="copilot-admin-hint"]').exists()).toBe(false)
	})

	it('re-emits a copilot-created app slug', async () => {
		get.mockResolvedValue({ data: {} })
		const wrapper = await mountStep1()

		wrapper.vm.onAiAppCreated('my-new-app')

		expect(wrapper.emitted('ai-app-created')).toEqual([['my-new-app']])
	})
})

describe('Step1Basics — SVG upload', () => {
	let readerResult
	let readerFails

	beforeEach(() => {
		clearCopilotHealthCache()
		get.mockReset().mockRejectedValue(new Error('no provider in tests'))
		readerResult = ''
		readerFails = false
		vi.stubGlobal('FileReader', class {
			readAsText() {
				if (readerFails) {
					this.onerror()
				} else {
					this.onload({ target: { result: readerResult } })
				}
			}
		})
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	/**
	 * Mount, with a recognisable value in the upload input so clearing shows.
	 *
	 * @return {Promise<import('@vue/test-utils').VueWrapper>}
	 */
	async function mounted() {
		const wrapper = await mountStep1()
		wrapper.vm.$refs.uploadInput = { value: 'C:\\fakepath\\icon' }
		return wrapper
	}

	it('ignores a change with no file', async () => {
		const wrapper = await mounted()
		wrapper.vm.iconError = 'old'

		wrapper.vm.onUploadSvg(changeEvent(undefined))

		expect(wrapper.vm.iconError).toBe('')
		expect(wrapper.emitted('update:payload')).toBeUndefined()
	})

	it('uses the trimmed markup of an SVG file as the icon', async () => {
		readerResult = '  <svg viewBox="0 0 1 1"></svg>\n'
		const wrapper = await mounted()

		wrapper.vm.onUploadSvg(changeEvent({ type: 'image/svg+xml', name: 'x' }))

		expect(wrapper.emitted('update:payload')).toContainEqual([
			{ iconValue: '<svg viewBox="0 0 1 1"></svg>' },
		])
		expect(wrapper.vm.iconError).toBe('')
		expect(wrapper.vm.$refs.uploadInput.value).toBe('')
	})

	it('accepts a .svg name without the SVG mime type', async () => {
		readerResult = '<svg/>'
		const wrapper = await mounted()

		wrapper.vm.onUploadSvg(changeEvent({ type: '', name: 'ICON.SVG' }))

		expect(wrapper.emitted('update:payload')).toContainEqual([{ iconValue: '<svg/>' }])
	})

	it('refuses a file that is not an SVG', async () => {
		const wrapper = await mounted()

		wrapper.vm.onUploadSvg(changeEvent({ type: 'image/png', name: 'icon.png' }))

		expect(wrapper.vm.iconError).toBe('Only .svg files are accepted')
		expect(wrapper.vm.$refs.uploadInput.value).toBe('')
		expect(wrapper.emitted('update:payload')).toBeUndefined()
	})

	it('refuses an SVG-named file without SVG markup', async () => {
		readerResult = 'plain text'
		const wrapper = await mounted()

		wrapper.vm.onUploadSvg(changeEvent({ type: 'image/svg+xml', name: 'a.svg' }))

		expect(wrapper.vm.iconError).toBe('That file does not contain an SVG')
		expect(wrapper.vm.$refs.uploadInput.value).toBe('')
		expect(wrapper.emitted('update:payload')).toBeUndefined()
	})

	it('treats a non-string read result as empty', async () => {
		readerResult = new ArrayBuffer(4)
		const wrapper = await mounted()

		wrapper.vm.onUploadSvg(changeEvent({ type: 'image/svg+xml', name: 'a.svg' }))

		expect(wrapper.vm.iconError).toBe('That file does not contain an SVG')
	})

	it('reports a file that cannot be read', async () => {
		readerFails = true
		const wrapper = await mounted()

		wrapper.vm.onUploadSvg(changeEvent({ type: 'image/svg+xml', name: 'a.svg' }))

		expect(wrapper.vm.iconError).toBe('Could not read that file')
		expect(wrapper.vm.$refs.uploadInput.value).toBe('')
	})
})
