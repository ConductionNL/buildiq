/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Spec coverage: openspec/changes/data-model-diagram/specs/data-model-diagram/spec.md
 * Task: openspec/changes/data-model-diagram/tasks.md#T06
 *
 * An app version's data model as a diagram, against a live instance:
 *
 *   1. Ensure the app `pw-model` and, through the designer's own "Add schema"
 *      dialog, the schemas `permit`, `applicant` and `document` in its
 *      production register (the dialog namespaces and attaches them).
 *   2. Give `permit` a one relation `applicant` and a many relation
 *      `documents` through OpenRegister's schema API (write-by-id).
 *   3. Switch the list to "Diagram": three boxes, the address carries
 *      `view=diagram`, and the table lists both relations with one/many.
 *   4. Drag from one box to another: no relation is added.
 *   5. Focus the `document` box and press Enter: the designer opens it.
 *   6. axe finds no serious or critical violation on the view.
 *
 * The "a schema the maker may not read stays nameless" scenario needs a
 * second user without read access to another register; it is covered by the
 * vitest cases on `dataModelGraph.js` and `DataModelDiagram.vue`.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import axeCore from 'axe-core'
import { dismissOverlays, ensureApp } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = 'pw-model'
const VERSION = 'production'
const SCHEMAS = [
	{ slug: 'permit', title: 'Permit' },
	{ slug: 'applicant', title: 'Applicant' },
	{ slug: 'document', title: 'Document' },
]

/**
 * The slug the designer gives a schema it creates in this version.
 *
 * @param slug The schema's own slug.
 * @return The namespaced slug.
 */
function namespaced(slug: string): string {
	return `${APP_SLUG}-${VERSION}-${slug}`
}

/**
 * Open the version's schema list.
 *
 * @param page The page.
 * @param query Extra query string, without the leading `&`.
 * @return Nothing.
 */
async function openSchemaList(page: Page, query = ''): Promise<void> {
	const suffix = query === '' ? '' : `&${query}`
	await page.goto(
		`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/schemas?_version=${VERSION}${suffix}`,
		{ waitUntil: 'domcontentloaded' },
	)
	await expect(page.getByRole('button', { name: /^diagram$/i })).toBeVisible({
		timeout: 45_000,
	})
	await dismissOverlays(page)
}

/**
 * Read a schema from OpenRegister by slug, in the page's session.
 *
 * @param page The page.
 * @param slug The namespaced slug.
 * @return The schema body, or null when it does not exist.
 */
async function readSchema(page: Page, slug: string): Promise<Record<string, unknown> | null> {
	return page.evaluate(async (slug) => {
		const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken ?? ''
		const resp = await fetch(`/index.php/apps/openregister/api/schemas/${encodeURIComponent(slug)}`, {
			headers: { requesttoken: tok },
		})
		return resp.ok ? ((await resp.json()) as Record<string, unknown>) : null
	}, slug)
}

/**
 * Create a schema through the designer's "Add schema" dialog when it is absent.
 *
 * @param page The page, on the schema list.
 * @param slug The schema's own slug.
 * @param title Its title.
 * @return Nothing.
 */
async function ensureSchema(page: Page, slug: string, title: string): Promise<void> {
	if ((await readSchema(page, namespaced(slug))) !== null) {
		return
	}

	await page.getByRole('button', { name: /add schema/i }).first().click()
	const dialog = page.locator('[role="dialog"]').filter({ hasText: /add schema/i }).first()
	await expect(dialog).toBeVisible({ timeout: 15_000 })
	await dialog.getByRole('textbox', { name: /schema slug/i }).fill(slug)
	await dialog.getByRole('textbox', { name: /^title$/i }).fill(title)
	await dialog.getByRole('button', { name: /^add schema$/i }).click()
	await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({
		timeout: 45_000,
	})
	await openSchemaList(page)
}

/**
 * Write `permit`'s two relations and a field on each schema through
 * OpenRegister's schema API. The API reads by slug but writes by id.
 *
 * @param page The page.
 * @return Nothing.
 */
async function linkSchemas(page: Page): Promise<void> {
	const fields: Record<string, Record<string, unknown>> = {
		permit: {
			reference: { type: 'string' },
			applicant: { type: 'object', $ref: `#/components/schemas/${namespaced('applicant')}` },
			documents: {
				type: 'array',
				items: { type: 'object', $ref: `#/components/schemas/${namespaced('document')}` },
			},
		},
		applicant: { name: { type: 'string' } },
		document: { fileName: { type: 'string' } },
	}

	for (const [slug, properties] of Object.entries(fields)) {
		const schema = await readSchema(page, namespaced(slug))
		expect(schema, `schema ${namespaced(slug)} must exist`).not.toBeNull()
		const status = await page.evaluate(
			async ({ schema, properties }) => {
				const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken ?? ''
				const id = String(schema.id ?? '')
				const resp = await fetch(`/index.php/apps/openregister/api/schemas/${id}`, {
					method: 'PUT',
					headers: { requesttoken: tok, 'Content-Type': 'application/json' },
					body: JSON.stringify({ ...schema, properties }),
				})
				return resp.status
			},
			{ schema: schema as Record<string, unknown>, properties },
		)
		expect(status, `writing ${namespaced(slug)}`).toBeLessThan(300)
	}
}

