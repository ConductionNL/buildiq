/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `operate-organisation-scoped-apps`, task
 * T07 (REQ-BQOS-001, REQ-BQOS-002): with two organisations on one instance, an
 * app made while the maker's active organisation is the first one starts as
 * "This organisation only"; it is listed and opens for a member of that
 * organisation, and a member of the second organisation gets the not-found
 * page on its link, a 404 from the manifest endpoint, and no row in the app
 * list.
 *
 *   1. The admin, active in organisation A, creates the app; it is held by A
 *      and its App settings show "This organisation only" switched on
 *      (REQ-BQOS-001).
 *   2. `rbac-viewer`, a member of A, finds the app in the app list and opens
 *      its runtime (REQ-BQOS-002).
 *   3. `rbac-outsider`, a member of B only, gets "App not found" on
 *      `/apps/buildiq/builder/{slug}`, a 404 from the manifest endpoint, and
 *      no row for it in the app list or the applications API (REQ-BQOS-002).
 *
 * Both users get the viewer role on the app, so the organisation scope is the
 * only thing that tells them apart: the role check alone would let both in.
 *
 * Fixture: two organisations, "PW Organisation North" and "PW Organisation
 * South", created through OpenRegister's organisations API as the admin, or
 * reused when an earlier run left them (OpenRegister has no route that
 * deletes an organisation). The two RBAC users the global setup provisions
 * join one each. afterAll takes both users out again, sets the admin's
 * active organisation back to what it was, and deletes the app with its
 * registers. The organisations themselves stay, with the admin as member.
 *
 * GUARDED: the scope is only offered, and a new app only starts scoped, while
 * OpenRegister's multitenancy is on (the page's `organisationScopeAvailable`
 * initial state, read by `AppOrganisationGuard::multitenancyOn()`). On an
 * instance where it is off, or where OpenRegister answers no organisations
 * API, every test skips with that reason.
 *
 * Not covered here: the navigation entry of a published app and the MCP
 * tools (PHPUnit, AppOrganisationGuardCoverageTest), records read around the
 * app through OpenRegister (REQ-BQOS-003), the slug collision message
 * (REQ-BQOS-004) and installs from the store (REQ-BQOS-005).
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/operate-organisation-scoped-apps/specs/organisation-scoped-apps/spec.md
 */

