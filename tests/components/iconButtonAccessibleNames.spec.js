/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every icon-only button in the schema designer sub-editors and the
 * walkthrough designer has an accessible name.
 *
 * Reads each component's template AST (no live instance or screen reader
 * needed) and asserts that every `NcButton` without visible text binds
 * `:aria-label` to a non-empty `t('buildiq', ...)` string. Covers the six
 * buttons of openbuild-icon-button-accessible-names and any icon-only
 * button added to these files later.
 *
 * @spec openspec/changes/archive/2026-11-24-openbuild-icon-button-accessible-names/tasks.md#4-verification
 */

import { parse } from '@vue/compiler-sfc'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const FILES = [
	'src/components/schema-editor/LifecycleEditor.vue',
	'src/components/schema-editor/WidgetEditor.vue',
	'src/components/schema-editor/RelationEditor.vue',
	'src/components/walkthrough-editor/WalkthroughDesigner.vue',
]

// Node types of @vue/compiler-core.
const ELEMENT = 1
const TEXT = 2
const INTERPOLATION = 5
const DIRECTIVE = 7

/**
 * Collect every element named `tag` in the template AST.
 *
 * @param {object} node AST node
 * @param {string} tag Element name
 * @param {Array<object>} found Accumulator
 * @return {Array<object>} The matching elements
 */
function collect(node, tag, found = []) {
	if (node.type === ELEMENT && node.tag === tag) {
		found.push(node)
	}
	for (const child of node.children || []) {
		collect(child, tag, found)
	}
	return found
}

/**
 * Whether a button shows text of its own outside the `#icon` slot.
 *
 * @param {object} button NcButton element node
 * @return {boolean} True when it has visible text
 */
function hasVisibleText(button) {
	const visit = (node) => {
		if (node.type === TEXT) {
			return node.content.trim() !== ''
		}
		if (node.type === INTERPOLATION) {
			return true
		}
		if (node.type === ELEMENT) {
			const slot = node.props.find((p) => p.type === DIRECTIVE && p.name === 'slot')
			if (node.tag === 'template' && slot?.arg?.content === 'icon') {
				return false
			}
			return (node.children || []).some(visit)
		}
		return false
	}
	return (button.children || []).some(visit)
}

/**
 * The translated aria-label string a button binds, or null.
 *
 * @param {object} button NcButton element node
 * @return {string|null} The English source string
 */
function ariaLabel(button) {
	for (const prop of button.props) {
		if (prop.type === DIRECTIVE && prop.name === 'bind' && /^aria-?label$/i.test(prop.arg?.content || '')) {
			const match = /^t\(\s*'buildiq'\s*,\s*'([^']*)'/.exec(prop.exp?.content.trim() || '')
			return match ? match[1] : null
		}
	}
	return null
}

describe('icon-only buttons have an accessible name', () => {
	for (const file of FILES) {
		it(`every icon-only NcButton in ${file} binds a translated aria-label`, () => {
			const { descriptor } = parse(readFileSync(resolve(__dirname, '../..', file), 'utf8'))
			const buttons = collect(descriptor.template.ast, 'NcButton')
			const iconOnly = buttons.filter((b) => !hasVisibleText(b))

			expect(iconOnly.length).toBeGreaterThan(0)
			for (const button of iconOnly) {
				const label = ariaLabel(button)
				expect(label, `${file}:${button.loc.start.line} has no translated aria-label`).toBeTruthy()
				expect(label.trim()).not.toBe('')
			}
		})
	}

	it('names the six buttons of the change', () => {
		const labels = FILES.flatMap((file) => {
			const { descriptor } = parse(readFileSync(resolve(__dirname, '../..', file), 'utf8'))
			return collect(descriptor.template.ast, 'NcButton').map(ariaLabel)
		})
		for (const name of ['Remove state', 'Remove transition', 'Remove action', 'Remove widget', 'Remove relation', 'Add option']) {
			expect(labels).toContain(name)
		}
	})
})
