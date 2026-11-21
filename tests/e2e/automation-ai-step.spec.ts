/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `ai-llm-steps-and-computed-fields`, task T06 (REQ-BQLS-002, REQ-BQLS-004):
 * with Hermiq and a stub agent, a classify automation fills `categorie` on a
 * new record, and an AI field fills on create.
 *
 * WHAT IT NEEDS, AND WHY IT IS NAMED RATHER THAN BUILT
 * ----------------------------------------------------
 * A model answer cannot be produced by this suite: it needs a Hermiq agent
 * backed by a stub provider that answers deterministically. The instance
 * provides it and names it through the environment:
 *
 *   AI_E2E_AGENT     uuid of a Hermiq agent without tools whose stub provider
 *                    answers `{"label": "verlichting"}` to a classify prompt
 *                    and a non-empty text to any other prompt
 *   AI_E2E_APP       slug of a buildiq application the editor below may edit
 *   AI_E2E_REGISTER  that application's register slug
 *   AI_E2E_SCHEMA    a schema in it with a string `omschrijving`, a
 *                    `categorie` choice list of exactly
 *                    `afval, verlichting, overig`, and a string `samenvatting`
 *
 * The suite skips, saying which, when one is missing or when OpenRegister's
 * node catalogue does not list `hermiq.agent-step`.
 *
 * The tests run as a NON-ADMIN editor in an empty storage state
 * (`rbac-editor`, provisioned by tests/e2e/global-setup.ts), granted on the app
 * by the admin context in `beforeAll`.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { grantAppRoles } from './support/appRoles.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const EDITOR_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const EDITOR_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'

const AGENT = process.env.AI_E2E_AGENT ?? ''
const APP_SLUG = process.env.AI_E2E_APP ?? ''
const REGISTER = process.env.AI_E2E_REGISTER ?? ''
const SCHEMA = process.env.AI_E2E_SCHEMA ?? ''
const LABELS = ['afval', 'verlichting', 'overig']
const CLASSIFY_SLUG = 'e2e-ai-categorise'
const AI_FIELD_SLUG = 'e2e-ai-field-samenvatting'

const missingFixture = [
	['AI_E2E_AGENT', AGENT],
	['AI_E2E_APP', APP_SLUG],
	['AI_E2E_REGISTER', REGISTER],
	['AI_E2E_SCHEMA', SCHEMA],
].filter(([, value]) => value === '').map(([name]) => name)

/**
 * Log in through the login form.
 *
 * @param page Playwright page in an empty storage state.
 * @param user User id.
 * @param pass Password.
 */
async function loginAs(page: Page, user: string, pass: string): Promise<void> {
	await page.goto(`${NEXTCLOUD_URL}/index.php/login`)
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(pass)
	await page.locator('button[type="submit"]').first().click()
	await page.waitForSelector('#header, header.header', { timeout: 20_000 })
	if (/\/login(\?|$|\/)/.test(page.url())) {
		throw new Error(`Login as ${user} appears to have failed, still on ${page.url()}.`)
	}
}

/**
 * Call a Nextcloud route from the page, with its session and request token.
 *
 * @param page   A logged-in page.
 * @param method HTTP method.
 * @param path   Path under /index.php.
 * @param body   JSON body, if any.
 * @return Status and parsed body.
 */
