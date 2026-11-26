/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `logic-automation-run-log`, task T09
 * (REQ-BQRL-001 to REQ-BQRL-004): a manual automation that runs leaves a run
 * record, a maker reads it through `GET /api/automations/{uuid}/runs` and in
 * the "Runs" dialog of the Automations page, and a colleague without a role on
 * the application gets the refusal the status endpoint gives.
 *
 *   1. A manual automation runs on a record; its run is listed newest first
 *      with a summary, and `outcome=failed` leaves it out (REQ-BQRL-001,
 *      REQ-BQRL-002).
 *   2. A colleague without a role on the app is refused exactly as `status`
 *      refuses them, and reads no run (REQ-BQRL-002).
 *   3. "Runs" on the Automations page lists the run; expanding it shows the
 *      "Action | Outcome" table and the duration (REQ-BQRL-003).
 *   4. An automation that never ran says "No run in the last 90 days" and
 *      shows no table (REQ-BQRL-003).
 *   5. A notification-only automation shows what OpenRegister recorded for
 *      its notification as a run row, from OpenRegister's own outcome record
 *      (REQ-BQRL-004). Needs an OpenRegister that records automation outcomes.
 *
 * The automations are written through buildiq's own `/api/automations`
 * endpoints, as `automations-flow-backend.spec.ts` does, on the seeded
 * `hello-world` app and its `hello-message` schema (override with
 * NC_BUILDIQ_TEST_SLUG / BUILDIQ_E2E_FLOW_SCHEMA). Every automation and record
 * this spec creates is deleted again in `afterAll`. The colleague is the
 * globalSetup fixture user `rbac-outsider`, from an empty storage state.
 *
 * The manual run is queued: OpenRegister's flow worker (a background job)
 * executes it, so the instance must run its background jobs (cron) while
 * this spec runs; the run is listed `waiting` until then.
 *
 * GUARDED: the manual run needs OpenRegister to start a manual flow for an app
 * user. Until it can, `POST /api/automations/{uuid}/run` (and the manual
 * listing) answers 503 and the scenarios that need a recorded run skip with
 * that reason; scenarios 2, 4 and 5 need no run and stay live. A run-log
 * service that is not wired answers 503 on `/runs` and skips likewise.
 *
 * Not covered here: a failing step and a skipped run (no fixture action can be
 * made to fail on demand without an integriq source), the approval run that
 * waits for a decision (automation-approval-form.spec.ts drives the approval),
 * and pruning (REQ-BQRL-005, `@e2e exclude`, PHPUnit with a fixed clock).
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/specs/automation-designer/spec.md
 * @e2e automation-designer/requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002/an-editor-reads-the-runs
 * @e2e automation-designer/requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002/filtering-on-failures
 * @e2e automation-designer/requirement-a-maker-reads-the-runs-of-an-automation-they-may-edit-req-bqrl-002/a-user-who-may-not-edit-the-application-reads-nothing
 * @e2e automation-designer/requirement-the-automations-page-shows-the-last-run-and-opens-the-run-log-req-bqrl-003/the-run-detail-reads-like-a-test-result
 * @e2e automation-designer/requirement-the-automations-page-shows-the-last-run-and-opens-the-run-log-req-bqrl-003/an-automation-that-never-ran-says-so
 * @e2e automation-designer/requirement-a-step-that-openregister-executes-is-labelled-never-invented-req-bqrl-004/a-notifications-runs-come-from-openregisters-record
 */

