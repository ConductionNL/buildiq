/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CodePreviewPanel — the page designer preview runs code components and
 * expressions through the sandbox frames and shows their errors
 * (pages-custom-code T07, REQ-BQCC-001, REQ-BQCC-002).
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'ann', displayName: 'Ann' }),
}))
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(await importOriginal()),
	translate: (_app, key, vars) => (vars ? key.replace(/\{(\w+)\}/g, (_m, k) => vars[k]) : key),
	translatePlural: (_app, one, many, count) => (count === 1 ? one : many).replace('%n', count),
}))

const { default: CodePreviewPanel, RERUN_DELAY_MS } = await import('../../../src/components/page-editor/CodePreviewPanel.vue')

const CODE = { html: '<div id="plan"></div>', js: 'draw(object)', inputs: ['object', 'user'] }

function manifest({ code = CODE, expression = 'row.aantal * row.prijs' } = {}) {
	return {
		pages: [
			{ id: 'gebouw', title: 'Gebouw', type: 'detail', config: { widgets: [{ id: 'plan', title: 'Plan', type: 'custom', config: { code } }] } },
			{ id: 'bestellingen', type: 'index', config: { columns: [{ key: 'totaal', expression }] } },
		],
	}
}

function deliver(source, data) {
	window.dispatchEvent(new MessageEvent('message', { data, source }))
}

let wrapper = null

function mountPanel(m = manifest()) {
	wrapper = mount(CodePreviewPanel, { props: { manifest: m }, attachTo: document.body })
	return wrapper
}

afterEach(() => {
	wrapper?.unmount()
	wrapper = null
	vi.useRealTimers()
})

describe('CodePreviewPanel', () => {
	it('renders nothing for a manifest without code or expressions', () => {
		expect(mountPanel({ pages: [{ id: 'x', type: 'index', config: {} }] }).find('section').exists()).toBe(false)
	})

	it('frames each code component sandboxed, scripts only, on the preview document', () => {
		const frame = mountPanel().find('[data-testid="code-preview-component"] iframe')
		expect(frame.attributes('sandbox')).toBe('allow-scripts')
		expect(frame.attributes('src')).toBe('/index.php/apps/buildiq/sandbox/preview')
		expect(wrapper.text()).toContain('Gebouw › Plan')
	})

	it('posts the code and the inputs it asks for into its frame once loaded', async () => {
		const frame = mountPanel().find('[data-testid="code-preview-component"] iframe')
		const post = vi.spyOn(frame.element.contentWindow, 'postMessage')
		await frame.trigger('load')
		expect(post).toHaveBeenCalledWith({ type: 'render', code: CODE, inputs: { object: {}, user: { id: 'ann', name: 'Ann' } } }, '*')
	})

	it('shows the errors its frame reports, and ignores other windows', async () => {
		const frame = mountPanel().find('[data-testid="code-preview-component"] iframe')
		await frame.trigger('load')
		deliver(frame.element.contentWindow, { type: 'error', message: 'Uncaught ReferenceError: draw is not defined' })
		deliver(window, { type: 'error', message: 'from elsewhere' })
		deliver(frame.element.contentWindow, { type: 'notice', text: 'not an error' })
		await flushPromises()

		const alerts = wrapper.findAll('[data-testid="code-preview-component"] [role="alert"] li')
		expect(alerts.map((li) => li.text())).toEqual(['Uncaught ReferenceError: draw is not defined'])
		expect(wrapper.vm.errors).toEqual({ '/pages/0/config/widgets/0/config/code': ['Uncaught ReferenceError: draw is not defined'] })
	})

	it('reloads the frames, dropping old errors, when the code has been still for a moment', async () => {
		vi.useFakeTimers()
		const panel = mountPanel()
		const before = panel.vm.generation
		panel.vm.errors = { k: ['old'] }
		await panel.setProps({ manifest: manifest({ code: { ...CODE, js: 'draw(object); label()' } }) })
		expect(panel.vm.generation).toBe(before)
		vi.advanceTimersByTime(RERUN_DELAY_MS)
		expect(panel.vm.generation).toBe(before + 1)
		expect(panel.vm.errors).toEqual({})
	})

	it('evaluates the expressions in the evaluator frame and lists the ones that fail', async () => {
		const panel = mountPanel(manifest({ expression: 'row.aantal *' }))
		const evaluator = panel.find('.code-preview-panel__evaluator')
		expect(evaluator.attributes('sandbox')).toBe('allow-scripts')
		expect(evaluator.attributes('src')).toBe('/index.php/apps/buildiq/sandbox/evaluator')
		const target = evaluator.element.contentWindow
		const post = vi.spyOn(target, 'postMessage')

		await evaluator.trigger('load')
		const [message] = post.mock.calls[0]
		expect(message).toMatchObject({
			type: 'evaluate',
			items: [{ key: '/pages/1/config/columns/0/expression', expression: 'row.aantal *' }],
			scope: { row: {}, values: {}, user: { id: 'ann', name: 'Ann' }, route: {} },
		})

		deliver(target, { type: 'evaluated', id: message.id, results: { '/pages/1/config/columns/0/expression': { error: 'Unexpected token \')\'' } } })
		await flushPromises()
		expect(panel.find('.code-preview-panel__expressions [role="alert"]').text()).toBe('row.aantal *: Unexpected token \')\'')
	})

	it('says when every expression runs, and when code on pages is switched off', async () => {
		const panel = mountPanel(manifest())
		const evaluator = panel.find('.code-preview-panel__evaluator')
		const post = vi.spyOn(evaluator.element.contentWindow, 'postMessage')
		await evaluator.trigger('load')
		deliver(evaluator.element.contentWindow, { type: 'evaluated', id: post.mock.calls[0][0].id, results: { '/pages/1/config/columns/0/expression': { value: null } } })
		await flushPromises()
		expect(panel.find('.code-preview-panel__ok').text()).toBe('1 expression runs without errors')
		expect(panel.find('[role="status"]').exists()).toBe(false)

		await evaluator.trigger('load')
		deliver(evaluator.element.contentWindow, { type: 'evaluated', id: post.mock.calls[1][0].id, results: { '/pages/1/config/columns/0/expression': { disabled: true } } })
		await flushPromises()
		expect(panel.find('[role="status"]').text()).toContain('Code on pages is switched off')
	})
})
