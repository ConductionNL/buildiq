/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The page designer warns about an action, widget or form field with an empty
 * label (REQ-PLAT-005): the renderer cannot give an unlabelled control an
 * accessible name.
 *
 * @spec openspec/specs/platform-properties/spec.md#REQ-PLAT-005
 */
import { describe, expect, it } from 'vitest'
import { validateLabels } from '../../src/services/manifestValidation/labels.js'

const page = (overrides) => ({ id: 'P', route: '/p', type: 'index', title: 'P', config: {}, ...overrides })

describe('validateLabels', () => {
	it('names an action on an index page whose label is left empty', () => {
		const manifest = {
			pages: [
				page({ actions: [{ id: 'approve', label: 'Approve' }, { id: 'reject', label: '  ' }, { id: 'archive' }] }),
			],
		}
		expect(validateLabels(manifest)).toEqual([
			'/pages/0/actions/1: buildiq.labels.warning.action-needs-a-label "reject"',
			'/pages/0/actions/2: buildiq.labels.warning.action-needs-a-label "archive"',
		])
	})

	it('reads the action lists in config too, and a title or aria label stands in for the label', () => {
		const manifest = {
			pages: [
				page({
					config: {
						actions: [{ id: 'a', title: 'Open' }, { id: 'b', ariaLabel: 'Delete' }, { label: '' }],
						headerActions: [{ id: 'h', label: '' }],
						rowActions: [{ id: 'r', label: 'Edit' }],
					},
				}),
			],
		}
		expect(validateLabels(manifest)).toEqual([
			'/pages/0/config/actions/2: buildiq.labels.warning.action-needs-a-label "#3"',
			'/pages/0/config/headerActions/0: buildiq.labels.warning.action-needs-a-label "h"',
		])
	})

	it('warns about a widget whose title is declared but blank, not one that declares none', () => {
		const manifest = {
			pages: [
				page({
					type: 'dashboard',
					config: { widgets: [{ id: 'w1', type: 'stat', title: 'Open cases' }, { id: 'w2', type: 'chart', title: '' }, { id: 'w3', type: 'divider' }] },
				}),
			],
		}
		expect(validateLabels(manifest)).toEqual([
			'/pages/0/config/widgets/1: buildiq.labels.warning.widget-needs-a-label "w2"',
		])
	})

	it('warns about a form field with a blank label, and stays quiet when every field has one', () => {
		const labelled = { pages: [page({ type: 'form', config: { fields: [{ key: 'name', label: 'Name' }, { key: 'email', label: 'E-mail' }] } })] }
		expect(validateLabels(labelled)).toEqual([])

		const blank = { pages: [page({ type: 'form', config: { fields: [{ key: 'name', label: 'Name' }, { key: 'email', label: '' }] } })] }
		expect(validateLabels(blank)).toEqual([
			'/pages/0/config/fields/1: buildiq.labels.warning.field-needs-a-label "email"',
		])
	})

	it('reads fields only on form pages and tolerates malformed input', () => {
		expect(validateLabels({ pages: [page({ config: { fields: [{ key: 'x', label: '' }] } })] })).toEqual([])
		expect(validateLabels(null)).toEqual([])
		expect(validateLabels({ pages: 'none' })).toEqual([])
		expect(validateLabels({ pages: [null, page({ actions: 'x', config: null })] })).toEqual([])
	})
})
