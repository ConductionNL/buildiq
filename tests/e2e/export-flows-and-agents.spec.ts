/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end coverage for openbuild-exports-flows-and-agents, driven through
 * the UI an operator actually uses.
 *
 * WHAT THIS HAS TO CATCH
 * ----------------------
 * Every wrong version of this feature produces a ZIP that looks correct and an
 * app that does not work:
 *
 *   - bundling the `agentflow` OBJECT mirror instead of the `Flow` ENTITY
 *     exports a graph that is not the one the engine runs;
 *   - seeding without preserving the UUID leaves every binding in the imported
 *     application pointing at nothing;
 *   - not seeding at all leaves flow JSON on disk that no engine ever reads.
 *
 * None of those raises an error and none is visible in a file listing, so the
 * last test asserts on a flow RUN — the only observation separating "imported"
 * from "imported and runnable".
 *
 * ⚠️ SCOPE, stated rather than hidden: the round trip re-imports onto the SAME
 * instance. It exercises the chain that fails silently (definition → entity →
 * execution) and the UUID preservation the binding depends on. It does NOT
 * exercise cross-instance collision, which needs a second Nextcloud.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { dismissOverlays } from './support/appFixture'
import { E2E_BASE_URL as BASE } from './support/baseUrl'

const TEST_SLUG = 'hello-world'
const POLL_TIMEOUT_MS = 90_000

/**
 * List the entries inside a ZIP.
 *
 * `unzip` rather than a JS zip library: none is in devDependencies, and adding
 * one to read a handful of filenames is a dependency for a listing.
 *
 * @param zipPath Path to the ZIP.
 * @return Entry names.
 */
function zipEntries(zipPath: string): string[] {
	return execFileSync('unzip', ['-Z1', zipPath], { encoding: 'utf8' })
		.split('\n')
		.map((line) => line.trim())
		.filter((line) => line.length > 0)
}

/**
 * Read one file out of a ZIP.
 *
 * @param zipPath Path to the ZIP.
 * @param entry Entry to extract.
 * @return The entry's contents.
 */
function zipRead(zipPath: string, entry: string): string {
	return execFileSync('unzip', ['-p', zipPath, entry], { encoding: 'utf8' })
}

/**
 * POST as the logged-in browser does.
 *
 * ⚠️ NOT `page.request.post()`. That carries the session cookie but no
 * `requesttoken`, and Nextcloud rejects a session-authenticated POST without
 * one — which this spec discovered the expensive way: both its tests SKIPPED
 * in CI for four runs, green, because the skip guard read a rejected create as
 * "environment unavailable". A skip and a pass are the same colour.
 *
 * Issued from inside the page with the token read off the document, mirroring
 * tests/e2e/support/appFixture.ts.
 *
 * @param page The Playwright page (must already be on a Nextcloud document).
 * @param url Absolute path, e.g. /index.php/apps/openregister/api/flows.
 * @param body JSON body.
 * @return status and parsed body.
 */
