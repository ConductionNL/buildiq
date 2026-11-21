/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `lifecycle-release-test-gate`, task T06
 * (REQ-BQRT-001 to REQ-BQRT-005): an app version's test suite runs before a
 * release, a broken column stops the release with the failed check in the
 * dialog, and an owner can release anyway with a reason that is kept on the
 * test run.
 *
 *   1. A development manifest whose index page has a column the schema does
 *      not have fails exactly one structure check (REQ-BQRT-001).
 *   2. A rule set the app owns, with a test case that now gives another
 *      answer, fails by rule set and case name; a rule set of another app is
 *      not run (REQ-BQRT-002).
 *   3. A record test that expects a refusal is reported, and no record is
 *      saved by the run (REQ-BQRT-003). GUARDED, see below.
 *   4. The "Tests" tab shows the latest run of the development version with
 *      its counts and the reason for a skip (REQ-BQRT-005).
 *   5. "Release" on the broken development version stops, the dialog lists
 *      the failed column, and production is unchanged (REQ-BQRT-004).
 *   6. "Release anyway" with a reason releases it, and the stored test run
 *      holds the failures, the owner and the reason (REQ-BQRT-004,
 *      REQ-BQRT-005).
 *
 * Fixture: the app `pw-release-gate`, created through the wizard with a
 * development -> staging -> production chain (support/versionChain.ts), so
 * the admin the global setup logs in is its sole owner. Its development page
 * reads the seeded `hello-message` schema of the shared `buildiq` register
 * (properties `title` and `body`). The rule sets, rule, test cases, record
 * test and test runs this spec writes, and the app with its own registers,
 * are removed in afterAll.
 *
 * GUARDED: OpenRegister cannot yet check a record without saving it (the
 * sibling half, task T07), so the suite reports every record test as
 * skipped with that reason. Scenario 3 asserts that it is never reported as
 * passed and that no record was saved, then skips with that reason until the
 * validate-only call ships.
 *
 * Not covered here: the release gate on a promotion onto production
 * (`VersionPromotionService::promote()`), and an editor's override being
 * refused; both are PHPUnit cases (VersionPromotionTestGateTest,
 * ApplicationVersionsControllerTest).
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/lifecycle-release-test-gate/specs/app-release-test-gate/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { ensureVersionChain } from './support/versionChain.ts'

const APP_SLUG = 'pw-release-gate'
const APP_NAME = 'PW Release gate'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const API = `${BASE_URL}/index.php/apps/buildiq/api`
const OR_OBJECTS = `${BASE_URL}/index.php/apps/openregister/api/objects/buildiq`
const HEADERS = { 'OCS-APIRequest': 'true' }
const RUN_ID = Date.now().toString(36)

/** The page the development manifest breaks, and the column it breaks it with. */
const PAGE_ID = 'messages'
const MISSING_COLUMN = 'referenceNumber'
const BROKEN_CHECK = `Page ${PAGE_ID}, column ${MISSING_COLUMN}`

const RULE_SET = `pw-gate-fees-${RUN_ID}`
const OTHER_RULE_SET = `pw-gate-other-${RUN_ID}`
const RULE_CASE = 'Large build pays 1200'
const RECORD_TEST = 'Message needs a title'
const RECORD_MARKER = `pw-release-gate record ${RUN_ID}`
const OVERRIDE_REASON = 'Column removed on purpose, page fixed in next release'

/** What the suite says about a record test while OpenRegister cannot validate without saving. */
const RECORD_SKIP_REASON = 'OpenRegister cannot check a record without saving it yet, so this record test did not run.'

/** Shared between the serial tests. */
const state = { appUuid: '', devUuid: '', recordTestId: '' }
/** OpenRegister objects this run created, as `schema/uuid`, removed in afterAll. */
const createdObjects: string[] = []

/**
 * The uuid of an OpenRegister object or buildiq response.
 *
 * @param body The decoded response.
 * @return The uuid, or ''.
 */
function uuidOf(body: Record<string, any> | null | undefined): string {
	return String(body?.['@self']?.id ?? body?.uuid ?? body?.id ?? '')
}

/**
 * The fixture app as the applications list returns it.
 *
 * @param page A logged-in page.
 * @return The Application row.
 */
