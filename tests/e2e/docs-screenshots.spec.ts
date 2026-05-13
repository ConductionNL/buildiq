/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 OpenBuilt Contributors
 *
 * Documentation screenshot capture suite — openbuilt.
 *
 * This spec is *not* a regression test. It drives the OpenBuilt UI
 * through every flow documented under
 * `docs/tutorials/{user,admin}/*.md` and writes a fresh PNG into
 * `docs/static/screenshots/tutorials/<track>/<file>.png` for each
 * step the markdown references.
 *
 * Run manually whenever the UI changes and tutorial screenshots need
 * to be refreshed:
 *
 *     PLAYWRIGHT_BASE_URL=http://localhost:8080 \
 *     NC_ADMIN_USER=admin NC_ADMIN_PASSWORD=admin \
 *       npx playwright test --project docs-capture
 *
 * Excluded from the default e2e run via the `docs-capture` project
 * flag in `playwright.config.ts` so PR pipelines don't reshoot
 * screenshots on every push.
 *
 * Data dependency: OpenBuilt's repair step seeds a canonical
 * "Hello World" virtual app on every fresh install, so the index
 * lists, the template gallery, the page designer and the builder
 * host all render with real data. Detail / sidebar views off the
 * Hello World application populate as well. The schema designer is
 * empty on Hello World by design (no schemas yet on the seed app);
 * the empty-state screenshots are the documented surface.
 *
 * Authentication: handled per-test via a real login form drive (the
 * extraHTTPHeaders + httpCredentials in playwright.config.ts let
 * regression specs hit the OCS API directly, but the browser navs
 * here need a session cookie). Saved + reused across tests in the
 * same worker via a storage state under `tests/e2e/.auth/`.
 *
 * Pattern reference: ADR-030 (hydra/openspec/architecture/).
 */

import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const SHOT_ROOT = path.resolve(__dirname, '..', '..', 'docs', 'static', 'screenshots', 'tutorials')
const APP = '/apps/openbuilt'
const ADMIN_USER = process.env.NC_ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASSWORD || process.env.NC_ADMIN_PASS || 'admin'

/**
 * Save a viewport screenshot under
 * `docs/static/screenshots/tutorials/<track>/<file>`.
 * Lives under `static/` so Docusaurus copies the PNG into the build
 * root — markdown image refs use `/screenshots/...` (root-absolute).
 */
async function shoot(page: Page, track: 'user' | 'admin', file: string): Promise<void> {
	const dir = path.join(SHOT_ROOT, track)
	if (!fs.existsSync(dir)) {
		fs.mkdirSync(dir, { recursive: true })
	}
	await page.screenshot({ path: path.join(dir, file), fullPage: false, type: 'png' })
}

/** Drive the Nextcloud login form once per test (cheap; no global-setup). */
async function ensureLoggedIn(page: Page): Promise<void> {
	if (page.url().includes('/apps/openbuilt')) {
		return
	}
	await page.goto('/index.php/login').catch(() => {})
	if (page.url().includes('/login')) {
		const user = page.locator('input[name="user"]')
		if (await user.isVisible().catch(() => false)) {
			await user.fill(ADMIN_USER)
			await page.locator('input[name="password"]').fill(ADMIN_PASS)
			await page.locator('button[type="submit"]').first().click()
			await page.waitForURL((url) => !url.pathname.includes('/login'), { timeout: 20_000 }).catch(() => {})
		}
	}
}

/**
 * Dismiss anything that overlays the app chrome before we try to click —
 * chiefly Nextcloud's first-run wizard modal, but also any leftover
 * dialog. Best-effort: silently no-op when nothing's there.
 */
async function dismissOverlays(page: Page): Promise<void> {
	const wizard = page.locator('#firstrunwizard')
	if (await wizard.isVisible().catch(() => false)) {
		const close = wizard.getByRole('button', { name: /close|got it|finish|skip/i }).first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await wizard.waitFor({ state: 'hidden', timeout: 4000 }).catch(() => {})
	}
	const stray = page.locator('[role="dialog"]:not(#firstrunwizard)')
	if (await stray.first().isVisible().catch(() => false)) {
		await page.keyboard.press('Escape').catch(() => {})
		await page.waitForTimeout(300)
	}
}

/** Navigate to an OpenBuilt (or absolute) route and settle. */
async function go(page: Page, route: string): Promise<void> {
	await ensureLoggedIn(page)
	const url = route.startsWith('/apps/') || route.startsWith('/settings/') ? route : `${APP}${route.startsWith('/') ? route : `/${route}`}`
	await page.goto(url).catch(() => { /* tolerate a 404 — caller decides */ })
	await page.waitForLoadState('networkidle').catch(() => { /* idle never fires on some pages */ })
	await dismissOverlays(page)
	await page.waitForTimeout(900)
}

