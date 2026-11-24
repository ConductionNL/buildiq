/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end coverage for openspec change
 * `forms-signature-audio-and-location`, task T07 (REQ-BQSA-001, REQ-BQSA-002,
 * REQ-BQSA-004), with a granted geolocation and a fake microphone:
 *
 *   1. A maker adds a signature field from the palette and allows drawing
 *      only; the form config holds it with typed signing off (REQ-BQSA-001).
 *   2. The page serving an app whose forms use a location field and no audio
 *      field allows geolocation for itself and still refuses the microphone
 *      (REQ-BQSA-004).
 *   3. A user draws a signature, records a clip and has their position taken
 *      in the built form; submitting lands all three on the record
 *      (REQ-BQSA-001, REQ-BQSA-002).
 *
 * Devices: Chromium is launched with `--use-fake-device-for-media-stream` and
 * `--use-fake-ui-for-media-stream` (a synthetic microphone, no prompt), and
 * the context is granted `geolocation` and `microphone` at a fixed position in
 * Utrecht. The clip is a few seconds rather than the scenario's 40, to stay in
 * the test budget; the length cap itself is checked by Vitest
 * (`FormFieldBuilder.deviceFields.spec.js`) and `deviceFieldProblems`.
 *
 * `beforeAll` builds the fixture app `pw-form-device-fields` as admin; each
 * test writes the form pages it needs through the manifest API, because the
 * device features are derived from the whole manifest. `afterAll` deletes the
 * app.
 *
 * GUARDED: scenario 3 needs a target schema with a `file` property for the
 * signature and the clip and two number properties for the position. It runs
 * when the instance names one, and skips with that reason otherwise:
 *
 *   FORM_DEVICE_REGISTER the register holding the schema
 *   FORM_DEVICE_SCHEMA   the schema, with `handtekening` and `spraaknotitie`
 *                        (type file) and `lat` and `lng` (type number)
 *
 * Not covered here: "Add the property to <schema>" and the too-long clip
 * (REQ-BQSA-002, Vitest), map pages showing the user (REQ-BQSA-003, Vitest on
 * MapPageEditor; the renderer half is nextcloud-vue's), and the public-form
 * refusal (REQ-BQSA-004, `formLogicValidation.spec.js`).
 *
 * NOTE: Playwright binaries are NOT installed by `npm install`. Run
 * `npm run test:e2e:install` once before invoking `npm run test:e2e`.
 *
 * @spec openspec/changes/archive/2026-11-24-forms-signature-audio-and-location/specs/form-device-fields/spec.md
 * @e2e form-device-fields/requirement-forms-offer-signature-audio-and-location-fields-req-bqsa-001/a-maker-adds-a-signature-to-an-inspection-form
 * @e2e form-device-fields/requirement-the-page-allows-only-the-devices-the-app-uses-req-bqsa-004/an-app-without-audio-does-not-get-the-microphone
 * @e2e form-device-fields/requirement-forms-offer-signature-audio-and-location-fields-req-bqsa-001/a-user-records-a-spoken-note-in-a-form
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissOverlays, ensureApp, suppressSupportDialog } from './support/appFixture.ts'
// PLAYWRIGHT_BASE_URL wins; there is no localhost default off CI.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { readStagedManifest } from './support/stagedManifest.ts'

const ADMIN_STORAGE_STATE = 'tests/e2e/.auth/admin.json'
const REGISTER = process.env.FORM_DEVICE_REGISTER ?? ''
const SCHEMA = process.env.FORM_DEVICE_SCHEMA ?? ''

const APP_SLUG = 'pw-form-device-fields'
const MANIFEST = `/index.php/apps/buildiq/api/applications/${APP_SLUG}/manifest`
const FORM_PAGE_ID = 'opleveringsrapport'
const POSITION = { latitude: 52.0907, longitude: 5.1214, accuracy: 12 }
const CLIP_MS = 3_000

// A launch option forces a fresh worker, so Playwright only accepts it at the
// top of the file. A fake microphone: getUserMedia answers with a synthetic
// stream and never shows a prompt.
test.use({
	launchOptions: {
		args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'],
	},
})

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
 * Make the form page the app's only form page, with this config.
 *
 * Every other form page is dropped: the page policy follows the device fields
 * of the WHOLE manifest, so a leftover page from another test would widen it.
 *
 * @param page   A logged-in page on the buildiq app.
 * @param config The form page config.
 * @return Index of the page in `manifest.pages`.
 */
