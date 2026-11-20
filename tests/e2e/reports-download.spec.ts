/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — the two report pages download what they show as CSV
 * (operate-report-download, REQ-BQREP-004).
 *
 * Each report is a `type: dashboard` page with `config.download: true`
 * (src/manifest.json). The header's "Download" button saves
 * `<downloadName>-<yyyy-mm-dd>.csv` with the header row `widget,label,value`
 * and one row per figure on the page (design D2). The button stays disabled
 * until every widget has settled.
 *
 * The figures are not seeded here: the test reads the count a stat tile shows
 * and asserts the same number in the file, so it holds on any instance.
 *
 * Runs as the suite's admin session (globalSetup storage state); the base URL
 * comes from tests/e2e/support/baseUrl.ts, never a hard-coded host.
 *
 * @spec openspec/changes/operate-report-download/specs/buildiq-reports/spec.md#requirement-a-report-can-be-downloaded-as-csv-req-bqrep-004
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readFile } from 'fs/promises'
import { suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const REPORTS = [
	{ route: 'reports/applications', fileName: 'buildiq-applications', stat: 'Published' },
	{ route: 'reports/delivery', fileName: 'buildiq-delivery', stat: 'Versions published' },
]

/**
 * Today's date as the file name carries it.
 *
 * @return {string} yyyy-mm-dd in local time.
 */
function today(): string {
	const now = new Date()
	const pad = (n: number): string => String(n).padStart(2, '0')
	return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`
}

/**
 * Open a report page with the first-run overlays out of the way.
 *
 * @param {Page} page The page.
 * @param {string} route The report route under /apps/buildiq/.
 * @return {Promise<void>}
 */
async function openReport(page: Page, route: string): Promise<void> {
	await suppressSupportDialog(page)
	await suppressSetupWizard(page)
	await page.goto(`${BASE_URL}/apps/buildiq/${route}`, { waitUntil: 'domcontentloaded' })
}

/**
 * Split a CSV file into rows of cells (quoted cells may hold commas).
 *
 * @param {string} text The file content.
 * @return {string[][]} The rows.
 */
function parseCsv(text: string): string[][] {
	return text
		.replace(/^\uFEFF/, '')
		.split(/\r?\n/)
		.filter((line) => line !== '')
		.map((line) => {
			const cells: string[] = []
			let cell = ''
			let quoted = false
			for (let i = 0; i < line.length; i++) {
				const char = line[i]
				if (quoted && char === '"' && line[i + 1] === '"') {
					cell += '"'
					i++
				} else if (char === '"') {
					quoted = !quoted
				} else if (char === ',' && !quoted) {
					cells.push(cell)
					cell = ''
				} else {
					cell += char
				}
			}
			cells.push(cell)
			return cells
		})
}

test.describe('the reports download as CSV', () => {
	for (const report of REPORTS) {
		test(`${report.route} saves ${report.fileName}-<date>.csv with the figures it shows`, async ({ page }) => {
			await openReport(page, report.route)

			const download = page.getByRole('button', { name: /^Download$/ }).first()
			await expect(download, 'the report header offers Download').toBeVisible({ timeout: 30_000 })
			await expect(download, 'Download is enabled once every widget settled').toBeEnabled({ timeout: 30_000 })

			// The number the stat tile shows is the number the file must carry.
			const tile = page.locator('.cn-widget-wrapper, [data-testid^="cn-widget"]').filter({ hasText: report.stat }).first()
			await expect(tile).toBeVisible()
			const shown = ((await tile.innerText()).match(/\d[\d.,]*/) ?? [''])[0].replace(/[.,]/g, '')

			const [file] = await Promise.all([page.waitForEvent('download'), download.click()])
			expect(file.suggestedFilename()).toBe(`${report.fileName}-${today()}.csv`)

			const rows = parseCsv(await readFile(await file.path(), 'utf8'))
			expect(rows[0], 'the header row').toEqual(['widget', 'label', 'value'])
			const countRow = rows.find((row) => row[0] === report.stat && row[1] === report.stat)
			expect(countRow, `a row for the ${report.stat} count`).toBeDefined()
			expect(countRow?.[2]).toBe(shown)
		})
	}

	test('Download is disabled while a widget is still loading', async ({ page }) => {
		// Hold every OpenRegister read so the export counts stay loading.
		let release: () => void = () => {}
		const held = new Promise<void>((resolve) => {
			release = resolve
		})
		await page.route('**/apps/openregister/api/**', async (route) => {
			await held
			await route.continue()
		})

		await openReport(page, 'reports/delivery')
		const download = page.getByRole('button', { name: /^Download$/ }).first()
		await expect(download).toBeVisible({ timeout: 30_000 })
		await expect(download, 'Download waits for the figures').toBeDisabled()

		release()
		await expect(download).toBeEnabled({ timeout: 30_000 })
	})
})
