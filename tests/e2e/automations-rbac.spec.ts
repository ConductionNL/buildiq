/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change `automation-designer`,
 * task 7.7 — REQ-AUTD-008 (pattern of `rbac-403.spec.ts`): an editor may
 * author + enable an automation on a draft (non-production) version; the
 * same editor gets 403 enabling on the production version; an owner
 * succeeds where the editor was rejected.
 *
 * Pre-conditions: `tests/e2e/global-setup.ts` provisions the `rbac-editor` and
 * `rbac-owner` users. Everything else this file needs — the two-version fixture
 * application, the role grants on it, and the one disabled automation on its
 * production version — is built by the `beforeAll` below, on the instance the
 * run is actually pointed at.
 *
 * It did NOT used to be. The roles were described as already held "on the
 * seeded `hello-world` Application", which carries a single production version
 * and so cannot express this requirement at all, while the app the tests
 * actually select existed only on one developer's container. Both tests
 * therefore had preconditions that no run could satisfy.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl'
import { ensureApp } from './support/appFixture'
import { grantAppRoles } from './support/appRoles'
import { dismissFirstVisitOverlays, collectFailedResponses } from './support/overlays'
const EDITOR_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const EDITOR_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'
const OWNER_USER = process.env.NC_RBAC_OWNER_USER ?? 'rbac-owner'
const OWNER_PASS = process.env.NC_RBAC_OWNER_PASS ?? 'RbacOwner-1!'
// Admin credentials for the capability probe below ONLY. Same resolution the
// config uses for `use.httpCredentials`; the tests themselves deliberately run
// as non-admins.
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS = process.env.NC_ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'
// The seeded `hello-world` fixture only ever carries a single `production`
// version (see lib/Command/SeedHelloWorldFixture.php) — there is no draft
// version to author on, so REQ-AUTD-008's "editor authors on draft, gets 403
// on production" scenario cannot run against it. `rbac-automations-app` is a
// dedicated fixture carrying BOTH a `development` and a `production`
// ApplicationVersion, with `rbac-editor` scoped to `editors` and `rbac-owner`
// to `owners` in its `permissions` block — exactly REQ-AUTD-008's documented
// precondition. Override via NC_RBAC_TEST_SLUG if recreated elsewhere.
//
// ⚠️ THIS FIXTURE USED TO EXIST ONLY ON ONE DEVELOPER'S INSTANCE.
//
// The comment that stood here said it was "created via the wizard's `dev-prod`
// preset during this session's live-verification" — i.e. by hand, once, on the
// dev container, and never anywhere else. Nothing in `tests/e2e/ci-seed.sh` or
// `global-setup.ts` creates it, so on a CI runner the app-picker had no such
// option and the second test died waiting 30s for
// `getByRole('option', { name: /rbac.?automations.?app/i })` — a fixture
// failure that reads exactly like an RBAC defect.
//
// `beforeAll` below now MAKES the precondition instead of assuming it, through
// the same two shared helpers every other fixture-owning spec uses. A test
// whose precondition is a manual step someone once performed is not repeatable,
// and "it passes locally" is the shape that produces.
const APP_SLUG = process.env.NC_RBAC_TEST_SLUG ?? 'rbac-automations-app'
// The app-picker option's accessible name is the Application TITLE, which
// for a wizard-created app is its `name` field, not necessarily its slug
// with hyphens-as-spaces — but the two are the same shape here regardless
// (e.g. "rbac-automations-app" → title "RBAC Automations App"). Build a
// generic loose match so this survives either shape.
const APP_TITLE_PATTERN = new RegExp(APP_SLUG.replace(/-/g, '.?'), 'i')

/**
 * The one automation seeded on the fixture app's PRODUCTION version.
 *
 * Named rather than reached with `.first()`: `.first()` asserts on the
 * container ("some row exists") and would happily drive whatever row the list
 * happened to order first, so the 403/200 pair could be measured against an
 * automation this spec never set up.
 */
const PROD_AUTOMATION_NAME = 'RBAC production automation'

