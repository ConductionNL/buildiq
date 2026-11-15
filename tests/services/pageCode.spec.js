/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * pageCode validation (pages-custom-code T04, REQ-BQCC-001): the D1 shapes of
 * a code component and of the three expression keys.
 */

import { describe, expect, it } from 'vitest'
import { codeBlockErrors, expressionErrors, validatePageCode } from '../../src/services/manifestValidation/pageCode.js'

const page = (type, config) => ({ id: 'p', type, config })

describe('pageCode validation', () => {
	it('accepts a code component with text parts and known inputs', () => {
		const manifest = { pages: [page('custom', { code: { html: '<b>x</b>', js: 'go()', inputs: ['object', 'user'] } })] }
		expect(validatePageCode(manifest)).toEqual([])
	})

	it('refuses a code block that is not an object', () => {
		expect(codeBlockErrors('alert(1)', '/c')).toEqual(['/c: buildiq.pageCode.error.codeNotObject'])
		expect(codeBlockErrors(['x'], '/c')).toEqual(['/c: buildiq.pageCode.error.codeNotObject'])
	})

	it('refuses a part that is not text and a block with no text at all', () => {
		expect(codeBlockErrors({ html: 5 }, '/c')).toEqual([
			'/c/html: buildiq.pageCode.error.partNotText',
			'/c: buildiq.pageCode.error.empty',
		])
		expect(codeBlockErrors({ css: '  ' }, '/c')).toEqual(['/c: buildiq.pageCode.error.empty'])
		expect(codeBlockErrors({ css: 'b{}' }, '/c')).toEqual([])
	})

	it('refuses unknown, duplicate and non-list inputs, pointing at the entry', () => {
		expect(codeBlockErrors({ html: 'x', inputs: ['rows', 'session', 'rows'] }, '/c')).toEqual([
			'/c/inputs/1: buildiq.pageCode.error.unknownInput',
			'/c/inputs/2: buildiq.pageCode.error.duplicateInput',
		])
		expect(codeBlockErrors({ html: 'x', inputs: 'rows' }, '/c')).toEqual(['/c/inputs: buildiq.pageCode.error.inputsNotList'])
	})

	it('refuses component and code on the same config', () => {
		const manifest = { pages: [page('custom', { component: 'Board', code: { html: 'x' } })] }
		expect(validatePageCode(manifest)).toEqual(['/pages/0/config/code: buildiq.pageCode.error.componentAndCode'])
	})

	it('refuses page-level code outside a custom page but checks widget code anywhere', () => {
		const manifest = {
			pages: [
				page('index', { code: { html: 'x' } }),
				page('dashboard', { widgets: [{ id: 'w', config: { code: { inputs: ['user'] } } }, { id: 'v' }] }),
			],
		}
		expect(validatePageCode(manifest)).toEqual([
			'/pages/0/config/code: buildiq.pageCode.error.codeOnWrongPage',
			'/pages/1/config/widgets/0/config/code: buildiq.pageCode.error.empty',
		])
	})

	it('checks the three expression keys and leaves absent ones alone', () => {
		const manifest = {
			pages: [
				page('grid', { columns: [{ key: 'a', expression: 'row.x * 2' }, { key: 'b', expression: '' }, { key: 'c' }] }),
				page('form', {
					fields: [
						{ key: 'f', expressionDefault: 3, visibleWhen: { expression: ' ' } },
						{ key: 'g', visibleWhen: { field: 'f', op: 'eq', value: 1 } },
					],
				}),
			],
		}
		expect(validatePageCode(manifest)).toEqual([
			'/pages/0/config/columns/1/expression: buildiq.pageCode.error.expressionEmpty',
			'/pages/1/config/fields/0/expressionDefault: buildiq.pageCode.error.expressionNotText',
			'/pages/1/config/fields/0/visibleWhen/expression: buildiq.pageCode.error.expressionEmpty',
		])
		expect(expressionErrors(undefined, '/e')).toEqual([])
	})

	it('tolerates a manifest without pages or configs', () => {
		expect(validatePageCode(null)).toEqual([])
		expect(validatePageCode({ pages: [{ id: 'x' }, null] })).toEqual([])
	})
})