async function seedFormPage(page: Page, config: Record<string, unknown>): Promise<number> {
	const read = await call(page, 'GET', MANIFEST)
	expect(read.status, 'GET manifest').toBe(200)
	const manifest = read.data
	const pages = (manifest.pages || []).filter((p: { id: string; type: string }) => p.id !== FORM_PAGE_ID && p.type !== 'form')
	pages.push({ id: FORM_PAGE_ID, type: 'form', route: `/${FORM_PAGE_ID}`, title: 'Opleveringsrapport', config })
	const write = await call(page, 'PUT', MANIFEST, { manifest: { ...manifest, pages } })
	expect(write.status, `seed the form page: ${JSON.stringify(write.data).slice(0, 200)}`).toBeLessThan(300)
	return pages.length - 1
}

/**
 * The directive list of a feature policy header, by feature.
 *
 * @param header The `Feature-Policy` (or `Permissions-Policy`) value.
 * @return Feature name to its allow list.
 */
function policyDirectives(header: string): Record<string, string> {
	const directives: Record<string, string> = {}
	for (const part of header.split(/[;,]/)) {
		const match = part.trim().match(/^([a-z-]+)[\s=]+(.*)$/i)
		if (match) {
			directives[match[1].toLowerCase()] = match[2]
		}
	}
	return directives
}