async function application(page: Page): Promise<Record<string, any>> {
	const resp = await page.request.get(`${API}/applications`, { headers: HEADERS })
	expect(resp.ok(), `list the applications: ${resp.status()}`).toBeTruthy()
	const body = await resp.json()
	const rows = Array.isArray(body) ? body : (body?.results ?? [])
	const app = rows.find((row: Record<string, any>) => row?.slug === APP_SLUG)
	expect(app, `${APP_SLUG} must exist`).toBeTruthy()
	return app
}

/**
 * The uuid the Application's production pointer names.
 *
 * @param page A logged-in page.
 * @return The production version uuid, or ''.
 */
async function productionPointer(page: Page): Promise<string> {
	const pointer = (await application(page)).productionVersion
	return typeof pointer === 'string' ? pointer : uuidOf(pointer)
}

/**
 * The fixture app's versions.
 *
 * @param page A logged-in page.
 * @return The ApplicationVersion rows.
 */
async function versions(page: Page): Promise<Array<Record<string, any>>> {
	const resp = await page.request.get(`${API}/applications/${APP_SLUG}/versions`, { headers: HEADERS })
	expect(resp.ok(), `list the versions: ${resp.status()}`).toBeTruthy()
	const body = await resp.json()
	return Array.isArray(body) ? body : (body?.results ?? [])
}

/**
 * Run the development version's suite by hand ("Run tests").
 *
 * @param page A logged-in page.
 * @return The stored run: counts and `report`, the list of checks.
 */
async function runDevelopmentTests(page: Page): Promise<Record<string, any>> {
	const resp = await page.request.post(`${API}/applications/${APP_SLUG}/versions/development/tests`, { headers: HEADERS })
	expect(resp.ok(), `run the tests: ${resp.status()} ${(await resp.text()).slice(0, 300)}`).toBeTruthy()
	return resp.json()
}

/**
 * The checks of a run of one kind.
 *
 * @param run  The run.
 * @param kind structure, rule-set or record.
 * @return The checks.
 */
function checksOf(run: Record<string, any>, kind: string): Array<Record<string, string>> {
	return (Array.isArray(run.report) ? run.report : []).filter((check: Record<string, string>) => check.kind === kind)
}

/**
 * Save an object in the shared buildiq register and remember it for cleanup.
 *
 * @param page   A logged-in admin page.
 * @param schema The schema slug.
 * @param data   The object.
 * @return The object's uuid.
 */
async function createObject(page: Page, schema: string, data: Record<string, unknown>): Promise<string> {
	const resp = await page.request.post(`${OR_OBJECTS}/${schema}`, { headers: HEADERS, data })
	expect(resp.status(), `create a ${schema}: ${(await resp.text()).slice(0, 300)}`).toBeLessThan(300)
	const uuid = uuidOf(await resp.json())
	expect(uuid, `the ${schema} has a uuid`).not.toBe('')
	createdObjects.push(`${schema}/${uuid}`)
	return uuid
}

/**
 * Open the app detail page and one of its sidebar tabs, polling the end
 * state the way app-api-docs.spec.ts and version-rollback.spec.ts do: the
 * sidebar re-mounts after first paint and can undo an early tab click.
 *
 * @param page     A logged-in page.
 * @param tabName  The tab's accessible name.
 * @param bodyCss  A selector inside the tab's body.
 */
