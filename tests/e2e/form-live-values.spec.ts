// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e — a running form prefills, calculates and checks as it is
 * filled in (forms-live-values-and-checks, T07).
 *
 * WHAT THIS ASSERTS THAT VITEST CANNOT
 * ------------------------------------
 * `tests/components/runtime/BuildiqFormPage.spec.js` drives BuildiqFormPage
 * against a stand-in for CnFormPage and a mocked evaluate client. This spec
 * is the whole path on a running server: a real permit form page in an app
 * manifest, two real decision tables, `POST /api/rules/{slug}/evaluate` in
 * preview mode, nextcloud-vue's `CnFormPage` hooks (default tokens, the
 * `calculate` hook, the unmet-conditions note), all seen by a signed-in
 * colleague who is NOT an administrator.
 *
 * SETUP
 * -----
 * The admin session (globalSetup) creates or reuses the fixture app, writes
 * two active decision-table rule sets, seeds one form page through the
 * manifest API, provisions a colleague with an e-mail address and grants
 * that colleague's group the viewer role on the app. The colleague then
 * signs in through the login form in a context with an EMPTY storage state,
 * so nothing of the admin session can leak into what is asserted.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not the save-time recompute (REQ-BQLV-005, PHPUnit
 * `FormLiveValuesListenerTest`), and not the absence of an execution log for
 * preview evaluations (REQ-BQLV-004, PHPUnit `RuleEngineServiceTest`).
 *
 * @spec openspec/changes/archive/2026-11-24-forms-live-values-and-checks/specs/form-live-values/spec.md
 * @e2e form-live-values/requirement-a-field-can-be-prefilled-from-the-user-or-the-record-req-bqlv-001/the-applicants-e-mail-is-filled-in
 * @e2e form-live-values/requirement-a-field-can-be-calculated-from-a-rule-set-req-bqlv-002/the-fee-follows-the-size-of-the-event
 * @e2e form-live-values/requirement-a-form-can-check-eligibility-as-it-is-filled-in-req-bqlv-003/the-applicant-learns-why-they-do-not-qualify
 */