test.describe('Data model diagram (REQ-BQMD-001, -003, -004)', () => {
	test.describe.configure({ timeout: 180_000 })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await ensureApp(page, APP_SLUG, 'PW Model')
			await openSchemaList(page)
			for (const schema of SCHEMAS) {
				await ensureSchema(page, schema.slug, schema.title)
			}
			await linkSchemas(page)
		} finally {
			await page.close()
		}
	})

	// @e2e data-model-diagram::a-maker-sees-how-permits-applicants-and-documents-link
	// @e2e data-model-diagram::a-screen-reader-user-reads-the-model
	test('the diagram draws the boxes and the table lists the relations', async ({ page }) => {
		await openSchemaList(page)
		await page.getByRole('button', { name: /^diagram$/i }).click()
		await expect(page).toHaveURL(/[?&]view=diagram(&|$)/)

		const canvas = page.getByTestId('data-model-canvas')
		await expect(canvas).toBeVisible({ timeout: 30_000 })
		const boxes = page.getByTestId('data-model-box')
		for (const schema of SCHEMAS) {
			await expect(boxes.filter({ hasText: schema.title }).first()).toBeVisible()
		}
		await expect(boxes.filter({ hasText: 'Permit' }).first()).toContainText('reference')

		const rows = page.getByTestId('data-model-relation-row')
		const applicantRow = rows.filter({ hasText: 'applicant' }).filter({ hasText: 'Applicant' }).first()
		await expect(applicantRow).toContainText('Permit')
		await expect(applicantRow).toContainText(/\bone\b/)
		const documentsRow = rows.filter({ hasText: 'documents' }).first()
		await expect(documentsRow).toContainText('Document')
		await expect(documentsRow).toContainText(/\bmany\b/)

		// Every row's "Open" button is reachable by keyboard.
		const open = applicantRow.getByRole('button', { name: /open/i })
		await open.focus()
		await expect(open).toBeFocused()

		// A link opens the same view.
		await openSchemaList(page, 'view=diagram')
		await expect(page.getByTestId('data-model-canvas')).toBeVisible({ timeout: 30_000 })
	})

	// @e2e data-model-diagram::dragging-does-not-make-a-relation
	test('dragging from one box to another adds no relation', async ({ page }) => {
		await openSchemaList(page, 'view=diagram')
		const boxes = page.getByTestId('data-model-box')
		await expect(boxes.first()).toBeVisible({ timeout: 30_000 })
		const before = await page.getByTestId('data-model-relation-row').count()
		const applicantBefore = JSON.stringify((await readSchema(page, namespaced('applicant')))?.properties ?? {})

		const from = await boxes.filter({ hasText: 'Applicant' }).first().boundingBox()
		const to = await boxes.filter({ hasText: 'Document' }).first().boundingBox()
		expect(from).not.toBeNull()
		expect(to).not.toBeNull()
		await page.mouse.move(from!.x + from!.width - 2, from!.y + from!.height / 2)
		await page.mouse.down()
		await page.mouse.move(to!.x + 2, to!.y + to!.height / 2, { steps: 12 })
		await page.mouse.up()

		await expect(page.getByTestId('data-model-relation-row')).toHaveCount(before)
		const applicantAfter = JSON.stringify((await readSchema(page, namespaced('applicant')))?.properties ?? {})
		expect(applicantAfter, 'a drag must not change the schema').toBe(applicantBefore)
	})

	// @e2e data-model-diagram::a-maker-opens-the-document-schema-from-the-diagram
	test('Enter on a focused box opens that schema in the designer', async ({ page }) => {
		await openSchemaList(page, 'view=diagram')
		const box = page.getByTestId('data-model-box').filter({ hasText: 'Document' }).first()
		await expect(box).toBeVisible({ timeout: 30_000 })

		// The canvas makes each node focusable; focus the one holding the box.
		await box.evaluate((element) => {
			const focusable = element.closest('[tabindex]') as HTMLElement | null
			;(focusable ?? (element as HTMLElement)).focus()
		})
		await page.keyboard.press('Enter')

		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({
			timeout: 30_000,
		})
		await expect(page).toHaveURL(new RegExp(`${namespaced('document')}|/document(\\?|$)`))
		await expect(page).toHaveURL(/_version=production/)
	})

	// T06: the view passes an axe check.
	test('the diagram view has no serious or critical axe violations', async ({ page }) => {
		await openSchemaList(page, 'view=diagram')
		await expect(page.getByTestId('data-model-table')).toBeVisible({ timeout: 30_000 })

		await page.addScriptTag({ content: axeCore.source })
		const violations = await page.evaluate(async () => {
			const axe = (window as unknown as {
				axe: { run: (ctx: unknown, opts: unknown) => Promise<{ violations: { id: string, impact: string | null, nodes: unknown[] }[] }> }
			}).axe
			const result = await axe.run(
				{ include: [['.buildiq-data-model']] },
				{ runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } },
			)
			return result.violations
				.filter((v) => v.impact === 'serious' || v.impact === 'critical')
				.map((v) => `${v.id} (${v.impact}, ${v.nodes.length} nodes)`)
		})
		expect(violations, violations.join('\n')).toEqual([])
	})
})