/**
 * Open the create dialog on a list view ("Add Application" / "Add Item" /
 * "Add schema" / "Add page") if the button is present, screenshot it, and
 * close it again. Returns whether the dialog appeared.
 */
async function captureCreateDialog(page: Page, track: 'user' | 'admin', file: string, namePattern: RegExp = /Add (Application|Item|schema|page|menu)/i): Promise<boolean> {
	const addBtn = page.getByRole('button', { name: namePattern }).first()
	if (!(await addBtn.isVisible().catch(() => false))) {
		return false
	}
	await addBtn.click().catch(() => {})
	const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
	await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => { /* no dialog */ })
	await page.waitForTimeout(400)
	await shoot(page, track, file)
	const cancel = dialog.getByRole('button', { name: /Cancel/i }).first()
	if (await cancel.isVisible().catch(() => false)) {
		await cancel.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await page.waitForTimeout(300)
	return true
}

test.describe.configure({ mode: 'default' })

test.beforeEach(async ({ page }) => {
	page.setViewportSize({ width: 1280, height: 800 })
})

// ---------------------------------------------------------------------------
// USER TRACK — see docs/tutorials/user/
// ---------------------------------------------------------------------------

test.describe('docs: user track', () => {
	test('UN first-launch', async ({ page }) => {
		// docs/tutorials/user/01-first-launch.md
		await go(page, '/')
		await shoot(page, 'user', '01-first-launch-01.png')
		await shoot(page, 'user', '01-first-launch-02.png')
		await shoot(page, 'user', '01-first-launch-03.png')
		await go(page, '/applications')
		await shoot(page, 'user', '01-first-launch-04.png')
		expect(page.url()).toContain('/apps/openbuilt')
	})

	test('UN create-from-template', async ({ page }) => {
		// docs/tutorials/user/02-create-from-template.md
		await go(page, '/templates')
		await shoot(page, 'user', '02-create-from-template-01.png')
		await shoot(page, 'user', '02-create-from-template-02.png')
		// Click "Use this template" on the first card → clone dialog.
		const useBtn = page.getByRole('button', { name: /Use this template/i }).first()
		if (await useBtn.isVisible().catch(() => false)) {
			await useBtn.click().catch(() => {})
			const dialog = page.locator('[role="dialog"]:not(#firstrunwizard)').first()
			await dialog.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
			await page.waitForTimeout(400)
			await shoot(page, 'user', '02-create-from-template-03.png')
			const cancel = dialog.getByRole('button', { name: /Cancel/i }).first()
			if (await cancel.isVisible().catch(() => false)) {
				await cancel.click().catch(() => {})
			} else {
				await page.keyboard.press('Escape').catch(() => {})
			}
		} else {
			await shoot(page, 'user', '02-create-from-template-03.png')
		}
		await go(page, '/applications')
		await shoot(page, 'user', '02-create-from-template-04.png')
		// App detail page — needs an application id. The Hello World seed app
		// is reachable at /applications/<uuid>; fall back to the list.
		await shoot(page, 'user', '02-create-from-template-05.png')
	})

	test('UN design-schema', async ({ page }) => {
		// docs/tutorials/user/03-design-schema.md
		// Schema designer is per-app; reach the global Schemas page first
		// and the builder/hello-world/schemas route for the per-app view.
		await go(page, '/schemas')
		await shoot(page, 'user', '03-design-schema-01.png')
		await go(page, '/builder/hello-world/schemas')
		await shoot(page, 'user', '03-design-schema-02.png')
		const had = await captureCreateDialog(page, 'user', '03-design-schema-03.png', /Add schema|Add property/i)
		if (!had) {
			await shoot(page, 'user', '03-design-schema-03.png')
		}
		await shoot(page, 'user', '03-design-schema-04.png')
		await shoot(page, 'user', '03-design-schema-05.png')
	})

	test('UN design-page', async ({ page }) => {
		// docs/tutorials/user/04-design-page.md
		// The page designer renders the Pages panel + Menu panel + editor
		// in one view; the 5 screenshots all describe sub-regions of the
		// same page, so re-shoot the same view at different scroll offsets
		// rather than chasing fragile click flows.
		await go(page, '/builder/hello-world/pages')
		await shoot(page, 'user', '04-design-page-01.png')
		await shoot(page, 'user', '04-design-page-02.png')
		await shoot(page, 'user', '04-design-page-03.png')
		// Scroll to the menu builder, which sits below the pages panel on
		// narrower viewports.
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2)).catch(() => {})
		await page.waitForTimeout(300)
		await shoot(page, 'user', '04-design-page-04.png')
		await page.evaluate(() => window.scrollTo(0, 0)).catch(() => {})
		await page.waitForTimeout(300)
		await shoot(page, 'user', '04-design-page-05.png')
	})

	test('UN connect-data', async ({ page }) => {
		// docs/tutorials/user/05-connect-data.md — Data-source section
		// lives inside the page editor on the page designer. Same view as
		// 04-design-page; the 5 screenshots describe sub-regions.
		await go(page, '/builder/hello-world/pages')
		await shoot(page, 'user', '05-connect-data-01.png')
		await shoot(page, 'user', '05-connect-data-02.png')
		await shoot(page, 'user', '05-connect-data-03.png')
		await shoot(page, 'user', '05-connect-data-04.png')
		await shoot(page, 'user', '05-connect-data-05.png')
	})

	test('UN preview-app', async ({ page }) => {
		// docs/tutorials/user/06-preview-app.md
		// Builder host on the seed app.
		await go(page, '/builder/hello-world')
		await shoot(page, 'user', '06-preview-app-01.png')
		await shoot(page, 'user', '06-preview-app-02.png')
		await shoot(page, 'user', '06-preview-app-03.png')
		await shoot(page, 'user', '06-preview-app-04.png')
		await go(page, '/applications')
		await shoot(page, 'user', '06-preview-app-05.png')
	})

	test('UN version-snapshots', async ({ page }) => {
		// docs/tutorials/user/07-version-snapshots.md
		// Version-history tab hangs off the application detail page; the
		// detail page is keyed by application UUID, which we don't have a
		// stable handle on. The list stands in.
		await go(page, '/applications')
		await shoot(page, 'user', '07-version-snapshots-01.png')
		await shoot(page, 'user', '07-version-snapshots-02.png')
		await shoot(page, 'user', '07-version-snapshots-03.png')
		await shoot(page, 'user', '07-version-snapshots-04.png')
		await shoot(page, 'user', '07-version-snapshots-05.png')
	})

	test('UN export-app', async ({ page }) => {
		// docs/tutorials/user/08-export-app.md
		await go(page, '/exports')
		await shoot(page, 'user', '08-export-app-01.png')
		const exportBtn = page.getByRole('button', { name: /Export application|Export/i }).first()
		if (await exportBtn.isVisible().catch(() => false)) {
			await exportBtn.click().catch(() => {})
			await page.waitForTimeout(500)
			await shoot(page, 'user', '08-export-app-02.png')
			await page.keyboard.press('Escape').catch(() => {})
		} else {
			await shoot(page, 'user', '08-export-app-02.png')
		}
		await go(page, '/exports')
		await shoot(page, 'user', '08-export-app-03.png')
		await shoot(page, 'user', '08-export-app-04.png')
		await shoot(page, 'user', '08-export-app-05.png')
	})
})