import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { ensureApp, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { dismissFirstVisitOverlays } from './support/overlays.ts'

const ADMIN_STATE = 'tests/e2e/.auth/admin.json'
const APP_SLUG = process.env.BUILDIQ_E2E_LIVE_VALUES_SLUG ?? 'pw-live-values'
const PAGE_ID = 'e2e-permit-form'
const FEE_RULES = 'e2e-event-fee'
const CHECK_RULES = 'e2e-permit-eligibility'
const UNMET = 'Eligibility criteria not met'

const COLLEAGUE = {
	id: 'e2e-live-values',
	pass: 'LiveValues-e2e-1!',
	email: 'e2e-live-values@example.com',
	group: 'e2e-live-values',
}

const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const RULE_SETS = `${BASE_URL}/index.php/apps/openregister/api/objects/buildiq/rule-set`
const MANIFEST = `${BASE_URL}/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`

/**
 * The fee table: a large event pays more than a small one.
 */
const FEE_TABLE = {
	hitPolicy: 'first',
	inputColumns: [{ name: 'attendees', type: 'number' }],
	outputColumns: [{ name: 'fee', type: 'number', defaultValue: 40 }],
	rules: [
		{ conditions: { attendees: '>=100' }, values: { fee: 250 }, label: 'Large event' },
		{ conditions: {}, values: { fee: 40 }, label: 'Small event' },
	],
}

/**
 * The eligibility table: an event of more than 500 attendees cannot be
 * permitted through this form, and the table says why.
 */
const CHECK_TABLE = {
	hitPolicy: 'first',
	inputColumns: [{ name: 'attendees', type: 'number' }],
	outputColumns: [
		{ name: 'decision', type: 'string', defaultValue: 'rejected' },
		{ name: 'reason', type: 'string', defaultValue: UNMET },
	],
	rules: [
		{ conditions: { attendees: '<=500' }, values: { decision: 'approved', reason: '' }, label: 'Within limit' },
		{ conditions: {}, values: { decision: 'rejected', reason: UNMET }, label: 'Over limit' },
	],
}

/**
 * Create the rule set when it is missing (or make it active), then write its
 * decision table. Idempotent across runs.
 *
 * @param request - An admin request context.
 * @param slug - The rule set slug.
 * @param name - Its display name.
 * @param table - The decision table to store.
 * @return {Promise<void>}
 */
async function ensureDecisionTable(
	request: APIRequestContext,
	slug: string,
	name: string,
	table: Record<string, unknown>,
): Promise<void> {
	const listed = await request.get(`${RULE_SETS}?slug=${slug}&_limit=1`, { headers: OCS })
	expect(listed.ok(), `listing rule set ${slug} must succeed`).toBeTruthy()
	const body = await listed.json()
	const rows = Array.isArray(body) ? body : (body?.results ?? [])
	const existing = rows.find((row: Record<string, unknown>) => row?.slug === slug)
	const record = { slug, name, status: 'active', ruleType: 'decision-table' }

	if (existing === undefined) {
		const created = await request.post(RULE_SETS, { headers: OCS, data: record })
		expect(created.ok(), `creating rule set ${slug} must succeed`).toBeTruthy()
	} else if (existing.status !== 'active' || existing.ruleType !== 'decision-table') {
		// OpenRegister saves are PUT-semantic: send the whole record back.
		const uuid = existing['@self']?.id ?? existing.id
		const next = { ...existing, ...record }
		delete next['@self']
		const updated = await request.put(`${RULE_SETS}/${encodeURIComponent(String(uuid))}`, {
			headers: OCS,
			data: next,
		})
		expect(updated.ok(), `activating rule set ${slug} must succeed`).toBeTruthy()
	}

	const saved = await request.put(
		`${BASE_URL}/index.php/apps/buildiq/api/rules/${slug}/decision-table`,
		{ headers: OCS, data: table },
	)
	expect(saved.ok(), `saving the decision table of ${slug} must succeed`).toBeTruthy()
}

/**
 * Replace the fixture page in the app manifest with the permit form.
 *
 * @param request - An admin request context.
 * @return {Promise<void>}
 */
async function seedPermitForm(request: APIRequestContext): Promise<void> {
	const current = await request.get(MANIFEST, { headers: OCS })
	expect(current.ok(), 'GET manifest must succeed').toBeTruthy()
	const manifest = await current.json()
	const pages = (manifest.pages || []).filter((p: { id?: string }) => p.id !== PAGE_ID)
	pages.push({
		id: PAGE_ID,
		type: 'form',
		route: `/${PAGE_ID}`,
		title: 'Event permit',
		config: {
			fields: [
				{ key: 'applicantEmail', label: 'Your e-mail', type: 'string', default: '@me.email' },
				{ key: 'attendees', label: 'Attendees', type: 'number' },
				{
					key: 'fee',
					label: 'Fee',
					type: 'number',
					calculate: { ruleSet: FEE_RULES, output: 'fee', inputs: ['attendees'] },
				},
			],
			eligibility: {
				ruleSet: CHECK_RULES,
				passWhen: { output: 'decision', equals: 'approved' },
				explainWith: 'reason',
				blockSubmit: true,
			},
		},
	})
	// The manifest endpoint reads plain and writes wrapped.
	const written = await request.put(MANIFEST, { headers: OCS, data: { manifest: { ...manifest, pages } } })
	expect(written.ok(), 'seeding the permit form via the manifest API must succeed').toBeTruthy()
}

/**
 * Provision the colleague (idempotent) with an e-mail address and a group.
 *
 * @param request - An admin request context.
 * @return {Promise<void>}
 */
async function ensureColleague(request: APIRequestContext): Promise<void> {
	const users = `${BASE_URL}/ocs/v2.php/cloud/users`
	await request.post(`${BASE_URL}/ocs/v2.php/cloud/groups`, {
		headers: OCS,
		form: { groupid: COLLEAGUE.group },
	})
	const created = await request.post(users, {
		headers: OCS,
		form: { userid: COLLEAGUE.id, password: COLLEAGUE.pass, email: COLLEAGUE.email, 'groups[]': COLLEAGUE.group },
	})
	if (!created.ok()) {
		// Already there: put back the password, the e-mail and the group.
		const user = `${users}/${encodeURIComponent(COLLEAGUE.id)}`
		for (const [key, value] of [['password', COLLEAGUE.pass], ['email', COLLEAGUE.email]]) {
			const edited = await request.put(user, { headers: OCS, form: { key, value } })
			expect(edited.ok(), `resetting the colleague ${key} must succeed`).toBeTruthy()
		}
		await request.post(`${user}/groups`, { headers: OCS, form: { groupid: COLLEAGUE.group } })
	}
}

/**
 * Sign the colleague in from an empty storage state and open the permit form.
 *
 * @param browser - The Playwright browser.
 * @return {Promise<{ page: Page, close: () => Promise<void> }>}
 */
async function openAsColleague(browser: Browser): Promise<{ page: Page, close: () => Promise<void> }> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	const page = await context.newPage()
	await suppressSupportDialog(page)
	await suppressSetupWizard(page)
	await page.goto(`${BASE_URL}/index.php/login`, { waitUntil: 'domcontentloaded' })
	await page.locator('input[name="user"]').fill(COLLEAGUE.id)
	await page.locator('input[name="password"]').fill(COLLEAGUE.pass)
	await page.locator('form').first().evaluate((form: HTMLFormElement) => form.requestSubmit())
	await page.waitForURL((url) => !/\/login(\?|$|\/)/.test(url.toString()), { timeout: 60_000 })

	await page.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/${PAGE_ID}`, { waitUntil: 'domcontentloaded' })
	await dismissFirstVisitOverlays(page)
	await expect(page.getByLabel('Attendees'), 'the permit form must render for the colleague').toBeVisible({ timeout: 30_000 })

	return { page, close: () => context.close() }
}

test.describe('a running form prefills, calculates and checks as it is filled in', () => {
	test.beforeAll(async ({ browser }) => {
		const context = await browser.newContext({ storageState: ADMIN_STATE })
		const page = await context.newPage()
		try {
			await suppressSupportDialog(page)
			await suppressSetupWizard(page)
			await ensureApp(page, APP_SLUG, 'PW Live Values')
			await ensureDecisionTable(page.request, FEE_RULES, 'E2E event fee', FEE_TABLE)
			await ensureDecisionTable(page.request, CHECK_RULES, 'E2E permit eligibility', CHECK_TABLE)
			await seedPermitForm(page.request)
			await ensureColleague(page.request)
			await grantAppRoles(page, APP_SLUG, { viewers: [`group:${COLLEAGUE.group}`] })
		} finally {
			await context.close()
		}
	})

	test("the applicant's e-mail is filled in for the signed-in colleague and can be edited", async ({ browser }) => {
		const { page, close } = await openAsColleague(browser)
		try {
			const email = page.getByLabel('Your e-mail')
			await expect(email).toHaveValue(COLLEAGUE.email)

			await email.fill('someone.else@example.com')
			await expect(email).toHaveValue('someone.else@example.com')
		} finally {
			await close()
		}
	})

	test('the fee follows the size of the event without leaving the field', async ({ browser }) => {
		const { page, close } = await openAsColleague(browser)
		try {
			const evaluations: Array<{ slug: string, body: Record<string, unknown> }> = []
			page.on('request', (request) => {
				const match = request.url().match(/\/api\/rules\/([a-z0-9-]+)\/evaluate/)
				if (match !== null && request.method() === 'POST') {
					evaluations.push({ slug: match[1], body: request.postDataJSON() })
				}
			})

			const attendees = page.getByLabel('Attendees')
			const fee = page.getByLabel('Fee')

			// The focus stays in the attendees field: the fee must follow while typing.
			await attendees.fill('250')
			await expect(fee).toHaveValue('250', { timeout: 10_000 })
			await expect(attendees).toBeFocused()

			await attendees.fill('40')
			await expect(fee).toHaveValue('40', { timeout: 10_000 })

			// Calculated, so the person filling in the form cannot type over it.
			await expect(fee).not.toBeEditable()

			// Every live evaluation is a preview and carries only the answers it reads.
			const feeCalls = evaluations.filter((e) => e.slug === FEE_RULES)
			expect(feeCalls.length).toBeGreaterThan(0)
			for (const call of feeCalls) {
				expect(call.body).toMatchObject({ mode: 'preview' })
				expect(Object.keys(call.body.payload as object)).toEqual(['attendees'])
			}
		} finally {
			await close()
		}
	})

	test('submit waits on the eligibility check and explains the unmet condition', async ({ browser }) => {
		const { page, close } = await openAsColleague(browser)
		try {
			const attendees = page.getByLabel('Attendees')
			const unmet = page.getByTestId('cn-form-page-unmet')
			const submit = page.locator('.cn-form-page__submit button[type="submit"]')

			await attendees.fill('800')
			await expect(unmet).toContainText(UNMET, { timeout: 10_000 })
			await expect(submit).toBeDisabled()

			await attendees.fill('120')
			await expect(unmet).toHaveCount(0, { timeout: 10_000 })
			await expect(submit).toBeEnabled()
		} finally {
			await close()
		}
	})
})
