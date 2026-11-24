/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `logic-automation-actions-that-run` (T10):
 * automations whose steps change records or decide with a rule set compile to
 * an OpenRegister flow that RUNS.
 *
 *   1. A record step on "object created" writes a record (REQ-BQAR-002).
 *   2. A rule decision writes the table's decision onto the record (REQ-BQAR-005).
 *   3. A manual automation runs from a record, once OpenRegister can start a
 *      manual flow for an app user (REQ-BQAR-006). Until it can, the listing
 *      route answers 503 and that scenario skips with that reason. A colleague
 *      without a role on the app is refused with 403 either way.
 *
 * The automations are written through buildiq's own `/api/automations`
 * endpoints, as the product's client does, then compiled and enabled. The
 * record steps UPDATE the triggering record (matched on its uuid), so a step on
 * "object created" cannot create records that trigger it again. Every
 * automation this spec creates is deleted again in `afterAll`.
 *
 * Fixture: the seeded `hello-world` app and its `hello-message` schema, and the
 * seeded `loan-eligibility` decision table (its `decision` output defaults to
 * "deny" when a record carries none of its inputs). Override with
 * NC_BUILDIQ_TEST_SLUG, BUILDIQ_E2E_FLOW_SCHEMA and BUILDIQ_E2E_FLOW_RULE_SET.
 * The colleague is the globalSetup fixture user `rbac-outsider`
 * (NC_RBAC_OUTSIDER_USER / NC_RBAC_OUTSIDER_PASS), from an empty storage state.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-automation-actions-that-run/specs/automation-designer/spec.md
 * @e2e automation-designer/requirement-a-record-step-runs-outside-an-approval-req-bqar-002/a-new-permit-creates-an-inspection-record
 * @e2e automation-designer/requirement-a-step-decides-with-a-rule-set-req-bqar-005/the-fee-category-is-decided-on-creation
 * @e2e automation-designer/requirement-app-users-run-manual-automations-from-a-record-req-bqar-006/a-clerk-runs-an-automation-on-one-record
 * @e2e automation-designer/requirement-app-users-run-manual-automations-from-a-record-req-bqar-006/a-user-of-another-app-cannot-run-it
 */

import type { APIRequestContext, APIResponse } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = process.env.NC_BUILDIQ_TEST_SLUG ?? 'hello-world'
const SCHEMA = process.env.BUILDIQ_E2E_FLOW_SCHEMA ?? 'hello-message'
const RULE_SET = process.env.BUILDIQ_E2E_FLOW_RULE_SET ?? 'loan-eligibility'
const OUTSIDER_USER = process.env.NC_RBAC_OUTSIDER_USER ?? 'rbac-outsider'
const OUTSIDER_PASS = process.env.NC_RBAC_OUTSIDER_PASS ?? 'RbacOutsider-1!'

const HEADERS = { 'OCS-APIRequest': 'true' }
const AUTOMATIONS = '/index.php/apps/buildiq/api/automations'
const RECORDS = `/index.php/apps/openregister/api/objects/buildiq/${SCHEMA}`
const RUN_ID = Date.now().toString(36)

/** Uuids of the automations this run created, removed in afterAll. */
const created: string[] = []

/**
 * The uuid of an OpenRegister object or buildiq record response.
 *
 * @param {object} body The decoded response.
 * @return {string} The uuid, or ''.
 */
function uuidOf(body: Record<string, any>): string {
	return String(body?.['@self']?.id ?? body?.uuid ?? body?.id ?? '')
}

/**
 * Fail with the status and the start of the body, so a refusal reads itself.
 *
 * @param {APIResponse} resp The response.
 * @param {string} what What was attempted.
 * @return {Promise<void>}
 */
async function expectOk(resp: APIResponse, what: string): Promise<void> {
	expect(resp.ok(), `${what}: ${resp.status()} ${(await resp.text()).slice(0, 300)}`).toBeTruthy()
}

