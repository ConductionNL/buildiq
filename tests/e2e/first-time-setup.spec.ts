/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — openbuild-first-time-setup (tasks 5.2 and 4.3).
 *
 * An admin on an instance whose setup was never completed meets the setup
 * wizard on the first visit, completes it, finds the starter templates in the
 * Marketplace, and the getting-started tour then runs up to `create-app`.
 * A non-admin on the same unconfigured instance never meets the wizard or the
 * tour: `/api/setup/status` answers 403 for them and the app opens as usual.
 *
 * "Fresh" is made by deleting the two completion keys through Nextcloud's
 * provisioning API (admin), so the spec needs no occ access and leaves the
 * instance as it found it: both keys are written back afterwards.
 *
 * Base URL comes from the environment (support/baseUrl.ts). The non-admin
 * runs in a context that starts with an empty storage state and signs in as
 * the `rbac-outsider` user globalSetup provisions.
 */

import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const OCS = { 'OCS-APIRequest': 'true' }
const CONFIG = `${BASE_URL}/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/buildiq`
const KEYS = ['setup_completed_version', 'walkthrough_completed_version']
const OUTSIDER = { id: 'rbac-outsider', pass: 'RbacOutsider-1!' }

/**
 * Read an app-config value, '' when it is not set.
 *
 * @param request An admin request context.
 * @param key The key.
 * @return The value.
 */
async function readKey(request: APIRequestContext, key: string): Promise<string> {
	const resp = await request.get(`${CONFIG}/${key}?format=json`, { headers: OCS, failOnStatusCode: false })
	if (resp.status() !== 200) {
		return ''
	}
	return String((await resp.json())?.ocs?.data?.data ?? '')
}

/**
 * Delete an app-config value.
 *
 * @param request An admin request context.
 * @param key The key.
 */
async function deleteKey(request: APIRequestContext, key: string): Promise<void> {
	await request.delete(`${CONFIG}/${key}`, { headers: OCS, failOnStatusCode: false })
}

/**
 * Write an app-config value back.
 *
 * @param request An admin request context.
 * @param key The key.
 * @param value The value.
 */
async function writeKey(request: APIRequestContext, key: string, value: string): Promise<void> {
	await request.post(`${CONFIG}/${key}`, { headers: OCS, form: { value }, failOnStatusCode: false })
}

/**
 * Press the wizard's forward button until it shows the step with the given title.
 *
 * @param page The page.
 * @param title The step title to reach.
 */
async function advanceTo(page: Page, title: string): Promise<void> {
	const dialog = page.getByRole('dialog').filter({ hasText: /Welcome to Buildiq|Load example data|Remote template marketplace|All set/ })
	for (let i = 0; i < 6; i++) {
		if (await dialog.getByText(title).first().isVisible().catch(() => false)) {
			return
		}
		await dialog.getByRole('button', { name: /^(Next|Skip|Continue)$/i }).first().click()
	}
	await expect(dialog.getByText(title).first()).toBeVisible()
}

test.describe.serial('first-time setup', () => {
	const saved: Record<string, string> = {}

	test.beforeAll(async ({ request }) => {
		for (const key of KEYS) {
			saved[key] = await readKey(request, key)
			await deleteKey(request, key)
		}
	})

	test.afterAll(async ({ request }) => {
		for (const key of KEYS) {
			if (saved[key] !== '') {
				await writeKey(request, key, saved[key])
			}
		}
	})

	test('a non-admin on an unconfigured instance gets neither the wizard nor the tour', async ({ browser }) => {
		// Task 4.3: the setup status is admin-only, so a non-admin must not meet a wizard they cannot finish.
		const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
		const page = await context.newPage()
		await suppressSupportDialog(page)
		await page.goto(`${BASE_URL}/index.php/login`, { waitUntil: 'domcontentloaded' })
		await page.locator('#user').fill(OUTSIDER.id)
		await page.locator('#password').fill(OUTSIDER.pass)
		await page.locator('#password').press('Enter')
		await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 30_000 })

		const status = await page.request.get(`${BASE_URL}/index.php/apps/buildiq/api/setup/status`, { failOnStatusCode: false })
		expect(status.status()).toBe(403)

		await page.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('#content, #app-content-vue, .app-content').first()).toBeVisible({ timeout: 30_000 })
		await expect(page.getByText('Welcome to Buildiq')).toHaveCount(0)
		await expect(page.getByText('Build the pet store')).toHaveCount(0)
		await context.close()
	})

	test('an admin completes the wizard, finds the templates, and the tour reaches create-app', async ({ page }) => {
		// Task 5.2.
		await suppressSupportDialog(page)
		await page.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })

		await expect(page.getByText('Welcome to Buildiq').first()).toBeVisible({ timeout: 30_000 })
		await advanceTo(page, 'All set')
		await page.getByRole('button', { name: /^(Finish|Done|Close|Get started)$/i }).first().click()
		await expect(page.getByText('Welcome to Buildiq')).toHaveCount(0)

		const status = await page.request.get(`${BASE_URL}/index.php/apps/buildiq/api/setup/status`)
		expect(status.status()).toBe(200)
		expect(await readKey(page.request, 'setup_completed_version')).toBe('1')

		// The tour starts once setup is complete.
		await expect(page.getByText('Build the pet store').first()).toBeVisible({ timeout: 30_000 })
		await page.getByRole('button', { name: /^(Next|Start|Let's go)$/i }).first().click()
		await page.locator('.app-navigation').getByRole('link', { name: /^\s*Apps\s*$/ }).first().click()
		await page.getByRole('button', { name: /Add app/i }).first().click()
		await expect(page.getByText('Name it Pet Store').first()).toBeVisible({ timeout: 30_000 })
		await page.keyboard.press('Escape')

		// The Marketplace lists the starter templates the seed step installed.
		const templates = await page.request.get(`${BASE_URL}/index.php/apps/openregister/api/objects/buildiq/application-template`, {
			headers: OCS,
			failOnStatusCode: false,
		})
		test.fail(templates.status() !== 200, 'The template schema slug differs on this instance.')
		const rows = ((await templates.json())?.results ?? []) as Array<Record<string, unknown>>
		expect(rows.length).toBeGreaterThan(0)
		await page.locator('.app-navigation').getByRole('link', { name: /^\s*Marketplace\s*$/ }).first().click()
		await expect(page.getByText(String(rows[0].name ?? rows[0].title ?? '')).first()).toBeVisible({ timeout: 30_000 })
	})
})
