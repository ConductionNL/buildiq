// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { shallowMount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import WalkthroughRecorder from '../../src/components/walkthrough-editor/WalkthroughRecorder.vue'

vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => '/index.php' + path,
}))

/**
 * Spec: buildiq-walkthrough-editor (ADR-043). The recorder embeds the running
 * virtual app and, while armed, turns each click inside it into a `pick` of
 * the most stable target instead of letting the app act on it.
 */
const mounted = []

function factory(props = {}) {
	const wrapper = shallowMount(WalkthroughRecorder, {
		props: { appSlug: 'pet-store', ...props },
		attachTo: document.body,
	})
	mounted.push(wrapper)
	return wrapper
}

/**
 * The iframe's document, with one element in it.
 *
 * @param {object} w The mounted recorder.
 * @param {string} html The body markup.
 * @return {Document} The iframe document.
 */
function frameDocument(w, html) {
	const doc = w.find('iframe').element.contentWindow.document
	doc.open()
	doc.write(`<!doctype html><html><body>${html}</body></html>`)
	doc.close()
	return doc
}

function click(doc, id) {
	return clickElement(doc.getElementById(id))
}

function clickElement(el) {
	const event = new el.ownerDocument.defaultView.MouseEvent('click', { bubbles: true, cancelable: true })
	el.dispatchEvent(event)
	return event
}

afterEach(() => {
	mounted.splice(0).forEach((w) => w.unmount())
})

describe('WalkthroughRecorder — click-to-record surface', () => {
	it('embeds the app runtime, with the version when one is given', () => {
		expect(factory().find('iframe').attributes('src')).toBe('/index.php/apps/buildiq/builder/pet-store')
		expect(factory({ versionSlug: 'v 2' }).find('iframe').attributes('src')).toBe(
			'/index.php/apps/buildiq/builder/pet-store?_version=v%202',
		)
	})

	it('while recording, a click in the app is swallowed and emitted as the resolved target', async () => {
		const w = factory()
		const doc = frameDocument(w, '<button data-walkthrough-id="save-pet"><span id="label">Save</span></button>')
		w.vm.onIframeLoad()

		const event = click(doc, 'label')

		expect(event.defaultPrevented).toBe(true)
		expect(w.emitted('pick')).toEqual([[{ kind: 'element', ref: 'save-pet' }]])
		await w.vm.$nextTick()
		expect(w.find('.wt-recorder__last').text()).toContain('element · save-pet')
		expect(w.find('.wt-recorder__frame-wrap').classes()).toContain('wt-recorder__frame-wrap--armed')
	})

	it('while paused, a click reaches the app and nothing is picked', async () => {
		const w = factory()
		const doc = frameDocument(w, '<button id="nav" data-walkthrough-id="save-pet">Save</button>')
		w.vm.onIframeLoad()
		w.vm.setRecording(false)
		await w.vm.$nextTick()

		const event = click(doc, 'nav')

		expect(event.defaultPrevented).toBe(false)
		expect(w.emitted('pick')).toBeUndefined()
		expect(w.find('.wt-recorder__last').exists()).toBe(false)
		expect(w.find('.wt-recorder__frame-wrap').classes()).not.toContain('wt-recorder__frame-wrap--armed')
	})

	it('a target without a stable id is picked as a selector and shown without a ref', async () => {
		const w = factory()
		const doc = frameDocument(w, '<p id="plain">Text</p>')
		w.vm.onIframeLoad()

		click(doc, 'plain')

		expect(w.emitted('pick')[0][0]).toEqual({ kind: 'selector', selector: '#plain' })
		await w.vm.$nextTick()
		expect(w.find('.wt-recorder__last code').text()).toBe('selector')
	})

	it('an element nothing can be resolved from is not picked', () => {
		const w = factory()

		w.vm.handlePick(null)

		expect(w.emitted('pick')).toBeUndefined()
		expect(w.vm.lastPick).toBeNull()
	})

	it('a reload binds the listener once, and unmounting removes it', () => {
		const onPick = vi.fn()
		const w = factory({ onPick })
		const doc = frameDocument(w, '<p id="plain">Text</p>')
		const plain = doc.getElementById('plain')
		const remove = vi.spyOn(doc, 'removeEventListener')
		w.vm.onIframeLoad()
		w.vm.onIframeLoad()

		clickElement(plain)
		expect(onPick).toHaveBeenCalledTimes(1)

		mounted.splice(mounted.indexOf(w), 1)
		w.unmount()
		expect(remove).toHaveBeenCalledWith('click', expect.any(Function), true)
		clickElement(plain)
		expect(onPick).toHaveBeenCalledTimes(1)
	})

	it('a frame without a readable document binds nothing', () => {
		const w = factory()
		Object.defineProperty(w.find('iframe').element, 'contentWindow', { value: null })

		w.vm.onIframeLoad()

		expect(w.vm.boundDoc).toBeNull()
	})

	it('Done recording closes the recorder', async () => {
		const w = factory()

		await w.findComponent({ name: 'NcButton' }).vm.$emit('click')

		expect(w.emitted('close')).toHaveLength(1)
	})
})