test.describe('forms-signature-audio-and-location: signature, audio and location fields', () => {
	test.describe.configure({ mode: 'serial', timeout: 120_000 })
	test.use({
		viewport: { width: 1600, height: 1200 },
		geolocation: POSITION,
		permissions: ['geolocation', 'microphone'],
	})

	test.beforeAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await suppressSupportDialog(page)
			await ensureApp(page, APP_SLUG, 'PW Form Device Fields')
		} finally {
			await admin.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const admin = await browser.newContext({ storageState: ADMIN_STORAGE_STATE })
		const page = await admin.newPage()
		try {
			await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
			const listed = await call(page, 'GET', '/index.php/apps/buildiq/api/applications')
			const rows = Array.isArray(listed.data) ? listed.data : (listed.data?.results ?? [])
			const app = rows.find((row: Record<string, any>) => (row?.slug ?? row?.['@self']?.slug) === APP_SLUG)
			const uuid = String(app?.uuid ?? app?.['@self']?.id ?? app?.id ?? '')
			if (uuid !== '') {
				await call(page, 'DELETE', `/index.php/apps/buildiq/api/applications/${uuid}`)
			}
		} finally {
			await admin.close()
		}
	})

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await page.goto(`${BASE_URL}/index.php/apps/buildiq/`, { waitUntil: 'domcontentloaded' })
	})

	test('REQ-BQSA-001: a maker adds a signature field that allows drawing only', async ({ page }) => {
		const index = await seedFormPage(page, { mode: 'create', fields: [{ key: 'naam', label: 'Naam', type: 'string' }] })

		await page.goto(`${BASE_URL}/apps/buildiq/builder/${APP_SLUG}/pages?_version=production`, { waitUntil: 'domcontentloaded' })
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)
		// Selecting the page is setup: dispatch the row's own click (its inputs
		// stop propagation; same as form-field-kinds.spec.ts).
		const pageRow = page.locator('.page-list-editor__row').nth(index)
		await pageRow.scrollIntoViewIfNeeded()
		await pageRow.dispatchEvent('click')
		await expect(page.locator('.form-page-editor')).toBeVisible({ timeout: 30_000 })

		await page.getByTestId('palette-signature').focus()
		await page.keyboard.press('Enter')
		const rows = page.locator('.form-field-builder__row')
		await expect(rows).toHaveCount(2)
		await rows.nth(1).getByLabel('Key').fill('handtekening')
		await rows.nth(1).getByLabel('Label').fill('Handtekening')

		const disclosure = page.locator('.form-field-builder__disclosure').nth(1)
		if ((await disclosure.getAttribute('aria-expanded')) !== 'true') {
			await disclosure.click()
		}
		const options = page.getByTestId('device-options-1')
		await expect(options.getByTestId('signature-allow-drawn')).toBeChecked()
		await options.getByTestId('signature-allow-typed').uncheck()

		const manifest = await readStagedManifest(page)
		const fields = manifest.pages.find((p: { id: string }) => p.id === FORM_PAGE_ID).config.fields
		expect(fields.map((field: { key: string }) => field.key)).toEqual(['naam', 'handtekening'])
		expect(fields[1]).toMatchObject({ key: 'handtekening', type: 'signature', allowTyped: false })
		expect(fields[1].allowDrawn).not.toBe(false)
	})

	test('REQ-BQSA-004: an app with a location field and no audio field gets geolocation, not the microphone', async ({ page }) => {
		await seedFormPage(page, {
			mode: 'create',
			fields: [{ key: 'positie', label: 'Positie', type: 'location', latField: 'lat', lngField: 'lng' }],
		})

		const served = await page.request.get(`${BASE_URL}/index.php/apps/buildiq/builder/${APP_SLUG}/`)
		expect(served.status(), 'the built app is served').toBeLessThan(400)
		const headers = served.headers()
		const header = headers['feature-policy'] ?? headers['permissions-policy'] ?? ''
		expect(header, 'the page sends a feature policy').not.toBe('')
		const policy = policyDirectives(header)
		expect(policy.geolocation ?? '', 'geolocation is allowed for the page itself').toContain('\'self\'')
		expect(policy.microphone ?? '', 'the microphone keeps the default refusal').not.toContain('\'self\'')
		expect(policy.camera ?? '', 'and so does the camera').not.toContain('\'self\'')
	})

	test('REQ-BQSA-001, REQ-BQSA-002: a signature, a clip and a position land on the record', async ({ page }) => {
		test.skip(
			REGISTER === '' || SCHEMA === '',
			'set FORM_DEVICE_REGISTER and FORM_DEVICE_SCHEMA to a schema with file properties handtekening and spraaknotitie and number properties lat and lng',
		)
		await seedFormPage(page, {
			mode: 'create',
			register: REGISTER,
			schema: SCHEMA,
			fields: [
				{ key: 'handtekening', label: 'Handtekening', type: 'signature', allowDrawn: true, allowTyped: false },
				{ key: 'spraaknotitie', label: 'Spraaknotitie', type: 'audio', maxSeconds: 120 },
				{ key: 'positie', label: 'Positie', type: 'location', latField: 'lat', lngField: 'lng' },
			],
		})

		await page.goto(`${BASE_URL}/index.php/apps/buildiq/builder/${APP_SLUG}/${FORM_PAGE_ID}`, { waitUntil: 'domcontentloaded' })
		await dismissOverlays(page)

		// Draw a signature: drawing only, so the canvas is shown straight away.
		const canvas = page.getByTestId('cn-signature-capture').locator('canvas')
		await expect(canvas).toBeVisible({ timeout: 45_000 })
		const box = await canvas.boundingBox()
		expect(box, 'the signature canvas has a size').not.toBeNull()
		await page.mouse.move(box!.x + 20, box!.y + box!.height / 2)
		await page.mouse.down()
		for (let step = 1; step <= 10; step++) {
			await page.mouse.move(box!.x + 20 + step * 15, box!.y + box!.height / 2 + (step % 2 === 0 ? -15 : 15))
		}
		await page.mouse.up()

		// Record a clip from the fake microphone.
		const audio = page.getByTestId('cn-audio-capture')
		await audio.getByTestId('cn-audio-capture-record').click()
		await expect(audio.getByTestId('cn-audio-capture-stop')).toBeVisible()
		await page.waitForTimeout(CLIP_MS)
		await audio.getByTestId('cn-audio-capture-stop').click()
		await expect(audio.getByTestId('cn-audio-capture-player'), 'the clip can be played back before submitting').toBeVisible()

		// The granted position is taken without a prompt.
		await expect(page.getByTestId('cn-geo-capture-line')).toContainText(/52[.,]09/, { timeout: 20_000 })

		const submitted = Date.now()
		await page.getByRole('button', { name: /submit|versturen|opslaan|save/i }).first().click()

		const recordsUrl = `${BASE_URL}/index.php/apps/openregister/api/objects/${REGISTER}/${SCHEMA}?_order[created]=desc&_limit=1`
		let saved: Record<string, any> = {}
		await expect.poll(async () => {
			const read = await page.request.get(recordsUrl, { headers: { 'OCS-APIRequest': 'true' } })
			saved = (await read.json()).results?.[0] ?? {}
			return Date.parse(saved?.['@self']?.created ?? '') >= submitted - 60_000 && typeof saved.handtekening === 'string'
		}, { timeout: 30_000, message: 'the submitted record is stored' }).toBeTruthy()

		expect(String(saved.handtekening)).toMatch(/^data:image\//)
		expect(String(saved.spraaknotitie)).toMatch(/^data:audio\//)
		expect(Number(saved.lat)).toBeCloseTo(POSITION.latitude, 3)
		expect(Number(saved.lng)).toBeCloseTo(POSITION.longitude, 3)

		// The user can play the clip back on the record.
		const playback = await page.evaluate(async (src) => {
			const player = new Audio(src)
			return new Promise<number>((resolve) => {
				player.addEventListener('loadedmetadata', () => resolve(player.duration))
				player.addEventListener('error', () => resolve(-1))
			})
		}, String(saved.spraaknotitie))
		expect(playback, 'the stored clip is playable audio').not.toBe(-1)
	})
})
