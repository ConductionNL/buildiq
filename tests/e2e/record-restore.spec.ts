/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Playwright e2e: restoring a record to an earlier version (change
 * data-restore-record-version, task T04, REQ-BQRR-001 to REQ-BQRR-004).
 *
 * The fixture app is cloned from the `incident-reporter` template; the spec's
 * permit page becomes the template's incident detail page, and the status
 * change "in review" to "rejected" becomes `triaged` to `resolved`.
 *
 * 1. A maker turns on "Show record history" on the detail page in the page
 *    designer (the staged tab carries `allowRestore: false`), then "Let users
 *    restore an earlier version", saves and publishes. The stored page config
 *    is read back.
 * 2. An app user's two edits are made through OpenRegister (`new` to
 *    `triaged`, then `triaged` to `resolved`). In the published app the user
 *    opens the "History" tab, expands the entry that set `triaged`, chooses
 *    "Restore this version" and confirms; the record reads `triaged` again and
 *    the history has one entry more, the restore, with the `resolved` entry
 *    kept. The case handler here is the admin who owns the app: the right that
 *    matters is update on the record, and the owner holds it.
 * 3. A reader (`rbac-viewer`, a viewer of the app, provisioned by
 *    tests/e2e/global-setup.ts) opens the same tab and sees the changes of an
 *    entry but no "Restore this version".
 * 4. A record locked by a colleague (`rbac-editor`) offers no usable restore:
 *    the button is disabled and names the lock holder. The lock is taken with
 *    the colleague's credentials through OpenRegister's lock route; when that
 *    is refused on the instance under test, the test skips with that reason.
 *
 * DEPENDS ON NEXTCLOUD-VUE. The button, the confirmation and the call are the
 * sibling half (task T03): `allowRestore` on `CnAuditTrailTab`. The suite reads
 * the installed `@conduction/nextcloud-vue` and skips the runtime tests, saying
 * so, when that component has no `allowRestore` yet; the maker test (1) needs
 * only buildiq and always runs.
 *
 * The fixture app is removed, data included, in afterAll.
 *
 * @spec openspec/changes/data-restore-record-version/specs/record-history-restore/spec.md
 * @e2e record-history-restore/requirement-a-maker-shows-the-record-history-on-a-detail-page-req-bqrr-001/a-maker-adds-the-history-tab-to-the-permit-page
 * @e2e record-history-restore/requirement-restore-is-the-maker-s-choice-and-off-by-default-req-bqrr-002/a-maker-lets-users-restore-permits
 * @e2e record-history-restore/requirement-an-app-user-restores-a-record-to-an-earlier-state-req-bqrr-003/a-case-handler-undoes-a-wrong-status-change
 * @e2e record-history-restore/requirement-only-a-user-who-may-edit-the-record-can-restore-it-req-bqrr-004/a-reader-sees-the-history-without-the-button
 * @e2e record-history-restore/requirement-only-a-user-who-may-edit-the-record-can-restore-it-req-bqrr-004/a-locked-record-refuses-the-restore
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { APIRequestContext, Locator, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { existsSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { dismissOverlays, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'
import { readStagedManifest } from './support/stagedManifest.ts'

/** The suite's admin session, written by global-setup. */
const ADMIN = { baseURL: E2E_BASE_URL, storageState: path.resolve(__dirname, '.auth', 'admin.json') }
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const VIEWER_USER = process.env.NC_RBAC_VIEWER_USER ?? 'rbac-viewer'
const VIEWER_PASS = process.env.NC_RBAC_VIEWER_PASS ?? 'RbacViewer-1!'
const COLLEAGUE_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const COLLEAGUE_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'

/** Whether the installed renderer offers restore (nextcloud-vue, task T03). */
const AUDIT_TAB = path.resolve(__dirname, '../../node_modules/@conduction/nextcloud-vue/src/components/CnObjectSidebar/CnAuditTrailTab.vue')
const RENDERER_RESTORES = existsSync(AUDIT_TAB) && readFileSync(AUDIT_TAB, 'utf8').includes('allowRestore')
const NO_RENDERER = 'the installed @conduction/nextcloud-vue has no allowRestore on CnAuditTrailTab yet (task T03)'

const APP_SLUG = 'pw-record-restore'
const TEMPLATE = 'incident-reporter'
const SCHEMA = 'incident'
const MANIFEST_URL = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`

let appUuid = ''
let register = ''
let detailPageId = ''
let detailBase = ''
let published = false
const records: Record<string, string> = {}

/**
 * Call a Nextcloud route from inside the page, with its session and request token.
 *
 * @param page   A signed-in page on a Nextcloud route.
 * @param method The HTTP method.
 * @param url    The path from the web root.
 * @param body   The JSON body, if any.
 * @return The status and the parsed answer.
 */
async function api(page: Page, method: string, url: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, url, body }) => {
			const token = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(url, {
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
		{ method, url, body },
	)
}

/**
 * An object's UUID in any of the shapes OpenRegister returns it in.
 *
 * @param obj The decoded object.
 * @return The UUID.
 */
function idOf(obj: any): string {
	return String(obj?.id ?? obj?.uuid ?? obj?.['@self']?.id ?? '')
}

/**
 * The OpenRegister path of one incident.
 *
 * @param id The incident.
 * @return The path from the web root.
 */
function objectPath(id: string): string {
	return `/index.php/apps/openregister/api/objects/${register}/${SCHEMA}/${id}`
}

/**
 * Create an incident with status `new` and edit its status twice, as an app
 * user would, so its history holds a create and two updates.
 *
 * @param page A signed-in page.
 * @param key  Where to remember the record.
 * @return The record's id.
 */
async function incidentEditedTwice(page: Page, key: string): Promise<string> {
	const made = await api(page, 'POST', `/index.php/apps/openregister/api/objects/${register}/${SCHEMA}`, {
		reportedBy: 'e2e',
		incidentType: 'equipment',
		severity: 'low',
		location: 'Depot',
		description: `Restore fixture ${key}`,
		status: 'new',
	})
	expect(made.status, JSON.stringify(made.data).slice(0, 300)).toBeLessThan(300)
	const id = idOf(made.data)
	records[key] = id
	for (const status of ['triaged', 'resolved']) {
		const current = (await api(page, 'GET', objectPath(id))).data
		const body = { ...current, status }
		// OpenRegister saves are PUT-semantic: the whole record goes back.
		delete body['@self']
		const edited = await api(page, 'PUT', objectPath(id), body)
		expect(edited.status, `edit to ${status}: ${JSON.stringify(edited.data).slice(0, 200)}`).toBeLessThan(300)
	}
	return id
}

/**
 * Log in through the login form.
 *
 * @param page Playwright page in an empty storage state.
 * @param user User id.
 * @param pass Password.
 */
async function loginAs(page: Page, user: string, pass: string): Promise<void> {
	await page.goto(`${E2E_BASE_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} appears to have failed, still on ${page.url()}.`)
	}
}

/**
 * Open a record's detail page in the published app and its "History" tab.
 *
 * The sidebar can be closed and re-mounts after the page loads, so the end
 * state (the tab's entries on screen) is polled, as version-rollback.spec.ts
 * does for its own sidebar tab.
 *
 * @param page A signed-in page.
 * @param id   The record.
 * @return The history entries.
 */
async function openHistory(page: Page, id: string): Promise<Locator> {
	await page.goto(`${E2E_BASE_URL}/index.php/apps/buildiq/builder/${APP_SLUG}${detailBase}/${id}`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	const tab = page.getByRole('tab', { name: /^History$/ }).first()
	const openSidebar = page.getByRole('button', { name: 'Open sidebar', exact: true })
	const entries = page.locator('.cn-audit-entry')
	await expect(async () => {
		if (!(await tab.isVisible().catch(() => false))) {
			await openSidebar.click({ timeout: 10_000 })
		}
		await tab.click({ timeout: 10_000 })
		await expect(entries.first()).toBeVisible({ timeout: 5_000 })
	}, 'the History tab must open and list the record\'s entries').toPass({ timeout: 60_000, intervals: [500, 1_000, 2_000] })
	return entries
}

/**
 * Expand the history entry that set a status, and return its details.
 *
 * @param entries The history entries, newest first.
 * @param status  The status the entry set.
 * @return The expanded details.
 */
async function expandEntrySetting(entries: Locator, status: string): Promise<Locator> {
	const count = await entries.count()
	for (let i = 0; i < count; i++) {
		const entry = entries.nth(i)
		await entry.getByText(/^(create|update|revert)$/).first().click()
		const details = entry.locator('.cn-audit-details')
		await expect(details).toBeVisible({ timeout: 10_000 })
		if ((await details.locator('.cn-audit-details__new').filter({ hasText: new RegExp(`^${status}$`) }).count()) > 0) {
			return details
		}
	}
	throw new Error(`no history entry sets status ${status}`)
}

/**
 * The colleague's request context: no admin session, the colleague's own
 * credentials.
 *
 * @param playwright The `playwright` fixture.
 * @return The context; the caller disposes it.
 */
async function colleagueContext(playwright: any): Promise<APIRequestContext> {
	return playwright.request.newContext({
		baseURL: E2E_BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: COLLEAGUE_USER, password: COLLEAGUE_PASS, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
}

test.describe('data-restore-record-version: an app user restores a record', () => {
	test.describe.configure({ mode: 'serial', timeout: 180_000 })
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			const created = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/from-template/${TEMPLATE}`, {
				name: 'PW Record restore',
				slug: APP_SLUG,
			})
			expect(created.status, JSON.stringify(created.data).slice(0, 300)).toBeLessThan(300)
			appUuid = String(created.data?.uuid ?? created.data?.id ?? created.data?.application?.uuid ?? '')

			const manifest = (await api(page, 'GET', MANIFEST_URL)).data
			const detail = (manifest.pages || []).find((p: any) => p.type === 'detail' && p.config?.schema === SCHEMA)
			expect(detail, 'the template has an incident detail page').toBeTruthy()
			register = String(detail.config.register ?? '')
			detailPageId = String(detail.id)
			// `/incidents/:id` → `/incidents`
			detailBase = String(detail.route).replace(/\/:[^/]+$/, '')

			await grantAppRoles(page, APP_SLUG, { owners: [`user:${ADMIN_USER}`], viewers: [`user:${VIEWER_USER}`] })
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage(ADMIN)
		try {
			await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
			for (const id of Object.values(records)) {
				await api(page, 'DELETE', objectPath(id)).catch(() => undefined)
			}
			if (appUuid !== '') {
				await api(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${appUuid}`, { deleteData: true })
			}
		} finally {
			await page.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
	})

	// @e2e record-history-restore/requirement-a-maker-shows-the-record-history-on-a-detail-page-req-bqrr-001/a-maker-adds-the-history-tab-to-the-permit-page
	// @e2e record-history-restore/requirement-restore-is-the-maker-s-choice-and-off-by-default-req-bqrr-002/a-maker-lets-users-restore-permits
	test('REQ-BQRR-001/002: a maker shows the history, then lets users restore, and publishes', async ({ page }) => {
		await page.goto(`/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)

		const pageIndex = ((await api(page, 'GET', MANIFEST_URL)).data.pages || []).findIndex((p: any) => p.id === detailPageId)
		const row = page.locator('.page-list-editor__row').nth(pageIndex)
		await row.scrollIntoViewIfNeeded()
		await row.dispatchEvent('click')
		const editor = page.locator('.detail-page-editor')
		await expect(editor).toBeVisible({ timeout: 30_000 })

		const restore = editor.getByTestId('record-history-restore')
		await expect(restore, 'restore is only offered while the history is shown').toBeDisabled()
		await editor.getByTestId('record-history-show').check()

		const historyTab = (manifest: Record<string, any>) => {
			const detail = (manifest.pages || []).find((p: any) => p.id === detailPageId)
			return (detail?.config?.sidebar?.tabs || []).find((tab: any) => tab?.id === 'history')
		}
		const staged = historyTab(await readStagedManifest(page))
		expect(staged?.widgets, 'one audit widget, restore off by default').toEqual([{ type: 'audit', props: { allowRestore: false } }])

		await expect(restore).toBeEnabled()
		await restore.check()
		await page.getByRole('button', { name: /save pages/i }).click()

		await expect
			.poll(async () => historyTab((await api(page, 'GET', MANIFEST_URL)).data)?.widgets ?? null, {
				timeout: 30_000,
				message: 'the history tab with restore on must reach the stored manifest',
			})
			.toEqual([{ type: 'audit', props: { allowRestore: true } }])

		const done = await api(page, 'POST', `/index.php/apps/buildiq/api/applications/${appUuid}/publish`)
		expect(done.status, `publishing: ${JSON.stringify(done.data).slice(0, 200)}`).toBeLessThan(300)
		published = true
	})

	// @e2e record-history-restore/requirement-an-app-user-restores-a-record-to-an-earlier-state-req-bqrr-003/a-case-handler-undoes-a-wrong-status-change
	test('REQ-BQRR-003: an app user restores the record to the state after its first edit', async ({ page }) => {
		test.skip(!RENDERER_RESTORES, NO_RENDERER)
		test.skip(!published, 'needs the published page from the maker test')

		await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		const id = await incidentEditedTwice(page, 'restored')

		const entries = await openHistory(page, id)
		await expect(entries, 'a create and two updates').toHaveCount(3, { timeout: 30_000 })

		const details = await expandEntrySetting(entries, 'triaged')
		await details.getByTestId('cn-audit-restore').click()
		const dialog = page.getByRole('dialog').filter({ hasText: /Restore this version/ }).last()
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByRole('button', { name: 'Restore this version' }).click()
		await expect(dialog.getByText('The record was restored.')).toBeVisible({ timeout: 30_000 })

		await expect
			.poll(async () => (await api(page, 'GET', objectPath(id))).data?.status, {
				timeout: 30_000,
				message: 'the record holds the restored value',
			})
			.toBe('triaged')
		await dialog.getByRole('button', { name: /close|ok/i }).first().click().catch(() => undefined)

		await expect(entries, 'the restore is a new entry, and none is removed').toHaveCount(4, { timeout: 30_000 })
		// Newest first: the restore on top, the mistaken "resolved" edit right below it.
		const below = entries.nth(1)
		await below.getByText(/^(create|update|revert)$/).first().click()
		await expect(
			below.locator('.cn-audit-details__new').filter({ hasText: /^resolved$/ }),
			'the "resolved" entry is still listed, below the restore',
		).toHaveCount(1, { timeout: 10_000 })
	})

	// @e2e record-history-restore/requirement-only-a-user-who-may-edit-the-record-can-restore-it-req-bqrr-004/a-reader-sees-the-history-without-the-button
	test.describe('as a reader of the app', () => {
		test.use({ storageState: { cookies: [], origins: [] } })

		test('REQ-BQRR-004: a reader sees an entry\'s changes and no restore button', async ({ page }) => {
			test.skip(!RENDERER_RESTORES, NO_RENDERER)
			test.skip(!records.restored, 'needs the record of the restore test')

			await loginAs(page, VIEWER_USER, VIEWER_PASS)
			await suppressSupportDialog(page)
			await suppressSetupWizard(page)
			const entries = await openHistory(page, records.restored)
			const details = await expandEntrySetting(entries, 'triaged')
			await expect(details.locator('.cn-audit-details__changes'), 'the entry shows its changes').toBeVisible()
			await expect(page.getByTestId('cn-audit-restore'), 'and no "Restore this version"').toHaveCount(0)
		})
	})

	// @e2e record-history-restore/requirement-only-a-user-who-may-edit-the-record-can-restore-it-req-bqrr-004/a-locked-record-refuses-the-restore
	test('REQ-BQRR-004: a record locked by a colleague cannot be restored and names the colleague', async ({ page, playwright }) => {
		test.skip(!RENDERER_RESTORES, NO_RENDERER)
		test.skip(!published, 'needs the published page from the maker test')

		await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		const id = await incidentEditedTwice(page, 'locked')

		const colleague = await colleagueContext(playwright)
		try {
			const locked = await colleague.post(`${objectPath(id)}/lock`, { data: { duration: 600 } })
			test.skip(!locked.ok(), `the colleague cannot lock this record here (HTTP ${locked.status()}), so there is no lock to refuse`)

			const entries = await openHistory(page, id)
			const details = await expandEntrySetting(entries, 'triaged')
			const button = details.getByTestId('cn-audit-restore')
			await expect(button, 'restore is refused while a colleague holds the lock').toBeDisabled()
			await expect(button).toHaveAttribute('title', /locked by/i)
			expect((await api(page, 'GET', objectPath(id))).data?.status, 'the record is unchanged').toBe('resolved')
		} finally {
			await colleague.post(`${objectPath(id)}/unlock`).catch(() => undefined)
			await colleague.dispose()
		}
	})
})
