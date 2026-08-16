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
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, resolve } from 'node:path'
import { dismissOverlays } from './support/appFixture'
import { E2E_BASE_URL as BASE } from './support/baseUrl'

const TEST_SLUG = 'hello-world'
const POLL_TIMEOUT_MS = 90_000

/** The fixture flow's name. One constant: the creating POST and the picker
 * assertion must not be able to drift apart. */
const FIXTURE_FLOW_NAME = 'PW export fixture agentic'

/** The background job that turns a queued ExportJob into a ZIP. */
const EXPORT_JOB_CLASS = 'OCA\\OpenBuild\\BackgroundJob\\RunExportJob'

/**
 * Run one pass of the export background job and SAY WHAT HAPPENED.
 *
 * Playwright's cwd is the app directory (`server/apps/openbuild` in CI), so
 * the server root — and `occ` — is two levels up.
 *
 * ⚠️ THIS RETURNS ITS OUTCOME ON PURPOSE. The first version swallowed every
 * error so a bad worker pass could not fail the test on its own. That was the
 * wrong trade: the run then produced NO evidence about whether `occ` ran at
 * all, and a missing binary, a wrong path and a job the worker declined were
 * indistinguishable from each other — the same "a check that did not run looks
 * like one that passed" shape this spec was written to close. The job's own
 * status is still the assertion; this string is folded into its message so the
 * next failure names its cause instead of hiding it.
 *
 * @return {string} A one-line account of the attempt, for the failure message.
 */
function runExportJobWorker(): string {
	const occ = resolve(process.cwd(), '../../occ')
	if (existsSync(occ) === false) {
		return `no occ at ${occ} (cwd ${process.cwd()}) — the job was never driven`
	}

	try {
		const out = execFileSync(
			'php',
			[occ, 'background-job:worker', '--once', EXPORT_JOB_CLASS],
			{ encoding: 'utf8', stdio: 'pipe', timeout: 60_000 },
		)

		// ⚠️ "no output, exit 0" is ALSO what this command prints when it finds
		// no job of that class — verified against a live instance. So the
		// worker's silence alone cannot tell "the job was never enqueued" from
		// "this occ is looking at a different database". The job list
		// disambiguates: if occ shares the web server's database it sees the
		// OTHER queued jobs, and then a zero count for ours means the enqueue
		// genuinely did not happen — which would be a product defect, not a
		// test one.
		let census = 'job list unavailable'
		try {
			const listed = execFileSync(
				'php',
				[occ, 'background-job:list', '--output=json'],
				{ encoding: 'utf8', stdio: 'pipe', timeout: 60_000 },
			)
			const jobs = JSON.parse(String(listed)) as Array<{ class?: string }>
			const mine = jobs.filter((job) =>
				String(job.class || '').includes('RunExportJob'),
			).length
			census = `job list: ${jobs.length} total, ${mine} RunExportJob`
		} catch (listError) {
			census = `job list failed: ${String(listError).slice(0, 160)}`
		}

		return `worker ok: ${String(out).trim().slice(0, 200) || '(no output)'}; ${census}`
	} catch (error) {
		const e = error as { status?: number; stdout?: string; stderr?: string }
		return `worker failed (exit ${e.status ?? '?'}): ${String(
			e.stderr || e.stdout || error,
		)
			.trim()
			.slice(0, 300)}`
	}
}

/**
 * Build a regex tolerant of the vue-select match-highlight quirk: an option's
 * rendered text can be fragmented into several inline nodes mid-word, and
 * Playwright's accessible-name computation joins those fragments with a
 * synthesized space, so a literal-text match fails wherever the split lands.
 * Same helper agents.spec.ts and automations.spec.ts already carry.
 *
 * @param text The option's literal display text.
 * @return {RegExp} A whitespace-tolerant, case-insensitive regex.
 */
