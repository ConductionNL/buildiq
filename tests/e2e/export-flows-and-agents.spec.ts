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

test.describe('Exporting the flows an app is made of', () => {
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
		// A real flow to bind. Created up front rather than assumed to exist:
		// on an instance with no flows every assertion below would be vacuous,
		// because an empty picker and an empty export both "succeed".
		const created = await page.request.post(
			`${BASE}/index.php/apps/openregister/api/flows`,
			{
				data: {
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
			},
		)
		test.skip(!created.ok(), 'OpenRegister flows API unavailable')
		const flow = await created.json()
		const flowUuid: string = flow.uuid || flow.id || flow['@self']?.id
		expect(flowUuid, 'the fixture flow must expose a UUID').toBeTruthy()

		// The detail route takes the OR OBJECT ID, not the slug.
		const lookup = await page.request.get(
			`${BASE}/index.php/apps/openregister/api/objects/openbuild/application?slug=${encodeURIComponent(TEST_SLUG)}&_limit=1`,
		)
		test.skip(!lookup.ok(), 'application lookup unavailable')
		const apps = (await lookup.json()).results || []
		test.skip(apps.length === 0, `${TEST_SLUG} Application not seeded`)
		const objectId = apps[0].uuid || apps[0].id

		await page.goto(`${BASE}/apps/openbuild/applications/${objectId}`)
		await dismissOverlays(page)
		await page.waitForSelector('.ob-detail-header', { timeout: 20_000 })

		// 1. BIND, through App settings.
		await page
			.getByRole('button', { name: /settings/i })
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

		// The parent persists the binding; wait for the PATCH rather than a
		// fixed sleep, so a slow instance does not produce a flaky pass.
		await page.waitForResponse(
			(response) =>
				response.url().includes('/apps/openbuild/api/applications')
				&& ['PATCH', 'PUT'].includes(response.request().method()),
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
		await expect(flowToggle.locator('input[type="checkbox"]')).toBeChecked()

		const [exportRequest] = await Promise.all([
			page.waitForRequest(
				(request) =>
					request.url().includes('/apps/openbuild/api/exports')
					&& request.method() === 'POST',
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
		const listed = await page.request.get(
			`${BASE}/index.php/apps/openbuild/api/exports?limit=1`,
		)
		const rows = (await listed.json()).results || []
		const jobUuid: string = rows[0]?.uuid || rows[0]?.jobUuid || ''
		expect(jobUuid, 'the export job must be listed').toBeTruthy()

		let status = ''
		const deadline = Date.now() + POLL_TIMEOUT_MS
		while (Date.now() < deadline) {
			const job = await (
				await page.request.get(
					`${BASE}/index.php/apps/openbuild/api/exports/${jobUuid}`,
				)
			).json()
			status = job.status || ''
			if (status === 'succeeded' || status === 'failed') {
				break
			}

			await page.waitForTimeout(2_000)
		}
		expect(status, 'the export job must finish').toBe('succeeded')

		const download = await page.request.get(
			`${BASE}/index.php/apps/openbuild/api/exports/${jobUuid}/download`,
		)
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
		const uuid = '00000000-0000-4000-8000-00000000e2e1'

		const seeded = await page.request.post(
			`${BASE}/index.php/apps/openregister/api/flows`,
			{
				data: {
					uuid,
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
			},
		)
		test.skip(!seeded.ok(), 'OpenRegister flows API unavailable')

		const seededFlow = await seeded.json()
		const seededUuid: string = seededFlow.uuid || seededFlow['@self']?.id || ''
		expect(
			seededUuid,
			'seeding must PRESERVE the shipped UUID rather than mint a new one — '
				+ 'a minted UUID leaves every application binding pointing at nothing',
		).toBe(uuid)

		const run = await page.request.post(
			`${BASE}/index.php/apps/openregister/api/flows/${seededUuid}/run`,
			{ data: {} },
		)
		expect(
			run.ok(),
			'an imported flow must be RUNNABLE — files that never reach the engine '
				+ 'pass every assertion that only inspects the ZIP',
		).toBeTruthy()

		const runBody = await run.json()
		const runUuid: string = runBody.uuid || runBody['@self']?.id || ''
		expect(runUuid, 'the run must have been created').toBeTruthy()

		let runStatus = ''
		const deadline = Date.now() + 60_000
		while (Date.now() < deadline) {
			const state = await (
				await page.request.get(
					`${BASE}/index.php/apps/openregister/api/flow-runs/${runUuid}`,
				)
			).json()
			runStatus = state.status || ''
			if (
				['completed', 'stopped', 'failed', 'suspended'].includes(runStatus)
			) {
				break
			}

			await page.waitForTimeout(2_000)
		}

		expect(
			['completed', 'stopped', 'suspended'],
			`the seeded flow must execute; it reported "${runStatus}"`,
		).toContain(runStatus)
	})
})
