// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E — "Start from a spreadsheet" in the create-app wizard
 * (capability-requirements-backfill T01, REQ-BQCV-001).
 *
 *   - A maker starts an app from a spreadsheet: a CSV of 40 rows with the columns
 *     name, address and status gives an app whose schema has those fields, whose
 *     first version's register holds 40 records, and which opens on a list page
 *     with a detail page beside it.
 *   - A file that cannot be read creates nothing: the wizard shows the error and no
 *     app with that slug exists afterwards.
 *
 * Written to run in the nightly against a live stack (BUILDIQ_E2E_LIVE=1); without
 * it every test is skipped, so `playwright test --list` still parses the file.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const LIVE = process.env.BUILDIQ_E2E_LIVE === '1'
const APP_SLUG = 'pw-permits-from-sheet'
const BROKEN_SLUG = 'pw-broken-sheet'
const OR_APPLICATIONS = '/index.php/apps/openregister/api/objects/buildiq/built-app'

/**
 * A CSV of 40 permits with the columns name, address and status.
 *
 * @return The file contents.
 */
function permitsCsv(): string {
	const lines = ['name,address,status']
	for (let i = 1; i <= 40; i++) {
		lines.push(`Permit ${i},"Main street ${i}, Utrecht",open`)
	}
	return lines.join('\n') + '\n'
}

/**
 * Call a Nextcloud route from inside the page, with its session and request token.
 *
 * @param page   A signed-in page on a Nextcloud route.
 * @param method The HTTP method.
 * @param path   The path from the web root.
 * @param body   The JSON body, if any.
 * @return The status and the parsed answer.
 */
async function api(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(path, {
				method,
				headers: { requesttoken: token, 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await resp.text()
			let data: unknown = text
			try {
				data = JSON.parse(text)
			} catch {
				// Not JSON; keep the text for the failure message.
			}
			return { status: resp.status, data }
		},
		{ method, path, body },
	)
}

/**
 * The Applications with one of these slugs.
 *
 * @param page  A signed-in page.
 * @param slugs The slugs.
 * @return The matching Application objects.
 */
async function appsWithSlug(page: Page, slugs: string[]): Promise<any[]> {
	const { data } = await api(page, 'GET', `${OR_APPLICATIONS}?_limit=200`)
	const items = Array.isArray(data) ? data : (data?.results ?? [])
	return items.filter((app: any) => slugs.includes(app?.slug ?? app?.['@self']?.slug))
}

/**
 * Remove the Applications this spec makes, so every run starts with free slugs.
 *
 * @param page A signed-in page.
 * @return {Promise<void>}
 */
async function removeFixtureApps(page: Page): Promise<void> {
	for (const app of await appsWithSlug(page, [APP_SLUG, BROKEN_SLUG])) {
		const id = app?.id ?? app?.['@self']?.id
		if (id) {
			await api(page, 'DELETE', `${OR_APPLICATIONS}/${id}`)
		}
	}
}

test.describe('Create an app from a spreadsheet (REQ-BQCV-001)', () => {
	test.skip(!LIVE, 'Needs a live stack: set BUILDIQ_E2E_LIVE=1.')

	test.beforeEach(async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/buildiq/applications`)
		await page.waitForSelector('.ob-va-actions, [data-cy="ob-actions"]', { timeout: 20_000 })
		await removeFixtureApps(page)
	})

	test('a maker starts an app from a spreadsheet', async ({ page }) => {
		await page.getByRole('button', { name: /add app/i }).first().click()
		const wizard = page.locator('[data-testid-modal="cn-wizard-dialog"]')
		await wizard.locator('#wizard-app-name').fill('PW permits from sheet')
		await wizard.locator('[data-testid="wizard-spreadsheet-toggle"]').check()
		await wizard.locator('[data-testid="wizard-spreadsheet-file"]').setInputFiles({
			name: 'permits.csv',
			mimeType: 'text/csv',
			buffer: Buffer.from(permitsCsv()),
		})
		await expect(wizard.locator('[data-testid="wizard-spreadsheet-chosen"]')).toBeVisible()

		const created = page.waitForResponse((resp) => resp.url().includes('/api/applications/wizard'))
		for (let step = 0; step < 4; step++) {
			const next = wizard.getByRole('button', { name: /^(next|create)/i }).last()
			if (await next.isVisible()) {
				await next.click()
			}
		}
		expect((await created).status()).toBe(201)

		const manifest = await api(page, 'GET', `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`)
		expect(manifest.status).toBe(200)
		const pages = manifest.data?.pages ?? manifest.data?.manifest?.pages ?? []
		expect(pages.map((entry: any) => entry.type).slice(0, 2)).toEqual(['index', 'detail'])
		const { register, schema } = pages[0].config

		const records = await api(page, 'GET', `/index.php/apps/openregister/api/objects/${register}/${schema}?_limit=100`)
		const results = records.data?.results ?? []
		expect(results).toHaveLength(40)
		expect(Object.keys(results[0])).toEqual(expect.arrayContaining(['name', 'address', 'status']))
	})

	test('a file that cannot be read creates nothing', async ({ page }) => {
		const answer = await api(page, 'POST', '/index.php/apps/buildiq/api/applications/wizard', {
			name: 'PW broken sheet',
			slug: BROKEN_SLUG,
			preset: 'single',
			fromFile: { name: 'broken.xlsx', mime: 'application/vnd.ms-excel', content: Buffer.from('not a workbook').toString('base64') },
		})

		expect(answer.status).toBeGreaterThanOrEqual(400)
		expect(String(answer.data?.message ?? '')).toContain('XLSX')
		expect(await appsWithSlug(page, [BROKEN_SLUG])).toHaveLength(0)
	})
})