async function call(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, path, body }) => {
			const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(`/index.php${path}`, {
				method,
				headers: { requesttoken: tok, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await resp.text()
			let data: unknown = text
			try {
				data = JSON.parse(text)
			} catch {
				// Not JSON; keep the text for the failure message.
			}
			return { status: resp.status, data }
		},
		{ method, path, body },
	)
}

/**
 * The uuid of the app's first (draft) version.
 *
 * @param page A logged-in page.
 * @return The version uuid.
 */
async function draftVersionUuid(page: Page): Promise<string> {
	const { status, data } = await call(page, 'GET', `/apps/buildiq/api/applications/${APP_SLUG}/versions`)
	expect(status, `versions of ${APP_SLUG}`).toBe(200)
	const versions = Array.isArray(data) ? data : (data?.results ?? [])
	const draft = versions.find((v: any) => v?.slug !== 'production') ?? versions[0]
	const uuid = draft?.['@self']?.id ?? draft?.id ?? draft?.uuid
	expect(uuid, 'a version to author on').toBeTruthy()
	return String(uuid)
}

/**
 * Delete earlier runs' automations by slug.
 *
 * @param page  A logged-in page.
 * @param slugs The slugs.
 */
async function deleteAutomations(page: Page, slugs: string[]): Promise<void> {
	const { data } = await call(page, 'GET', `/apps/openregister/api/objects/buildiq/automation?_limit=200&applicationSlug=${APP_SLUG}`)
	for (const row of (data?.results ?? [])) {
		if (slugs.includes(row?.slug)) {
			await call(page, 'DELETE', `/apps/buildiq/api/automations/${row.id ?? row['@self']?.id}`)
		}
	}
}

/**
 * Create and compile an automation as the logged-in user.
 *
 * @param page       A logged-in page.
 * @param automation The automation.
 */
async function createAndCompile(page: Page, automation: Record<string, unknown>): Promise<void> {
	const created = await call(page, 'POST', '/apps/buildiq/api/automations', automation)
	expect(created.status, JSON.stringify(created.data).slice(0, 300)).toBe(201)
	const uuid = created.data?.id ?? created.data?.uuid ?? created.data?.['@self']?.id
	const compiled = await call(page, 'POST', `/apps/buildiq/api/automations/${uuid}/compile`, {})
	expect(compiled.status, JSON.stringify(compiled.data).slice(0, 300)).toBe(200)
}

/**
 * Create a record and wait until a field of it is filled by the flow.
 *
 * @param page  A logged-in page.
 * @param field The field the AI fills.
 * @return The filled value.
 */
async function createAndAwait(page: Page, field: string): Promise<unknown> {
	const created = await call(page, 'POST', `/apps/openregister/api/objects/${REGISTER}/${SCHEMA}`, {
		omschrijving: 'Lantaarnpaal kapot bij de school',
	})
	expect([200, 201]).toContain(created.status)
	const uuid = created.data?.id ?? created.data?.uuid ?? created.data?.['@self']?.id
	let value: unknown
	await expect.poll(async () => {
		const read = await call(page, 'GET', `/apps/openregister/api/objects/${REGISTER}/${SCHEMA}/${uuid}`)
		value = read.data?.[field]
		return typeof value === 'string' && value !== ''
	}, { timeout: 60_000, intervals: [1_000, 2_000, 5_000] }).toBe(true)
	return value
}

test.describe('ai-llm-steps-and-computed-fields: AI steps and AI fields run', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	let nodeListed = false

	test.beforeAll(async ({ browser }) => {
		if (missingFixture.length > 0) {
			return
		}
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await page.goto(`${NEXTCLOUD_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			const catalogue = await call(page, 'GET', '/apps/openregister/api/flow/node-catalog')
			nodeListed = (catalogue.data?.results ?? []).some((node: any) => node?.id === 'hermiq.agent-step')
			await grantAppRoles(page, APP_SLUG, { owners: [`user:${ADMIN_USER}`], editors: [`user:${EDITOR_USER}`] })
			await deleteAutomations(page, [CLASSIFY_SLUG, AI_FIELD_SLUG])
		} finally {
			await admin.close()
		}
	})

	test('REQ-BQLS-002: a classify automation fills categorie on a new record', async ({ page }) => {
		// @e2e openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-an-ai-step-compiles-to-a-flow-that-writes-the-answer-back-req-bqls-002
		test.skip(missingFixture.length > 0, `set ${missingFixture.join(', ')} to run AI step e2e`)
		test.skip(!nodeListed, 'OpenRegister lists no hermiq.agent-step: install Hermiq on the instance')

		await loginAs(page, EDITOR_USER, EDITOR_PASS)
		await page.goto(`${NEXTCLOUD_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		await createAndCompile(page, {
			slug: CLASSIFY_SLUG,
			name: 'E2E categorise reports',
			applicationSlug: APP_SLUG,
			versionUuid: await draftVersionUuid(page),
			enabled: true,
			origin: 'composer',
			trigger: { type: 'object-created', schema: SCHEMA },
			actions: [{ type: 'ai-step', preset: 'classify', agent: AGENT, sourceFields: ['omschrijving'], targetField: 'categorie', labels: LABELS }],
		})

		expect(LABELS).toContain(await createAndAwait(page, 'categorie'))
	})

	test('REQ-BQLS-004: an AI field fills when a record is created', async ({ page }) => {
		// @e2e openspec/changes/ai-llm-steps-and-computed-fields/specs/automation-ai-steps/spec.md#requirement-a-schema-field-can-be-filled-by-ai-req-bqls-004
		test.skip(missingFixture.length > 0, `set ${missingFixture.join(', ')} to run AI step e2e`)
		test.skip(!nodeListed, 'OpenRegister lists no hermiq.agent-step: install Hermiq on the instance')

		await loginAs(page, EDITOR_USER, EDITOR_PASS)
		await page.goto(`${NEXTCLOUD_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
		await createAndCompile(page, {
			slug: AI_FIELD_SLUG,
			name: 'AI field samenvatting',
			applicationSlug: APP_SLUG,
			versionUuid: await draftVersionUuid(page),
			enabled: true,
			origin: 'ai-field',
			trigger: { type: 'object-created', schema: SCHEMA },
			actions: [{ type: 'ai-step', preset: 'summarise', agent: AGENT, sourceFields: ['omschrijving'], targetField: 'samenvatting' }],
		})

		const summary = await createAndAwait(page, 'samenvatting')
		expect(String(summary).length).toBeGreaterThan(0)

		// The automations list hides AI field rows; the schema designer edits them.
		await page.goto(`${NEXTCLOUD_URL}/index.php/apps/buildiq/automations`)
		await page.waitForSelector('.automations-page', { timeout: 20_000 })
		await expect(page.getByText('AI field samenvatting')).toHaveCount(0)
	})
})
