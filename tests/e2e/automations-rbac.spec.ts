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
 * PRE-CONDITIONS, AND WHY THEY ARE BUILT RATHER THAN ASSUMED
 * ----------------------------------------------------------
 * Only one thing is assumed: `tests/e2e/global-setup.ts` provisions the users
 * `rbac-editor` and `rbac-owner`. Everything else this file needs — an
 * Application with BOTH a draft and a production version, and a `permissions`
 * block granting those two users `editors` / `owners` — is created in
 * `beforeAll`.
 *
 * The previous revision assumed the Application too, describing it as "created
 * via the wizard's dev-prod preset during this session's live-verification".
 * That is a fixture that existed on exactly one laptop. On CI the app-picker
 * had no such option, both tests died on a locator timeout, and that timeout
 * was then reported as a fact about `GET /api/applications` — becoming
 * Conduction/buildiq#171 and the second comment on #173, both of which
 * claimed a granted editor cannot see their application. They can; see
 * tests/e2e/support/appRoles.ts for the measurement that retracts it.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl'
import { ensureApp, suppressSupportDialog } from './support/appFixture'
import { grantAppRoles } from './support/appRoles'
import { dismissWalkthrough } from './support/overlays'

/** Admin session minted by globalSetup; used only to BUILD the fixture. */
const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const EDITOR_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const EDITOR_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'
const OWNER_USER = process.env.NC_RBAC_OWNER_USER ?? 'rbac-owner'
const OWNER_PASS = process.env.NC_RBAC_OWNER_PASS ?? 'RbacOwner-1!'
// Admin credentials for the capability probe below ONLY. Same resolution the
// config uses for `use.httpCredentials`; the tests themselves deliberately run
// as non-admins.
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS =
	process.env.NC_ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'
// The seeded `hello-world` fixture only ever carries a single `production`
// version (see lib/Command/SeedHelloWorldFixture.php) — there is no draft
// version to author on, so REQ-AUTD-008's "editor authors on draft, gets 403
// on production" scenario cannot run against it. `rbac-automations-app` is a
// dedicated fixture carrying BOTH a `development` and a `production`
// ApplicationVersion, with `rbac-editor` in `editors` and `rbac-owner` in
// `owners` — exactly REQ-AUTD-008's precondition.
//
// It is BUILT BY THIS FILE's `beforeAll`, not assumed. It used to be assumed,
// and the assumption was only ever true on one laptop.
const APP_SLUG = process.env.NC_RBAC_TEST_SLUG ?? 'rbac-automations-app'
/** Display name the wizard stores; the app-picker option's accessible name. */
const APP_TITLE = 'RBAC Automations App'
/**
 * Pick an app-picker option by its FULL name — not by its accessible name.
 *
 * ⚠️ `getByRole('option', { name: /rbac.?automations.?app/i })` CANNOT match
 * this option, and the reason is not in this repo.
 *
 * `NcSelect` renders each option through `NcEllipsisedOption`, which implements
 * middle-ellipsis by SPLITTING the label into two `<span>`s whenever it is 10
 * characters or longer:
 *
 *     needsTruncate() { return this.name.length >= 10 }
 *     split()         { return len - Math.min(Math.floor(len / 2), 10) }
 *
 * The wrapper span carries `title="RBAC Automations App"`, so the label is
 * correct where a human reads it — but the `option` role computes its
 * accessible name from its CONTENTS, and the accessible-name algorithm inserts
 * a space at every element boundary. The option therefore announces, and
 * matches, as:
 *
 *     "RBAC Autom ations App"
 *
 * That is an upstream `@nextcloud/vue` accessibility defect (a screen reader
 * reads it the same mangled way), not a test-authoring problem, and it is not
 * fixable from here.
 *
 * It has already cost more than a test: this locator timing out was read as
 * "the editor cannot see the application", which became Conduction/buildiq#171
 * and the second comment on #173. A locator that finds nothing says nothing
 * about the API underneath it.
 *
 * Matching on `[title]` targets the component's own record of the full name, so
 * it is exact and stays exact. Loosening the regex to tolerate stray spaces
 * would paper over the defect AND risk matching a different option in a longer
 * list.
 *
 * The VERSION picker is hit by exactly the same defect, which is easy to miss
 * because the names are short: `"production"` is 10 characters, so it splits to
 * `"produ ction"`, and `"development"` (11) splits to `"develo pment"`. Use this
 * helper for every NcSelect option, not just the long ones.
 *
 * @param page  Playwright page.
 * @param title The option's full label (the value bound to NcSelect's `label` prop).
 * @return {import('@playwright/test').Locator} The matching option.
 */