/**
 * Is openbuild's `automation` schema readable and shaped as this suite expects?
 *
 * THIS PROBE USED TO REPORT `false` HERE AND `true` EVERYWHERE ELSE, IN THE
 * SAME RUN, AGAINST THE SAME INSTANCE.
 *
 * It is a copy of `automations.spec.ts`'s helper, and it carried that file's
 * reason with it: "the openbuild `automation` schema slug collides with a
 * pre-existing schema of the same slug on this shared instance — automation
 * CREATE/SAVE 400s regardless of app/version". Run 31083894467 disproves that
 * for CI outright. Seven tests in `automations.spec.ts` sit behind the very
 * same guard and PASSED, composing and saving real automations end to end
 * (REQ-AUTD-002 ×3, -003, -005, -006, -007). Both tests in THIS file skipped.
 *
 * The discriminator is not the instance, it is the auth context. This describe
 * declares `test.use({ storageState: { cookies: [], origins: [] } })` so each
 * test can log in as a non-admin — which also makes the `request` fixture
 * anonymous. The probe's read of `api/schemas/automation` was therefore
 * refused, `resp.ok()` was false, and the helper reported the refusal as a
 * fact about the schema. A guard that returns "the feature is broken" when it
 * means "I could not look" produces exactly this: a permanent skip with a
 * confident, wrong explanation attached.
 *
 * Two changes. The probe authenticates with the admin credentials the config
 * already uses for `httpCredentials`, independently of whatever storageState
 * the test is running under. And a NON-OK response is now a thrown error
 * rather than a `false`, so "cannot read the schema" fails the run loudly
 * instead of silently becoming "the schema is unusable" — the failure mode
 * this helper just spent a release exhibiting.
 *
 * @param request Playwright APIRequestContext (fixture-provided).
 * @return {Promise<boolean>} True when the schema reads back with the expected shape.
 */
async function automationSchemaIsUsable(request: APIRequestContext): Promise<boolean> {
	const auth = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	const resp = await request.get(`${NEXTCLOUD_URL}/index.php/apps/openregister/api/schemas/automation`, {
		headers: { 'OCS-APIRequest': 'true', Authorization: `Basic ${auth}` },
	})
	if (resp.ok() === false) {
		throw new Error(
			`automationSchemaIsUsable: could not read api/schemas/automation — HTTP ${resp.status()}. `
			+ 'This is a broken probe, not a verdict about the schema; it must not be reported as one.',
		)
	}
	const schema = await resp.json()
	return schema?.properties?.trigger?.type === 'object'
}

/**
 * Log a fresh browser context into Nextcloud as the supplied user (mirrors
 * rbac-403.spec.ts's `loginAs` — we need a non-admin session so the
 * PermissionResolver check actually runs, not an admin-bypass shortcut).
 *
 * @param page Playwright page object.
 * @param user The username to log in.
 * @param pass The user's password.
 */
async function loginAs(page: Page, user: string, pass: string): Promise<void> {
	await page.goto(`${NEXTCLOUD_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} appears to have failed — still on ${page.url()}.`)
	}
}

/**
 * Idempotently put ONE disabled automation on a version of the fixture app.
 *
 * The second scenario is "the editor is refused when ENABLING on production" —
 * so something has to be there to enable, and it has to start DISABLED or the
 * toggle would be a disable and never reach the guard under test. A freshly
 * created app has no automations at all, which is why this cannot be left to
 * the app-creation helper.
 *
 * Created through OpenRegister's object API, the same surface
 * `AutomationEditDialog.saveAutomation()` posts to — not a bespoke test
 * endpoint — so the fixture is the same shape the product writes.
 *
 * @param adminPage   Playwright page authenticated as the admin/owner.
 * @param slug        The fixture application slug.
 * @param versionSlug The version to attach the automation to.
 * @param name        The automation's name.
 *
 * @return {Promise<void>}
 */
async function ensureDisabledAutomation(adminPage: Page, slug: string, versionSlug: string, name: string): Promise<void> {
	const result = await adminPage.evaluate(async ({ slug, versionSlug, name }) => {
		const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
			|| document.querySelector('head')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: tok, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

		const verResp = await fetch(`/index.php/apps/openbuild/api/applications/${slug}/versions`, { headers })
		if (!verResp.ok) {
			return `versions read failed: ${verResp.status}`
		}
		const verBody = await verResp.json().catch(() => null)
		const versions = Array.isArray(verBody) ? verBody : (verBody?.results ?? verBody?.versions ?? [])
		const version = versions.find((v: Record<string, unknown>) => v?.slug === versionSlug)
		if (!version) {
			return `version ${versionSlug} not found among ${JSON.stringify(versions.map((v: Record<string, unknown>) => v?.slug))}`
		}
		const versionUuid = version['@self']?.id ?? version.uuid ?? version.id

		const api = '/index.php/apps/openregister/api/objects/openbuild/automation'
		const listed = await (await fetch(`${api}?_limit=200`, { headers })).json().catch(() => null)
		const rows = Array.isArray(listed) ? listed : (listed?.results ?? [])
		if (rows.some((r: Record<string, unknown>) => r?.name === name)) {
			return 'exists'
		}

		const resp = await fetch(api, {
			method: 'POST',
			headers,
			body: JSON.stringify({
				// `slug` IS REQUIRED. The Automation schema declares
				// `required: ['slug', 'name', 'applicationSlug', 'versionUuid', 'trigger']`
				// (lib/Settings/register.d/40-automations.json), and omitting it is a
				// 400: "The required property (slug) is missing."
				slug: 'rbac-production-automation',
				name,
				description: 'e2e fixture automation for REQ-AUTD-008',
				applicationSlug: slug,
				versionUuid,
				// Starts DISABLED on purpose — the scenario under test is the
				// ENABLE guard.
				enabled: false,
				trigger: { type: 'event', event: 'object.created', schema: 'hello-message' },
				condition: {},
				actions: [{ type: 'notification', subject: { en: 'fixture' } }],
			}),
		})
		return resp.ok ? 'created' : `create failed: ${resp.status} ${(await resp.text()).slice(0, 200)}`
	}, { slug, versionSlug, name })

	if (result !== 'exists' && result !== 'created') {
		throw new Error(`ensureDisabledAutomation(${slug}/${versionSlug}) — ${result}`)
	}
}