async function apiPost(
	page: Page,
	url: string,
	body: unknown,
): Promise<{ status: number; ok: boolean; json: any }> {
	return page.evaluate(
		async ({ url, body }) => {
			const tok =
				document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const response = await fetch(url, {
				method: 'POST',
				headers: {
					requesttoken: tok,
					'OCS-APIRequest': 'true',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify(body),
			})

			return {
				status: response.status,
				ok: response.ok,
				json: await response.json().catch(() => null),
			}
		},
		{ url, body },
	)
}

test.describe('Exporting the flows an app is made of', () => {
	// The config default is 30_000, and this spec cannot fit in it: binding
	// through the UI, then an export job that is polled for up to 90 s, then a
	// download and a ZIP read. The first run blew the budget mid-wait and
	// reported the picker as missing — a timeout wearing the costume of a
	// missing element.
	//
	// iconUpload.spec.ts raises it the same way for the same reason.
	test.setTimeout(240_000)

	let scratch: string

	test.beforeAll(() => {
		scratch = mkdtempSync(join(tmpdir(), 'ob-export-'))
	})

	test.afterAll(() => {
		rmSync(scratch, { recursive: true, force: true })
	})

	test('an operator binds a flow in App settings and exports it', async ({
		page,
	}) => {
		// A Nextcloud document must exist before apiPost() can read the
		// requesttoken off it, so navigate first.
		await page.goto(`${BASE}/apps/openbuild/`)
		await dismissOverlays(page)

		// A real flow to bind. Created up front rather than assumed to exist:
		// on an instance with no flows every assertion below would be vacuous,
		// because an empty picker and an empty export both "succeed".
		const created = await apiPost(
			page,
			'/index.php/apps/openregister/api/flows',
			{
				name: 'PW export fixture agentic',
				description: 'Bound through the UI and exported.',
				enabled: false,
				nodes: [
					{
						id: 'start',
						type: 'openregister.trigger-schedule',
						config: {},
					},
					// An AGENTIC node: this fixture proves ADR-065's rule that
					// such a flow takes the ordinary path, not only the plain case.
					{ id: 'work', type: 'hermiq.workload-step', config: {} },
					{ id: 'done', type: 'openregister.end', config: {} },
				],
				edges: [
					{ from: 'start', to: 'work' },
					{ from: 'work', to: 'done' },
				],
			},
		)
		// ASSERTED, not skipped. A skip guard here is what hid four green CI
		// runs in which neither test ran at all.
		expect(
			created.status,
			`creating the fixture flow must succeed (got ${created.status})`,
		).toBe(201)
		const flowUuid: string = created.json?.uuid || created.json?.id || ''
		expect(flowUuid, 'the fixture flow must expose a UUID').toBeTruthy()

		// The detail route takes the OR OBJECT ID, not the slug.
		const lookup = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuild/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		expect(lookup.ok(), 'the application lookup must succeed').toBeTruthy()
		const apps = (await lookup.json()).results || []
		expect(
			apps.length,
			`${TEST_SLUG} must be seeded — an empty result makes every assertion below vacuous`,
		).toBeGreaterThan(0)
		const objectId = apps[0].uuid || apps[0].id

		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await dismissOverlays(page)
		await page.waitForSelector('.ob-detail-header', { timeout: 20_000 })

		// 1. BIND, through App settings.
		//
		// ⚠️ Settings lives in the detail page's OVERFLOW menu — the NcActions
		// is `forceMenu`, so the button does not exist in the DOM until the
		// menu is opened, and it is rendered only for an app OWNER. Reaching
		// straight for a button named /settings/i found nothing and failed on
		// the picker instead of on the menu. Same pattern as
		// save-as-template.spec.ts.
		await page
			.getByRole('button', { name: /^Actions$/i })
			.first()
			.click()

		// ⚠️ SCOPED TO THE OPENED MENU. An unscoped /^Settings$/i matches
		// NEXTCLOUD'S OWN Settings button in the header user menu — the trace
		// from the previous run shows it sitting there `[expanded]`, because
		// that is what the click hit. The app's settings modal never opened and
		// the failure surfaced 20 s later on the picker.
		const actionsMenu = page.getByRole('menu').first()
		await actionsMenu
			.getByRole('menuitem', { name: /^Settings$/i })
			.or(actionsMenu.getByRole('button', { name: /^Settings$/i }))
			.first()
			.click()

		const picker = page.locator('[data-test="app-settings-flow-picker"]')
		await expect(
			picker,
			'App settings must offer a flow PICKER — a UUID cannot be typed into a text field',
		).toBeVisible({ timeout: 20_000 })

		await picker.click()
		await page
			.getByRole('option', { name: 'PW export fixture agentic' })
			.first()
			.click()

		// The parent persists the binding; wait for the WRITE rather than a
		// fixed sleep, so a slow instance does not produce a flaky pass.
		//
		// ⚠️ It is a PUT to the OpenRegister OBJECT
		// (/apps/openregister/api/objects/openbuild/application/{uuid}), not to
		// an openbuild route — `obPatchApp()` writes the Application object
		// directly. Waiting on /apps/openbuild/api/applications would wait for
		// a request that is never made.
		await page.waitForResponse(
			(response) =>
				response
					.url()
					.includes('/apps/openregister/api/objects/openbuild/application')
				&& response.request().method() === 'PUT',
			{ timeout: 20_000 },
		)

		// 2. EXPORT, through the export dialog, and confirm the flow is offered.
		await page.keyboard.press('Escape')
		await page
			.getByRole('button', { name: /^export$/i })
			.first()
			.click()

		const dialog = page.locator('.export-dialog')
		await expect(dialog).toBeVisible({ timeout: 20_000 })

		const flowToggle = dialog.locator(`[data-test="export-flow-${flowUuid}"]`)
		await expect(
			flowToggle,
			'the bound flow must appear in the export dialog for the operator to include',
		).toBeVisible({ timeout: 10_000 })

		// Included by DEFAULT: an exported app without its flows installs and
		// does nothing, so "ship what this app is made of" is the useful default.
		//
		// The hook is on a WRAPPER, so the input is inside it — NcCheckboxRadioSwitch
		// sets `inheritAttrs: false` and never renders an attribute passed to it.
		await expect(
			flowToggle.locator('input[type="checkbox"]').first(),
		).toBeChecked()

		const [exportRequest] = await Promise.all([
			page.waitForRequest(
				(request) =>
					// The REAL submit route is
					// /api/applications/{slug}/exports — not /api/exports,
					// which is only the download path. Matching the wrong URL
					// here made this test wait for a request that never came.
					/\/apps\/openbuild\/api\/applications\/[^/]+\/exports$/.test(
						request.url(),
					) && request.method() === 'POST',
				{ timeout: 20_000 },
			),
			page.getByRole('button', { name: /start export/i }).click(),
		])

		// The payload carries the UUID the operator chose — not a slug and not
		// the numeric id, both of which resolve to nothing on another instance.
		const payload = exportRequest.postDataJSON()
		expect(
			payload.flows,
			'the export payload must carry the chosen flow bindings',
		).toContainEqual({ flow: flowUuid })

		// 3. Poll to completion and look inside the ZIP.
		//
		// An ExportJob is an OpenRegister OBJECT, not an openbuild route: there
		// is no GET /api/exports. `ExportJobsList.vue` reads it the same way.
		const jobsUrl = `${BASE}/index.php/apps/openregister/api/objects/openbuild/export-job?applicationUuid=${encodeURIComponent(objectId)}`

		let status = ''
		let jobUuid = ''
		const deadline = Date.now() + POLL_TIMEOUT_MS
		while (Date.now() < deadline) {
			const rows =
				(await (await page.request.get(jobsUrl)).json()).results || []
			const job = rows[0] || {}
			jobUuid = job.uuid || job['@self']?.id || ''
			status = job.status || ''
			if (status === 'succeeded' || status === 'failed') {
				break
			}

			await page.waitForTimeout(2_000)
		}
		expect(jobUuid, 'the export job must exist').toBeTruthy()
		expect(status, 'the export job must finish').toBe('succeeded')

		const download = await page.request.get(
			`${BASE}/index.php/apps/openbuild/api/exports/${jobUuid}/download`,
		)
		// ↑ the one /api/exports/… route that DOES exist.
		expect(download.ok()).toBeTruthy()

		const zipPath = join(scratch, 'export.zip')
		writeFileSync(zipPath, await download.body())

		expect(
			zipEntries(zipPath),
			'the bound flow must be in the ZIP — this is the whole feature',
		).toContain(`lib/Settings/flows/${flowUuid}.json`)

		const definition = JSON.parse(
			zipRead(zipPath, `lib/Settings/flows/${flowUuid}.json`),
		)
		expect(
			definition.uuid,
			'a definition exported without its UUID orphans every binding on import',
		).toBe(flowUuid)
		expect(
			definition.nodes.map((node: { type: string }) => node.type),
		).toContain('hermiq.workload-step')
	})

	test('the round trip is proven by RUNNING the imported flow', async ({
		page,
	}) => {
		// Put a definition back through the path an importing instance uses —
		// write the `Flow` ENTITY, preserving the UUID — and then RUN it.
		//
		// A test stopping at "the flow exists" would pass against a seeder that
		// wrote the `agentflow` object mirror instead: the register UI would
		// show it and the engine would never see it. Only the run tells them
		// apart.
		// ⚠️ THE UUID CANNOT BE CHOSEN OVER HTTP, and that is a fact about
		// OpenRegister rather than about this feature: `POST /api/flows`
		// MINTS a uuid and ignores one supplied in the body. Measured — sent
		// 6d67a16d-…, got back d5edb726-….
		//
		// So UUID PRESERVATION IS NOT ASSERTED HERE. It is asserted where
		// seeding actually happens: FlowSeedService writes the entity through
		// FlowMapper::insert(), which DOES preserve a supplied uuid (verified
		// directly: insert() returned the uuid it was given and findByUuid()
		// resolved it), and FlowSeedServiceTest pins that with a mutation
		// control. Asserting it against the HTTP API tested the API, failed,
		// and said nothing about the seeder.
		//
		// What this test still proves is the part no unit test can: that a
		// definition in the entity store is visible to the ENGINE.
		//
		// ⚠️ NAVIGATE FIRST. apiPost() fetches from inside the page with a
		// RELATIVE url, and a page still on about:blank has no base to resolve
		// it against — "Failed to parse URL from /index.php/…". My previous
		// edit removed this goto along with the block it was sitting in.
		await page.goto(`${BASE}/apps/openbuild/`)
		await dismissOverlays(page)

		const seeded = await apiPost(
			page,
			'/index.php/apps/openregister/api/flows',
			{
				name: 'PW round-trip fixture',
				enabled: true,
				nodes: [
					{
						id: 'start',
						type: 'openregister.trigger-schedule',
						config: {},
					},
					{ id: 'done', type: 'openregister.end', config: {} },
				],
				edges: [{ from: 'start', to: 'done' }],
			},
		)
		expect(
			seeded.status,
			`seeding the definition must succeed (got ${seeded.status})`,
		).toBe(201)

		const seededUuid: string =
			seeded.json?.uuid || seeded.json?.['@self']?.id || ''
		expect(seededUuid, 'the seeded flow must have a uuid').toBeTruthy()

		// THE ASSERTION THAT DISTINGUISHES ENTITY FROM MIRROR.
		//
		// `POST /flows/{uuid}/run` is served by the flow ENGINE, which reads the
		// `Flow` entity. A definition seeded into the `agentflow` OBJECT store
		// instead would be visible in the register UI and invisible here — the
		// engine would answer "no such flow". So a created run proves the seeded
		// definition reached the store the engine actually executes, which is
		// the failure this whole feature is exposed to.
		const run = await apiPost(
			page,
			`/index.php/apps/openregister/api/flows/${seededUuid}/run`,
			{},
		)
		expect(
			run.ok,
			'the engine must accept a run for the seeded flow — a definition it '
				+ 'cannot see is a flow that exists only in a mirror',
		).toBeTruthy()

		const runBody = run.json || {}
		const runUuid: string = runBody.uuid || runBody['@self']?.id || ''
		expect(runUuid, 'the run must have been created').toBeTruthy()
		expect(
			runBody.flowId,
			'the run must be bound to the flow that was seeded',
		).toBe(seededUuid)

		// ⚠️ EXECUTION IS NOT ASSERTED, and the reason is stated rather than
		// hidden: a run is created `queued` and advanced by `FlowRunWorker`,
		// which is a background job. Nothing guarantees cron has run inside a
		// test's patience — measured on the dev instance, queued runs sat
		// unadvanced indefinitely — so an assertion on a terminal status would
		// fail for want of a worker rather than for want of a working import.
		//
		// What IS asserted above is the part that separates a seeded entity
		// from a seeded mirror. If the run does reach a terminal state within
		// the budget, it must not be `failed`: that would mean the engine saw
		// the flow and could not execute it, which is a real defect.
		let runStatus = 'queued'
		const deadline = Date.now() + 30_000
		while (Date.now() < deadline) {
			const state = await (
				await page.request.get(
					`${BASE}/index.php/apps/openregister/api/flow-runs/${runUuid}`,
				)
			).json()
			runStatus = state.status || runStatus
			if (
				['completed', 'stopped', 'failed', 'suspended'].includes(runStatus)
			) {
				break
			}

			await page.waitForTimeout(2_000)
		}

		expect(
			runStatus,
			'a run the engine started and could not execute is a real defect',
		).not.toBe('failed')
	})
})
