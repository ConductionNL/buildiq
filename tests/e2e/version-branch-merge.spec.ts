/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * lifecycle-git-branches T06: branch a version, change the same page on both
 * sides, resolve the conflict in the Merge dialog and see the merged page on
 * the target (REQ-BQGB-001, REQ-BQGB-003).
 *
 * WHAT IS DRIVEN THROUGH THE UI AND WHAT IS NOT
 * --------------------------------------------
 * The merge is what a maintainer does in the Version history tab, so the
 * dialog is clicked: the target, the conflict, "Take branch", Merge. Making the
 * draft, the branch and the two edits are set-up, done through the versions
 * API the same way `support/versionChain.ts` seeds a chain. The result is read
 * back from the API: the target's manifest carries the branch's page.
 *
 * `MergeVersionDialog.spec.js` and `VersionMergeControllerTest` cover the
 * dialog and the merge rules against doubles; this is the live round trip.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSupportDialog } from './support/appFixture.ts'

const APP_SLUG = 'pw-branch-merge'
const RUN = Date.now().toString(36)
const BASE_SLUG = `pw-draft-${RUN}`
const BRANCH_NAME = `pw-branch-${RUN}`
const PAGE_ID = `pwMergePage${RUN}`

/**
 * Call the app's API from inside the page, with the session's request token.
 *
 * @param page   The page.
 * @param method The HTTP method.
 * @param path   The path under /index.php/apps/buildiq/api.
 * @param body   The JSON body, if any.
 * @return The status and the parsed answer.
 */
async function api(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, path, body }) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(`/index.php/apps/buildiq/api${path}`, {
				method,
				headers: { requesttoken: token, 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await resp.text()
			let data: unknown = text
			try {
				data = JSON.parse(text)
			} catch {
				// not JSON
			}
			return { status: resp.status, data }
		},
		{ method, path, body },
	)
}

/**
 * The versions of the app.
 *
 * @param page The page.
 * @return The version rows.
 */
async function versions(page: Page): Promise<any[]> {
	const { data } = await api(page, 'GET', `/applications/${APP_SLUG}/versions`)
	return Array.isArray(data) ? data : (data?.results ?? [])
}

/**
 * A version's manifest with the test page titled `title`.
 *
 * @param manifest The manifest.
 * @param title    The page's title.
 * @return The changed manifest.
 */
function withPageTitle(manifest: any, title: string): any {
	const pages = (manifest?.pages ?? []).filter((p: any) => p?.id !== PAGE_ID)
	pages.push({ id: PAGE_ID, route: `/pw-merge-${RUN}`, type: 'custom', component: 'PwMergePage', title })
	return { ...manifest, pages }
}

test.describe('A branch is merged back, with its conflict resolved in the dialog', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	let branchSlug = ''

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		await page.goto('/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		for (const slug of [branchSlug, BASE_SLUG].filter((s) => s !== '')) {
			await api(page, 'DELETE', `/applications/${APP_SLUG}/versions/${slug}`)
		}
		await page.close()
	})

	test('set-up: a draft, a branch of it, and the same page changed on both sides', async ({ page }) => {
		await ensureApp(page, APP_SLUG, 'PW Branch Merge')

		const created = await api(page, 'POST', `/applications/${APP_SLUG}/versions`, {
			slug: BASE_SLUG,
			name: BASE_SLUG,
			semver: '1.0.0',
			status: 'draft',
			manifest: withPageTitle({ version: '1.0.0', menu: [], pages: [] }, 'The page as it was'),
		})
		expect(created.status, JSON.stringify(created.data)).toBeLessThan(300)

		const branched = await api(page, 'POST', `/applications/${APP_SLUG}/versions/${BASE_SLUG}/branch`, { name: BRANCH_NAME })
		expect(branched.status, JSON.stringify(branched.data)).toBe(201)

		const rows = await versions(page)
		const branch = rows.find((v) => v?.name === BRANCH_NAME || v?.slug === BRANCH_NAME)
		expect(branch, 'the branch is a version of the app').toBeTruthy()
		branchSlug = String(branch.slug)
		const base = rows.find((v) => v?.slug === BASE_SLUG)

		for (const [slug, row, title] of [[branchSlug, branch, 'Changed on the branch'], [BASE_SLUG, base, 'Changed on the target']]) {
			const saved = await api(page, 'PUT', `/applications/${APP_SLUG}/versions/${slug}`, {
				...row,
				manifest: withPageTitle(row.manifest, title),
			})
			expect(saved.status, `${slug}: ${JSON.stringify(saved.data)}`).toBeLessThan(300)
		}
	})

	test('the maintainer merges the branch, taking the branch side of the conflict', async ({ page }) => {
		test.skip(branchSlug === '', 'needs the set-up test')
		const app = (await api(page, 'GET', '/applications')).data
		const rows: any[] = Array.isArray(app) ? app : (app?.results ?? [])
		const application = rows.find((a) => a?.slug === APP_SLUG)
		const objectId = String(application?.id ?? application?.uuid ?? application?.['@self']?.id ?? '')
		expect(objectId, 'the app has an id').not.toBe('')

		await page.goto(`/apps/buildiq/applications/${objectId}`)
		await dismissOverlays(page)
		await page.getByRole('tab', { name: /Version history|Versiegeschiedenis/ }).first().click()

		const row = page.locator('li, tr').filter({ hasText: BRANCH_NAME }).filter({ has: page.locator('[data-test="version-merge"]') }).first()
		await expect(row).toBeVisible({ timeout: 45_000 })
		await row.locator('[data-test="version-merge"]').click()

		const dialog = page.getByRole('dialog')
		await dialog.getByRole('combobox').first().click()
		await page.getByRole('option', { name: new RegExp(BASE_SLUG) }).first().click()

		const conflict = dialog.locator('[data-test="merge-conflict"]').first()
		await expect(conflict, 'the page changed on both sides is a conflict').toBeVisible({ timeout: 30_000 })
		const merge = dialog.getByRole('button', { name: 'Merge', exact: true })
		await expect(merge, 'Merge stays off until the conflict has a side').toBeDisabled()

		await conflict.getByText('Take branch').click()
		await expect(merge).toBeEnabled()
		await merge.click()
		await expect(dialog).toBeHidden({ timeout: 30_000 })

		const target = (await versions(page)).find((v) => v?.slug === BASE_SLUG)
		const merged = (target?.manifest?.pages ?? []).find((p: any) => p?.id === PAGE_ID)
		expect(merged?.title, 'the target carries the branch side').toBe('Changed on the branch')
	})
})
