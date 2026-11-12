/*
 * SPDX-FileCopyrightText: 2026 Buildiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vitest spec for isEditableTarget — whether a key event belongs to a text field.
 *
 * Covers:
 *  - inputs, textareas and selects (any case) are editable.
 *  - a contenteditable element is editable; other elements are not.
 *  - without an event target the focused element decides.
 *  - a target without a tag name is not editable.
 */

import { afterEach, describe, expect, it } from 'vitest'
import { isEditableTarget } from '../../src/utils/isEditableTarget.js'

afterEach(() => {
	document.body.innerHTML = ''
})

describe('isEditableTarget', () => {
	it('treats form fields as editable', () => {
		for (const tag of ['input', 'textarea', 'select']) {
			expect(isEditableTarget({ target: document.createElement(tag) })).toBe(true)
		}
		expect(isEditableTarget({ target: { tagName: 'INPUT' } })).toBe(true)
	})

	it('treats a contenteditable element as editable', () => {
		expect(isEditableTarget({ target: { tagName: 'DIV', isContentEditable: true } })).toBe(true)
	})

	it('does not treat other elements as editable', () => {
		expect(isEditableTarget({ target: document.createElement('button') })).toBe(false)
		expect(isEditableTarget({ target: { tagName: 'DIV' } })).toBe(false)
	})

	it('falls back to the focused element without a target', () => {
		const field = document.createElement('textarea')
		document.body.appendChild(field)
		field.focus()
		expect(isEditableTarget({})).toBe(true)
		expect(isEditableTarget(null)).toBe(true)

		field.blur()
		expect(isEditableTarget(undefined)).toBe(false)
	})

	it('is false for a target without a tag name', () => {
		expect(isEditableTarget({ target: { tagName: 5 } })).toBe(false)
		expect(isEditableTarget({ target: window })).toBe(false)
	})
})