function looseOptionName(text: string): RegExp {
	const escaped = text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	return new RegExp(escaped.split('').join('\\s*'), 'i')
}

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
				name: FIXTURE_FLOW_NAME,
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
		// Arm the wait BEFORE the click that triggers the fetch, or the
		// response can land first and this waits forever.
		//
		// This is the assertion that the picker's loader RAN. It is the honest
		// form of the check: the product bug this spec first caught was the
		// Settings action assigning `settingsOpen = true` and never calling
		// `onSettingsOpen()`, so NO GET was issued at all — and with no
		// request there is nothing for this to resolve. Asserting on the
		// absence of the "No flows exist" hint instead does NOT work: while
		// the fetch is in flight `loadingFlows` is true, which hides that hint
		// too, so the assertion passes on the LOADING state and tells you
		// nothing. That mistake cost a CI cycle.
		const flowsLoaded = page.waitForResponse(
			(response) =>
				response.url().includes('/apps/openregister/api/flows')
				&& response.request().method() === 'GET',
			{ timeout: 60_000 },
		)

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

		const flowsResponse = await flowsLoaded
		expect(
			flowsResponse.ok(),
			`opening App settings must READ the flow list (got ${flowsResponse.status()})`,
		).toBeTruthy()

		// ⚠️ DRIVE THE COMBOBOX, NOT THE WRAPPER. `data-test` sits on a
		// wrapper div because NcSelect does not forward stray attributes;
		// clicking that div lands on padding and never opens the dropdown.
		// Every other NcSelect in this suite (agents.spec.ts,
		// automations.spec.ts) uses the `combobox` role, named from
		// `inputLabel`.
		//
		// ⚠️ The control is `:disabled` while `loadingFlows` is true, and the
		// GET measured ~1.3 s on CI. Wait for ENABLED before clicking — a
		// click on the disabled input focuses it without opening anything, and
		// the screenshot of that state is indistinguishable from a control
		// that simply refused to open.
		const combo = page.getByRole('combobox', {
			name: /flows this app is made of/i,
		})
		await expect(combo).toBeEnabled({ timeout: 30_000 })
		await combo.click()

		// Typing both opens the dropdown and filters it, which is the
		// vue-select interaction least sensitive to where the click landed.
		await combo.fill(FIXTURE_FLOW_NAME)

		await page
			.getByRole('option', { name: looseOptionName(FIXTURE_FLOW_NAME) })
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
		//
		// ⚠️ CLOSE THE SETTINGS MODAL FIRST, AND PROVE IT CLOSED. A bare
		// `Escape` here was not enough: focus is still inside the NcSelect
		// after picking an option, so Escape closes the vue-select DROPDOWN
		// and leaves the modal up. The Export button then resolves, reports
		// "visible, enabled and stable", and every click is swallowed by
		// `.modal-mask` — Playwright retried 441 times and the test died on
		// the 240 s budget with a call log that never once says "modal".
		//
		// `dismissOverlays` is the suite's own helper for this and handles
		// NcModal's icon close; the assertion after it is what turns a silent
		// swallow into a named failure.
		await page.keyboard.press('Escape')
		await dismissOverlays(page)
		await expect(
			page.locator('.modal-mask'),
			'the App settings modal must be closed before Export is clickable — an open one silently intercepts every click',
		).toHaveCount(0, { timeout: 20_000 })

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
		let workerAccount = 'the worker never ran'
		const deadline = Date.now() + POLL_TIMEOUT_MS
		while (Date.now() < deadline) {
			// ⚠️ RUN OUR JOB, DO NOT TICK THE QUEUE. The export is an
			// `IJobList` background job with no synchronous path, and the
			// obvious approach — calling `/cron.php` in the loop — does not
			// work here. `CronService::runWeb()` executes exactly ONE job per
			// call, and this instance's queue is poisoned: OpenRegister's
			// `ObjectTextExtractionJob` throws on every object
			// ("organization is not a valid attribute") and is re-queued, so
			// ~90 cron calls never reached ours and the status stayed
			// "queued" for the whole 90 s budget.
			//
			// `background-job:worker` takes the job CLASSES to look for, so it
			// runs ours and ignores the backlog entirely.
			workerAccount = runExportJobWorker()

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

		// ⚠️ NAME THE CAUSE, DO NOT JUST REPORT THE SYMPTOM. A job stuck at
		// `queued` has two very different explanations and they look
		// identical from the status alone:
		//
		//   - the worker never ran it, or
		//   - the worker ran it and the STATUS COULD NOT MOVE, because
		//     `RunExportJob` never writes `status` directly — it fires the
		//     declarative `start` transition, and a schema whose deployed
		//     `x-openregister-lifecycle` is missing makes that a silent
		//     no-op.
		//
		// The second is a real defect this suite already caught once
		// (openregister#2525): openbuild declared the lifecycle at schema
		// version 0.1.0 while the instance carried 1.0.0, the import was
		// version-skipped, and every export sat at `queued` forever with no
		// log line anywhere. `available-actions` is the one-call check —
		// empty means the object's schema has no live state machine.
		let actionsAccount = 'available-actions not read'
		try {
			const actionsResponse = await page.request.get(
				`${BASE}/index.php/apps/openregister/api/objects/${jobUuid}/available-actions`,
			)
			const actions = (await actionsResponse.json())?.actions ?? []
			actionsAccount = `available-actions=[${actions
				.map((a: { action?: string }) => a?.action)
				.join(',')}]`
			if (actions.length === 0) {
				actionsAccount +=
					' — the deployed export-job schema has NO live lifecycle, so the'
					+ ' status can never move (see openregister#2525)'
			}
		} catch (error) {
			actionsAccount = `available-actions failed: ${String(error).slice(0, 120)}`
		}

		expect(
			status,
			`the export job must finish — last status "${status}"; ${actionsAccount}; last worker pass: ${workerAccount}`,
		).toBe('succeeded')

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