/**
 * The uuid of the app's production version.
 *
 * @param {APIRequestContext} request The admin request context.
 * @return {Promise<string>} The version uuid.
 */
async function productionVersion(request: APIRequestContext): Promise<string> {
	const resp = await request.get(
		`${BASE_URL}/index.php/apps/buildiq/api/applications/${APP_SLUG}/versions`,
		{ headers: HEADERS },
	)
	await expectOk(resp, 'list the app versions')
	const versions = await resp.json()
	const production = (Array.isArray(versions) ? versions : []).find((v: any) => v?.slug === 'production')
	const uuid = uuidOf(production ?? {})
	expect(uuid, `${APP_SLUG} needs a production version`).not.toBe('')
	return uuid
}

/**
 * Create, compile and enable an automation; returns its uuid.
 *
 * @param {APIRequestContext} request The admin request context.
 * @param {object} definition The automation, without slug/app/version.
 * @return {Promise<string>} The automation uuid.
 */
async function createAutomation(request: APIRequestContext, definition: Record<string, any>): Promise<string> {
	const versionUuid = await productionVersion(request)
	const resp = await request.post(`${BASE_URL}${AUTOMATIONS}`, {
		headers: HEADERS,
		data: {
			applicationSlug: APP_SLUG,
			versionUuid,
			enabled: false,
			...definition,
		},
	})
	await expectOk(resp, `create automation ${definition.slug}`)
	const uuid = uuidOf(await resp.json())
	expect(uuid).not.toBe('')
	created.push(uuid)

	await expectOk(
		await request.post(`${BASE_URL}${AUTOMATIONS}/${uuid}/compile`, { headers: HEADERS }),
		'compile it to a flow',
	)
	await expectOk(
		await request.post(`${BASE_URL}${AUTOMATIONS}/${uuid}/enable`, { headers: HEADERS }),
		'enable it',
	)
	return uuid
}

/**
 * Disable an automation, so the next scenario's writes do not run it.
 *
 * @param {APIRequestContext} request The admin request context.
 * @param {string} uuid The automation uuid.
 * @return {Promise<void>}
 */
async function disableAutomation(request: APIRequestContext, uuid: string): Promise<void> {
	await request.post(`${BASE_URL}${AUTOMATIONS}/${uuid}/disable`, { headers: HEADERS })
}

/**
 * Create a record of the fixture schema; returns its uuid.
 *
 * @param {APIRequestContext} request The request context.
 * @param {string} title The record title.
 * @return {Promise<string>} The record uuid.
 */
async function createRecord(request: APIRequestContext, title: string): Promise<string> {
	const resp = await request.post(`${BASE_URL}${RECORDS}`, { headers: HEADERS, data: { title } })
	await expectOk(resp, `create a ${SCHEMA} record`)
	const uuid = uuidOf(await resp.json())
	expect(uuid).not.toBe('')
	return uuid
}

/**
 * Poll a record's field until it holds the expected value.
 *
 * @param {APIRequestContext} request The request context.
 * @param {string} uuid The record uuid.
 * @param {string} field The field.
 * @param {string} expected The value the flow writes.
 * @param {string} message The assertion message.
 * @return {Promise<void>}
 */
async function expectField(request: APIRequestContext, uuid: string, field: string, expected: string, message: string): Promise<void> {
	await expect
		.poll(
			async () => {
				const resp = await request.get(`${BASE_URL}${RECORDS}/${uuid}`, { headers: HEADERS })
				return resp.ok() ? ((await resp.json())?.[field] ?? null) : `HTTP ${resp.status()}`
			},
			{ timeout: 30_000, message },
		)
		.toBe(expected)
}

