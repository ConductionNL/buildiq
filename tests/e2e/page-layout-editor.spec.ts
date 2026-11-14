// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e for what the page-layout body editor saves (change
 * case-page-layout-per-case-type, tasks 4.2 and 8.3).
 *
 * The editor itself (tab kinds, move buttons, widths, the row preview, the
 * header list) is covered by Vitest in tests/components/PageLayoutBodyEditor.spec.js
 * and tests/components/AppliesToPanel.spec.js. What those cannot see is whether
 * the body they emit survives the save route and the pageLayout schema: this
 * spec PUTs exactly that body as the admin and reads it back from the list
 * route. Each run uses its own schema name, so the uniqueness rule on
 * (targetApp, register, schema, typeProperty, typeValue) never meets an
 * earlier run.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md
 * @e2e page-layout-per-type/requirement-a-tab-declares-its-kind-and-an-admin-orders-tabs-per-case-type-req-obpl-004/a-case-type-gets-its-own-three-tabs
 * @e2e page-layout-per-type/requirement-a-widget-tab-holds-a-grid-each-widget-with-a-width-and-an-order-req-obpl-005/two-widgets-sit-side-by-side-and-a-third-fills-the-row
 * @e2e page-layout-per-type/requirement-the-header-is-its-own-slot-with-fields-and-chips-per-case-type-req-obpl-007/the-building-permit-header-shows-the-address-and-the-term
 */
import { expect, test } from '@playwright/test'

const API = '/index.php/apps/buildiq/api/page-layouts'
const HEADERS = { 'OCS-APIRequest': 'true' }

test.describe('page layout editor body', () => {
	test('a case type keeps its own tabs, widget widths and header, and the schema-wide layout is untouched', async ({
		request,
	}) => {
		const schema = `e2e-layout-${Date.now()}`
		const base = {
			targetApp: 'dossiq',
			register: 'e2e-layouts',
			schema,
			typeProperty: 'caseType',
			status: 'published',
		}

		const schemaWide = {
			...base,
			id: `${schema}-all`,
			typeProperty: '',
			typeValue: '',
			tabs: [
				{
					id: 'gegevens',
					kind: 'fieldGroup',
					label: 'Gegevens',
					order: 0,
					fields: ['title'],
				},
			],
		}
		const wide = await request.put(API, { headers: HEADERS, data: schemaWide })
		test.skip(wide.status() === 404, 'buildiq is not installed here')
		expect(wide.status(), await wide.text()).toBe(200)

		// The body PageLayoutBodyEditor emits after an admin adds a field group,
		// a widget tab and a leaf tab and moves the leaf tab to the front.
		const perType = {
			...base,
			id: `${schema}-bouwvergunning`,
			typeValue: 'bouwvergunning',
			tabs: [
				{
					id: 'tab-3',
					kind: 'leaf',
					label: 'Documenten',
					order: 0,
					ref: 'filinq-documents',
				},
				{
					id: 'tab-1',
					kind: 'fieldGroup',
					label: 'Gegevens',
					order: 1,
					fields: ['title', 'deadline'],
				},
				{
					id: 'tab-2',
					kind: 'widgets',
					label: 'Overzicht',
					order: 2,
					widgets: [
						{
							id: 'widget-1',
							order: 0,
							width: 'medium',
							conditions: [],
							highContrast: false,
						},
						{
							id: 'widget-2',
							order: 1,
							width: 'medium',
							conditions: [],
							highContrast: true,
						},
						{
							id: 'widget-3',
							order: 2,
							width: 'extraLarge',
							conditions: [],
							highContrast: false,
						},
					],
				},
			],
			header: {
				titleField: 'title',
				fields: [
					{ field: 'location', label: '', order: 0 },
					{ field: 'dueDate', label: '', order: 1 },
				],
			},
		}
		const saved = await request.put(API, { headers: HEADERS, data: perType })
		expect(saved.status(), await saved.text()).toBe(200)

		const list = await request.get(
			`${API}?register=e2e-layouts&schema=${schema}`,
			{
				headers: HEADERS,
			},
		)
		expect(list.status()).toBe(200)
		const items = ((await list.json()).items ?? []) as Array<Record<string, any>>

		const typed = items.find((item) => item.typeValue === 'bouwvergunning')
		expect(typed, 'the per-type layout is listed').toBeTruthy()
		expect(typed!.tabs.map((tab: any) => [tab.kind, tab.order])).toEqual([
			['leaf', 0],
			['fieldGroup', 1],
			['widgets', 2],
		])
		const widgets = typed!.tabs.find(
			(tab: any) => tab.kind === 'widgets',
		).widgets
		expect(widgets.map((w: any) => w.width)).toEqual([
			'medium',
			'medium',
			'extraLarge',
		])
		expect(widgets[1].highContrast).toBe(true)
		expect(typed!.header.fields.map((f: any) => f.field)).toEqual([
			'location',
			'dueDate',
		])

		const all = items.find((item) => (item.typeValue ?? '') === '')
		expect(all, 'the schema-wide layout is listed').toBeTruthy()
		expect(all!.tabs.map((tab: any) => tab.id)).toEqual(['gegevens'])
	})
})