function selectOption(page: Page, title: string) {
	return page
		.getByRole('option')
		.filter({ has: page.locator(`[title="${title}"]`) })
}

/** Name of the automation the production-scoped test toggles. */
const PROD_AUTOMATION_NAME = 'RBAC production enable probe'
/** Its slug — the `automation` schema requires one and derives nothing. */
const PROD_AUTOMATION_SLUG = 'rbac-production-enable-probe'
/** Name the draft test authors through the designer. */
const DRAFT_AUTOMATION_NAME = 'RBAC editor draft automation'
/** The slug the designer derives from that name (`derivedSlug`). */
const DRAFT_AUTOMATION_SLUG = 'rbac-editor-draft-automation'

/**
 * Delete every automation carrying one of `slugs`, as admin.
 *
 * THIS SUITE IS NOT READ-ONLY — it authors automations through the UI, so
 * without a reset each run inherits the last run's rows, `locator(…, { hasText:
 * NAME })` matches N of them, and Playwright fails with a strict-mode violation
 * that reads like a selector bug.
 *
 * ⚠️ CORRECTION, recorded because the wrong version of it was briefly written
 * here: I first blamed the render delay on `refreshStatuses()` issuing one
 * `/status` call per row. It does issue one per row — but through
 * `Promise.all`, i.e. in PARALLEL, so row count is not the driver. Measured
 * phase timings for this page on a live instance:
 *
 *     goto                12760 ms   <- the SPA boot dominates
 *     .automations-page     164 ms
 *     dismissFirstVisitOverlays 4132 ms  <- dead waiting on absent overlays
 *     pick app + version     ~1 s
 *     row visible           939 ms   <- the status calls are NOT the problem
 *
 * The reset below is worth doing for the strict-mode reason alone. The timing
 * claim was not, and a plausible-but-unmeasured reason in a comment is how the
 * next person "fixes" the wrong thing.
 *
 * @param page  Playwright page on an ADMIN session.
 * @param slugs Automation slugs to remove.
 * @return {Promise<void>}
 */
