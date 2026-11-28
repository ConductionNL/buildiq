// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: the script step of an automation (change logic-script-step,
 * task T07, REQ-BQSS-001, REQ-BQSS-002 and REQ-BQSS-004). An owner adds a
 * script and tests it on sample items, an editor is refused a change, and
 * without the code runner the kind is offered disabled with the reason.
 *
 * WHY EVERY TEST IS GUARDED, AND BY WHAT
 * --------------------------------------
 * Two things this spec needs do not exist yet, and they are guarded apart
 * because they become true at different times:
 *
 *   1. OpenRegister's code runner (openregister#2066). The script runs only
 *      there (D4), so the owner, editor and test-run scenarios skip while
 *      OpenRegister's node catalogue lists no code step, and the "no runner"
 *      scenario skips once it does. This is a property of the instance, so it
 *      is a runtime `test.skip` with the reason.
 *   2. Buildiq's own half: the "Allow script steps" setting (T01), the
 *      `script-step` action kind (T02), the owner-only write (T03), the
 *      compile (T04) and the script form with "Test script" (T05). Until
 *      those ship there is no "Run a script" to pick, so every test is marked
 *      `test.fixme` naming the open task. Remove each `test.fixme` as its task
 *      lands; the body below it is the test that should then pass.
 *
 * THE FIXTURE
 * -----------
 * `beforeAll` clones `incident-reporter` into a `pw-` app, makes the admin an
 * owner and `rbac-editor` (tests/e2e/global-setup.ts) an editor, and turns
 * "Allow script steps" on through the app config. The setting's key is T01's to
 * name; BUILDIQ_E2E_SCRIPT_SETTING overrides the assumed `allow_script_steps`.
 * `afterAll` puts the setting back and removes the automations and the app.
 *
 * @spec openspec/changes/logic-script-step/specs/automation-script-step/spec.md
 * @e2e automation-script-step/requirement-the-composer-offers-a-script-step-req-bqss-001/an-owner-adds-a-script-step
 * @e2e automation-script-step/requirement-the-composer-offers-a-script-step-req-bqss-001/no-runner-no-script-step
 * @e2e automation-script-step/requirement-only-app-owners-change-script-steps-req-bqss-002/an-editor-cannot-change-the-code
 * @e2e automation-script-step/requirement-a-maker-tests-a-script-on-sample-items-req-bqss-004/a-test-shows-the-result
 */

import type { Page } from '@playwright/test'
import type { TemplateApp } from './support/templateApp.ts'

import { expect, test } from '@playwright/test'
import { suppressSupportDialog } from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'
import {
	adminPage,
	api,
	cloneTemplateApp,
	deleteApp,
	fixtureSlug,
	idOf,
} from './support/templateApp.ts'

const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const EDITOR_USER = process.env.NC_RBAC_EDITOR_USER ?? 'rbac-editor'
const EDITOR_PASS = process.env.NC_RBAC_EDITOR_PASS ?? 'RbacEditor-1!'
const SETTING = process.env.BUILDIQ_E2E_SCRIPT_SETTING ?? 'allow_script_steps'
const CONFIG = `/ocs/v2.php/apps/provisioning_api/api/v1/config/apps/buildiq/${SETTING}`
const AUTOMATIONS = '/index.php/apps/buildiq/api/automations'
const OR_OBJECTS = '/index.php/apps/openregister/api/objects'
const RUN = Date.now().toString(36)
const APP_NAME = `PW script step ${RUN}`

const JOIN_NAME = 'return items.map((item) => ({ ...item, json: { ...item.json, naam: `${item.json.voornaam} ${item.json.achternaam}` } }))'
const UPPERCASE_POSTCODE = 'return items.map((item) => ({ ...item, json: { ...item.json, postcode: String(item.json.postcode).toUpperCase() } }))'

/** Why every test waits on buildiq's own half of the change. */
const NOT_SHIPPED = 'buildiq has no script step yet: logic-script-step T01 to T05 are open'

test.describe.configure({ mode: 'serial', timeout: 90_000 })

let app: TemplateApp | null = null
let runnerInstalled = false
let previousSetting: string | null = null
const created: string[] = []

/**
 * Whether OpenRegister's node catalogue lists a code step. The node id is
 * openregister#2066's to name (D2), so any entry whose id or type says code.
 *
 * @param page An admin page.
 * @return Whether the runner is there.
 */
async function codeStepListed(page: Page): Promise<boolean> {
	const catalogue = await api(page, 'GET', '/index.php/apps/openregister/api/flow/node-catalog')
	if (catalogue.status !== 200) {
		return false
	}
	const nodes = (catalogue.json?.results ?? catalogue.json ?? []) as Array<Record<string, unknown>>
	return Array.isArray(nodes) && nodes.some((node) => /(^|[.-])code([.-]|$)/i.test(`${node?.id ?? ''} ${node?.type ?? ''}`))
}

/**
 * Store an app config value through the provisioning API, or delete it.
 *
 * @param page  An admin page.
 * @param value The value, or null to delete the key.
 * @return Resolves once written.
 */
