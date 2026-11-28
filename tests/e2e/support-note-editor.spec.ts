/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — the support-note editor saves (apps-support-note-editor-saves, T05).
 *
 * A maker opens "Support & donation" on the app detail page, switches the note
 * on, sets a title and clicks Done: one PUT to the app's manifest, and the
 * editor shows the saved note when reopened. Closing without Done sends
 * nothing. A failed save keeps the editor open with the edit. A viewer has no
 * such action. The virtual app shows the note on first open when it is on, and
 * an app without a support block shows none.
 *
 * Fresh contexts. The viewer and the "first open" visitors sign in from an
 * empty storage state through the login form, so neither the admin's session
 * nor a remembered "already shown" flag leaks in. The maker is the admin of
 * the shared storage state, as in every other detail-page spec.
 *
 * Apps of its own: `sn-e2e-<run>` (note on) and `sn-e2e-<run>-bare` (no
 * note), made with the wizard fixture, so nothing seeded is changed.
 *
 * Not run in the change that wrote it (decision 139): the live pass runs it.
 *
 * @spec openspec/changes/archive/2026-11-24-apps-support-note-editor-saves/specs/support-note/spec.md
 * @e2e support-note/requirement-the-support-editor-saves-the-note-to-the-apps-manifest-req-bqsn-001/a-maker-saves-a-support-note
 * @e2e support-note/requirement-the-support-editor-saves-the-note-to-the-apps-manifest-req-bqsn-001/closing-without-done-keeps-the-saved-note
 * @e2e support-note/requirement-the-support-editor-saves-the-note-to-the-apps-manifest-req-bqsn-001/a-viewer-does-not-get-the-action
 * @e2e support-note/requirement-a-failed-save-keeps-the-editor-open-req-bqsn-002/the-server-refuses-the-save
 * @e2e support-note/requirement-a-virtual-app-shows-the-note-only-when-it-is-switched-on-req-bqsn-003/switched-on-the-note-shows-on-first-open
 * @e2e support-note/requirement-a-virtual-app-shows-the-note-only-when-it-is-switched-on-req-bqsn-003/no-support-block-no-note
 */
import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSetupWizard } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const ADMIN_STATE = 'tests/e2e/.auth/admin.json'
const RUN = Date.now().toString(36)
const SLUG = `sn-e2e-${RUN}`
const BARE = `sn-e2e-${RUN}-bare`
const TITLE = 'Over het Subsidieloket'

/** The persona global-setup creates in `rbac-viewers` (tests/e2e/global-setup.ts). */
const VIEWER = { id: 'rbac-viewer', pass: 'RbacViewer-1!' }
const ADMIN = {
	id: process.env.NC_ADMIN_USER ?? 'admin',
	pass: process.env.NC_ADMIN_PASS ?? 'admin',
}

/**
 * A page in a context with an empty storage state, signed in through the form.
 *
 * @param browser The browser.
 * @param user    Who signs in.
 * @param user.id   The user id.
 * @param user.pass The password.
 * @return The page; the caller closes its context.
 */
async function freshSignIn(browser: Browser, user: { id: string; pass: string }): Promise<Page> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await page.goto(`${BASE_URL}/index.php/login`, { waitUntil: 'domcontentloaded' })
	await page.locator('input[name="user"]').fill(user.id)
	await page.locator('input[name="password"]').fill(user.pass)
	await page.locator('button[type="submit"], input[type="submit"]').first().click()
	await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })

	return page
}

/**
 * Open the app detail page and its Actions menu.
 *
 * @param page The page.
 * @param slug The app.
 */
