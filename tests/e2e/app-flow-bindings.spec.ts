/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * logic-shared-flows-and-automations T07 (REQ-BQSF-002, REQ-BQSF-005): an owner
 * attaches an OpenRegister flow to an app in App settings, adds a "Run on"
 * entry (one of the app's schemas, when it is updated), updates a record of
 * that schema, and sees the run on the app's detail page.
 *
 * Runs on the seeded `hello-world` app as the admin the global setup signs
 * in (an owner of every app). The fixture flow is a manual trigger straight
 * to the end, so a run of it changes nothing; it and the binding are removed
 * at the end, and removing the binding removes buildiq's wrapper flow.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE } from './support/baseUrl.ts'
import { ensureVersionChain } from './support/versionChain.ts'

const TEST_SLUG = 'hello-world'
const OR_API = `${BASE}/index.php/apps/openregister/api`
// Nextcloud refuses a cookie-authenticated write without it (412, CSRF check).
const OCS = { 'OCS-APIRequest': 'true' }
const FLOW_NAME = `PW run-on fixture ${Date.now()}`
const WRAPPER_PREFIX = `aut-attach-${TEST_SLUG}-`

/**
 * The seeded app's object.
 *
 * @param page The page.
 * @return The Application object.
 */
async function application(page: Page): Promise<Record<string, any>> {
	const lookup = await page.request.get(
		`${OR_API}/objects/buildiq/built-app?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		{ headers: OCS },
	)
	expect(lookup.ok(), 'the application lookup must succeed').toBeTruthy()
	const apps = (await lookup.json()).results || []
	expect(apps.length, `${TEST_SLUG} must be seeded`).toBeGreaterThan(0)
	return apps[0]
}

/**
 * Every OpenRegister flow on the instance.
 *
 * @param page The page.
 * @return The flows.
 */
async function flows(page: Page): Promise<Array<Record<string, any>>> {
	const resp = await page.request.get(`${OR_API}/flows?_limit=500`, { headers: OCS })
	expect(resp.ok()).toBeTruthy()
	const body = await resp.json()
	return (Array.isArray(body) ? body : body.results || []) as Array<Record<string, any>>
}

/**
 * A value for every property a schema requires, so a record of it saves.
 *
 * @param page   The page.
 * @param schema The schema id or slug.
 * @return The record body.
 */
async function requiredValues(page: Page, schema: string): Promise<Record<string, unknown>> {
	const resp = await page.request.get(`${OR_API}/schemas/${encodeURIComponent(schema)}`, { headers: OCS })
	const definition = resp.ok() ? await resp.json() : {}
	const properties: Record<string, { type?: string, enum?: unknown[] }> = definition?.properties ?? {}
	const body: Record<string, unknown> = {}
	for (const name of (definition?.required ?? []) as string[]) {
		const property = properties[name] ?? {}
		if (Array.isArray(property.enum) && property.enum.length > 0) {
			body[name] = property.enum[0]
			continue
		}
		body[name] = ({ number: 1, integer: 1, boolean: true, array: [], object: {} } as Record<string, unknown>)[property.type ?? ''] ?? 'e2e'
	}
	return body
}

test.describe('app-flow-bindings', () => {
	let flowUuid = ''
	let appId = ''
	let bindingsBefore: unknown[] = []

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			if (appId !== '') {
				await page.request.patch(`${OR_API}/objects/buildiq/built-app/${appId}`, {
					headers: OCS,
					data: { flows: bindingsBefore },
					failOnStatusCode: false,
				})
			}
			if (flowUuid !== '') {
				await page.request.delete(`${OR_API}/flows/${flowUuid}`, { headers: OCS, failOnStatusCode: false })
			}
		} finally {
			await page.close()
		}
	})

	// @e2e app-flow-bindings::a-record-change-runs-the-attached-flow
	test('a flow attached with "Run on" runs when a record is updated, and the app page shows it', async ({ page }) => {
		test.setTimeout(240_000)
		await page.goto(`${BASE}/apps/buildiq/`)
		await dismissOverlays(page)
		await ensureVersionChain(page, TEST_SLUG, 'Hello World')

		const created = await page.request.post(`${OR_API}/flows`, {
			headers: OCS,
			data: {
				name: FLOW_NAME,
				description: 'Attached with a Run on entry by tests/e2e/app-flow-bindings.spec.ts.',
				enabled: true,
				nodes: [
					{ id: 'start', type: 'openregister.trigger-manual', config: {} },
					{ id: 'done', type: 'openregister.end', config: {} },
				],
				edges: [{ from: 'start', to: 'done' }],
			},
		})
		expect(created.status(), 'creating the fixture flow must succeed').toBe(201)
		const createdBody = await created.json()
		flowUuid = String(createdBody.uuid || createdBody.id || '')
		expect(flowUuid).toBeTruthy()
		// OpenRegister runs a flow only from its published version, and the
		// wrapper's sub-flow refuses an unpublished one: publish the fixture, as
		// a maker's flow is published before it is attached.
		const published = await page.request.post(`${OR_API}/flows/${flowUuid}/publish`, { headers: OCS, failOnStatusCode: false })
		expect(published.status(), `publishing the fixture flow: ${(await published.text()).slice(0, 300)}`).toBeLessThan(300)

		const app = await application(page)
		appId = String(app.uuid || app.id)
		bindingsBefore = Array.isArray(app.flows) ? app.flows : []

		// Attach the flow and add where it runs, in App settings.
		await page.goto(`${BASE}/apps/buildiq/applications/${appId}`)
		await dismissOverlays(page)
		await page.waitForSelector('.ob-detail-header', { timeout: 20_000 })
		await page.locator('[data-testid="cn-action-app-settings-action"]').first().click()

		const combo = page.getByRole('combobox', { name: /flows this app is made of/i })
		await expect(combo).toBeEnabled({ timeout: 30_000 })
		await combo.click()
		await combo.fill(FLOW_NAME)
		// The option shows the name split for its ellipsis ("PW run-on f" + "ixture ...")
		// and carries the whole name as its title.
		await page.getByRole('option').filter({ has: page.getByTitle(FLOW_NAME, { exact: true }) }).first().click()
		await page.keyboard.press('Escape')

		const runOn = page.locator(`[data-test="app-settings-run-on-${flowUuid}"]`)
		await expect(runOn, 'a picked flow gets its own "Run on" editor').toBeVisible({ timeout: 20_000 })
		await runOn.locator('[data-test="app-settings-run-on-add"]').click()
		const row = runOn.locator('[data-test="app-settings-run-on-row"]').first()
		await row.getByRole('combobox', { name: /^Schema$/ }).click()
		await page.getByRole('option').first().click()
		await row.getByRole('combobox', { name: /^When$/ }).click()
		await page.getByRole('option', { name: /^Updated$/ }).click()

		// The binding is stored with its entry, and buildiq wires a wrapper flow.
		let entry: Record<string, any> | undefined
		await expect.poll(async () => {
			const stored = await application(page)
			const binding = (stored.flows || []).find((b: Record<string, any>) => b.flow === flowUuid)
			entry = binding && (binding.runOn || [])[0]
			return entry ? entry.event : null
		}, { timeout: 30_000, message: 'the binding is saved with an "updated" entry' }).toBe('object.updated')

		let wrapper: Record<string, any> | undefined
		await expect.poll(async () => {
			wrapper = (await flows(page)).find((flow) => String(flow.name || '').startsWith(WRAPPER_PREFIX)
				&& JSON.stringify(flow.nodes || []).includes(flowUuid))
			return Boolean(wrapper)
		}, { timeout: 30_000, message: `a wrapper flow ${WRAPPER_PREFIX}… starts the attached flow` }).toBe(true)

		const trigger = (wrapper!.nodes || []).find((node: Record<string, any>) => node.type === 'openregister.trigger-object')
		expect(trigger.config).toMatchObject({ event: 'object.updated', schema: entry!.schema })
		const subFlow = (wrapper!.nodes || []).find((node: Record<string, any>) => node.type === 'openregister.sub-flow')
		expect(subFlow.config).toMatchObject({ flowId: flowUuid, wait: false })

		// Update a record of that schema.
		const objects = `${OR_API}/objects/${trigger.config.register}/${trigger.config.schema}`
		const record = await page.request.post(objects, {
			headers: OCS,
			data: await requiredValues(page, String(trigger.config.schema)),
			failOnStatusCode: false,
		})
		expect(record.status(), 'a record of the app schema can be created').toBeLessThan(300)
		const recordBody = await record.json()
		const recordId = String(recordBody.id || recordBody.uuid || recordBody['@self']?.id)
		const updated = await page.request.patch(`${objects}/${recordId}`, { headers: OCS, data: {}, failOnStatusCode: false })
		expect(updated.status(), 'the record can be updated').toBeLessThan(300)

		await expect.poll(async () => {
			const runs = await page.request.get(`${OR_API}/flow-runs`, { headers: OCS, params: { flowId: flowUuid, limit: '1' } })
			if (!runs.ok()) {
				return 0
			}
			const body = await runs.json()
			return (Array.isArray(body) ? body : body.results || []).length
		}, { timeout: 120_000, message: 'updating the record runs the attached flow' }).toBeGreaterThan(0)

		await page.request.delete(`${objects}/${recordId}`, { headers: OCS, failOnStatusCode: false })

		// The app's detail page says where the flow runs and how its last run went.
		await page.goto(`${BASE}/apps/buildiq/applications/${appId}`)
		await dismissOverlays(page)
		const widget = page.locator('.ob-flows-widget').first()
		await expect(widget).toContainText(FLOW_NAME, { timeout: 20_000 })
		// The widget lists every flow bound to the app (its wrappers too), so
		// read the line of this spec's flow only.
		const item = widget.locator('.ob-flows-widget__list > li').filter({ hasText: FLOW_NAME })
		await expect(item.locator('.ob-flows-widget__run-on')).toContainText(/is updated/)
		await expect(item.locator('.ob-flows-widget__last-run')).toBeVisible({ timeout: 20_000 })
	})
})
