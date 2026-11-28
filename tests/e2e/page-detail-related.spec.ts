// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e for pages-detail-related-items (T04): a maker adds "Requests
 * of this client" to the client detail page and hides its files tab in the
 * page designer, saves, and an app user then sees only that client's requests
 * and no files tab.
 *
 * Fixtures: a register with the schemas `client` and `request` (a request's
 * `client` field holds the client's id), two clients with their own requests,
 * and a fixture app whose manifest has a client index and a client detail
 * page with a sidebar. They are created through the OpenRegister API as the
 * admin and removed afterwards. The app user is `rbac-viewer` from the global
 * setup, a non-admin with its own storage state, given the viewer role on the
 * fixture app.
 *
 * @spec openspec/changes/archive/2026-11-24-pages-detail-related-items/specs/page-detail-related-items/spec.md
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	dismissOverlays,
	ensureApp,
	suppressSupportDialog,
} from './support/appFixture.ts'
import { grantAppRoles } from './support/appRoles.ts'
// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'

const APP_SLUG = 'pw-detail-related'
const RUN = `pwdr${Date.now().toString(36)}`
const REGISTER = `${RUN}-crm`
const OR = '/index.php/apps/openregister/api'
const MANIFEST = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
const DETAIL_ROUTE = '/clients/:id'
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

const ids: Record<string, string> = {}
const cleanup: string[] = []

/**
 * The id of an OpenRegister response row.
 *
 * @param body The response body.
 */
function idOf(body: Record<string, any>): string {
	return String(body?.id ?? body?.uuid ?? body?.['@self']?.id ?? '')
}

/**
 * POST a JSON body as the admin and return the created row.
 *
 * @param request The admin request context.
 * @param path The API path.
 * @param data The body.
 */
async function create(
	request: APIRequestContext,
	path: string,
	data: Record<string, unknown>,
): Promise<Record<string, any>> {
	const resp = await request.post(path, { headers: HEADERS, data })
	expect(resp.status(), `POST ${path}: ${await resp.text()}`).toBeLessThan(300)
	const body = await resp.json()
	cleanup.push(`${path}/${idOf(body)}`)
	return body
}

/**
 * The stored manifest, read the way the designer reads it.
 *
 * @param page An admin page on the buildiq app.
 */
async function storedManifest(page: Page): Promise<Record<string, any>> {
	return page.evaluate(async (url) => {
		const resp = await fetch(url, { headers: { 'OCS-APIRequest': 'true' } })
		return resp.json()
	}, MANIFEST)
}

/**
 * Replace the fixture app's pages with the client index and detail page.
 *
 * @param page An admin page on the buildiq app.
 */
