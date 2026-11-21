/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `pages-action-buttons`, tasks T03, T04
 * and T05 (REQ-BQAB-001 to REQ-BQAB-005): a maker adds typed buttons to a
 * detail page in the page editor, and the running page offers them.
 *
 *   1. An index page action saved with the old `target: navigate` opens in
 *      the page editor as "Go to a page" with that page picked (REQ-BQAB-004).
 *   2. A "change fields" action that sets `status` is refused with a pointer
 *      to status buttons (REQ-BQAB-003).
 *   3. On the detail page the maker adds "Run intake check" (start a flow,
 *      the app's declared flow "Intake check" and its node), shown only when
 *      `status` equals `submitted`, and switches Status buttons on; the
 *      stored manifest holds a `run-node` header action and live lifecycle
 *      actions, and the running page's Actions menu offers the button
 *      (REQ-BQAB-001, REQ-BQAB-002, REQ-BQAB-005).
 *   4. A submitted request offers "Approve" and "Reject"; an approved request
 *      offers neither (REQ-BQAB-003).
 *   5. Choosing "Run intake check" on a submitted request runs the flow node
 *      for that request (REQ-BQAB-002). GUARDED, see below.
 *   6. An approved request does not offer "Run intake check" (REQ-BQAB-005).
 *   7. The maker lists "approve" with a confirm question; on a submitted
 *      request "Approve" asks it, and confirming moves the record to
 *      `approved` (REQ-BQAB-003, the T04 check).
 *
 * Fixture: a register and a `request` schema with the lifecycle submitted,
 * approved, rejected (`x-openregister-lifecycle`), one submitted and one
 * approved request, an OpenRegister flow of two nodes declared on the app as
 * "Intake check", and the app `pw-page-actions` with a request index and a
 * request detail page. All of it is created as the admin through the
 * OpenRegister and buildiq APIs and removed in afterAll. The running page is
 * driven as the admin too: an owner of the app is also an app user, and the
 * lifecycle move needs update rights on the record that a viewer does not
 * hold.
 *
 * GUARDED: OpenRegister runs a flow node directly only for a node type that
 * implements `IFlowDirectlyInvokable`, and OpenRegister itself ships none;
 * another app has to register one. The node type is taken from
 * BUILDIQ_E2E_INVOKABLE_NODE_TYPE (default `openregister.end`), and scenario 5
 * skips when OpenRegister answers 404 for that node's run form, the answer it
 * gives for a node type that has not opted in.
 *
 * Not covered here: the result message the page shows after the node ran,
 * whose text comes from the node, not from buildiq.
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/pages-action-buttons/specs/page-action-authoring/spec.md
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSetupWizard, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = 'pw-page-actions'
const APP_NAME = 'PW Page actions'
const RUN = `pwpa${Date.now().toString(36)}`
const REGISTER = `${RUN}-desk`
const SCHEMA = `${RUN}-request`
const OR_API = `${BASE_URL}/index.php/apps/openregister/api`
const API = `${BASE_URL}/index.php/apps/buildiq/api`
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

const INDEX_ROUTE = '/requests'
const DETAIL_ROUTE = '/requests/:id'
const FLOW_LABEL = 'Intake check'
const NODE_ID = 'check'
const NODE_TYPE = process.env.BUILDIQ_E2E_INVOKABLE_NODE_TYPE ?? 'openregister.end'
const BUTTON = 'Run intake check'
const CONFIRM = 'Approve this request?'

/** Shared between the serial tests. */
const ids = { app: '', flow: '', submitted: '', approved: '' }
/** Absolute URLs this run created, deleted in reverse in afterAll. */
const cleanup: string[] = []

/**
 * The id of an OpenRegister response row.
 *
 * @param body The response body.
 * @return The id, or ''.
 */
function idOf(body: Record<string, any> | null | undefined): string {
	return String(body?.id ?? body?.uuid ?? body?.['@self']?.id ?? '')
}

/**
 * POST a JSON body as the admin, remember the row for cleanup, return it.
 *
 * @param page The admin page.
 * @param url  The collection URL.
 * @param data The body.
 * @return The created row.
 */
async function create(page: Page, url: string, data: Record<string, unknown>): Promise<Record<string, any>> {
	const resp = await page.request.post(url, { headers: HEADERS, data })
	expect(resp.status(), `POST ${url}: ${(await resp.text()).slice(0, 300)}`).toBeLessThan(300)
	const body = await resp.json()
	cleanup.push(`${url}/${idOf(body)}`)
	return body
}

/**
 * The app's stored manifest, read the way the page editor reads it.
 *
 * @param page A logged-in page.
 * @return The manifest.
 */
async function storedManifest(page: Page): Promise<Record<string, any>> {
	const resp = await page.request.get(`${API}/applications/${APP_SLUG}/manifest`, { headers: HEADERS })
	expect(resp.ok(), `read the manifest: ${resp.status()}`).toBeTruthy()
	return resp.json()
}

/**
 * The stored detail page.
 *
 * @param page A logged-in page.
 * @return The page entry.
 */
async function detailPage(page: Page): Promise<Record<string, any>> {
	const manifest = await storedManifest(page)
	return (manifest.pages || []).find((p: { id?: string }) => p.id === 'request-detail') ?? {}
}

/**
 * Open the page editor on one page of the app, picked by its route.
 *
 * @param page   The admin page.
 * @param route  The page's route.
 * @param editor The selector of that page type's editor.
 */
async function openPageEditorOn(page: Page, route: string, editor: string): Promise<void> {
	await page.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('aside[class$="__left"]', { timeout: 60_000 })
	await dismissOverlays(page)
	await page.locator('.page-list-editor__row')
		.filter({ has: page.locator(`input[value="${route}"]`) })
		.first()
		.click()
	await expect(page.locator(editor)).toBeVisible({ timeout: 30_000 })
}

/**
 * Save the pages in the page editor.
 *
 * @param page The admin page in the page editor.
 */
async function savePages(page: Page): Promise<void> {
	await page.getByRole('button', { name: /save pages|save & open preview/i }).first().click()
}

/**
 * Open a request in the running app and its header Actions menu, once the
 * record's lifecycle transitions have been read.
 *
 * @param page     A logged-in page.
 * @param recordId The request.
 */
async function openActionsOn(page: Page, recordId: string): Promise<void> {
	const transitions = page.waitForResponse((resp) => resp.url().includes(`/objects/${recordId}/available-actions`), { timeout: 45_000 })
	await page.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/requests/${recordId}?_version=production`, { waitUntil: 'domcontentloaded' })
	await dismissOverlays(page)
	await transitions
	const trigger = page.getByRole('button', { name: 'Actions', exact: true }).first()
	await expect(trigger, 'the page header has an Actions menu').toBeVisible({ timeout: 45_000 })
	await trigger.click()
}

/**
 * The status of a request as OpenRegister stores it.
 *
 * @param page     A logged-in page.
 * @param recordId The request.
 * @return The status.
 */
async function statusOf(page: Page, recordId: string): Promise<string> {
	const resp = await page.request.get(`${OR_API}/objects/${REGISTER}/${SCHEMA}/${recordId}`, { headers: HEADERS })
	return resp.ok() ? String((await resp.json())?.status ?? '') : ''
}

test.describe('pages-action-buttons: typed buttons on a detail page', () => {
	test.describe.configure({ mode: 'serial', timeout: 150_000 })
	// The page editor is a three-pane desktop surface.
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await suppressSupportDialog(page)
			const probe = await page.request.get(`${OR_API}/registers`, { headers: HEADERS })
			test.skip(probe.status() === 404, 'OpenRegister is not installed here')

			const schema = await create(page, `${OR_API}/schemas`, {
				slug: SCHEMA,
				title: 'Request',
				properties: {
					title: { type: 'string', title: 'Title' },
					status: { type: 'string', title: 'Status', enum: ['submitted', 'approved', 'rejected'] },
				},
				'x-openregister-lifecycle': {
					field: 'status',
					initial: 'submitted',
					createStates: ['submitted', 'approved'],
					states: { submitted: {}, approved: {}, rejected: {} },
					transitions: {
						approve: { from: ['submitted'], to: 'approved' },
						reject: { from: ['submitted'], to: 'rejected' },
					},
				},
			})
			await create(page, `${OR_API}/registers`, { slug: REGISTER, title: 'PW Request desk', schemas: [idOf(schema)] })
			const objects = `${OR_API}/objects/${REGISTER}/${SCHEMA}`
			ids.submitted = idOf(await create(page, objects, { title: 'Dormer at the corner', status: 'submitted' }))
			ids.approved = idOf(await create(page, objects, { title: 'Fence along the canal', status: 'approved' }))

			// A flow the "start a flow" button can pick, published so its node can run.
			const flow = await create(page, `${OR_API}/flows`, {
				name: `PW intake check ${RUN}`,
				description: 'Started from a page button by tests/e2e/page-actions.spec.ts.',
				enabled: true,
				nodes: [
					{ id: 'start', type: 'openregister.trigger-manual', config: {} },
					{ id: NODE_ID, type: NODE_TYPE, config: {} },
				],
				edges: [{ from: 'start', to: NODE_ID }],
			})
			ids.flow = String(flow.uuid ?? idOf(flow))
			// Flows are addressed by uuid, which is not always their `id`.
			cleanup[cleanup.length - 1] = `${OR_API}/flows/${ids.flow}`
			const published = await page.request.post(`${OR_API}/flows/${ids.flow}/publish`, { headers: HEADERS })
			expect(published.status(), `publish the flow: ${(await published.text()).slice(0, 300)}`).toBeLessThan(300)

			// The app, with the flow declared on it and a request index and detail page.
			await ensureApp(page, APP_SLUG, APP_NAME)
			const lookup = await page.request.get(`${OR_API}/objects/buildiq/built-app`, { headers: HEADERS, params: { slug: APP_SLUG, _limit: '1' } })
			const app = ((await lookup.json())?.results ?? []).find((row: Record<string, any>) => row?.slug === APP_SLUG)
			ids.app = idOf(app)
			expect(ids.app, `${APP_SLUG} exists`).not.toBe('')
			const declared = await page.request.patch(`${OR_API}/objects/buildiq/built-app/${ids.app}`, {
				headers: HEADERS,
				data: { flows: [{ flow: ids.flow, label: FLOW_LABEL }] },
			})
			expect(declared.status(), 'declare the flow on the app').toBeLessThan(300)

			const current = await storedManifest(page)
			const pages = [
				...(current.pages || []).filter((p: { id?: string }) => p.id !== 'requests' && p.id !== 'request-detail'),
				{
					id: 'requests',
					route: INDEX_ROUTE,
					type: 'index',
					title: 'Requests',
					config: {
						register: REGISTER,
						schema: SCHEMA,
						rowRoute: 'request-detail',
						// Saved before actions were typed.
						actions: [{ id: 'all-requests', label: 'All requests', target: 'navigate', route: 'requests' }],
					},
				},
				{ id: 'request-detail', route: DETAIL_ROUTE, type: 'detail', title: 'Request', config: { register: REGISTER, schema: SCHEMA } },
			]
			const put = await page.request.put(`${API}/applications/${APP_SLUG}/manifest`, {
				headers: HEADERS,
				data: { manifest: { ...current, pages } },
			})
			expect(put.ok(), `store the pages: ${put.status()} ${(await put.text()).slice(0, 300)}`).toBeTruthy()
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await browser.newPage()
		try {
			await page.goto(`${BASE_URL}/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			if (ids.app !== '') {
				await page.request.delete(`${API}/applications/${ids.app}`, { headers: HEADERS, data: { deleteData: true } }).catch(() => undefined)
			}
			for (const url of cleanup.reverse()) {
				await page.request.delete(url, { headers: HEADERS }).catch(() => undefined)
			}
		} finally {
			await page.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await suppressSetupWizard(page)
	})

	// @e2e page-action-authoring::an-app-built-last-month-opens-cleanly
	test('an index action saved with target navigate opens as "Go to a page" with that page picked', async ({ page }) => {
		await openPageEditorOn(page, INDEX_ROUTE, '.index-page-editor')
		const builder = page.locator('.index-page-editor .action-builder').first()
		await expect(builder.locator('.action-builder__note'), 'the editor says the old action is shown in its new form').toBeVisible()
		await expect(builder.getByLabel('What the button does'), 'the action reads as "Go to a page"').toHaveValue('open-page')
		await expect(builder.getByLabel('Page', { exact: true }), 'with the page it led to').toHaveValue('requests')
	})

	// @e2e page-action-authoring::a-field-patch-cannot-bypass-the-lifecycle
	test('a "change fields" action that sets status is refused with a pointer to status buttons', async ({ page }) => {
		await openPageEditorOn(page, DETAIL_ROUTE, '.detail-page-editor')
		const buttons = page.locator('.detail-page-editor__buttons')
		await buttons.getByRole('button', { name: /add action/i }).click()
		const action = buttons.locator('.action-builder__action').last()
		await action.getByLabel('Action id (e.g. edit)').fill('force-approve')
		await action.getByLabel('What the button does').selectOption({ label: 'Change fields on this record' })
		await action.getByLabel('Field to set').fill('status')
		await action.getByRole('button', { name: /add field/i }).click()

		await expect(action.getByRole('alert'), 'the editor refuses the patch').toHaveText('status moves through the lifecycle. Use a status button instead.')
		await expect(action.locator('.action-builder__key'), 'status is not added to the values').toHaveCount(0)
		// Nothing is saved: the stored page holds no field patch of status.
		const stored = await detailPage(page)
		expect(JSON.stringify(stored.config?.headerActions ?? [])).not.toContain('"status"')
	})

	// @e2e page-action-authoring::a-maker-adds-a-button-that-starts-a-flow
	test('a maker adds a "start a flow" button and status buttons, and the running page offers the button', async ({ page }) => {
		await openPageEditorOn(page, DETAIL_ROUTE, '.detail-page-editor')
		const buttons = page.locator('.detail-page-editor__buttons')
		await buttons.getByRole('button', { name: /add action/i }).click()
		const action = buttons.locator('.action-builder__action').last()
		await action.getByLabel('Action id (e.g. edit)').fill('intake-check')
		await action.getByLabel('Label (i18n key)').fill(BUTTON)
		await action.getByLabel('What the button does').selectOption({ label: 'Start a flow' })
		await action.getByLabel('Flow', { exact: true }).selectOption({ label: FLOW_LABEL })
		await action.getByLabel('Node id').fill(NODE_ID)
		// Shown only while the request is submitted.
		await action.getByLabel('Condition field').selectOption('status')
		await action.getByLabel('Condition operator').selectOption('eq')
		await action.getByLabel('Condition value').fill('submitted')

		await page.locator('.detail-page-editor__status-toggle').check()
		await savePages(page)

		await expect.poll(async () => (await detailPage(page)).config?.headerActions?.length ?? 0, {
			timeout: 30_000,
			message: 'the button reaches the stored manifest',
		}).toBe(1)
		const stored = await detailPage(page)
		expect(stored.config.headerActions[0]).toMatchObject({
			id: 'intake-check',
			label: BUTTON,
			type: 'run-node',
			flowId: ids.flow,
			nodeId: NODE_ID,
			visibleWhen: { field: 'status', op: 'eq', value: 'submitted' },
		})
		expect(stored.config.headerActions[0], 'only the keys run-node allows').not.toHaveProperty('target')
		expect(stored.config.lifecycleActions, 'status buttons follow the live lifecycle').toEqual({ field: 'status' })

		await openActionsOn(page, ids.submitted)
		await expect(page.getByRole('menuitem', { name: BUTTON }), 'the Actions menu offers the button').toBeVisible({ timeout: 20_000 })
	})

	// @e2e page-action-authoring::a-maker-adds-status-buttons
	test('a submitted request offers Approve and Reject, an approved one offers neither', async ({ page }) => {
		await openActionsOn(page, ids.submitted)
		await expect(page.getByRole('menuitem', { name: 'Approve' })).toBeVisible({ timeout: 20_000 })
		await expect(page.getByRole('menuitem', { name: 'Reject' })).toBeVisible()
		await page.keyboard.press('Escape')

		await openActionsOn(page, ids.approved)
		await expect(page.getByRole('menuitem').first(), 'the menu is open').toBeVisible({ timeout: 20_000 })
		await expect(page.getByRole('menuitem', { name: 'Approve' })).toHaveCount(0)
		await expect(page.getByRole('menuitem', { name: 'Reject' })).toHaveCount(0)
	})

	// @e2e page-action-authoring::an-app-user-runs-the-action-from-a-record
	test('choosing the button runs the flow node for the open request', async ({ page }) => {
		const form = await page.request.get(`${OR_API}/flows/${ids.flow}/nodes/${NODE_ID}/run`, { headers: HEADERS })
		test.skip(
			form.status() === 404,
			`OpenRegister does not run a ${NODE_TYPE} node directly: no node type here implements IFlowDirectlyInvokable. Set BUILDIQ_E2E_INVOKABLE_NODE_TYPE to one that does.`,
		)

		await openActionsOn(page, ids.submitted)
		const ran = page.waitForResponse((resp) => resp.request().method() === 'POST'
			&& resp.url().includes(`/flows/${ids.flow}/nodes/${NODE_ID}/run`), { timeout: 45_000 })
		await page.getByRole('menuitem', { name: BUTTON }).click()
		// A node with options of its own asks for them first.
		const runInDialog = page.getByRole('dialog').getByRole('button', { name: 'Run', exact: true })
		if (await runInDialog.waitFor({ state: 'visible', timeout: 5_000 }).then(() => true, () => false)) {
			await runInDialog.click()
		}

		const answer = await ran
		expect(answer.status(), `the node runs: ${(await answer.text()).slice(0, 300)}`).toBe(201)
		expect(answer.request().postDataJSON()?.subject, 'for the open request').toMatchObject({ uuid: ids.submitted })
	})

	// @e2e page-action-authoring::only-submitted-requests-offer-the-check
	test('an approved request does not offer the button', async ({ page }) => {
		await openActionsOn(page, ids.approved)
		await expect(page.getByRole('menuitem').first(), 'the menu is open').toBeVisible({ timeout: 20_000 })
		await expect(page.getByRole('menuitem', { name: BUTTON })).toHaveCount(0)
	})

	// @e2e page-action-authoring::a-maker-adds-status-buttons
	test('a listed status button asks its confirm question and moves the request to approved', async ({ page }) => {
		await openPageEditorOn(page, DETAIL_ROUTE, '.detail-page-editor')
		const status = page.locator('.detail-page-editor__status-buttons')
		await status.getByRole('button', { name: /list a status button/i }).click()
		const row = status.locator('.detail-page-editor__transition').last()
		await row.getByLabel('Transition (e.g. approve)').fill('approve')
		await row.getByLabel('Button label').fill('Approve')
		await row.getByLabel('Confirm question (optional)').fill(CONFIRM)
		await savePages(page)

		await expect.poll(async () => JSON.stringify((await detailPage(page)).config?.lifecycleActions ?? {}), {
			timeout: 30_000,
			message: 'the listed status button reaches the stored manifest',
		}).toContain(CONFIRM)

		await openActionsOn(page, ids.submitted)
		await page.getByRole('menuitem', { name: 'Approve' }).click()
		const dialog = page.getByRole('dialog').filter({ hasText: CONFIRM })
		await expect(dialog, 'the move asks first').toBeVisible({ timeout: 20_000 })
		await dialog.getByRole('button', { name: 'Approve' }).click()

		await expect.poll(() => statusOf(page, ids.submitted), {
			timeout: 30_000,
			message: 'the request is approved through its lifecycle',
		}).toBe('approved')
	})
})