async function writeSetting(page: Page, value: string | null): Promise<void> {
	await page.evaluate(
		async ({ url, value }) => {
			const token = (window as any).OC?.requestToken ?? ''
			await fetch(url, {
				method: value === null ? 'DELETE' : 'POST',
				headers: { 'OCS-APIRequest': 'true', requesttoken: token, 'Content-Type': 'application/x-www-form-urlencoded' },
				body: value === null ? undefined : new URLSearchParams({ value }).toString(),
			})
		},
		{ url: CONFIG, value },
	)
}

/**
 * Create an automation holding a script step, as the composer saves it.
 *
 * @param page A page signed in as an owner.
 * @param code The script.
 * @return The automation uuid.
 */
async function createScriptAutomation(page: Page, code: string): Promise<string> {
	const versions = await api(page, 'GET', `/index.php/apps/buildiq/api/applications/${app!.slug}/versions`)
	const list = Array.isArray(versions.json) ? versions.json : (versions.json?.results ?? [])
	const version = list.find((v: any) => v?.slug === 'production') ?? list[0]
	const response = await api(page, 'POST', AUTOMATIONS, {
		slug: `pw-script-${RUN}-${created.length}`,
		name: `PW script ${RUN} ${created.length}`,
		applicationSlug: app!.slug,
		versionUuid: idOf(version),
		enabled: false,
		trigger: { type: 'manual' },
		actions: [{ type: 'script-step', code, timeoutSeconds: 10, note: 'Joins the names' }],
	})
	expect(response.status, JSON.stringify(response.json).slice(0, 300)).toBeLessThan(300)
	const uuid = idOf(response.json)
	created.push(uuid)
	return uuid
}

/**
 * The stored script of an automation's first script step.
 *
 * @param page A signed-in page.
 * @param uuid The automation uuid.
 * @return The code, or null.
 */
async function storedCode(page: Page, uuid: string): Promise<string | null> {
	const read = await api(page, 'GET', `${OR_OBJECTS}/buildiq/automation/${uuid}`)
	const step = ((read.json?.actions ?? []) as Array<Record<string, any>>).find((a) => a?.type === 'script-step')
	return step ? String(step.code ?? '') : null
}

/**
 * Open the composer for a new automation on the fixture app.
 *
 * @param page The admin page.
 * @return Resolves once the dialog is up.
 */
async function openComposer(page: Page): Promise<void> {
	await suppressSupportDialog(page)
	await page.goto('/index.php/apps/buildiq/automations')
	await page.waitForSelector('.automations-page', { timeout: 20_000 })
	await page.getByRole('combobox', { name: /application/i }).click()
	await page.getByRole('option', { name: APP_NAME }).first().click()
	await page.getByRole('combobox', { name: /version/i }).click()
	await page.getByRole('option').first().click()
	await page.getByRole('button', { name: /new automation/i }).click()
	await expect(page.locator('.automation-edit')).toBeVisible()
}

/**
 * Add an action row and open its kind list.
 *
 * @param page The admin page, with the composer open.
 * @return The "Run a script" option.
 */
async function openActionKinds(page: Page) {
	await page.getByRole('button', { name: /add action/i }).click()
	await page.locator('[data-testid="action-row"]').last().getByRole('combobox', { name: /action type/i }).click()
	return page.getByRole('option', { name: /run a script/i })
}

/**
 * Replace the text of the script editor (CnJsonViewer, a CodeMirror editor in
 * text mode).
 *
 * @param page The admin page.
 * @param code The script.
 * @return Resolves once typed.
 */
async function typeScript(page: Page, code: string): Promise<void> {
	const editor = page.locator('[data-testid="action-row"]').last().locator('.cm-content').first()
	await editor.click()
	await page.keyboard.press('ControlOrMeta+A')
	await page.keyboard.insertText(code)
}

test.beforeAll(async ({ browser }) => {
	const page = await adminPage(browser)
	try {
		runnerInstalled = await codeStepListed(page)
		app = await cloneTemplateApp(page, fixtureSlug('script-step'), APP_NAME)
		await grantAppRoles(page, app.slug, { owners: [`user:${ADMIN_USER}`], editors: [`user:${EDITOR_USER}`] })

		const before = await api(page, 'GET', `${CONFIG}?format=json`)
		previousSetting = before.status === 200 ? String(before.json?.ocs?.data?.data ?? '') : null
		await writeSetting(page, 'yes')
	} finally {
		await page.close()
	}
})

test.afterAll(async ({ browser }) => {
	const page = await adminPage(browser)
	try {
		for (const uuid of created) {
			await api(page, 'DELETE', `${AUTOMATIONS}/${uuid}`).catch(() => undefined)
		}
		await writeSetting(page, previousSetting)
		await deleteApp(page, app?.uuid ?? '')
	} finally {
		await page.close()
	}
})

