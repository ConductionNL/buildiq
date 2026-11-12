/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for the icon controls of the wizard's first step: a picked
 * light or dark icon is emitted (a cleared one as null), and an uploaded
 * file becomes the icon only when it is an SVG that holds an <svg>; every
 * refusal shows its reason and resets the file input.
 */

import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p) => p,
}))
vi.mock('@nextcloud/axios', () => ({
	default: { get: vi.fn(() => Promise.reject(new Error('no provider in tests'))) },
}))

import Step1Basics from '../../../src/dialogs/CreateApplicationWizard/Step1Basics.vue'
import { clearCopilotHealthCache } from '../../../src/composables/useCopilot.js'

beforeEach(() => {
	clearCopilotHealthCache()
})

function mountStep1() {
	return mount(Step1Basics, {
		props: { payload: { name: '', slug: '', description: '', preset: '', versions: [] } },
	})
}

function payloads(wrapper) {
	return (wrapper.emitted('update:payload') || []).map(([update]) => update)
}

async function upload(wrapper, file) {
	const input = wrapper.find('input[type="file"]')
	Object.defineProperty(input.element, 'files', { value: file ? [file] : [], configurable: true })
	await input.trigger('change')
	await vi.waitFor(() => {
		if (wrapper.vm.iconError === '' && payloads(wrapper).every((p) => !('iconValue' in p))) {
			throw new Error('still reading')
		}
	}, { timeout: 1000 }).catch(() => {})
	await wrapper.vm.$nextTick()
	return input
}

function errorText(wrapper) {
	const alert = wrapper.find('.wizard-step1__error-msg')
	return alert.exists() ? alert.text() : ''
}

describe('Step1Basics icon controls', () => {
	it('emits a picked light icon, and null when it is cleared', () => {
		const wrapper = mountStep1()

		wrapper.vm.onIconInput('mdi:folder')
		wrapper.vm.onIconInput('')

		expect(payloads(wrapper)).toEqual([{ iconValue: 'mdi:folder' }, { iconValue: null }])
	})

	it('emits a picked dark icon, and null when it is cleared', () => {
		const wrapper = mountStep1()

		wrapper.vm.onDarkInput('mdi:folder-outline')
		wrapper.vm.onDarkInput(undefined)

		expect(payloads(wrapper)).toEqual([{ iconDarkValue: 'mdi:folder-outline' }, { iconDarkValue: null }])
	})

	it('picking an icon clears an earlier upload error', async () => {
		const wrapper = mountStep1()
		await upload(wrapper, new File(['x'], 'logo.png', { type: 'image/png' }))
		expect(errorText(wrapper)).not.toBe('')

		wrapper.vm.onIconInput('mdi:folder')
		await wrapper.vm.$nextTick()

		expect(errorText(wrapper)).toBe('')
	})

	it('uses the trimmed text of an uploaded SVG as the icon and resets the input', async () => {
		const wrapper = mountStep1()
		const svg = '  <svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>\n'

		const input = await upload(wrapper, new File([svg], 'logo.svg', { type: 'image/svg+xml' }))

		expect(payloads(wrapper)).toEqual([{ iconValue: svg.trim() }])
		expect(errorText(wrapper)).toBe('')
		expect(input.element.value).toBe('')
	})

	it('accepts an .svg file by its name when the browser gives no type', async () => {
		const wrapper = mountStep1()

		await upload(wrapper, new File(['<svg/>'], 'LOGO.SVG', { type: '' }))

		expect(payloads(wrapper)).toEqual([{ iconValue: '<svg/>' }])
	})

	it('refuses a file that is not an SVG, without reading it', async () => {
		const wrapper = mountStep1()

		await upload(wrapper, new File(['<svg/>'], 'logo.png', { type: 'image/png' }))

		expect(payloads(wrapper)).toEqual([])
		expect(errorText(wrapper)).toBe('Only .svg files are accepted')
	})

	it('refuses an SVG file that holds no <svg> element', async () => {
		const wrapper = mountStep1()

		await upload(wrapper, new File(['<html></html>'], 'logo.svg', { type: 'image/svg+xml' }))

		expect(payloads(wrapper)).toEqual([])
		expect(errorText(wrapper)).toBe('That file does not contain an SVG')
	})

	it('does nothing when no file was chosen', async () => {
		const wrapper = mountStep1()

		await upload(wrapper, null)

		expect(payloads(wrapper)).toEqual([])
		expect(errorText(wrapper)).toBe('')
	})

	it('toggles the dark override control', async () => {
		const wrapper = mountStep1()
		const toggle = wrapper.findAll('button.wizard-step1__advanced-toggle').find((b) => b.text() === 'Add a dark override')

		await toggle.trigger('click')

		expect(toggle.text()).toBe('Hide dark override')
	})
})
