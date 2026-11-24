// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: automations started by a mail or by another system's call
 * (change logic-email-and-webhook-triggers, task T06, REQ-BQTR-002 and
 * REQ-BQTR-003), against integriq in mock mode.
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * InboundTriggerDeclarationsTest proves buildiq writes the right integriq
 * objects against a store double. It cannot prove that integriq, given those
 * objects, actually starts the compiled flow: that a mail on the subscribed
 * mailbox becomes a record, that a signed call to the shown address changes
 * one, and that an unsigned call changes nothing. Only the three apps together
 * can show that.
 *
 * THE FIXTURE
 * -----------
 * `beforeAll` clones the `incident-reporter` template into a `pw-` app, so the
 * record steps write to `incident`, a schema of the app's own (the flow backend
 * refuses any other). `afterAll` removes the automations and the app. The spec's
 * "melding" is an incident here, and its "status gereed" is `status: resolved`,
 * the schema's own word for a finished job.
 *
 * INTEGRIQ MOCK MODE, AND WHAT THIS SPEC ASSUMES OF IT
 * ---------------------------------------------------
 * A mock-mode integriq source carries `configuration.mock: true` and talks to
 * nothing outside the instance. The spec needs from it:
 *
 *   - a mailbox source in mock mode. Named by INTEGRIQ_E2E_MAILBOX (its uuid),
 *     or else the first `source` of type `mailbox` with `configuration.mock`
 *     in the `integriq` register;
 *   - a way to make that mailbox receive a mail. A mock mailbox reads its
 *     messages from integriq's own `mail_message` objects instead of a server,
 *     so the spec seeds one there (INTEGRIQ_E2E_MAIL_SEED overrides the path);
 *   - the key of the webhook's consumer, readable by an administrator on the
 *     consumer object (`authorizationConfiguration`), sent in the header named
 *     by INTEGRIQ_E2E_KEY_HEADER (default `Authorization`).
 *
 * Each test skips, saying which, when integriq is not installed or when the
 * piece it needs is missing. Buildiq itself serves no route here (REQ-BQTR-004):
 * every outside call goes to integriq's address, which the composer shows.
 *
 * @spec openspec/changes/archive/2026-11-24-logic-email-and-webhook-triggers/specs/automation-inbound-triggers/spec.md
 * @e2e automation-inbound-triggers/requirement-mail-starts-the-automations-flow-req-bqtr-002/a-report-by-mail-becomes-a-record
 * @e2e automation-inbound-triggers/requirement-a-webhook-starts-the-automations-flow-req-bqtr-003/the-planning-system-reports-a-finished-job
 * @e2e automation-inbound-triggers/requirement-a-webhook-starts-the-automations-flow-req-bqtr-003/a-call-without-the-key-does-nothing
 */

import type { Page } from '@playwright/test'
import type { TemplateApp } from './support/templateApp.ts'

import { expect, test } from '@playwright/test'
import { E2E_BASE_URL } from './support/baseUrl.ts'
import {
	adminPage,
	api,
	cloneTemplateApp,
	deleteApp,
	fixtureSlug,
	idOf,
} from './support/templateApp.ts'

const OR_OBJECTS = '/index.php/apps/openregister/api/objects'
const AUTOMATIONS = '/index.php/apps/buildiq/api/automations'
const INTEGRIQ = process.env.INTEGRIQ_E2E_APP_ID ?? 'integriq'
const MAIL_SEED = process.env.INTEGRIQ_E2E_MAIL_SEED ?? `${OR_OBJECTS}/${INTEGRIQ}/mail_message`
const KEY_HEADER = process.env.INTEGRIQ_E2E_KEY_HEADER ?? 'Authorization'
const RUN = Date.now().toString(36)
const SUBJECT = `Losse stoeptegel ${RUN}`

test.describe.configure({ mode: 'serial', timeout: 120_000 })

let app: TemplateApp | null = null
let integriqInstalled = false
let mailbox = ''
let mailAutomation = ''
let webhookAutomation = ''
let webhookSlug = ''
let consumer = ''

/**
 * The uuid of the app's production version.
 *
 * @param page An admin page.
 * @param slug The app slug.
 * @return The version uuid.
 */
async function productionVersion(page: Page, slug: string): Promise<string> {
	const { status, json } = await api(page, 'GET', `/index.php/apps/buildiq/api/applications/${slug}/versions`)
	expect(status, `versions of ${slug}`).toBe(200)
	const versions = Array.isArray(json) ? json : (json?.results ?? [])
	const production = versions.find((v: any) => v?.slug === 'production') ?? versions[0]
	const uuid = idOf(production)
	expect(uuid, `${slug} needs a production version`).not.toBe('')
	return uuid
}

/**
 * Create, compile and enable an automation, as the composer's Save and Apply do.
 *
 * @param page       An admin page.
 * @param automation The automation, without app and version.
 * @return The automation uuid.
 */
