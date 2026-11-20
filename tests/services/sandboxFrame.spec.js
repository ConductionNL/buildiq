/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * sandboxFrame — what the page designer sends into the code sandbox
 * (pages-custom-code T07).
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
}))

const {
	codeComponentsOf,
	createEvaluatorClient,
	expressionsOf,
	previewInputs,
	sandboxDocumentUrl,
} = await import('../../src/services/sandboxFrame.js')

const MANIFEST = {
	pages: [
		{ id: 'calc', title: 'Calculator', type: 'custom', config: { code: { html: '<p>1</p>' } } },
		{ id: 'stray', type: 'index', config: { code: { html: 'not a custom page' }, columns: [{ key: 'a' }, { key: 't', expression: 'row.a * 2' }, { key: 'blank', expression: '  ' }] } },
		{
			id: 'home',
			title: 'Home',
			type: 'dashboard',
			config: { widgets: [{ id: 'plan', title: 'Plan', type: 'custom', config: { code: { js: 'x()' } } }, { id: 'kpi', type: 'custom' }] },
		},
		{ id: 'form', type: 'form', config: { fields: [{ key: 'f', expressionDefault: 'user.name', visibleWhen: { expression: 'values.x === 1' } }, 'junk'] } },
		null,
	],
}

describe('sandboxFrame', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	it('points at the buildiq sandbox documents', () => {
		expect(sandboxDocumentUrl('preview')).toBe('/index.php/apps/buildiq/sandbox/preview')
		expect(sandboxDocumentUrl('evaluator')).toBe('/index.php/apps/buildiq/sandbox/evaluator')
	})

	it('finds code on custom pages and on widgets, not on other pages', () => {
		expect(codeComponentsOf(MANIFEST)).toEqual([
			{ key: '/pages/0/config/code', pageId: 'calc', id: 'calc', label: 'Calculator', code: { html: '<p>1</p>' } },
			{ key: '/pages/2/config/widgets/0/config/code', pageId: 'home', id: 'plan', label: 'Home › Plan', code: { js: 'x()' } },
		])
		expect(codeComponentsOf({})).toEqual([])
	})

	it('finds every non-empty expression by its pointer', () => {
		expect(expressionsOf(MANIFEST)).toEqual([
			{ key: '/pages/1/config/columns/1/expression', expression: 'row.a * 2' },
			{ key: '/pages/3/config/fields/0/expressionDefault', expression: 'user.name' },
			{ key: '/pages/3/config/fields/0/visibleWhen/expression', expression: 'values.x === 1' },
		])
	})

	it('hands a preview only the inputs the code asks for', () => {
		const user = { id: 'ann', name: 'Ann' }
		expect(previewInputs({ inputs: ['user', 'rows', 'bogus'] }, user)).toEqual({ user, rows: [] })
		expect(previewInputs({}, user)).toEqual({})
	})

	it('posts a batch and resolves with the answer for its id from its frame only', async () => {
		const frame = { postMessage: vi.fn() }
		const host = new EventTarget()
		const client = createEvaluatorClient(() => frame, { host })
		const items = [{ key: 'k', expression: '1 + 1' }]
		const answer = client.evaluate(items, { row: {} })

		expect(frame.postMessage).toHaveBeenCalledWith({ type: 'evaluate', id: 'eval-1', items, scope: { row: {} } }, '*')
		const send = (source, data) => {
			const event = new Event('message')
			Object.assign(event, { source, data })
			host.dispatchEvent(event)
		}
		send({}, { type: 'evaluated', id: 'eval-1', results: { k: { value: 'forged' } } })
		send(frame, { type: 'evaluated', id: 'eval-9', results: { k: { value: 'other' } } })
		send(frame, { type: 'evaluated', id: 'eval-1', results: { k: { value: 2 } } })

		await expect(answer).resolves.toEqual({ k: { value: 2 } })
		client.dispose()
	})

	it('answers a timeout for every key when the frame stays silent', async () => {
		vi.useFakeTimers()
		const client = createEvaluatorClient(() => ({ postMessage: () => {} }), { host: new EventTarget(), timeout: 50 })
		const answer = client.evaluate([{ key: 'a', expression: 'while(1){}' }, { key: 'b', expression: '1' }], {})
		vi.advanceTimersByTime(50)
		await expect(answer).resolves.toEqual({ a: { error: 'timeout' }, b: { error: 'timeout' } })
	})

	it('posts nothing without a frame or without expressions', async () => {
		const frame = { postMessage: vi.fn() }
		await expect(createEvaluatorClient(() => null, { host: new EventTarget() }).evaluate([{ key: 'a', expression: '1' }], {})).resolves.toEqual({})
		await expect(createEvaluatorClient(() => frame, { host: new EventTarget() }).evaluate([], {})).resolves.toEqual({})
		expect(frame.postMessage).not.toHaveBeenCalled()
	})
})