/**
 * Open the automations page as an already-logged-in NON-admin and select the
 * fixture application + the named version.
 *
 * ⚠️ `dismissFirstVisitOverlays()` IS LOAD-BEARING, NOT TIDYING.
 *
 * `tests/e2e/ci-seed.sh` marks the first-visit overlays (`CnWalkthrough` +
 * `CnSupportDialog`) as seen for the ADMIN ONLY, and says why: pre-marking the
 * rbac-* users would make `non-admin-access.spec.ts`'s assertion — that a
 * non-admin is never blocked by a first-run overlay they cannot complete —
 * pass without the product doing anything. So every rbac-* session here IS a
 * first visit and DOES get the overlays, and each renders a full-viewport
 * backdrop that swallows pointer events.
 *
 * That is what actually failed: the first test's click on the application
 * combobox retried for the full 30s test budget against
 * `<div … data-testid-modal="cn-support-dialog" class="… modal-mask"> subtree
 * intercepts pointer events`. The locator resolved, the element was "visible,
 * enabled and stable", and the click still could not land — a precondition
 * failure wearing an RBAC failure's clothes.
 *
 * @param page        Playwright page, already logged in as a non-admin.
 * @param versionName The version option to pick, e.g. /development/ or /production/.
 *
 * @return {Promise<void>}
 */
async function openAutomationsFor(page: Page, versionName: RegExp): Promise<void> {
	// Attribution, not a gate — see collectFailedResponses(). These two tests
	// run as users whose sessions are built from scratch, so a refused request
	// (a role grant that did not land, a version endpoint that 404s) shows up
	// downstream as an option that never appears. Naming the refusals in the
	// assertion message is the difference between "the option was not there"
	// and "the option was not there, and GET .../versions returned 403".
	const refusals = collectFailedResponses(page)

	await page.goto(`${NEXTCLOUD_URL}/apps/openbuild/automations`)
	await page.waitForSelector('.automations-page', { timeout: 20_000 })
	await dismissFirstVisitOverlays(page)

	await page.getByRole('combobox', { name: /application/i }).click()
	// Assert the fixture option is THERE before clicking it. Without this the
	// failure is a bare 30s click timeout that names no cause; with it, a
	// missing fixture says so in the assertion message.
	const appOption = page.getByRole('option', { name: APP_TITLE_PATTERN }).first()
	await expect(
		appOption,
		`the ${APP_SLUG} fixture application must be listed for this user; refused requests so far: ${JSON.stringify(refusals())}`,
	).toBeVisible({ timeout: 15_000 })
	await appOption.click()

	await page.getByRole('combobox', { name: /version/i }).click()
	const versionOption = page.getByRole('option', { name: versionName }).first()
	await expect(
		versionOption,
		`the ${versionName} version of ${APP_SLUG} must be listed; refused requests so far: ${JSON.stringify(refusals())}`,
	).toBeVisible({ timeout: 15_000 })
	await versionOption.click()
}

