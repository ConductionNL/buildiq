/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Playwright e2e — scanning and codes on pages (change pages-qr-and-barcodes,
 * task T07, REQ-BQQR-001 and REQ-BQQR-003).
 *
 * The app is cloned from the `incident-reporter` template. Its index page gets
 * a scan on `location` that opens the match, and its detail page a QR `code`
 * widget showing `location`. No QR library is needed in the test: the detail
 * page draws the record's own QR code, the test takes that drawing as an image,
 * and a faked camera (getUserMedia answered with a canvas stream showing the
 * image) holds it up to the index page's "Scan".
 *
 * A second record supplies a code that matches nothing: its QR is taken the
 * same way, then the record is deleted, so scanning it must say "No record has
 * this code." and leave the list as it was.
 *
 * The renderer half (the scanner, the scan action and the code widget) lives in
 * @conduction/nextcloud-vue (task T06); until it ships this spec is red there,
 * which is what it is for.
 *
 * @spec openspec/changes/archive/2026-11-24-pages-qr-and-barcodes/specs/page-codes/spec.md
 * @e2e pages-qr-and-barcodes/requirement-index-pages-can-scan-to-find-a-record-req-bqqr-001/a-technician-opens-a-device-by-its-label
 * @e2e pages-qr-and-barcodes/requirement-index-pages-can-scan-to-find-a-record-req-bqqr-001/no-match-is-said-plainly
 * @e2e pages-qr-and-barcodes/requirement-detail-pages-can-show-a-code-req-bqqr-003/a-maker-puts-a-qr-code-on-the-asset-page
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import path from 'node:path'
import { dismissOverlays } from './support/appFixture.ts'
import { E2E_BASE_URL } from './support/baseUrl.ts'

/** The suite's admin session, written by global-setup. */
const ADMIN = { baseURL: E2E_BASE_URL, storageState: path.resolve(__dirname, '.auth', 'admin.json') }

const RUN = Date.now().toString(36)
const SLUG = `e2e-codes-${RUN}`.slice(0, 32)
const MATCH = `SN-40211-${RUN}`
const NO_MATCH = `SN-99999-${RUN}`

test.describe.configure({ mode: 'serial' })

let appUuid = ''
let register = ''
const records: Record<string, string> = {}
const codes: Record<string, string> = {}

/**
 * Call a Buildiq or OpenRegister route from inside the page, so the request
 * carries the session cookie and the CSRF request token.
 *
 * @param page   A signed-in page.
 * @param method The HTTP method.
 * @param url    The path, starting with `/index.php/`.
 * @param body   The JSON body, if any.
 * @return The status and the decoded body.
 */
async function api(
	page: Page,
	method: string,
	url: string,
	body?: unknown,
): Promise<{ status: number; json: any }> {
	return page.evaluate(
		async ({ method, url, body }) => {
			const token = (window as any).OC?.requestToken
				?? document.head.dataset.requesttoken
				?? ''
			const resp = await fetch(url, {
				body: body === undefined ? undefined : JSON.stringify(body),
				headers: {
					'Content-Type': 'application/json',
					'OCS-APIRequest': 'true',
					requesttoken: token,
				},
				method,
			})
			const text = await resp.text()
			const parse = (raw: string): unknown => {
				try {
					return JSON.parse(raw)
				} catch {
					return raw
				}
			}
			return { json: parse(text), status: resp.status }
		},
		{ body, method, url },
	)
}

/**
 * An object's UUID in any of the shapes OpenRegister returns it in.
 *
 * @param obj The decoded object.
 * @return The UUID.
 */
function idOf(obj: any): string {
	return String(obj?.id ?? obj?.uuid ?? obj?.['@self']?.id ?? '')
}

/**
 * Answer the page's camera with a still image, as a phone held to a label.
 *
 * @param page    The page, before it navigates.
 * @param dataUrl The image to show, as a PNG data URL.
 * @return Resolves once the stub is installed.
 */
async function holdUpToCamera(page: Page, dataUrl: string): Promise<void> {
	await page.addInitScript((src) => {
		const image = new Image()
		image.src = src
		const fake = async () => {
			await image.decode()
			const canvas = document.createElement('canvas')
			canvas.width = Math.max(image.width, 320) + 80
			canvas.height = Math.max(image.height, 240) + 80
			const ctx = canvas.getContext('2d') as CanvasRenderingContext2D
			const draw = () => {
				ctx.fillStyle = '#fff'
				ctx.fillRect(0, 0, canvas.width, canvas.height)
				ctx.drawImage(image, 40, 40)
				requestAnimationFrame(draw)
			}
			draw()
			return canvas.captureStream(15)
		}
		const devices = navigator.mediaDevices ?? ({} as MediaDevices)
		Object.defineProperty(navigator, 'mediaDevices', { configurable: true, value: devices })
		devices.getUserMedia = fake as any
		devices.enumerateDevices = async () => [
			{ deviceId: 'fake', groupId: 'fake', kind: 'videoinput', label: 'Fake camera', toJSON: () => ({}) } as MediaDeviceInfo,
		]
	}, dataUrl)
}

/**
 * The detail page's QR code for a record, as a PNG data URL.
 *
 * @param page The signed-in page.
 * @param id   The record.
 * @return The image of the code.
 */