async function resetPages(page: Page): Promise<void> {
	await page.evaluate(
		async ({ url, register, route }) => {
			const tok =
				(window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const headers = {
				requesttoken: tok,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			}
			const current = await (await fetch(url, { headers })).json()
			const keep = (current.pages || []).filter(
				(p: { id?: string }) =>
					p.id !== 'clients' && p.id !== 'client-detail',
			)
			const pages = [
				...keep,
				{
					id: 'clients',
					route: '/clients',
					type: 'index',
					title: 'Clients',
					config: {
						register,
						schema: 'client',
						rowRoute: 'client-detail',
					},
				},
				{
					id: 'client-detail',
					route,
					type: 'detail',
					title: 'Client',
					config: { register, schema: 'client', sidebar: true },
				},
			]
			await fetch(url, {
				method: 'PUT',
				headers,
				body: JSON.stringify({ manifest: { ...current, pages } }),
			})
		},
		{ url: MANIFEST, register: REGISTER, route: DETAIL_ROUTE },
	)
}

test.describe('detail page related items', () => {
	test.describe.configure({ mode: 'serial' })
	// The designer is a three-pane desktop surface.
	test.use({ viewport: { width: 1600, height: 1200 } })

	test.beforeAll(async ({ request }) => {
		const probe = await request.get(`${OR}/registers`, { headers: HEADERS })
		test.skip(probe.status() === 404, 'OpenRegister is not installed here')

		const client = await create(request, `${OR}/schemas`, {
			slug: `${RUN}-client`,
			title: 'Client',
			properties: { name: { type: 'string', title: 'Name' } },
		})
		const requestSchema = await create(request, `${OR}/schemas`, {
			slug: `${RUN}-request`,
			title: 'Request',
			properties: {
				title: { type: 'string', title: 'Title' },
				client: {
					type: 'string',
					format: 'uuid',
					title: 'Client',
					$ref: `${RUN}-client`,
				},
			},
		})
		await create(request, `${OR}/registers`, {
			slug: REGISTER,
			title: 'PW CRM',
			schemas: [idOf(client), idOf(requestSchema)],
		})

		const objects = `${OR}/objects/${REGISTER}`
		ids.acme = idOf(
			await create(request, `${objects}/${RUN}-client`, { name: 'Acme Bouw' }),
		)
		ids.other = idOf(
			await create(request, `${objects}/${RUN}-client`, {
				name: 'Bakker Installatie',
			}),
		)
		for (const [title, owner] of [
			['Offerte dakkapel Acme', ids.acme],
			['Onderhoud cv Acme', ids.acme],
			['Storing Bakker', ids.other],
		]) {
			await create(request, `${objects}/${RUN}-request`, {
				title,
				client: owner,
			})
		}
	})

	test.afterAll(async ({ request }) => {
		for (const path of cleanup.reverse()) {
			await request.delete(path, { headers: HEADERS }).catch(() => undefined)
		}
	})

	// @e2e page-detail-related-items::a-maker-adds-the-requests-of-a-client
	// @e2e page-detail-related-items::an-app-user-sees-only-this-clients-requests
	// @e2e page-detail-related-items::files-are-hidden-on-a-detail-page
	test("a maker adds the related list and hides files, an app user sees only that client's requests", async ({
		page,
		browser,
	}) => {
		await suppressSupportDialog(page)
		await ensureApp(page, APP_SLUG, 'PW Detail Related')
		await resetPages(page)
		await grantAppRoles(page, APP_SLUG, { viewers: ['user:rbac-viewer'] })

		await page.goto(
			`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`,
			{
				waitUntil: 'domcontentloaded',
			},
		)
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)

		await page
			.locator('.page-list-editor__row')
			.filter({ has: page.locator(`input[value="${DETAIL_ROUTE}"]`) })
			.first()
			.click()
		const editor = page.locator('.detail-page-editor')
		await expect(editor).toBeVisible({ timeout: 30_000 })

		// The sidebar's Files checkbox: unticked hides the files tab.
		const files = editor.locator(
			'.detail-page-editor__files input[type="checkbox"]',
		)
		await expect(files).toBeChecked()
		await files.uncheck()

		// Related items: "Requests of this client", on the request schema's client field.
		const related = editor.locator('.related-collection-builder')
		await related.getByRole('button', { name: /add related list/i }).click()
		const row = related.locator('.related-collection-builder__row').last()
		await row.getByLabel('Title').fill('Requests of this client')
		await row.getByLabel('Register').selectOption({ label: 'PW CRM' })
		await row.getByLabel('Schema').selectOption({ label: 'Request' })
		await row
			.getByLabel('Field that points at this record')
			.selectOption('client')

		await page
			.getByRole('button', { name: /save pages|save & open preview/i })
			.first()
			.click()

		await expect
			.poll(
				async () => {
					const stored = await storedManifest(page)
					const detail = (stored.pages || []).find(
						(p: { id?: string }) => p.id === 'client-detail',
					)
					return JSON.stringify({
						related: detail?.config?.relatedCollections ?? [],
						hidden: detail?.config?.sidebar?.hiddenTabs ?? [],
					})
				},
				{
					timeout: 30_000,
					message:
						'the related list and the hidden files tab reach the stored manifest',
				},
			)
			.toContain('"client":"@objectId"')
		const stored = await storedManifest(page)
		const detail = stored.pages.find(
			(p: { id?: string }) => p.id === 'client-detail',
		)
		expect(detail.config.relatedCollections).toHaveLength(1)
		expect(detail.config.relatedCollections[0].title).toBe(
			'Requests of this client',
		)
		expect(detail.config.relatedCollections[0].filter).toEqual({
			client: '@objectId',
		})
		expect(detail.config.relatedCollections[0]).not.toHaveProperty('limit')
		expect(detail.config.sidebar).toEqual({
			enabled: true,
			hiddenTabs: ['files'],
		})

		// The app user opens Acme's detail page.
		const context = await browser.newContext({
			storageState: 'tests/e2e/.auth/rbac-viewer.json',
		})
		const user = await context.newPage()
		try {
			await user.goto(
				`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/clients/${ids.acme}?_version=production`,
				{ waitUntil: 'domcontentloaded' },
			)
			await dismissOverlays(user)
			await expect(
				user.getByText('Requests of this client').first(),
			).toBeVisible({ timeout: 45_000 })
			await expect(
				user.getByText('Offerte dakkapel Acme').first(),
			).toBeVisible()
			await expect(user.getByText('Onderhoud cv Acme').first()).toBeVisible()
			await expect(
				user.getByText('Storing Bakker'),
				"another client's request stays out",
			).toHaveCount(0)
			await expect(user.getByRole('tab', { name: /^files$/i })).toHaveCount(0)
		} finally {
			await context.close()
		}
	})
})