import type { Browser, BrowserContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = 'pw-org-scoped'
const APP_NAME = 'PW Organisation app'
const MEMBER_USER = process.env.NC_RBAC_VIEWER_USER ?? 'rbac-viewer'
const OUTSIDER_USER = process.env.NC_RBAC_OUTSIDER_USER ?? 'rbac-outsider'
const MEMBER_STATE = 'tests/e2e/.auth/rbac-viewer.json'
const OUTSIDER_STATE = 'tests/e2e/.auth/rbac-outsider.json'

const API = `${BASE_URL}/index.php/apps/buildiq/api`
const ORGANISATIONS = `${BASE_URL}/index.php/apps/openregister/api/organisations`
const OR_APPLICATIONS = `${BASE_URL}/index.php/apps/openregister/api/objects/buildiq/built-app`
const HEADERS = { 'OCS-APIRequest': 'true' }

const ORG_NORTH = 'PW Organisation North'
const ORG_SOUTH = 'PW Organisation South'

/** Shared between the serial tests. */
const state = {
	skipReason: '',
	north: '',
	south: '',
	adminActiveBefore: '',
	appUuid: '',
}

/**
 * The uuid of an OpenRegister object or organisation.
 *
 * @param body The decoded object.
 * @return The uuid, or ''.
 */
function uuidOf(body: Record<string, any> | null | undefined): string {
	return String(body?.uuid ?? body?.['@self']?.id ?? body?.id ?? '')
}

/**
 * The organisations the logged-in user belongs to, and their active one.
 *
 * @param page A logged-in page.
 * @return The list, or null when OpenRegister answers no organisations API.
 */
async function myOrganisations(page: Page): Promise<{ active: string, results: Array<Record<string, any>> } | null> {
	const resp = await page.request.get(ORGANISATIONS, { headers: HEADERS })
	if (!resp.ok()) {
		return null
	}
	const body = await resp.json()
	return { active: uuidOf(body?.active), results: Array.isArray(body?.results) ? body.results : [] }
}

/**
 * The uuid of an organisation the admin is in, by name; created when absent.
 *
 * @param page A logged-in admin page.
 * @param name The organisation's name.
 * @return The uuid.
 */
async function organisationNamed(page: Page, name: string): Promise<string> {
	const listed = await myOrganisations(page)
	const found = (listed?.results ?? []).find((org) => org?.name === name)
	if (found) {
		return uuidOf(found)
	}
	const created = await page.request.post(ORGANISATIONS, {
		headers: HEADERS,
		data: { name, description: 'Fixture organisation of tests/e2e/organisation-scoped-apps.spec.ts.' },
	})
	expect(created.status(), `create ${name}: ${(await created.text()).slice(0, 300)}`).toBe(201)
	const uuid = uuidOf((await created.json())?.organisation)
	expect(uuid, `${name} has a uuid`).not.toBe('')
	return uuid
}

/**
 * Make an organisation the logged-in user's active one.
 *
 * @param page A logged-in page.
 * @param uuid The organisation.
 */
async function setActive(page: Page, uuid: string): Promise<void> {
	const resp = await page.request.post(`${ORGANISATIONS}/${uuid}/set-active`, { headers: HEADERS })
	expect(resp.ok(), `set the active organisation: ${resp.status()} ${(await resp.text()).slice(0, 200)}`).toBeTruthy()
}

/**
 * The fixture app as OpenRegister stores it, with its `@self.organisation`.
 *
 * @param page A logged-in admin page.
 * @return The Application object, or undefined.
 */
async function storedApplication(page: Page): Promise<Record<string, any> | undefined> {
	const resp = await page.request.get(OR_APPLICATIONS, {
		headers: HEADERS,
		params: { slug: APP_SLUG, _limit: '1' },
	})
	if (!resp.ok()) {
		return undefined
	}
	const body = await resp.json()
	return (body?.results ?? []).find((row: Record<string, any>) => row?.slug === APP_SLUG)
}

/**
 * The slugs the buildiq applications API lists for the logged-in user.
 *
 * @param page A logged-in page.
 * @return The slugs.
 */
async function listedSlugs(page: Page): Promise<string[]> {
	const resp = await page.request.get(`${API}/applications`, { headers: HEADERS })
	expect(resp.ok(), `list the applications: ${resp.status()}`).toBeTruthy()
	const body = await resp.json()
	const rows = Array.isArray(body) ? body : (body?.results ?? [])
	return rows.map((row: Record<string, any>) => String(row?.slug ?? row?.['@self']?.slug ?? ''))
}

/**
 * Whether the buildiq page offers the organisation scope: OpenRegister's
 * multitenancy is on. Read from the initial state the page itself reads.
 *
 * @param page A logged-in page on a buildiq route.
 * @return True when the scope is available.
 */
async function scopeAvailable(page: Page): Promise<boolean> {
	return page.evaluate(() => {
		const el = document.getElementById('initial-state-buildiq-organisationScopeAvailable') as HTMLInputElement | null
		if (!el) {
			return false
		}
		try {
			return JSON.parse(atob(el.value)) === true
		} catch {
			return false
		}
	})
}

/**
 * A logged-in context for one of the RBAC fixture users.
 *
 * @param browser      The browser.
 * @param storageState The user's storage state from the global setup.
 * @return The context; the caller closes it.
 */
async function userContext(browser: Browser, storageState: string): Promise<BrowserContext> {
	return browser.newContext({ baseURL: BASE_URL, storageState })
}

test.describe('operate-organisation-scoped-apps: an app kept to its organisation', () => {
	test.describe.configure({ mode: 'serial', timeout: 150_000 })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await suppressSupportDialog(page)
			await page.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			if (!(await scopeAvailable(page))) {
				state.skipReason = 'OpenRegister multitenancy is off on this instance, so a new app starts shared and the organisation scope is not offered.'
				return
			}
			const mine = await myOrganisations(page)
			if (mine === null) {
				state.skipReason = 'OpenRegister answers no organisations API here, so a second organisation cannot be created.'
				return
			}
			state.adminActiveBefore = mine.active

			state.north = await organisationNamed(page, ORG_NORTH)
			state.south = await organisationNamed(page, ORG_SOUTH)
			for (const [org, user] of [[state.north, MEMBER_USER], [state.south, OUTSIDER_USER]]) {
				const joined = await page.request.post(`${ORGANISATIONS}/${org}/join`, { headers: HEADERS, data: { userId: user } })
				expect(joined.ok(), `${user} joins an organisation: ${joined.status()} ${(await joined.text()).slice(0, 200)}`).toBeTruthy()
			}

			// The app is made while the maker works in the north organisation.
			await setActive(page, state.north)
			await ensureApp(page, APP_SLUG, APP_NAME)
			await grantAppRoles(page, APP_SLUG, { viewers: [`user:${MEMBER_USER}`, `user:${OUTSIDER_USER}`] })
			const stored = await storedApplication(page)
			state.appUuid = uuidOf(stored)
			expect(state.appUuid, 'the fixture app has a uuid').not.toBe('')

			// One page, so a member who may open the app sees it rather than
			// the "App not found" an app without pages also shows.
			const manifest = await page.request.get(`${API}/applications/${APP_SLUG}/manifest`, { headers: HEADERS })
			const current = manifest.ok() ? await manifest.json() : {}
			const put = await page.request.put(`${API}/applications/${APP_SLUG}/manifest`, {
				headers: HEADERS,
				data: {
					manifest: {
						...current,
						version: current.version ?? '1.0.0',
						menu: [{ label: 'Messages', route: 'messages' }],
						pages: [{
							id: 'messages',
							route: '/',
							type: 'index',
							title: 'Messages',
							config: { register: 'buildiq', schema: 'hello-message', columns: ['title'] },
						}],
					},
				},
			})
			expect(put.ok(), `store the app's page: ${put.status()} ${(await put.text()).slice(0, 200)}`).toBeTruthy()
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		if (state.north === '' && state.south === '') {
			return
		}
		const page = await browser.newPage()
		try {
			await page.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			if (state.appUuid !== '') {
				await page.request.delete(`${API}/applications/${state.appUuid}`, {
					headers: HEADERS,
					data: { deleteData: true },
				}).catch(() => undefined)
			}
			for (const [org, user] of [[state.north, MEMBER_USER], [state.south, OUTSIDER_USER]]) {
				if (org !== '') {
					await page.request.post(`${ORGANISATIONS}/${org}/leave`, { headers: HEADERS, data: { userId: user } }).catch(() => undefined)
				}
			}
			if (state.adminActiveBefore !== '') {
				await page.request.post(`${ORGANISATIONS}/${state.adminActiveBefore}/set-active`, { headers: HEADERS }).catch(() => undefined)
			}
		} finally {
			await page.close()
		}
	})

	// @e2e organisation-scoped-apps::a-maker-of-the-omgevingsdienst-creates-an-app
	test('an app made in an organisation is held by it and starts as "This organisation only"', async ({ page }) => {
		test.skip(state.skipReason !== '', state.skipReason)
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)

		const stored = await storedApplication(page)
		expect(stored?.['@self']?.organisation, 'OpenRegister holds the app in the maker\'s active organisation').toBe(state.north)
		expect(stored?.organisationScope, 'a new app starts scoped to its organisation').toBe('holder')

		await page.goto(`${BASE_URL}/apps/buildiq/applications/${state.appUuid}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)
		await page.waitForSelector('.ob-detail-header', { timeout: 30_000 })
		await page.locator('[data-testid="cn-action-app-settings-action"]').first().click()
		const scope = page.locator('[data-testid="organisation-scope"]')
		await expect(scope, 'the owner is offered the scope').toBeVisible({ timeout: 30_000 })
		await expect(scope, 'it is the "This organisation only" switch').toContainText('This organisation only')
		await expect(scope.locator('input[type="checkbox"]').first(), 'and it is on').toBeChecked()
	})

	// @e2e organisation-scoped-apps::a-link-to-another-organisations-app-finds-nothing
	test('a member of the organisation sees the app in the list and opens it', async ({ browser }) => {
		test.skip(state.skipReason !== '', state.skipReason)
		const context = await userContext(browser, MEMBER_STATE)
		const member = await context.newPage()
		try {
			await suppressSupportDialog(member)
			await suppressSetupWizard(member)
			await member.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			// The app list page reads OpenRegister with its organisation filter,
			// which follows the active organisation.
			await setActive(member, state.north)

			expect(await listedSlugs(member), 'the applications API lists the app for a member').toContain(APP_SLUG)

			await member.goto(`${BASE_URL}/apps/buildiq/applications`, { waitUntil: 'domcontentloaded' })
			await dismissOverlays(member)
			await expect(
				member.locator('.ob-app-card').filter({ hasText: APP_NAME }).first(),
				'the app list shows the app to a member',
			).toBeVisible({ timeout: 45_000 })

			const manifest = member.waitForResponse((resp) => resp.url().includes(`/api/applications/${APP_SLUG}/manifest`))
			await member.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}`, { waitUntil: 'domcontentloaded' })
			expect((await manifest).status(), 'the runtime gets the manifest').toBe(200)
			await dismissOverlays(member)
			await expect(member.getByText('Messages').first(), 'the app opens on its page').toBeVisible({ timeout: 45_000 })
			await expect(member.getByText('App not found'), 'a member never sees the not-found page').toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	// @e2e organisation-scoped-apps::a-link-to-another-organisations-app-finds-nothing
	test('a member of another organisation gets the not-found page on its link and does not see it listed', async ({ browser }) => {
		test.skip(state.skipReason !== '', state.skipReason)
		const context = await userContext(browser, OUTSIDER_STATE)
		const outsider = await context.newPage()
		try {
			await suppressSupportDialog(outsider)
			await suppressSetupWizard(outsider)
			await outsider.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			await setActive(outsider, state.south)

			const direct = await outsider.request.get(`${API}/applications/${APP_SLUG}/manifest`, { headers: HEADERS })
			expect(direct.status(), 'the manifest endpoint answers as for an app that does not exist').toBe(404)
			expect(await listedSlugs(outsider), 'the applications API does not list it').not.toContain(APP_SLUG)

			await outsider.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}`, { waitUntil: 'domcontentloaded' })
			await dismissOverlays(outsider)
			await expect(outsider.getByText('App not found').first(), 'the link shows the not-found page').toBeVisible({ timeout: 45_000 })
			await expect(outsider.getByText('Messages'), 'nothing of the app is shown').toHaveCount(0)

			// The list page reads the Application objects from OpenRegister; wait
			// for that answer before saying the app is not among them.
			const listed = outsider.waitForResponse((resp) => resp.url().includes('/objects/buildiq/built-app'), { timeout: 45_000 })
			await outsider.goto(`${BASE_URL}/apps/buildiq/applications`, { waitUntil: 'domcontentloaded' })
			expect((await listed).ok(), 'the app list loaded').toBeTruthy()
			await dismissOverlays(outsider)
			await expect(
				outsider.locator('.ob-app-card').filter({ hasText: APP_NAME }),
				'the app list does not show it',
			).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
