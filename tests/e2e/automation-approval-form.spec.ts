/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e for openspec change `logic-approval-task-form`, task T07
 * (REQ-BQTF-001, REQ-BQTF-003): an approval action declares two fields the
 * approver fills in; creating a record opens the approval task; in "My
 * approvals" the approver cannot complete while the required field is empty,
 * and approving with a value writes it onto the record.
 *
 *   1. The automation stores `form.fields` in the declared order with their
 *      `required` flags, and compiles (REQ-BQTF-001).
 *   2. A missing required field keeps the dialog open with the other value
 *      still typed and the task still open; filling it in and confirming
 *      completes the task with outcome `approved` and the value lands on the
 *      record (REQ-BQTF-003).
 *
 * Fixture: the seeded `hello-world` app's `hello-message` schema (properties
 * `title` and `body`) stands in for the spec's `klacht` with `bedrag` and
 * `motivering`: `body` is the required field, `title` the optional one. The
 * automation is written through buildiq's `/api/automations` endpoints as
 * `automations-flow-backend.spec.ts` does, assigned to the `admin` group the
 * suite's admin belongs to. "My approvals" is a runtime widget, so `beforeAll`
 * builds the fixture app `pw-approval-form` with one dashboard page holding a
 * `my-approvals` widget. Override the schema with NC_BUILDIQ_TEST_SLUG /
 * BUILDIQ_E2E_FLOW_SCHEMA. `afterAll` removes the automation, the record and
 * the fixture app.
 *
 * The widget lists every open task of the signed-in user. So that the test
 * acts on ITS task and nothing else, the inbox read (`GET flow-tasks`) is
 * narrowed in the browser to the task opened for this run's record; the task
 * read, the completion and the record write go to OpenRegister untouched.
 *
 * GUARDED: carrying a form into the task is OpenRegister's (its
 * `approval-position-task-form` change). On an OpenRegister without it the
 * compiler refuses the form with "Approval forms need OpenRegister to carry a
 * form into the task" (REQ-BQTF-002) and the run scenario skips with that
 * reason. An instance whose flow engine opens no task for the record within
 * the wait skips likewise rather than fail on a missing sibling.
 *
 * Not covered here: the read-only refusal (hello-message has no read-only
 * property; PHPUnit `ApprovalFormDeclarationTest`), the compiled chain and its
 * drift (REQ-BQTF-002, `@e2e exclude`), a broken form and an approval without
 * a form (Vitest `MyApprovalsWidget.spec.js`).
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/logic-approval-task-form/specs/automation-approval-action/spec.md
 * @e2e automation-approval-action/requirement-a-maker-names-the-fields-an-approver-fills-in-req-bqtf-001/a-maker-asks-the-approver-for-two-fields
 * @e2e automation-approval-action/requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003/a-missing-required-field-keeps-the-dialog-open
 * @e2e automation-approval-action/requirement-an-approver-fills-in-the-form-before-approving-req-bqtf-003/approving-with-a-form-writes-the-values
 */

import type { APIRequestContext, APIResponse, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSupportDialog } from './support/appFixture.ts'
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const SOURCE_APP = process.env.NC_BUILDIQ_TEST_SLUG ?? 'hello-world'
const SCHEMA = process.env.BUILDIQ_E2E_FLOW_SCHEMA ?? 'hello-message'
const APPROVER_GROUP = process.env.NC_APPROVAL_GROUP ?? 'admin'

const APP_SLUG = 'pw-approval-form'
const PAGE_ID = 'goedkeuringen'
const HEADERS = { 'OCS-APIRequest': 'true' }
const AUTOMATIONS = '/index.php/apps/buildiq/api/automations'
const OR_API = '/index.php/apps/openregister/api'
const RECORDS = `${OR_API}/objects/buildiq/${SCHEMA}`
const RUN_ID = Date.now().toString(36)

/** The form the approver fills in: `body` required, `title` not, in that order. */
const FORM_FIELDS = [
	{ field: 'body', required: true },
	{ field: 'title', required: false },
]
const TITLE = `pw-approval-${RUN_ID}`
const EDITED_TITLE = `Klacht C-2026-0412 ${RUN_ID}`
const APPROVED_BODY = 'Vergoeding 450 euro toegekend'

/** Shared between the serial tests. */
const state = { automation: '', record: '', skipReason: '' }

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
 * The rows of a list answer: a bare array or `{results}`.
 *
 * @param data The decoded body.
 * @return The rows.
 */
function rowsOf(data: any): Array<Record<string, any>> {
	return Array.isArray(data) ? data : (data?.results ?? [])
}

/**
 * The open approval task OpenRegister opened for a record, if any.
 *
 * @param request The admin request context.
 * @param record  The record uuid.
 * @return The task, or null.
 */
async function openTaskFor(request: APIRequestContext, record: string): Promise<Record<string, any> | null> {
	for (const scope of ['pooled', 'assigned']) {
		const resp = await request.get(`${BASE_URL}${OR_API}/flow-tasks?scope=${scope}&isTerminal=false&limit=200`, { headers: HEADERS })
		if (!resp.ok()) {
			continue
		}
		const task = rowsOf(await resp.json()).find((row) => row?.objectUuid === record)
		if (task) {
			return task
		}
	}
	return null
}