async function openActions(page: Page, slug: string): Promise<void> {
	await page.goto(`${BASE_URL}/apps/buildiq/applications/${slug}`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	await page.getByRole('button', { name: /^Actions$/i }).first().click({ timeout: 30_000 })
}

/**
 * Open the support editor from the Actions menu.
 *
 * @param page The page.
 * @return The editor dialog.
 */
async function openSupportEditor(page: Page) {
	await openActions(page, SLUG)
	await page.locator('[data-testid="cn-action-app-edit-support"]').first().click()
	const dialog = page.getByRole('dialog', { name: /Edit support/i })
	await expect(dialog).toBeVisible({ timeout: 30_000 })

	return dialog
}

/**
 * Count the PUTs to the app's manifest from now on.
 *
 * @param page The page.
 * @return A function that answers the count so far.
 */
function countManifestPuts(page: Page): () => number {
	let count = 0
	page.on('request', (sent) => {
		if (sent.method() === 'PUT' && sent.url().includes(`/api/applications/${SLUG}/manifest`)) {
			count++
		}
	})

	return () => count
}

test.describe('the support-note editor saves the note', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(180_000)

	test.beforeAll(async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSetupWizard(page)
			await ensureApp(page, SLUG, 'Subsidieloket e2e')
			await ensureApp(page, BARE, 'Meldingen e2e')
			await grantAppRoles(page, SLUG, { viewers: ['group:rbac-viewers'] })
		} finally {
			await context.close()
		}
	})

	test('a maker saves a support note, and reopening shows it', async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSetupWizard(page)
			const puts = countManifestPuts(page)
			const dialog = await openSupportEditor(page)

			const toggle = dialog.locator('input[type="checkbox"]').first()
			if (!(await toggle.isChecked())) {
				await dialog.getByText('Show the support note on first open').click()
			}
			await dialog.getByLabel('Dialog title').fill(TITLE)
			await dialog.getByRole('button', { name: /^Done$/ }).click()
			await expect(dialog).toHaveCount(0, { timeout: 30_000 })
			expect(puts()).toBe(1)

			const reopened = await openSupportEditor(page)
			await expect(reopened.getByLabel('Dialog title')).toHaveValue(TITLE)
			await expect(reopened.locator('input[type="checkbox"]').first()).toBeChecked()
		} finally {
			await context.close()
		}
	})

	test('closing without Done sends nothing and keeps the saved note', async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSetupWizard(page)
			const puts = countManifestPuts(page)
			const dialog = await openSupportEditor(page)
			await dialog.getByLabel('Dialog title').fill('Een andere titel')
			await dialog.getByRole('button', { name: /close/i }).first().click()
			await expect(dialog).toHaveCount(0, { timeout: 15_000 })
			expect(puts()).toBe(0)

			const reopened = await openSupportEditor(page)
			await expect(reopened.getByLabel('Dialog title')).toHaveValue(TITLE)
		} finally {
			await context.close()
		}
	})

	test('a refused save keeps the editor open with the edit', async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSetupWizard(page)
			await page.route(`**/api/applications/${SLUG}/manifest`, async (route) => {
				if (route.request().method() === 'PUT') {
					await route.fulfill({ status: 500, json: { error: 'e2e refusal' } })
					return
				}
				await route.continue()
			})
			const dialog = await openSupportEditor(page)
			await dialog.getByLabel('Dialog title').fill('Titel die niet opslaat')
			await dialog.getByRole('button', { name: /^Done$/ }).click()

			await expect(page.getByText(/Could not save the support note/)).toBeVisible({ timeout: 15_000 })
			await expect(dialog).toBeVisible()
			await expect(dialog.getByLabel('Dialog title')).toHaveValue('Titel die niet opslaat')
		} finally {
			await context.close()
		}
	})

	test('a viewer has no support action', async ({ browser }) => {
		const page = await freshSignIn(browser, VIEWER)
		try {
			await suppressSetupWizard(page)
			await openActions(page, SLUG)
			await expect(page.locator('[data-testid="cn-action-app-edit-support"]')).toHaveCount(0)
		} finally {
			await page.context().close()
		}
	})

	test('the virtual app shows the saved note on first open', async ({ browser }) => {
		const page = await freshSignIn(browser, ADMIN)
		try {
			await page.goto(`${BASE_URL}/apps/buildiq/builder/${SLUG}`, { waitUntil: 'domcontentloaded' })
			const note = page.locator('[data-testid-modal="cn-support-dialog"]')
			await expect(note).toBeVisible({ timeout: 60_000 })
			await expect(note).toContainText(TITLE)
		} finally {
			await page.context().close()
		}
	})

	test('an app without a support block shows no note', async ({ browser, request }) => {
		const manifest = await request.get(`${BASE_URL}/index.php/apps/buildiq/api/applications/${BARE}/manifest`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		expect(manifest.ok()).toBeTruthy()
		expect((await manifest.json()).support?.enabled ?? false).toBe(false)

		const page = await freshSignIn(browser, ADMIN)
		try {
			await page.goto(`${BASE_URL}/apps/buildiq/builder/${BARE}`, { waitUntil: 'domcontentloaded' })
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({ timeout: 60_000 })
			await expect(page.locator('[data-testid-modal="cn-support-dialog"]')).toHaveCount(0)
		} finally {
			await page.context().close()
		}
	})
})