test('REQ-BQSS-001: an owner adds a script step', async ({ page }) => {
	test.fixme(true, NOT_SHIPPED)
	test.skip(!runnerInstalled, 'OpenRegister lists no code step: its code runner (openregister#2066) is not installed')

	await openComposer(page)
	await page.getByRole('textbox', { name: /^name$/i }).fill(`PW joins names ${RUN}`)
	await page.getByRole('combobox', { name: /^when$/i }).click()
	await page.getByRole('option', { name: /^manual$/i }).click()

	const option = await openActionKinds(page)
	await expect(option, 'with the runner and the setting on, the kind is offered').toBeEnabled()
	await option.click()
	await typeScript(page, JOIN_NAME)
	await page.getByRole('button', { name: /^save$/i }).click()
	await expect(page.locator('.automation-edit')).toHaveCount(0, { timeout: 15_000 })

	const list = await api(page, 'GET', `${OR_OBJECTS}/buildiq/automation?_limit=200&applicationSlug=${app!.slug}`)
	const saved = ((list.json?.results ?? []) as Array<Record<string, any>>).find((a) => a?.name === `PW joins names ${RUN}`)
	expect(saved, 'the automation is stored').toBeTruthy()
	created.push(idOf(saved))
	const step = (saved!.actions as Array<Record<string, any>>).find((a) => a?.type === 'script-step')
	expect(step, 'the automation saves with a script step').toBeTruthy()
	expect(step!.code).toContain('naam')
	expect(step!.code).toContain('voornaam')
	expect(step!.timeoutSeconds).toBeGreaterThanOrEqual(1)
	expect(step!.timeoutSeconds).toBeLessThanOrEqual(30)
})

test('REQ-BQSS-001: no runner, no script step', async ({ page }) => {
	test.fixme(true, NOT_SHIPPED)
	test.skip(runnerInstalled, 'OpenRegister has a code runner here, so the kind is not disabled for that reason')

	await openComposer(page)
	const option = await openActionKinds(page)
	await expect(option, '"Run a script" is listed, but cannot be picked').toBeVisible()
	await expect(option).toHaveAttribute('aria-disabled', 'true')
	await page.keyboard.press('Escape')
	await expect(page.locator('.automation-edit').getByText(/code runner is not installed/i).first()).toBeVisible()
})

test('REQ-BQSS-002: an editor cannot change the code', async ({ page, playwright }) => {
	test.fixme(true, NOT_SHIPPED)
	test.skip(!runnerInstalled, 'OpenRegister lists no code step: its code runner (openregister#2066) is not installed')

	await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
	const uuid = await createScriptAutomation(page, JOIN_NAME)
	const read = await api(page, 'GET', `${OR_OBJECTS}/buildiq/automation/${uuid}`)
	const automation = read.json as Record<string, any>

	// The editor, from an EMPTY storage state.
	const editor = await playwright.request.newContext({
		baseURL: E2E_BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: { username: EDITOR_USER, password: EDITOR_PASS, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
	try {
		// An editor may read the code.
		const seen = await editor.get(`${OR_OBJECTS}/buildiq/automation/${uuid}`)
		expect(seen.ok(), 'an editor reads the script step').toBeTruthy()

		const changed = (automation.actions as Array<Record<string, any>>).map((a) =>
			a?.type === 'script-step' ? { ...a, code: 'return []' } : a)
		const refused = await editor.put(`${AUTOMATIONS}/${uuid}`, {
			data: { ...automation, actions: changed },
		})
		expect(refused.status(), 'the save is refused').toBe(403)
		expect(await refused.text()).toContain('Only app owners can change a script step.')

		const compile = await editor.post(`${AUTOMATIONS}/${uuid}/compile`)
		expect(compile.status(), 'the compile route refuses an editor too').toBe(403)
	} finally {
		await editor.dispose()
	}

	expect(await storedCode(page, uuid), 'the stored code is unchanged').toBe(JOIN_NAME)
})

test('REQ-BQSS-004: a test shows the result', async ({ page }) => {
	test.fixme(true, NOT_SHIPPED)
	test.skip(!runnerInstalled, 'OpenRegister lists no code step: its code runner (openregister#2066) is not installed')

	await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
	const records = `${OR_OBJECTS}/${app!.register}/${app!.schema}?_limit=200`
	const before = JSON.stringify((await api(page, 'GET', records)).json?.results ?? [])

	await openComposer(page)
	const option = await openActionKinds(page)
	await option.click()
	await typeScript(page, UPPERCASE_POSTCODE)

	const row = page.locator('[data-testid="action-row"]').last()
	await row.getByRole('textbox', { name: /sample items/i }).fill('[{"json": {"postcode": "1234ab"}}]')
	await row.getByRole('button', { name: /test script/i }).click()

	// The runner's answer, shown in the composer.
	await expect(row.getByText('1234AB').first()).toBeVisible({ timeout: 30_000 })

	// Nothing was written.
	await page.keyboard.press('Escape')
	expect(JSON.stringify((await api(page, 'GET', records)).json?.results ?? []), 'no record changed').toBe(before)
})