async function applyAutomation(page: Page, automation: Record<string, unknown>): Promise<string> {
	const created = await api(page, 'POST', AUTOMATIONS, {
		applicationSlug: app!.slug,
		versionUuid: await productionVersion(page, app!.slug),
		enabled: false,
		...automation,
	})
	expect(created.status, `create: ${JSON.stringify(created.json).slice(0, 300)}`).toBeLessThan(300)
	const uuid = idOf(created.json)
	const compiled = await api(page, 'POST', `${AUTOMATIONS}/${uuid}/compile`, {})
	expect(compiled.status, `compile: ${JSON.stringify(compiled.json).slice(0, 300)}`).toBe(200)
	const enabled = await api(page, 'POST', `${AUTOMATIONS}/${uuid}/enable`, {})
	expect(enabled.status, `enable: ${JSON.stringify(enabled.json).slice(0, 300)}`).toBe(200)
	return uuid
}

/**
 * The integriq objects an apply wrote, from the automation's provenance.
 *
 * @param page An admin page.
 * @param uuid The automation uuid.
 * @return The `inbound` provenance: schema slug => uuid.
 */
async function inboundOf(page: Page, uuid: string): Promise<Record<string, string>> {
	const read = await api(page, 'GET', `${OR_OBJECTS}/buildiq/automation/${uuid}`)
	return (read.json?.provenance?.inbound ?? {}) as Record<string, string>
}

/**
 * Create an incident the way a clerk would.
 *
 * @param page An admin page.
 * @param location Where it is, to tell the records apart.
 * @return The record uuid.
 */
async function createIncident(page: Page, location: string): Promise<string> {
	const created = await api(page, 'POST', `${OR_OBJECTS}/${app!.register}/${app!.schema}`, {
		reportedBy: 'e2e',
		incidentType: 'equipment',
		severity: 'low',
		location,
	})
	expect(created.status, JSON.stringify(created.json).slice(0, 300)).toBeLessThan(300)
	return idOf(created.json)
}

/**
 * One field of a record, or a marker string when it cannot be read.
 *
 * @param page  An admin page.
 * @param uuid  The record uuid.
 * @param field The field.
 * @return The value.
 */
async function fieldOf(page: Page, uuid: string, field: string): Promise<unknown> {
	const read = await api(page, 'GET', `${OR_OBJECTS}/${app!.register}/${app!.schema}/${uuid}`)
	return read.status === 200 ? (read.json?.[field] ?? null) : `HTTP ${read.status}`
}

/**
 * The address integriq serves the webhook automation at, as the composer
 * shows it (`webhookAddress()` in src/dialogs/automationInboundTrigger.js).
 *
 * @return The absolute URL.
 */
function webhookAddress(): string {
	const parts = ['buildiq', app!.slug, webhookSlug].map((part) => encodeURIComponent(part))
	return `${E2E_BASE_URL}/index.php/apps/${INTEGRIQ}/api/endpoint/${parts.join('/')}`
}

/**
 * The consumer's key, read as an administrator from the consumer integriq
 * issued. Buildiq never stores or shows it (D3), so this is the only place.
 *
 * @param page An admin page.
 * @return The key, or '' when the consumer does not show one.
 */
async function consumerKey(page: Page): Promise<string> {
	const read = await api(page, 'GET', `${OR_OBJECTS}/${INTEGRIQ}/consumer/${consumer}`)
	const config = read.json?.authorizationConfiguration
	if (typeof config === 'string') {
		return config
	}
	if (Array.isArray(config)) {
		return String(config[0]?.key ?? config[0] ?? '')
	}
	return String(config?.key ?? config?.apiKey ?? config?.token ?? config?.keys?.[0] ?? '')
}

test.beforeAll(async ({ browser }) => {
	const page = await adminPage(browser)
	try {
		const probe = await api(page, 'GET', `${OR_OBJECTS}/${INTEGRIQ}/source?_limit=200`)
		integriqInstalled = probe.status === 200
		if (!integriqInstalled) {
			return
		}
		const sources = (probe.json?.results ?? []) as Array<Record<string, any>>
		mailbox = process.env.INTEGRIQ_E2E_MAILBOX
			?? idOf(sources.find((s) => s?.type === 'mailbox' && s?.configuration?.mock === true))

		app = await cloneTemplateApp(page, fixtureSlug('inbound'), `PW inbound triggers ${RUN}`)

		if (mailbox !== '') {
			mailAutomation = await applyAutomation(page, {
				slug: `pw-mail-${RUN}`,
				name: `PW mail creates an incident ${RUN}`,
				trigger: { type: 'email-received', source: mailbox, subjectContains: RUN },
				actions: [{
					type: 'object-op',
					operation: 'create',
					schema: app.schema,
					fieldMapping: {
						description: '{{subject}}',
						reportedBy: '{{from}}',
						incidentType: 'mail',
						severity: 'low',
					},
				}],
			})
		}

		webhookSlug = `pw-hook-${RUN}`
		webhookAutomation = await applyAutomation(page, {
			slug: webhookSlug,
			name: `PW planning reports a finished job ${RUN}`,
			trigger: { type: 'webhook-called', authorization: 'api-key', bodyExample: '{"opdracht": "42"}' },
			actions: [{
				type: 'object-op',
				operation: 'update',
				schema: app.schema,
				match: { uuid: '{{opdracht}}' },
				fieldMapping: { status: 'resolved' },
			}],
		})
		consumer = (await inboundOf(page, webhookAutomation)).consumer ?? ''
	} finally {
		await page.close()
	}
})