async function qrOf(page: Page, id: string): Promise<string> {
	await page.goto(`/index.php/apps/buildiq/builder/${SLUG}/incidents/${id}`)
	await dismissOverlays(page)
	// The widget is the renderer's; find its drawing by the widget, not by markup.
	const code = page
		.locator('[data-widget-type="code"], [data-widget-id="code-1"], .cn-code-widget')
		.locator('svg, canvas, img')
		.first()
	await expect(code, 'the detail page draws the record\'s code').toBeVisible({ timeout: 30_000 })
	const png = await code.screenshot()
	return `data:image/png;base64,${png.toString('base64')}`
}

test.beforeAll(async ({ browser }) => {
	const page = await browser.newPage(ADMIN)
	await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })

	const created = await api(page, 'POST', '/index.php/apps/buildiq/api/applications/from-template/incident-reporter', {
		name: `E2E codes ${RUN}`,
		slug: SLUG,
	})
	expect(created.status, JSON.stringify(created.json).slice(0, 300)).toBeLessThan(300)
	appUuid = String(created.json?.uuid ?? created.json?.id ?? created.json?.application?.uuid ?? '')

	const manifestUrl = `/index.php/apps/buildiq/api/applications/${SLUG}/manifest`
	const manifest = (await api(page, 'GET', manifestUrl)).json
	const pages = manifest.pages as Array<Record<string, any>>
	const index = pages.find((p) => p.type === 'index' && p.config?.schema === 'incident')
	const detail = pages.find((p) => p.type === 'detail' && p.config?.schema === 'incident')
	expect(index && detail, 'the template has an incident index and detail page').toBeTruthy()
	register = String(detail!.config.register ?? index!.config.register ?? `buildiq-${SLUG}`)

	index!.config.scan = { enabled: true, field: 'location', onMatch: 'open' }
	detail!.config.widgets = [
		...(detail!.config.widgets ?? []),
		{ field: 'location', format: 'qr', id: 'code-1', source: 'field', type: 'code' },
	]
	if (Array.isArray(detail!.config.layout)) {
		detail!.config.layout.push({ gridHeight: 4, gridWidth: 4, gridX: 0, gridY: 20, id: 'code-1-cell', widgetId: 'code-1' })
	}
	const saved = await api(page, 'PUT', manifestUrl, manifest)
	expect(saved.status, JSON.stringify(saved.json).slice(0, 300)).toBeLessThan(300)

	for (const [key, location] of [['match', MATCH], ['noMatch', NO_MATCH]]) {
		const obj = await api(page, 'POST', `/index.php/apps/openregister/api/objects/${register}/incident`, {
			incidentType: 'equipment',
			location,
			reportedBy: 'e2e',
			severity: 'low',
		})
		expect(obj.status, JSON.stringify(obj.json).slice(0, 300)).toBeLessThan(300)
		records[key] = idOf(obj.json)
	}
	await page.close()
})

test.afterAll(async ({ browser }) => {
	const page = await browser.newPage(ADMIN)
	await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
	for (const id of Object.values(records)) {
		await api(page, 'DELETE', `/index.php/apps/openregister/api/objects/${register}/incident/${id}`).catch(() => undefined)
	}
	if (appUuid !== '') {
		await api(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${appUuid}`).catch(() => undefined)
	}
	await page.close()
})

test('the detail page shows the record\'s code with a download as SVG', async ({ page }) => {
	codes.match = await qrOf(page, records.match)
	await expect(page.getByRole('button', { name: /download as svg/i }).or(page.getByRole('link', { name: /download as svg/i })).first()).toBeVisible()

	codes.noMatch = await qrOf(page, records.noMatch)
	const gone = await api(page, 'DELETE', `/index.php/apps/openregister/api/objects/${register}/incident/${records.noMatch}`)
	expect(gone.status).toBeLessThan(300)
	delete records.noMatch
})

test('scanning a label opens the record with that code', async ({ page }) => {
	await page.context().grantPermissions(['camera'])
	await holdUpToCamera(page, codes.match)
	await page.goto(`/index.php/apps/buildiq/builder/${SLUG}/incidents`)
	await dismissOverlays(page)
	await page.getByRole('button', { name: /^scan$/i }).click()
	await expect(page).toHaveURL(new RegExp(`/incidents/${records.match}`), { timeout: 30_000 })
	await expect(page.getByText(MATCH).first()).toBeVisible()
})

test('a code that matches no record is said plainly and the list stays', async ({ page }) => {
	await page.context().grantPermissions(['camera'])
	await holdUpToCamera(page, codes.noMatch)
	await page.goto(`/index.php/apps/buildiq/builder/${SLUG}/incidents`)
	await dismissOverlays(page)
	await expect(page.getByText(MATCH).first()).toBeVisible({ timeout: 30_000 })
	await page.getByRole('button', { name: /^scan$/i }).click()
	await expect(page.getByText('No record has this code.')).toBeVisible({ timeout: 30_000 })
	await expect(page).toHaveURL(/\/incidents\/?(\?.*)?$/)
	await expect(page.getByText(MATCH).first()).toBeVisible()
})
