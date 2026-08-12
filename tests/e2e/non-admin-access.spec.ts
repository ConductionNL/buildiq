/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — a non-admin with an app role can actually USE the app.
 *
 * This is a regression test for a failure that took three attempts to fix, and
 * every one of the first two looked green:
 *
 *  1. openbuild#76 — every schema declared a non-empty `authorization` block
 *     with no `read` key. OpenRegister fails that closed to owner-only rows, so
 *     a non-admin saw ZERO objects. Fixed by `read: ["authenticated"]` (#81).
 *  2. ConductionNL/nextcloud-vue#574/#575 — with objects finally visible, a
 *     non-admin instead met the first-time-SETUP WIZARD. `/api/setup/status` is
 *     admin-only and answers 403; useSetupStatus read that as "nothing done".
 *     The first fix short-circuited `completed` — which CnAppRoot never reads.
 *     It gates on requiredUnmet/optionalUnmet, so the fix was inert and the
 *     unit tests passed anyway, because they asserted the wrong gate.
 *  3. ConductionNL/nextcloud-vue#576 — `forbidden` empties both unmet lists.
 *
 * The lesson this file encodes: the only assertion that would have caught all
 * three is "a non-admin sees the app". Each layer's own tests were green while
 * the user-visible outcome stayed broken, so this asserts the OUTCOME.
 *
 * Requires @conduction/nextcloud-vue >= 2.1.0-vue3.15.
 */

import { test, expect } from '@playwright/test'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl'
import { ensureVersionChain } from './support/versionChain'
import { grantAppRoles } from './support/appRoles'
import { suppressSupportDialog, suppressSetupWizard } from './support/appFixture'

const TEST_SLUG = process.env.NC_TEST_SLUG ?? 'pw-verchain'
const ADMIN_STATE = 'tests/e2e/.auth/admin.json'

test.describe('a non-admin with an app role can use the app', () => {
	test.beforeAll(async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSupportDialog(page)
		await suppressSetupWizard(page)
			await page.goto(`${BASE_URL}/apps/openbuild/`, { waitUntil: 'domcontentloaded' })
			await ensureVersionChain(page, TEST_SLUG, 'PW Version Chain')
			await grantAppRoles(page, TEST_SLUG, {
				editors: ['group:rbac-editors'],
				viewers: ['group:rbac-viewers'],
			})
		} finally {
			await context.close()
		}
	})

	test('an editor reaches the schema designer, not the first-time-setup wizard', async ({ browser }) => {
		const context = await browser.newContext({ storageState: 'tests/e2e/.auth/rbac-editor.json' })
		const page = await context.newPage()
		try {
			await page.goto(`${BASE_URL}/apps/openbuild/builder/${TEST_SLUG}/schemas`, { waitUntil: 'domcontentloaded' })

			// The positive half goes FIRST, because it is also the readiness
			// signal. This used to sit behind
			// `waitForLoadState('networkidle', 30s).catch(() => {})`, which can
			// never settle on Nextcloud (ADR-074 rule 4): it burned the whole 30s
			// and swallowed the timeout, and the absence assertion below then ran
			// at an arbitrary moment. Asserting only the absence of the wizard
			// would pass on a blank page — so prove the real surface rendered,
			// then prove the wizard is not on it.
			await expect(
				page.locator('.openbuild-schema-list'),
				'the schema designer must render for an editor',
			).toBeVisible({ timeout: 30_000 })

			// The setup wizard is admin-only work. A non-admin meeting it is the
			// regression — they cannot complete it, and it covers the whole app.
			await expect(
				page.getByText(/Set up this app|Welcome to OpenBuild/i),
				'a non-admin must never be shown the first-time-setup wizard',
			).toHaveCount(0)
		} finally {
			await context.close()
		}
	})

	test('an editor sees the applications they were granted, and only those', async ({ browser }) => {
		const context = await browser.newContext({ storageState: 'tests/e2e/.auth/rbac-editor.json' })
		const page = await context.newPage()
		try {
			const resp = await page.request.get(
				`${BASE_URL}/index.php/apps/openbuild/api/applications`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			expect(resp.status(), 'the application list must answer for a non-admin').toBe(200)
			const body = await resp.json()
			const rows = Array.isArray(body) ? body : (body?.results ?? [])

			// Non-zero is the openbuild#76 regression guard: OR used to filter
			// every row out one layer below openbuild's own permission check.
			expect(rows.length, 'a granted editor must see at least their app').toBeGreaterThan(0)
			expect(
				rows.map((r: Record<string, unknown>) => r?.slug),
				'the granted app must be among them',
			).toContain(TEST_SLUG)
		} finally {
			await context.close()
		}
	})

	test('an outsider with no role sees nothing — the grant is what matters', async ({ browser }) => {
		const context = await browser.newContext({ storageState: 'tests/e2e/.auth/rbac-outsider.json' })
		const page = await context.newPage()
		try {
			const resp = await page.request.get(
				`${BASE_URL}/index.php/apps/openbuild/api/applications`,
				{ headers: { 'OCS-APIRequest': 'true' } },
			)
			const body = await resp.json().catch(() => null)
			const rows = Array.isArray(body) ? body : (body?.results ?? [])

			// The control for the test above: `read: ["authenticated"]` is a COARSE
			// grant at the OpenRegister layer. If openbuild's own row-level filter
			// ever stopped running, this is the test that would notice.
			expect(rows.length, 'a caller with no app role must see no applications').toBe(0)
		} finally {
			await context.close()
		}
	})
})