import type { APIRequestContext, APIResponse, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = process.env.NC_BUILDIQ_TEST_SLUG ?? 'hello-world'
/** The app's display name: the app-picker option's `[title]`. */
const APP_TITLE = process.env.NC_BUILDIQ_TEST_TITLE ?? 'Hello World'
const SCHEMA = process.env.BUILDIQ_E2E_FLOW_SCHEMA ?? 'hello-message'
const OUTSIDER_USER = process.env.NC_RBAC_OUTSIDER_USER ?? 'rbac-outsider'
const OUTSIDER_PASS = process.env.NC_RBAC_OUTSIDER_PASS ?? 'RbacOutsider-1!'

const HEADERS = { 'OCS-APIRequest': 'true' }
const AUTOMATIONS = '/index.php/apps/buildiq/api/automations'
const RUN_ID = Date.now().toString(36)

const RAN = { slug: `pw-runs-manual-${RUN_ID}`, name: `PW runs manual ${RUN_ID}` }
const NEVER = { slug: `pw-runs-never-${RUN_ID}`, name: `PW runs never ${RUN_ID}` }
const NOTIFY = { slug: `pw-runs-notify-${RUN_ID}`, name: `PW runs notification ${RUN_ID}` }

/** Uuids this run created, removed in afterAll. */
const createdAutomations: string[] = []
const createdRecords: string[] = []

/** Shared between the serial tests. */
const state = { ran: '', never: '', notify: '', record: '', records: '', runSkipReason: '' }

/**
 * The uuid of an OpenRegister object or buildiq record response.
 *
 * @param body The decoded response.
 * @return The uuid, or ''.
 */
function uuidOf(body: Record<string, any>): string {
	return String(body?.['@self']?.id ?? body?.uuid ?? body?.id ?? '')
}

/**
 * Fail with the status and the start of the body, so a refusal reads itself.
 *
 * @param resp The response.
 * @param what What was attempted.
 */
async function expectOk(resp: APIResponse, what: string): Promise<void> {
	expect(resp.ok(), `${what}: ${resp.status()} ${(await resp.text()).slice(0, 300)}`).toBeTruthy()
}

/**
 * The app's production version: its uuid and the register its records live in.
 *
 * A compiled automation reads and writes the records of the version's own
 * register (`openbuild-<slug>` for the seeded fixture), so the record the
 * manual run acts on is created there, not in buildiq's own register.
 *
 * @param request The admin request context.
 * @return The version uuid and register slug.
 */
async function productionVersion(request: APIRequestContext): Promise<{ uuid: string, register: string }> {
	const resp = await request.get(`${BASE_URL}/index.php/apps/buildiq/api/applications/${APP_SLUG}/versions`, { headers: HEADERS })
	await expectOk(resp, 'list the app versions')
	const versions = await resp.json()
	const production = (Array.isArray(versions) ? versions : []).find((v: any) => v?.slug === 'production')
	const uuid = uuidOf(production ?? {})
	expect(uuid, `${APP_SLUG} needs a production version`).not.toBe('')
	const register = String(production?.register ?? '')
	expect(register, `${APP_SLUG}'s production version names its register`).not.toBe('')
	return { uuid, register }
}

/**
 * Create an automation (disabled); returns its uuid.
 *
 * @param request    The admin request context.
 * @param definition The automation, without app/version.
 * @return The automation uuid.
 */
async function createAutomation(request: APIRequestContext, definition: Record<string, any>): Promise<string> {
	const resp = await request.post(`${BASE_URL}${AUTOMATIONS}`, {
		headers: HEADERS,
		data: { applicationSlug: APP_SLUG, versionUuid: (await productionVersion(request)).uuid, enabled: false, ...definition },
	})
	await expectOk(resp, `create automation ${definition.slug}`)
	const uuid = uuidOf(await resp.json())
	expect(uuid).not.toBe('')
	createdAutomations.push(uuid)
	return uuid
}

/**
 * Open the Automations page on the fixture app's production version.
 *
 * The app picker is an NcSelect whose option splits a label of ten characters
 * or more into two spans, so its accessible name is mangled ("Hello Wo rld");
 * match the option on its `[title]` instead (see automations-rbac.spec.ts).
 *
 * @param page The page.
 */
async function openAutomationsPage(page: Page): Promise<void> {
	await suppressSupportDialog(page)
	await page.goto('/apps/buildiq/automations', { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('.automations-page', { timeout: 30_000 })
	await dismissOverlays(page)
	await page.getByRole('combobox', { name: /application/i }).click()
	await page.getByRole('option').filter({ has: page.locator(`[title="${APP_TITLE}"]`) }).first().click()
	await page.getByRole('combobox', { name: /version/i }).click()
	await page.getByRole('option').first().click()
}

/**
 * The Automations page row of one automation.
 *
 * @param page The page.
 * @param name The automation's name.
 * @return The row.
 */
function automationRow(page: Page, name: string) {
	return page.getByTestId('automation-row').filter({ hasText: name })
}

test.describe('logic-automation-run-log: the runs of an automation', () => {
	test.describe.configure({ mode: 'serial', timeout: 90_000 })

	test.beforeAll(async ({ request }) => {
		state.ran = await createAutomation(request, {
			slug: RAN.slug,
			name: RAN.name,
			trigger: { type: 'manual' },
			actions: [{
				type: 'object-op',
				operation: 'update',
				schema: SCHEMA,
				match: { uuid: '{{uuid}}' },
				fieldMapping: { body: 'Run by hand for the run log' },
			}],
		})
		await expectOk(await request.post(`${BASE_URL}${AUTOMATIONS}/${state.ran}/compile`, { headers: HEADERS }), 'compile the manual automation')
		await expectOk(await request.post(`${BASE_URL}${AUTOMATIONS}/${state.ran}/enable`, { headers: HEADERS }), 'enable it')

		state.never = await createAutomation(request, {
			slug: NEVER.slug,
			name: NEVER.name,
			trigger: { type: 'manual' },
			actions: [{ type: 'object-op', operation: 'update', schema: SCHEMA, match: { uuid: '{{uuid}}' }, fieldMapping: { body: 'Never run' } }],
		})

		state.notify = await createAutomation(request, {
			slug: NOTIFY.slug,
			name: NOTIFY.name,
			trigger: { type: 'object-created', schema: SCHEMA },
			actions: [{
				type: 'send-notification',
				channels: ['nextcloud'],
				// Recipient records, the x-openregister-notifications shape the
				// automation schema and the editor use (the editor's default).
				recipients: [{ kind: 'object-acl', permission: 'manage' }],
				// A subject per language, as the editor writes it.
				subject: { en: 'New message', nl: 'Nieuw bericht' },
			}],
		})

		state.records = `/index.php/apps/openregister/api/objects/${(await productionVersion(request)).register}/${SCHEMA}`
		const record = await request.post(`${BASE_URL}${state.records}`, { headers: HEADERS, data: { title: `pw-runs-${RUN_ID}` } })
		await expectOk(record, `create a ${SCHEMA} record`)
		state.record = uuidOf(await record.json())
		createdRecords.push(state.record)
	})

	test.afterAll(async ({ request }) => {
		for (const uuid of createdAutomations) {
			await request.post(`${BASE_URL}${AUTOMATIONS}/${uuid}/disable`, { headers: HEADERS }).catch(() => {})
			await request.delete(`${BASE_URL}${AUTOMATIONS}/${uuid}`, { headers: HEADERS }).catch(() => {})
		}
		for (const uuid of createdRecords) {
			await request.delete(`${BASE_URL}${state.records}/${uuid}`, { headers: HEADERS }).catch(() => {})
		}
	})

	test('REQ-BQRL-001, REQ-BQRL-002: a manual run is recorded and an editor reads it, newest first, with its summary', async ({ request }) => {
		const run = await request.post(`${BASE_URL}${AUTOMATIONS}/${state.ran}/run`, { headers: HEADERS, data: { object: state.record } })
		if (run.status() === 503) {
			state.runSkipReason = 'OpenRegister cannot start a manual flow for an app user yet (503)'
		}
		test.skip(state.runSkipReason !== '', state.runSkipReason)
		expect(run.status(), `start the run: ${(await run.text()).slice(0, 300)}`).toBe(202)

		const runsUrl = `${BASE_URL}${AUTOMATIONS}/${state.ran}/runs`
		const probe = await request.get(runsUrl, { headers: HEADERS })
		if (probe.status() === 503) {
			state.runSkipReason = 'the run log is not available on this instance (503)'
		}
		test.skip(state.runSkipReason !== '', state.runSkipReason)

		let body: Record<string, any> = {}
		await expect.poll(async () => {
			const resp = await request.get(runsUrl, { headers: HEADERS })
			body = resp.ok() ? await resp.json() : {}
			// The run is listed 'waiting' as soon as it is queued and completed
			// from its flow run once OpenRegister's flow worker has run it.
			return (body.results ?? []).filter((r: any) => r?.subject?.uuid === state.record && r?.outcome !== 'waiting').length
		}, { timeout: 120_000, message: 'one finished run record for this automation and record' }).toBe(1)

		const recorded = body.results.find((r: any) => r?.subject?.uuid === state.record)
		expect(recorded.trigger).toBe('manual')
		expect(recorded.outcome).toBe('succeeded')
		expect(typeof recorded.durationMs).toBe('number')
		expect(recorded.steps, 'one step per action').toHaveLength(1)
		expect(recorded.steps[0].outcome).toBe('succeeded')
		expect(recorded, 'the record carries no input payload').not.toHaveProperty('payload')

		const starts = (body.results as Array<{ startedAt: string }>).map((r) => Date.parse(r.startedAt))
		expect(starts, 'newest first').toEqual([...starts].sort((a, b) => b - a))
		expect(body.summary).toMatchObject({ lastOutcome: 'succeeded' })
		expect(body.summary.lastRunAt).toBeTruthy()

		const failed = await request.get(`${runsUrl}?outcome=failed`, { headers: HEADERS })
		await expectOk(failed, 'filter the runs on failures')
		const failedRuns = (await failed.json()).results ?? []
		expect(failedRuns.every((r: any) => r.outcome === 'failed'), 'only failed runs come back').toBeTruthy()
		expect(failedRuns.map((r: any) => r?.subject?.uuid)).not.toContain(state.record)
	})

	test('REQ-BQRL-002: a colleague without a role gets the refusal status gives, and no run', async ({ playwright }) => {
		const outsider = await playwright.request.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: OUTSIDER_USER, password: OUTSIDER_PASS, send: 'always' },
			extraHTTPHeaders: HEADERS,
		})
		try {
			const status = await outsider.get(`${AUTOMATIONS}/${state.ran}/status`)
			const runs = await outsider.get(`${AUTOMATIONS}/${state.ran}/runs`)
			expect(status.ok(), 'the status endpoint refuses a colleague without a role').toBeFalsy()
			expect(runs.status(), 'the runs endpoint refuses with the same status').toBe(status.status())
			const body = await runs.json().catch(() => ({}))
			expect(body.results ?? [], 'no run is returned').toEqual([])
			const statusBody = await status.json().catch(() => ({}))
			if (statusBody.error !== undefined) {
				expect(body.error, 'and with the same refusal').toBe(statusBody.error)
			}
		} finally {
			await outsider.dispose()
		}
	})

	test('REQ-BQRL-003: "Runs" lists the run, and its detail reads like a test result', async ({ page }) => {
		test.skip(state.runSkipReason !== '', state.runSkipReason)
		await openAutomationsPage(page)

		const row = automationRow(page, RAN.name)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row.getByTestId('last-run'), 'the row shows its last run').toContainText(/Last run/i)

		await row.getByTestId('runs-button').click()
		const dialog = page.locator('.automation-runs')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		const runRow = dialog.getByTestId('run-row').filter({ hasText: state.record })
		await expect(runRow).toHaveCount(1, { timeout: 15_000 })
		await expect(runRow).toContainText('manual')
		await expect(runRow).toContainText('succeeded')
		await expect(runRow.getByRole('link', { name: state.record }), 'the object cell links to the object').toBeVisible()

		// The row toggles its detail; its object cell is a link that opens the
		// record (and stops the toggle), so click the first cell, not the middle.
		await runRow.locator('td').first().click()
		await expect(runRow).toHaveAttribute('aria-expanded', 'true')
		const detail = dialog.getByTestId('run-detail')
		await expect(detail.getByRole('columnheader', { name: 'Action', exact: true })).toBeVisible()
		await expect(detail.getByRole('columnheader', { name: 'Outcome', exact: true })).toBeVisible()
		await expect(detail.getByTestId('run-step')).toHaveCount(1)
		await expect(detail.locator('.automation-runs__duration')).toContainText(/Duration \d+ ms/)
	})

	test('REQ-BQRL-003: an automation that never ran says so, with no empty table', async ({ page }) => {
		await openAutomationsPage(page)

		const row = automationRow(page, NEVER.name)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await expect(row.getByTestId('last-run')).toHaveText('No run in the last 90 days')

		await row.getByTestId('runs-button').click()
		const dialog = page.locator('.automation-runs')
		await expect(dialog.getByTestId('runs-empty')).toHaveText('No run in the last 90 days', { timeout: 15_000 })
		await expect(dialog.getByTestId('runs-table')).toHaveCount(0)
	})

	test('REQ-BQRL-004: a notification\'s runs come from OpenRegister\'s record', async ({ page, request }) => {
		// Enabling compiles the notification with its outcome key; the record
		// created after it fires the notification, which OpenRegister records.
		await expectOk(await request.post(`${BASE_URL}${AUTOMATIONS}/${state.notify}/enable`, { headers: HEADERS }), 'enable the notification')
		const created = await request.post(`${BASE_URL}${state.records}`, { headers: HEADERS, data: { title: `pw-runs-notify-${RUN_ID}` } })
		await expectOk(created, `create a ${SCHEMA} record that fires the notification`)
		const recordUuid = uuidOf(await created.json())
		createdRecords.push(recordUuid)

		await expect.poll(async () => {
			const resp = await request.get(`${BASE_URL}${AUTOMATIONS}/${state.notify}/runs`, { headers: HEADERS })
			const body = resp.ok() ? await resp.json() : {}
			return (body?.results ?? []).filter((run: any) => run?.source === 'openregister' && run?.subject?.uuid === recordUuid).length
		}, { timeout: 30_000, message: 'OpenRegister records the notification it sent' }).toBeGreaterThan(0)

		await openAutomationsPage(page)
		const row = automationRow(page, NOTIFY.name)
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.getByTestId('runs-button').click()

		const dialog = page.locator('.automation-runs')
		await expect(dialog.getByTestId('runs-entirely-in-openregister')).toHaveCount(0)
		const run = dialog.getByTestId('run-row').filter({ hasText: recordUuid })
		await expect(run).toBeVisible({ timeout: 15_000 })
		await run.click()
		await expect(dialog.getByTestId('run-step').first()).toContainText('send-notification')
	})
})