test.afterAll(async ({ browser }) => {
	const page = await adminPage(browser)
	try {
		// Removing the automation removes the integriq objects its apply wrote
		// (REQ-BQTR-002), so this also cleans integriq up.
		for (const uuid of [mailAutomation, webhookAutomation]) {
			if (uuid !== '') {
				await api(page, 'DELETE', `${AUTOMATIONS}/${uuid}`).catch(() => undefined)
			}
		}
		await deleteApp(page, app?.uuid ?? '')
	} finally {
		await page.close()
	}
})

test('REQ-BQTR-002: a report by mail becomes a record', async ({ page }) => {
	test.skip(!integriqInstalled, 'integriq is not installed on this instance')
	test.skip(mailbox === '', 'no mock-mode mailbox source: set INTEGRIQ_E2E_MAILBOX')

	await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })

	// The apply wrote one subscription on the mailbox, with the flow as action.
	const inbound = await inboundOf(page, mailAutomation)
	expect(inbound.event_subscription, 'the apply records the mail subscription in provenance').toBeTruthy()

	const seeded = await api(page, 'POST', MAIL_SEED, {
		source: mailbox,
		from: 'bewoner@example.nl',
		to: ['meldingen@gemeente.nl'],
		subject: SUBJECT,
		receivedAt: new Date().toISOString(),
		bodyText: 'Voor nummer 12 ligt een stoeptegel los.',
		attachments: [],
	})
	test.skip(seeded.status === 404, `the mock mailbox takes no seeded mail at ${MAIL_SEED}`)
	expect(seeded.status, JSON.stringify(seeded.json).slice(0, 300)).toBeLessThan(300)

	let made: Record<string, any> | undefined
	await expect
		.poll(async () => {
			const list = await api(page, 'GET', `${OR_OBJECTS}/${app!.register}/${app!.schema}?_limit=200`)
			made = ((list.json?.results ?? []) as Array<Record<string, any>>).find((r) => r?.description === SUBJECT)
			return Boolean(made)
		}, { timeout: 90_000, intervals: [2_000, 5_000], message: 'the mail must create an incident titled by its subject' })
		.toBe(true)

	// The run received the mail as input: the sender reached the record too.
	expect(made!.reportedBy).toBe('bewoner@example.nl')
})

test('REQ-BQTR-003: a call without the key does nothing', async ({ page, playwright }) => {
	test.skip(!integriqInstalled, 'integriq is not installed on this instance')

	await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
	const record = await createIncident(page, `unsigned-${RUN}`)

	// An outside system: no session, no cookie, no key.
	const caller = await playwright.request.newContext({ storageState: { cookies: [], origins: [] } })
	try {
		const refused = await caller.post(webhookAddress(), { data: { opdracht: record } })
		expect([401, 403], `integriq must refuse an unsigned call, got ${refused.status()}`).toContain(refused.status())
	} finally {
		await caller.dispose()
	}

	// No flow run starts, so the record keeps its status. Give a run that
	// should not exist the time a real one takes.
	await page.waitForTimeout(10_000)
	expect(await fieldOf(page, record, 'status')).not.toBe('resolved')
})

test('REQ-BQTR-003: the planning system reports a finished job', async ({ page, playwright }) => {
	test.skip(!integriqInstalled, 'integriq is not installed on this instance')

	await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
	expect(consumer, 'the apply records the webhook consumer in provenance').not.toBe('')
	const key = await consumerKey(page)
	test.skip(key === '', 'the mock-mode consumer shows no key to an administrator')

	const record = await createIncident(page, `signed-${RUN}`)
	const caller = await playwright.request.newContext({ storageState: { cookies: [], origins: [] } })
	try {
		const accepted = await caller.post(webhookAddress(), {
			headers: { [KEY_HEADER]: key },
			data: { opdracht: record },
		})
		expect(accepted.ok(), `the signed call: ${accepted.status()} ${(await accepted.text()).slice(0, 300)}`).toBeTruthy()
	} finally {
		await caller.dispose()
	}

	await expect
		.poll(async () => fieldOf(page, record, 'status'), {
			timeout: 60_000,
			intervals: [2_000, 5_000],
			message: 'the signed call must run the flow that finishes the job',
		})
		.toBe('resolved')
})