test.describe('automation-designer — RBAC (REQ-AUTD-008)', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	// Build the fixture ONCE, as the admin, before any non-admin test runs.
	//
	// This describe runs session-less (`storageState: { cookies: [], origins: [] }`)
	// so that each test can log in as a real non-admin and the PermissionResolver
	// actually runs instead of being short-circuited by the admin bypass. That
	// makes the `page`/`request` fixtures anonymous, so the SETUP — which has to
	// be done by an owner — needs its own explicitly-authenticated context.
	//
	// `storageState` is named explicitly rather than left to default: a context
	// created inside a test inherits the config's root `use.storageState`, which
	// is exactly how an "anonymous visitor" test elsewhere turned out to be a
	// logged-in admin and stayed green. Here we WANT the admin, so we say so.
	test.beforeAll(async ({ browser }) => {
		const adminContext = await browser.newContext({ storageState: 'tests/e2e/.auth/admin.json' })
		const adminPage = await adminContext.newPage()
		try {
			// BOTH versions — REQ-AUTD-008 is "editor may enable on a draft,
			// is refused on production", which a one-version app cannot express.
			await ensureApp(adminPage, APP_SLUG, 'RBAC Automations App', ['development', 'production'])
			// rbac-owner is granted OWNER deliberately: the scenario's second half
			// is "an owner succeeds where the editor was rejected", and if that
			// owner were the admin its success would come from the admin bypass
			// rather than from ownership — proving nothing about the grant.
			await grantAppRoles(adminPage, APP_SLUG, {
				owners: [`user:${OWNER_USER}`],
				editors: [`user:${EDITOR_USER}`],
			})
			// The production-version scenario needs something to enable.
			await ensureDisabledAutomation(adminPage, APP_SLUG, 'production', PROD_AUTOMATION_NAME)
		} finally {
			await adminContext.close()
		}
	})

	test('editor authors + enables an automation on a non-production (draft) version', async ({ page, request }) => {
		test.skip(await automationSchemaIsUsable(request) === false, 'openbuild `automation` schema does not read back with a `trigger` object property — see automationSchemaIsUsable() for why this must be a real verdict and not a failed lookup')
		await loginAs(page, EDITOR_USER, EDITOR_PASS)
		await openAutomationsFor(page, /development/i)

		await page.getByRole('button', { name: /new automation/i }).click()
		await page.waitForSelector('.automation-edit')
		await page.waitForTimeout(1_500)
		await page.getByRole('textbox', { name: /^name$/i }).fill('RBAC editor draft automation')
		await page.getByRole('button', { name: /add action/i }).click()
		await page.getByRole('textbox', { name: /subject \(english\)/i }).fill('x')
		await page.getByRole('button', { name: /^save$/i }).click()
		await expect(page.locator('.automation-edit')).toHaveCount(0, { timeout: 10_000 })

		const row = page.locator('[data-testid="automation-row"]', { hasText: 'RBAC editor draft automation' })
		await expect(row).toBeVisible()
		const toggle = row.locator('.ncswitch-stub, [class*="checkbox-radio-switch"]').first()
		await toggle.click()
		// No error note card on a non-production enable.
		await expect(page.locator('.ncnotecard-stub, [class*="note-card"][class*="error"]')).toHaveCount(0)
	})

	test('editor gets 403 enabling on the production version; owner succeeds', async ({ page, browser, request }) => {
		test.skip(await automationSchemaIsUsable(request) === false, 'openbuild `automation` schema does not read back with a `trigger` object property — see automationSchemaIsUsable() for why this must be a real verdict and not a failed lookup')
		await loginAs(page, EDITOR_USER, EDITOR_PASS)
		await openAutomationsFor(page, /production/i)

		// The row seeded by `beforeAll`, BY NAME. `.first()` would have asserted
		// on the container — "some automation exists" — and driven whichever row
		// the list happened to order first, so the 403 could have been measured
		// against an automation this spec never set up.
		const row = page.locator('[data-testid="automation-row"]', { hasText: PROD_AUTOMATION_NAME })
		await expect(row).toBeVisible({ timeout: 10_000 })
		const toggle = row.locator('.ncswitch-stub, [class*="checkbox-radio-switch"]').first()

		const responsePromise = page.waitForResponse((resp) => resp.url().includes('/api/automations/') && resp.url().includes('/enable'))
		await toggle.click()
		const response = await responsePromise
		expect(response.status()).toBe(403)

		// Same automation, owner session: succeeds.
		//
		// `storageState: undefined` is stated EXPLICITLY. A context created
		// inside a test inherits the config's root `use.storageState` — which is
		// the shared ADMIN session — so a bare `browser.newContext()` here would
		// hand `loginAs()` a page that is already the admin. The whole point of
		// this half is that a non-admin OWNER succeeds; letting the admin bypass
		// answer for it would make the test pass while measuring nothing.
		const ownerContext = await browser.newContext({ storageState: undefined })
		const ownerPage = await ownerContext.newPage()
		await loginAs(ownerPage, OWNER_USER, OWNER_PASS)
		await openAutomationsFor(ownerPage, /production/i)

		// The SAME automation, by name — "an owner succeeds where the editor was
		// rejected" is only true if both halves acted on one object.
		const ownerRow = ownerPage.locator('[data-testid="automation-row"]', { hasText: PROD_AUTOMATION_NAME })
		await expect(ownerRow).toBeVisible({ timeout: 10_000 })
		const ownerToggle = ownerRow.locator('.ncswitch-stub, [class*="checkbox-radio-switch"]').first()
		const ownerResponsePromise = ownerPage.waitForResponse((resp) => resp.url().includes('/api/automations/') && resp.url().includes('/enable'))
		await ownerToggle.click()
		const ownerResponse = await ownerResponsePromise
		expect(ownerResponse.status()).toBe(200)

		await ownerContext.close()
	})
})