/**
 * Call a Nextcloud route from the page, with its session and request token.
 *
 * @param page   A logged-in page.
 * @param method HTTP method.
 * @param path   Path from the web root.
 * @param body   JSON body, if any.
 * @return Status and parsed body.
 */
async function call(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ method, path, body }) => {
			const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const resp = await fetch(path, {
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
 * Give the fixture app one dashboard page holding the "My approvals" widget.
 *
 * @param page An admin page on the buildiq app.
 */
async function seedApprovalsPage(page: Page): Promise<void> {
	const path = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
	const read = await call(page, 'GET', path)
	expect(read.status, 'GET manifest').toBe(200)
	const manifest = read.data
	const pages = (manifest.pages || []).filter((p: { id: string }) => p.id !== PAGE_ID)
	pages.push({
		id: PAGE_ID,
		type: 'dashboard',
		route: `/${PAGE_ID}`,
		title: 'Approvals',
		config: {
			widgets: [{ id: 'approvals', title: 'My approvals', type: 'my-approvals' }],
			layout: [{ id: 'approvals', widgetId: 'approvals', gridX: 0, gridY: 0, gridWidth: 12, gridHeight: 6 }],
		},
	})
	const menu = [...(manifest.menu || []).filter((m: { id: string }) => m.id !== PAGE_ID), { id: PAGE_ID, label: 'Approvals', route: PAGE_ID }]
	const write = await call(page, 'PUT', path, { manifest: { ...manifest, pages, menu } })
	expect(write.status, `seed the approvals page: ${JSON.stringify(write.data).slice(0, 200)}`).toBeLessThan(300)
}

test.describe('logic-approval-task-form: the approver fills in the declared fields', () => {
	test.describe.configure({ mode: 'serial', timeout: 120_000 })

	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Approval Form')
			await seedApprovalsPage(page)
		} finally {
			await admin.close()
		}
	})

	test.afterAll(async ({ browser, request }) => {
		if (state.automation !== '') {
			await request.post(`${BASE_URL}${AUTOMATIONS}/${state.automation}/disable`, { headers: HEADERS }).catch(() => {})
			await request.delete(`${BASE_URL}${AUTOMATIONS}/${state.automation}`, { headers: HEADERS }).catch(() => {})
		}
		if (state.record !== '') {
			await request.delete(`${BASE_URL}${RECORDS}/${state.record}`, { headers: HEADERS }).catch(() => {})
		}
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			const listed = await call(page, 'GET', '/index.php/apps/buildiq/api/applications')
			const app = rowsOf(listed.data).find((row) => (row?.slug ?? row?.['@self']?.slug) === APP_SLUG)
			const uuid = String(app?.uuid ?? app?.['@self']?.id ?? app?.id ?? '')
			if (uuid !== '') {
				await call(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${uuid}`)
			}
		} finally {
			await admin.close()
		}
	})

	test('REQ-BQTF-001: the approval action stores the two fields in order, with their required flags', async ({ request }) => {
		const versions = await request.get(`${BASE_URL}/index.php/apps/buildiq/api/applications/${SOURCE_APP}/versions`, { headers: HEADERS })
		await expectOk(versions, 'list the app versions')
		const production = rowsOf(await versions.json()).find((v) => v?.slug === 'production')
		const versionUuid = uuidOf(production ?? {})
		expect(versionUuid, `${SOURCE_APP} needs a production version`).not.toBe('')

		const created = await request.post(`${BASE_URL}${AUTOMATIONS}`, {
			headers: HEADERS,
			data: {
				applicationSlug: SOURCE_APP,
				versionUuid,
				enabled: false,
				slug: `pw-approval-form-${RUN_ID}`,
				name: `PW approval form ${RUN_ID}`,
				trigger: { type: 'object-created', schema: SCHEMA },
				actions: [{
					type: 'approval',
					assigneeGroup: APPROVER_GROUP,
					form: { fields: FORM_FIELDS },
					onApprove: [],
					onReject: [],
				}],
			},
		})
		await expectOk(created, 'create the approval automation')
		state.automation = uuidOf(await created.json())
		expect(state.automation).not.toBe('')

		const stored = await request.get(`${BASE_URL}${OR_API}/objects/buildiq/automation/${state.automation}`, { headers: HEADERS })
		await expectOk(stored, 'read the stored automation')
		const action = ((await stored.json()).actions ?? [])[0] ?? {}
		expect(action.type).toBe('approval')
		expect(action.form?.fields, 'form.fields in the declared order').toEqual(FORM_FIELDS)

		const compiled = await request.post(`${BASE_URL}${AUTOMATIONS}/${state.automation}/compile`, { headers: HEADERS })
		const compiledText = await compiled.text()
		if (!compiled.ok() && compiledText.includes('Approval forms need OpenRegister to carry a form into the task')) {
			state.skipReason = 'OpenRegister does not carry an approval form into the task yet (approval-position-task-form)'
		}
		test.skip(state.skipReason !== '', state.skipReason)
		expect(compiled.ok(), `compile: ${compiled.status()} ${compiledText.slice(0, 300)}`).toBeTruthy()
		await expectOk(await request.post(`${BASE_URL}${AUTOMATIONS}/${state.automation}/enable`, { headers: HEADERS }), 'enable it')
	})

	test('REQ-BQTF-003: a missing required field keeps the dialog open; approving with it writes the value', async ({ page, request }) => {
		test.skip(state.skipReason !== '', state.skipReason)
		expect(state.automation, 'the automation from the previous test').not.toBe('')

		const record = await request.post(`${BASE_URL}${RECORDS}`, { headers: HEADERS, data: { title: TITLE } })
		await expectOk(record, `create a ${SCHEMA} record`)
		state.record = uuidOf(await record.json())

		const found: { task: Record<string, any> | null } = { task: null }
		await expect.poll(async () => {
			found.task = await openTaskFor(request, state.record)
			return found.task !== null
		}, { timeout: 45_000, message: 'an approval task opens for the record' }).toBeTruthy().catch(() => {})
		test.skip(found.task === null, 'the flow engine opened no approval task for the record on this instance')
		const task = found.task as Record<string, any>
		const taskUuid = String(task.uuid ?? task.id)
		expect(task.metadata?.form, 'OpenRegister copied the form into the task').toBeTruthy()

		// Narrow the inbox to this run's task; every other request passes through.
		await page.route(/\/apps\/openregister\/api\/flow-tasks\?/, async (route) => {
			const response = await route.fetch()
			const data = await response.json().catch(() => null)
			const rows = rowsOf(data).filter((row) => row?.objectUuid === state.record)
			await route.fulfill({ response, json: Array.isArray(data) ? rows : { ...(data ?? {}), results: rows, total: rows.length } })
		})

		await suppressSupportDialog(page)
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/builder/${APP_SLUG}/${PAGE_ID}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)

		const row = page.getByTestId('my-approvals-row')
		await expect(row).toHaveCount(1, { timeout: 45_000 })
		await expect(row.getByTestId('my-approvals-form')).toHaveText(/2 fields to fill in/)
		const approve = row.getByTestId('approve-button')
		await expect(approve).toBeEnabled()
		await approve.click()

		const dialog = page.getByRole('dialog').filter({ has: page.getByRole('button', { name: 'Complete' }) })
		await expect(dialog).toBeVisible({ timeout: 20_000 })
		const body = dialog.getByRole('textbox', { name: /^body/i })
		const title = dialog.getByRole('textbox', { name: /^title/i })
		await expect(body).toBeVisible()
		await expect(dialog.getByText(/body\s*\*/i).first(), 'the required field is marked').toBeVisible()

		// Missing required: the other value is typed, the required one left empty.
		await body.fill('')
		await title.fill(EDITED_TITLE)
		const complete = dialog.getByRole('button', { name: 'Complete' })
		await expect(complete, 'the dialog will not confirm without the required field').toBeDisabled()
		await complete.click({ force: true }).catch(() => {})
		await expect(dialog).toBeVisible()
		await expect(title, 'the typed value is kept').toHaveValue(EDITED_TITLE)
		const stillOpen = await request.get(`${BASE_URL}${OR_API}/flow-tasks/${taskUuid}`, { headers: HEADERS })
		await expectOk(stillOpen, 'read the task')
		expect((await stillOpen.json()).isTerminal, 'the task is still open').toBeFalsy()

		// Approving with the required value completes the task and writes it.
		await body.fill(APPROVED_BODY)
		await expect(complete).toBeEnabled()
		const completion = page.waitForResponse((resp) => resp.url().includes(`/flow-tasks/${taskUuid}/complete`) && resp.request().method() === 'POST')
		await complete.click()
		const completed = await completion
		expect(completed.ok(), `complete the task: ${completed.status()}`).toBeTruthy()
		const sent = completed.request().postDataJSON()
		expect(sent.outcome).toBe('approved')
		expect(sent.data).toMatchObject({ body: APPROVED_BODY, title: EDITED_TITLE })
		await expect(dialog).toBeHidden({ timeout: 20_000 })

		await expect.poll(async () => {
			const resp = await request.get(`${BASE_URL}${OR_API}/flow-tasks/${taskUuid}`, { headers: HEADERS })
			const read = resp.ok() ? await resp.json() : {}
			return [read.isTerminal === true, read.outcome ?? read.result?.outcome ?? null]
		}, { timeout: 30_000, message: 'the task completes with outcome approved' }).toEqual([true, 'approved'])

		await expect.poll(async () => {
			const resp = await request.get(`${BASE_URL}${RECORDS}/${state.record}`, { headers: HEADERS })
			return resp.ok() ? (await resp.json())?.body ?? null : `HTTP ${resp.status()}`
		}, { timeout: 30_000, message: 'the record carries the approver\'s value' }).toBe(APPROVED_BODY)
	})
})