async function openSidebarTab(page: Page, tabName: RegExp, bodyCss: string): Promise<void> {
	await page.goto(`${BASE_URL}/apps/buildiq/applications/${state.appUuid}`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	const tab = page.getByRole('tab', { name: tabName }).first()
	const openSidebar = page.getByRole('button', { name: 'Open sidebar', exact: true })
	const body = page.locator(bodyCss).first()
	await expect(async () => {
		if (!(await tab.isVisible().catch(() => false))) {
			await openSidebar.click({ timeout: 10_000 })
		}
		await tab.click({ timeout: 10_000 })
		await expect(tab).toHaveAttribute('aria-selected', 'true', { timeout: 5_000 })
		await expect(body).toBeVisible({ timeout: 5_000 })
	}, `the ${tabName} tab must open and render its body`).toPass({ timeout: 60_000, intervals: [500, 1_000, 2_000] })
}

/**
 * Click "Release" on the development row of the Version history tab.
 *
 * @param page A logged-in owner page.
 */
async function releaseDevelopment(page: Page): Promise<void> {
	await openSidebarTab(page, /version history/i, '.version-history__row')
	const row = page.locator('.version-history__row').filter({ hasText: 'development' }).first()
	await row.getByRole('button', { name: 'Release', exact: true }).click()
}

test.describe('lifecycle-release-test-gate: the test suite gates a release', () => {
	test.describe.configure({ mode: 'serial', timeout: 150_000 })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await suppressSupportDialog(page)
			await page.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			await ensureVersionChain(page, APP_SLUG, APP_NAME)
			state.appUuid = uuidOf(await application(page))
			expect(state.appUuid, 'the fixture app has a uuid').not.toBe('')

			// Break the development version: an index page with a column the
			// schema does not have, and a menu item that leads to that page.
			const manifest = {
				version: '1.0.0',
				menu: [{ label: 'Messages', route: PAGE_ID }],
				pages: [{
					id: PAGE_ID,
					route: '/messages',
					type: 'index',
					title: 'Messages',
					config: { register: 'buildiq', schema: 'hello-message', columns: ['title', MISSING_COLUMN] },
				}],
			}
			const put = await page.request.put(`${API}/applications/${APP_SLUG}/versions/development`, {
				headers: HEADERS,
				data: { manifest },
			})
			expect(put.ok(), `store the development manifest: ${put.status()} ${(await put.text()).slice(0, 300)}`).toBeTruthy()

			const development = (await versions(page)).find((v) => v?.slug === 'development')
			state.devUuid = uuidOf(development)
			expect(state.devUuid, 'the development version has a uuid').not.toBe('')
			expect(development?.status, 'development is a draft, so it can be released').toBe('draft')
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await page.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			if (state.recordTestId !== '') {
				await page.request.delete(`${API}/applications/${APP_SLUG}/record-tests/${state.recordTestId}`, { headers: HEADERS }).catch(() => undefined)
			}
			for (const path of createdObjects.reverse()) {
				await page.request.delete(`${OR_OBJECTS}/${path}`, { headers: HEADERS }).catch(() => undefined)
			}
			if (state.appUuid !== '') {
				// Every stored run of the app, then the app with its own registers.
				const runs = await page.request.get(`${OR_OBJECTS}/app-test-run`, {
					headers: HEADERS,
					params: { application: state.appUuid, _limit: '200' },
				}).catch(() => null)
				const body = runs && runs.ok() ? await runs.json() : {}
				for (const run of (Array.isArray(body) ? body : (body?.results ?? []))) {
					if (run?.application === state.appUuid) {
						await page.request.delete(`${OR_OBJECTS}/app-test-run/${uuidOf(run)}`, { headers: HEADERS }).catch(() => undefined)
					}
				}
				await page.request.delete(`${API}/applications/${state.appUuid}`, {
					headers: HEADERS,
					data: { deleteData: true },
				}).catch(() => undefined)
			}
		} finally {
			await page.close()
		}
	})

	// @e2e app-release-test-gate::a-removed-property-fails-the-suite
	test('a column the schema does not have fails one structure check', async ({ page }) => {
		const run = await runDevelopmentTests(page)
		const structure = checksOf(run, 'structure')
		const failed = structure.filter((check) => check.outcome === 'failed')

		expect(failed, 'exactly one structure check fails').toHaveLength(1)
		expect(failed[0].name, 'it names the page and the column').toBe(BROKEN_CHECK)
		expect(failed[0].message, 'it says the schema has no such property').toBe('No such property on hello-message.')
		expect(
			structure.find((check) => check.name === `Page ${PAGE_ID}, column title`)?.outcome,
			'a column the schema does have passes',
		).toBe('passed')
		expect(
			structure.find((check) => check.name === 'Menu item Messages')?.outcome,
			'a menu item that leads to a page passes',
		).toBe('passed')
	})

	// @e2e app-release-test-gate::a-fee-rule-test-fails
	test('a test case of a rule set the app owns fails by name, and another app\'s rule set is not run', async ({ page }) => {
		// A rule that always sets the fee to 900, and a case that expects 1200.
		await createObject(page, 'rule-set', {
			slug: RULE_SET,
			name: 'PW permit fees',
			ruleType: 'condition-action',
			ownerApp: APP_SLUG,
			status: 'draft',
		})
		await createObject(page, 'condition-action-rule', {
			ruleSetId: RULE_SET,
			name: 'Fee for a large build',
			condition: '',
			priority: 1,
			active: true,
			actions: [{ type: 'set-field', parameters: { field: 'fee', value: 900 } }],
		})
		await createObject(page, 'rule-test-case', {
			ruleSetId: RULE_SET,
			name: RULE_CASE,
			inputPayload: { size: 'large' },
			expectedResult: { fee: 1200 },
		})

		// The same failing case on a rule set another app owns.
		await createObject(page, 'rule-set', {
			slug: OTHER_RULE_SET,
			name: 'PW other app fees',
			ruleType: 'condition-action',
			ownerApp: 'pw-release-gate-other',
			status: 'draft',
		})
		await createObject(page, 'rule-test-case', {
			ruleSetId: OTHER_RULE_SET,
			name: RULE_CASE,
			inputPayload: { size: 'large' },
			expectedResult: { fee: 1200 },
		})

		const run = await runDevelopmentTests(page)
		const ruleChecks = checksOf(run, 'rule-set')
		const fee = ruleChecks.find((check) => check.name === `${RULE_SET}: ${RULE_CASE}`)
		expect(fee, `the report lists "${RULE_SET}: ${RULE_CASE}"`).toBeTruthy()
		expect(fee?.outcome, 'the case that now returns 900 fails').toBe('failed')
		expect(
			ruleChecks.filter((check) => check.name.startsWith(`${OTHER_RULE_SET}`)),
			'a rule set of another app is not run',
		).toHaveLength(0)
	})

	// @e2e app-release-test-gate::a-permit-without-an-applicant-is-refused
	test('a record test that expects a refusal is reported, and the run saves no record', async ({ page }) => {
		const created = await page.request.post(`${API}/applications/${APP_SLUG}/record-tests`, {
			headers: HEADERS,
			data: {
				name: RECORD_TEST,
				schema: 'hello-message',
				record: { body: RECORD_MARKER },
				expect: 'refuse',
				expectedFields: ['title'],
			},
		})
		expect(created.status(), `add the record test: ${(await created.text()).slice(0, 300)}`).toBe(201)
		state.recordTestId = uuidOf(await created.json())
		expect(state.recordTestId).not.toBe('')

		const run = await runDevelopmentTests(page)
		const check = checksOf(run, 'record').find((entry) => entry.name === `hello-message: ${RECORD_TEST}`)
		expect(check, 'the record test is in the report').toBeTruthy()

		const saved = await page.request.get(`${OR_OBJECTS}/hello-message`, {
			headers: HEADERS,
			params: { body: RECORD_MARKER, _limit: '5' },
		})
		expect(saved.ok()).toBeTruthy()
		const savedBody = await saved.json()
		const savedRows = (Array.isArray(savedBody) ? savedBody : (savedBody?.results ?? []))
			.filter((row: Record<string, any>) => row?.body === RECORD_MARKER)
		expect(savedRows, 'running the record test saved no message').toHaveLength(0)

		if (check?.outcome === 'skipped') {
			// Never reported as passed while it cannot run.
			expect(check.message, 'the skip says why').toBe(RECORD_SKIP_REASON)
			test.skip(true, 'OpenRegister has no validate-only call on its contract yet (task T07), so a record test is reported as skipped, never run.')
		}
		expect(check?.outcome, 'the refusal on title is what the test expects').toBe('passed')
	})

	// @e2e app-release-test-gate::a-maker-checks-the-last-run-before-release
	test('the Tests tab shows the last run of the development version with its counts', async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)

		const overview = await page.request.get(`${API}/applications/${APP_SLUG}/tests`, { headers: HEADERS })
		expect(overview.ok()).toBeTruthy()
		const latest = (await overview.json())?.latestRuns?.[state.devUuid]
		expect(latest, 'the earlier runs are kept for the development version').toBeTruthy()
		expect(latest.trigger).toBe('manual')

		await openSidebarTab(page, /^Tests$/, '.ob-tests-tab')
		const tab = page.locator('.ob-tests-tab').first()
		await tab.getByRole('combobox', { name: /version/i }).click()
		await page.getByRole('option').filter({ has: page.locator('[title="development"]') }).first().click()

		await expect(tab.locator('.ob-tests-tab__summary'), 'the summary shows the stored counts').toContainText(
			`${latest.passed} passed, ${latest.failed} failed, ${latest.skipped} skipped`,
			{ timeout: 20_000 },
		)
		await expect(
			tab.locator('.ob-tests-tab__check--failed').filter({ hasText: BROKEN_CHECK }),
			'the failed column is listed',
		).toBeVisible()
		if (latest.skipped > 0) {
			await expect(
				tab.locator('.ob-tests-tab__check--skipped .ob-tests-tab__message').first(),
				'a skip shows its reason',
			).toContainText(RECORD_SKIP_REASON)
		}
	})

	// @e2e app-release-test-gate::a-broken-version-does-not-reach-production
	test('"Release" on the broken version stops, lists the failed check and leaves production as it was', async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
		const productionBefore = await productionPointer(page)
		expect(productionBefore, 'the app has a production version').not.toBe('')

		const answer = page.waitForResponse((resp) => resp.request().method() === 'POST'
			&& resp.url().includes(`/applications/${APP_SLUG}/versions/development/release`))
		await releaseDevelopment(page)
		const released = await answer
		expect(released.status(), 'the release is refused').toBe(422)
		expect((await released.json())?.error).toBe('release_tests_failed')

		const dialog = page.getByRole('dialog', { name: 'The release was stopped' })
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		await expect(dialog.locator('.release-test-report__checks'), 'the dialog lists the failed check').toContainText(BROKEN_CHECK)
		await dialog.getByRole('button', { name: 'Close' }).click()
		await expect(dialog).toBeHidden()

		expect(await productionPointer(page), 'production still points at the same version').toBe(productionBefore)
		const development = (await versions(page)).find((v) => v?.slug === 'development')
		expect(development?.status, 'development is still a draft').toBe('draft')
	})

	// @e2e app-release-test-gate::an-owner-releases-a-hotfix-anyway
	test('the owner releases anyway with a reason, and the test run keeps the failures, the owner and the reason', async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
		await releaseDevelopment(page)

		const dialog = page.getByRole('dialog', { name: 'The release was stopped' })
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		const anyway = dialog.getByRole('button', { name: 'Release anyway' })
		await expect(anyway, 'no release without a reason').toBeDisabled()
		await dialog.getByLabel('Reason to release anyway').fill(OVERRIDE_REASON)

		const answer = page.waitForResponse((resp) => resp.request().method() === 'POST'
			&& resp.url().includes(`/applications/${APP_SLUG}/versions/development/release`))
		await anyway.click()
		const released = await answer
		expect(released.status(), `the override releases: ${(await released.text()).slice(0, 300)}`).toBe(200)
		expect(released.request().postDataJSON(), 'the dialog sends the reason').toMatchObject({ override: { reason: OVERRIDE_REASON } })

		await expect.poll(() => productionPointer(page), {
			timeout: 30_000,
			message: 'production now points at the development version',
		}).toBe(state.devUuid)

		let run: Record<string, any> | undefined
		await expect.poll(async () => {
			const overview = await page.request.get(`${API}/applications/${APP_SLUG}/tests`, { headers: HEADERS })
			run = (await overview.json())?.latestRuns?.[state.devUuid]
			return run?.override?.reason ?? null
		}, { timeout: 30_000, message: 'the release run is stored with the override' }).toBe(OVERRIDE_REASON)

		expect(run?.trigger, 'the run was started by the release').toBe('release')
		expect(run?.override?.user, 'the override names the owner').toBe(ADMIN_USER)
		expect(run?.failed, 'the run keeps its failures').toBeGreaterThan(0)
		expect(
			(run?.report ?? []).some((check: Record<string, string>) => check.name === BROKEN_CHECK && check.outcome === 'failed'),
			'the failed column is in the stored report',
		).toBe(true)
	})
})