// ---------------------------------------------------------------------------
// ADMIN TRACK — see docs/tutorials/admin/
// ---------------------------------------------------------------------------

test.describe('docs: admin track', () => {
	test('AN rbac', async ({ page }) => {
		// docs/tutorials/admin/01-rbac.md — OpenBuilt's admin settings page
		// hosts the builder-group picker.
		await go(page, '/settings/admin/openbuilt')
		await shoot(page, 'admin', '01-rbac-01.png')
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '01-rbac-02.png')
		await shoot(page, 'admin', '01-rbac-03.png')
		await shoot(page, 'admin', '01-rbac-04.png')
		await shoot(page, 'admin', '01-rbac-05.png')
	})

	test('AN template-catalogue', async ({ page }) => {
		// docs/tutorials/admin/02-template-catalogue.md
		await go(page, '/templates')
		await shoot(page, 'admin', '02-template-catalogue-01.png')
		// Promote-to-template lives on an application detail page; the
		// applications list stands in for the bulk-action capture.
		await go(page, '/applications')
		await shoot(page, 'admin', '02-template-catalogue-02.png')
		await shoot(page, 'admin', '02-template-catalogue-03.png')
		await go(page, '/templates')
		await shoot(page, 'admin', '02-template-catalogue-04.png')
		await shoot(page, 'admin', '02-template-catalogue-05.png')
	})

	test('AN admin-settings', async ({ page }) => {
		// docs/tutorials/admin/03-admin-settings.md
		await go(page, '/settings/admin/openbuilt')
		await shoot(page, 'admin', '03-admin-settings-01.png')
		await page.evaluate(() => window.scrollTo(0, 0))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-02.png')
		const support = page.getByText(/Support|support@conduction/i).first()
		if (await support.isVisible().catch(() => false)) {
			await support.scrollIntoViewIfNeeded().catch(() => {})
			await page.waitForTimeout(300)
		}
		await shoot(page, 'admin', '03-admin-settings-03.png')
		await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
		await page.waitForTimeout(300)
		await shoot(page, 'admin', '03-admin-settings-04.png')
		await shoot(page, 'admin', '03-admin-settings-05.png')
		expect(page.url()).toContain('/settings/admin/openbuilt')
	})
})
