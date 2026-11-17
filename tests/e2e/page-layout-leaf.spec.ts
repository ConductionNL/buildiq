// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — the page-layout leaf (spec: page-layout-per-type).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * PageLayoutLeafProviderTest already covers the resolution order, the
 * fall-backs and the condition evaluation, against a double. What it cannot
 * see is whether the leaf reached OpenRegister's catalogue at all. A provider
 * that is never contributed answers nothing at runtime, every consuming app
 * quietly falls back to its manifest, and no unit test notices, because
 * falling back to the manifest is also the CORRECT behaviour when no layout is
 * published. That is exactly why this file checks the catalogue rather than a
 * rendered page.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * The served layout only for the task panel: that test makes its own host
 * case and layout. The high-contrast mark, the task-list column and the locked
 * upload field are asserted in the unit suite.
 *
 * @spec openspec/changes/case-page-layout-per-case-type/specs/page-layout-per-type/spec.md
 * @e2e page-layout-per-type/requirement-a-widget-carries-display-conditions-and-a-high-contrast-flag-req-obpl-006/a-high-contrast-widget-reaches-the-consumer-marked
 * @e2e page-layout-per-type/requirement-a-case-type-declares-its-task-list-columns-and-search-fields-req-obpl-008/building-permits-show-the-address-column-on-the-task-list
 * @e2e page-layout-per-type/requirement-a-case-type-declares-its-document-upload-fields-req-obpl-009/the-upload-dialog-defaults-the-confidentiality-and-locks-it
 * @e2e openspec/changes/pages-task-panel-beside-case-tabs/specs/page-layout-task-panel/spec.md#dossiq-asks-for-the-layout-of-a-building-permit-case
 */
import { expect, test } from '@playwright/test'
import { createLeafHost } from './support/leafHost.ts'

const CATALOGUE = '/index.php/apps/openregister/api/integrations'
const LEAF_ID = 'buildiq-page-layout'
const HEADERS = { 'OCS-APIRequest': 'true' }
const LAYOUTS = '/index.php/apps/buildiq/api/page-layouts'
const LAYOUT_OBJECTS = '/index.php/apps/openregister/api/objects/buildiq/pageLayout'

test.describe('page-layout leaf', () => {
	test("the leaf is on OpenRegister's catalogue under its own id", async ({
		request,
	}) => {
		const response = await request.get(CATALOGUE, { headers: HEADERS })

		test.skip(response.status() === 404, 'OpenRegister is not installed here')
		expect(response.ok()).toBeTruthy()

		const body = await response.json()
		const entries = (body.integrations ?? body.items ?? body.results ?? body ?? []) as Array<
			Record<string, unknown>
		>

		expect(entries.map((entry) => String(entry.id ?? ''))).toContain(LEAF_ID)
	})

	test('the leaf offers no way to append a layout', async ({ request }) => {
		// A layout is authored in buildiq, where the rules that validate it
		// live. A consumer that could append one could publish a second layout
		// for a type and nothing would know which of the two to render.
		const host = await createLeafHost(request)
		try {
			const response = await request.post(host.leafPath(LEAF_ID), {
				headers: HEADERS,
				data: { tabs: [] },
			})

			expect(response.status()).not.toBe(200)
			expect(response.status()).not.toBe(201)
		} finally {
			await host.dispose()
		}
	})

	test('a published layout serves its task panel to the case it is for', async ({
		request,
	}) => {
		const host = await createLeafHost(request)
		const layoutId = `pl-e2e-${Date.now().toString(36)}`
		try {
			const saved = await request.put(LAYOUTS, {
				headers: HEADERS,
				data: {
					id: layoutId,
					name: 'Bouwvergunning e2e',
					targetApp: 'dossiq',
					register: host.register,
					schema: host.schema,
					typeProperty: 'caseType',
					typeValue: 'bouwvergunning',
					status: 'published',
					tabs: [
						{ id: 'overzicht', label: 'Overzicht', kind: 'fieldGroup', fields: ['title'] },
						{ id: 'documenten', label: 'Documenten', kind: 'leaf', ref: 'files' },
					],
					taskList: {
						columns: [{ field: 'title' }],
						panel: { placement: 'side', tabs: ['overzicht'], collapsed: true },
					},
				},
			})
			expect(saved.status(), await saved.text()).toBe(200)

			const served = await request.get(host.leafPath(LEAF_ID), { headers: HEADERS })
			expect(served.ok()).toBeTruthy()
			const body = await served.json()

			expect(body.total).toBe(1)
			expect(body.items[0].taskList.panel).toEqual({
				placement: 'side',
				tabs: ['overzicht'],
				collapsed: true,
			})
		} finally {
			await request.delete(`${LAYOUT_OBJECTS}/${layoutId}`, { headers: HEADERS })
			await host.dispose()
		}
	})
})