async function deleteAutomationsBySlug(page: Page, slugs: string[]): Promise<void> {
	await page.evaluate(
		async ({ slugs }) => {
			const tok =
				(window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const headers = {
				requesttoken: tok,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			}
			const listResp = await fetch(
				'/index.php/apps/openregister/api/objects/openbuild/automation?_limit=200',
				{ headers },
			)
			if (!listResp.ok) {
				return
			}
			const listed = await listResp.json().catch(() => null)
			const rows = Array.isArray(listed) ? listed : (listed?.results ?? [])
			for (const row of rows) {
				if (slugs.includes(row?.slug)) {
					// Buildiq's own DELETE: it removes the COMPILED ARTIFACTS
					// first. Deleting straight over OR REST would orphan live
					// notifications and schedules with no definition left to
					// manage them from.
					await fetch(
						`/index.php/apps/buildiq/api/automations/${row['@self']?.id ?? row.id}`,
						{ method: 'DELETE', headers },
					)
				}
			}
		},
		{ slugs },
	)
}

/**
 * Seed ONE automation on the fixture's production version, DISABLED.
 *
 * The production-scoped test asserts what happens when an editor and then an
 * owner ENABLE something, so the thing has to start disabled — and it has to be
 * a row this suite owns. Re-seeded (deleted, then recreated) on every run rather
 * than left idempotent: the test's whole point is to flip `enabled` to true, so
 * a second run would otherwise start from the state the first run left behind
 * and send `/disable` instead.
 *
 * Written through Buildiq's own endpoints, as the product's own client does.
 *
 * @param page Playwright page on an ADMIN session.
 * @return {Promise<void>}
 */
async function seedDisabledProductionAutomation(page: Page): Promise<void> {
	const result = await page.evaluate(
		async ({ appSlug, name, slug }) => {
			const tok =
				(window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const headers = {
				requesttoken: tok,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			}

			const versionsResp = await fetch(
				`/index.php/apps/buildiq/api/applications/${appSlug}/versions`,
				{ headers },
			)
			if (!versionsResp.ok) {
				return `versions ${versionsResp.status}`
			}
			const versions = await versionsResp.json()
			const production = (Array.isArray(versions) ? versions : []).find(
				(v) => v?.slug === 'production',
			)
			const versionUuid = production?.['@self']?.id ?? production?.id
			if (!versionUuid) {
				return `no production version among ${JSON.stringify((versions ?? []).map((v) => v?.slug))}`
			}

			const resp = await fetch('/index.php/apps/buildiq/api/automations', {
				method: 'POST',
				headers,
				body: JSON.stringify({
					slug,
					name,
					applicationSlug: appSlug,
					versionUuid,
					enabled: false,
					trigger: { type: 'manual' },
					// `send-notification` is one of the six values the schema's
					// `actions[].type` enum accepts. ⚠️ OR's validation message
					// renders that enum as an EMPTY list ("should be one of: , but
					// is 'x'"), so read the allowed values from
					// GET /api/schemas/automation, never from the error.
					actions: [
						{ type: 'send-notification', channels: ['nc-notification'] },
					],
				}),
			})
			return resp.status === 201
				? 'created'
				: `create ${resp.status}: ${(await resp.text()).slice(0, 200)}`
		},
		{
			appSlug: APP_SLUG,
			name: PROD_AUTOMATION_NAME,
			slug: PROD_AUTOMATION_SLUG,
		},
	)

	if (result !== 'created') {
		throw new Error(`seedDisabledProductionAutomation failed — ${result}`)
	}
}

/**
 * Is buildiq's `automation` schema readable and shaped as this suite expects?
 *
 * THIS PROBE USED TO REPORT `false` HERE AND `true` EVERYWHERE ELSE, IN THE
 * SAME RUN, AGAINST THE SAME INSTANCE.
 *
 * It is a copy of `automations.spec.ts`'s helper, and it carried that file's
 * reason with it: "the buildiq `automation` schema slug collides with a
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
async function automationSchemaIsUsable(
	request: APIRequestContext,
): Promise<boolean> {
	const auth = Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	const resp = await request.get(
		`${NEXTCLOUD_URL}/index.php/apps/openregister/api/schemas/automation`,
		{
			headers: { 'OCS-APIRequest': 'true', Authorization: `Basic ${auth}` },
		},
	)
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
		throw new Error(
			`Login as ${user} appears to have failed — still on ${page.url()}.`,
		)
	}
}

test.describe('automation-designer — RBAC (REQ-AUTD-008)', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	/**
	 * Build the fixture this suite asserts against, instead of assuming it.
	 *
	 * The previous revision of this file documented `rbac-automations-app` as
	 * having been "created via the wizard's dev-prod preset during this
	 * session's live-verification" — i.e. by hand, on one developer's box.
	 * `ci-seed.sh` seeds only `hello-world`, so on CI the app-picker had no such
	 * option and both tests died on a locator timeout. That timeout was then
	 * read as evidence about `GET /api/applications` and written up as two
	 * issues (#171, #173) claiming a granted editor cannot see an application.
	 * Measured directly, they can — see tests/e2e/support/appRoles.ts.
	 *
	 * Runs as ADMIN (its own context, since the describe clears storageState so
	 * the tests can log in as non-admins) and is idempotent.
	 */
	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const adminPage = await admin.newPage()
		try {
			// Two versions, production LAST — REQ-AUTD-008 is precisely the
			// difference between a draft and the production version, so a
			// single-version fixture cannot express the requirement at all.
			await ensureApp(adminPage, APP_SLUG, APP_TITLE, [
				'development',
				'production',
			])
			// A NON-ADMIN owner. The production-scoped check runs with
			// `allowAdminBypass: false`, so `admin` being the implicit owner
			// would prove nothing about the owner path.
			// `user:admin` is granted EXPLICITLY, not assumed.
			//
			// Every automation route runs with `allowAdminBypass: false` — that
			// is the requirement, not an oversight — so being an NC admin grants
			// nothing here. `ensureApp` does leave `owners: ['user:admin']` on an
			// app it creates, but it short-circuits when the app already exists,
			// and on a re-used instance whose `permissions` were rewritten in the
			// meantime the admin is simply not an owner. The seeding step below
			// then 403s, from the fixture builder, with a message that reads like
			// the feature is broken. Naming admin here makes the fixture's
			// precondition explicit instead of inherited.
			await grantAppRoles(adminPage, APP_SLUG, {
				owners: [`user:${OWNER_USER}`, `user:${ADMIN_USER}`],
				editors: [`user:${EDITOR_USER}`],
			})
			// Reset before seeding: both the row the production test toggles and
			// the row the draft test AUTHORS have to be absent at the start of a
			// run, or a second run inherits the first run's state.
			await deleteAutomationsBySlug(adminPage, [
				PROD_AUTOMATION_SLUG,
				DRAFT_AUTOMATION_SLUG,
			])
			await seedDisabledProductionAutomation(adminPage)
		} finally {
			await admin.close()
		}
	})

	test('editor authors + enables an automation on a non-production (draft) version', async ({
		page,
		request,
	}) => {
		// @e2e openspec/specs/automation-designer/spec.md#editor-authors-and-enables-on-a-draft-version
		test.skip(
			(await automationSchemaIsUsable(request)) === false,
			'buildiq `automation` schema does not read back with a `trigger` object property — see automationSchemaIsUsable() for why this must be a real verdict and not a failed lookup',
		)
		await loginAs(page, EDITOR_USER, EDITOR_PASS)
		await suppressSupportDialog(page)
		await page.goto(`${NEXTCLOUD_URL}/apps/buildiq/automations`)
		await page.waitForSelector('.automations-page', { timeout: 20_000 })
		await dismissWalkthrough(page)

		await page.getByRole('combobox', { name: /application/i }).click()
		await selectOption(page, APP_TITLE).first().click()
		await page.getByRole('combobox', { name: /version/i }).click()
		await selectOption(page, 'development').first().click()

		await page.getByRole('button', { name: /new automation/i }).click()
		await page.waitForSelector('.automation-edit')
		await page.waitForTimeout(1_500)
		await page
			.getByRole('textbox', { name: /^name$/i })
			.fill(DRAFT_AUTOMATION_NAME)
		await page.getByRole('button', { name: /add action/i }).click()
		await page.getByRole('textbox', { name: /subject \(english\)/i }).fill('x')
		await page.getByRole('button', { name: /^save$/i }).click()
		await expect(page.locator('.automation-edit')).toHaveCount(0, {
			timeout: 10_000,
		})

		const row = page.locator('[data-testid="automation-row"]', {
			hasText: DRAFT_AUTOMATION_NAME,
		})
		await expect(row).toBeVisible()
		const toggle = row
			.locator('.ncswitch-stub, [class*="checkbox-radio-switch"]')
			.first()
		await toggle.click()
		// No error note card on a non-production enable.
		await expect(
			page.locator('.ncnotecard-stub, [class*="note-card"][class*="error"]'),
		).toHaveCount(0)
	})

	test('editor gets 403 enabling on the production version; owner succeeds', async ({
		browser,
		request,
	}) => {
		// @e2e openspec/specs/automation-designer/spec.md#editor-cannot-enable-on-the-production-version
		test.skip(
			(await automationSchemaIsUsable(request)) === false,
			'buildiq `automation` schema does not read back with a `trigger` object property — see automationSchemaIsUsable() for why this must be a real verdict and not a failed lookup',
		)

		/**
		 * Open the automations page for the fixture's PRODUCTION version, as
		 * `user`, and return the enable-toggle of the seeded row.
		 *
		 * ⚠️ THE ROW IS THE SUITE'S OWN, MATCHED BY NAME — never
		 * `[data-testid="automation-row"]').first()`, which this test used to do.
		 * `.first()` picked whatever row sorted first, in whatever state it was
		 * in, and `NcCheckboxRadioSwitch` is a TOGGLE: an already-enabled row
		 * sends `/disable`. The `waitForResponse(/\/enable/)` below then waits
		 * out the entire test budget and reports a timeout whose message says
		 * nothing about the fixture's STATE being the problem.
		 *
		 * @param storageState Path to a session minted by globalSetup.
		 * @return {Promise<{page: import('@playwright/test').Page, toggle: import('@playwright/test').Locator, close: () => Promise<void>}>}
		 */
		const openProductionRowAs = async (storageState: string) => {
			const context = await browser.newContext({ storageState })
			const p = await context.newPage()
			await suppressSupportDialog(p)
			await p.goto(`${NEXTCLOUD_URL}/apps/buildiq/automations`)
			await p.waitForSelector('.automations-page', { timeout: 20_000 })
			await dismissWalkthrough(p)

			await p.getByRole('combobox', { name: /application/i }).click()
			await selectOption(p, APP_TITLE).first().click()
			await p.getByRole('combobox', { name: /version/i }).click()
			await selectOption(p, 'production').first().click()

			const row = p.locator('[data-testid="automation-row"]', {
				hasText: PROD_AUTOMATION_NAME,
			})
			await expect(row).toBeVisible({ timeout: 20_000 })
			return {
				page: p,
				toggle: row
					.locator('.ncswitch-stub, [class*="checkbox-radio-switch"]')
					.first(),
				close: () => context.close(),
			}
		}

		// Sessions come from globalSetup's stored state, not from an interactive
		// login per session. This test needs TWO users, and two form logins plus
		// two cold SPA boots did not fit the 30 s per-test budget — which is a
		// reason to stop re-doing work globalSetup already did, not a reason to
		// raise the budget. `tests/e2e/global-setup.ts` mints
		// `.auth/rbac-{owner,editor,viewer,outsider}.json` on every run and this
		// file was ignoring all four.
		const editor = await openProductionRowAs(
			`tests/e2e/.auth/${EDITOR_USER}.json`,
		)
		const editorResponse = editor.page.waitForResponse(
			(resp) =>
				resp.url().includes('/api/automations/')
				&& resp.url().includes('/enable'),
		)
		await editor.toggle.click()
		expect((await editorResponse).status()).toBe(403)
		await editor.close()

		// Same automation, owner session: succeeds where the editor was refused.
		// `rbac-owner` is NOT an NC admin, and every automation route runs with
		// `allowAdminBypass: false`, so this asserts the owner ROLE and nothing
		// else.
		const owner = await openProductionRowAs(`tests/e2e/.auth/${OWNER_USER}.json`)
		const ownerResponse = owner.page.waitForResponse(
			(resp) =>
				resp.url().includes('/api/automations/')
				&& resp.url().includes('/enable'),
		)
		await owner.toggle.click()
		expect((await ownerResponse).status()).toBe(200)
		await owner.close()
	})
})