test.describe('automations run as OpenRegister flows', () => {
	test.describe.configure({ mode: 'serial', timeout: 90_000 })

	test.afterAll(async ({ request }) => {
		for (const uuid of created) {
			await request.delete(`${BASE_URL}${AUTOMATIONS}/${uuid}`, { headers: HEADERS }).catch(() => {})
		}
	})

	test('REQ-BQAR-002: a record step on object created writes a record', async ({ request }) => {
		const uuid = await createAutomation(request, {
			slug: `pw-flow-record-${RUN_ID}`,
			name: `PW flow record step ${RUN_ID}`,
			trigger: { type: 'object-created', schema: SCHEMA },
			actions: [{
				type: 'object-op',
				operation: 'update',
				schema: SCHEMA,
				match: { uuid: '{{uuid}}' },
				fieldMapping: { body: 'Written by the flow for {{title}}' },
			}],
		})

		const title = `pw-flow-record-${RUN_ID}`
		const record = await createRecord(request, title)
		await expectField(request, record, 'body', `Written by the flow for ${title}`, 'the record step must write the record')
		await disableAutomation(request, uuid)
	})

	test('REQ-BQAR-005: a rule decision writes the table\'s decision onto the record', async ({ request }) => {
		const uuid = await createAutomation(request, {
			slug: `pw-flow-decision-${RUN_ID}`,
			name: `PW flow rule decision ${RUN_ID}`,
			trigger: { type: 'object-created', schema: SCHEMA },
			actions: [{
				type: 'rule-decision',
				ruleSetSlug: RULE_SET,
				inputMapping: {},
				outputMapping: { decision: 'body' },
			}],
		})

		const record = await createRecord(request, `pw-flow-decision-${RUN_ID}`)
		await expectField(request, record, 'body', 'deny', 'the rule decision must write the table\'s decision')
		await disableAutomation(request, uuid)
	})

	test('REQ-BQAR-006: a manual automation runs from a record; an outsider is refused', async ({ request, playwright }) => {
		const uuid = await createAutomation(request, {
			slug: `pw-flow-manual-${RUN_ID}`,
			name: `PW flow manual run ${RUN_ID}`,
			trigger: { type: 'manual' },
			actions: [{
				type: 'object-op',
				operation: 'update',
				schema: SCHEMA,
				match: { uuid: '{{uuid}}' },
				fieldMapping: { body: 'Run by hand' },
			}],
		})
		const record = await createRecord(request, `pw-flow-manual-${RUN_ID}`)

		// A colleague with no role on the app, from an EMPTY storage state.
		const outsider = await playwright.request.newContext({
			baseURL: BASE_URL,
			storageState: { cookies: [], origins: [] },
			httpCredentials: { username: OUTSIDER_USER, password: OUTSIDER_PASS, send: 'always' },
			extraHTTPHeaders: HEADERS,
		})
		try {
			const refused = await outsider.post(`${AUTOMATIONS}/${uuid}/run`, { data: { object: record } })
			expect(refused.status(), 'a colleague without a role on the app is refused').toBe(403)
		} finally {
			await outsider.dispose()
		}

		const listing = await request.get(
			`${BASE_URL}${AUTOMATIONS}/manual?app=${encodeURIComponent(APP_SLUG)}&schema=${encodeURIComponent(SCHEMA)}`,
			{ headers: HEADERS },
		)
		test.skip(listing.status() === 503, 'OpenRegister cannot start a manual flow for an app user yet (503)')
		await expectOk(listing, 'list the manual automations')
		const listed = ((await listing.json())?.results ?? []).map((row: any) => uuidOf(row))
		expect(listed, 'the enabled manual automation is offered on the record').toContain(uuid)

		const run = await request.post(`${BASE_URL}${AUTOMATIONS}/${uuid}/run`, {
			headers: HEADERS,
			data: { object: record },
		})
		expect(run.status(), `starting the flow: ${(await run.text()).slice(0, 300)}`).toBe(202)
		await expectField(request, record, 'body', 'Run by hand', 'the manual run must write the record')
		await disableAutomation(request, uuid)
	})
})
