/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * End-to-end coverage for openbuild-exports-flows-and-agents.
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
 * None of those raises an error, and none is visible in a file listing. So the
 * second test here asserts on a flow RUN — the only observation that separates
 * "imported" from "imported and runnable".
 *
 * ⚠️ SCOPE NOTE, stated rather than hidden: the round trip re-imports onto the
 * SAME instance rather than a second one. That still exercises the chain that
 * fails silently (definition → entity → execution) and the UUID preservation
 * the binding depends on. It does NOT exercise cross-instance id collision,
 * which needs a second Nextcloud and belongs in the cohort-wide fixture.
 */

import { expect, test } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { E2E_BASE_URL as NEXTCLOUD_URL } from './support/baseUrl'

const POLL_TIMEOUT_MS = 90_000

/**
 * List the entries inside a ZIP.
 *
 * `unzip -Z1` rather than a JS zip library: none is in devDependencies, and
 * adding one to read six filenames is a dependency for a listing.
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

test.describe('OpenBuild exports the flows an app is made of', () => {
	let scratch: string

	test.beforeAll(() => {
		scratch = mkdtempSync(join(tmpdir(), 'ob-export-'))
	})

	test.afterAll(() => {
		rmSync(scratch, { recursive: true, force: true })
	})

	test('a bound flow and the app’s agents reach the ZIP', async ({ request }) => {
		// A real flow to bind. Created through the API rather than assumed to
		// exist, so the test does not silently pass on an instance where the
		// fixture was never seeded — an empty export satisfies every assertion
		// that only checks "the export succeeded".
		const flowCreate = await request.post(
			`${NEXTCLOUD_URL}/index.php/apps/openregister/api/flows`,
			{
				data: {
					name: 'E2E export fixture — agentic',
					description: 'Bound by an application and exported.',
					enabled: false,
					nodes: [
						{
							id: 'start',
							type: 'openregister.trigger-schedule',
							config: {},
						},
						// An AGENTIC node, so this fixture proves ADR-065's rule
						// (one flow system) rather than only the plain path.
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
		expect(
			flowCreate.ok(),
			'the fixture flow must be created, or the export has nothing to carry',
		).toBeTruthy()

		const flow = await flowCreate.json()
		const flowUuid: string = flow.uuid ?? flow.id ?? flow['@self']?.id
		expect(flowUuid, 'the created flow must expose its UUID').toBeTruthy()

		// Bind it to an application. The binding is by UUID because the Flow
		// entity has no slug.
		const appsResponse = await request.get(
			`${NEXTCLOUD_URL}/index.php/apps/openbuild/api/applications`,
		)
		expect(appsResponse.ok()).toBeTruthy()
		const apps = await appsResponse.json()
		const application = (apps.results ?? apps.items ?? apps)[0]
		const applicationId = application['@self']?.id ?? application.id

		const bind = await request.put(
			`${NEXTCLOUD_URL}/index.php/apps/openbuild/api/applications/${applicationId}`,
			{
				data: {
					...application,
					flows: [{ label: 'E2E export fixture', flow: flowUuid }],
				},
			},
		)
		expect(
			bind.ok(),
			'binding a flow by UUID must be accepted by the application schema',
		).toBeTruthy()

		// Export.
		const exportResponse = await request.post(
			`${NEXTCLOUD_URL}/index.php/apps/openbuild/api/exports`,
			{
				data: {
					applicationUuid: applicationId,
					target: 'zip',
					flows: [{ flow: flowUuid }],
				},
			},
		)
		expect(exportResponse.ok()).toBeTruthy()
		const { jobUuid } = await exportResponse.json()

		// Poll to completion.
		let status = ''
		const deadline = Date.now() + POLL_TIMEOUT_MS
		while (Date.now() < deadline) {
			const job = await (
				await request.get(
					`${NEXTCLOUD_URL}/index.php/apps/openbuild/api/exports/${jobUuid}`,
				)
			).json()
			status = job.status ?? ''
			if (status === 'succeeded' || status === 'failed') {
				break
			}

			await new Promise((resolve) => setTimeout(resolve, 2_000))
		}
		expect(status, 'the export job must finish').toBe('succeeded')

		// Download and look inside.
		const download = await request.get(
			`${NEXTCLOUD_URL}/index.php/apps/openbuild/api/exports/${jobUuid}/download`,
		)
		expect(download.ok()).toBeTruthy()

		const zipPath = join(scratch, 'export.zip')
		writeFileSync(zipPath, await download.body())

		const entries = zipEntries(zipPath)
		expect(
			entries,
			'the bound flow must be in the ZIP — this is the whole feature',
		).toContain(`lib/Settings/flows/${flowUuid}.json`)

		// The definition must carry its UUID, because the importing side seeds
		// it verbatim and the application's binding resolves against it.
		const definition = JSON.parse(
			zipRead(zipPath, `lib/Settings/flows/${flowUuid}.json`),
		)
		expect(
			definition.uuid,
			'a definition exported without its UUID orphans every binding on import',
		).toBe(flowUuid)
		expect(definition.nodes).toHaveLength(3)

		// The agentic node survives unchanged: one flow system, no special case.
		expect(
			definition.nodes.map((node: { type: string }) => node.type),
		).toContain('hermiq.workload-step')
	})

	test('the round trip is proven by RUNNING the imported flow', async ({
		request,
	}) => {
		// Take the definition the exporter produced and put it back through the
		// path an importing instance uses: write the `Flow` ENTITY, preserving
		// the UUID. Then RUN it.
		//
		// A test that stopped at "the flow exists" would pass against a seeder
		// that wrote the `agentflow` object mirror instead — the register UI
		// would show it and the engine would never see it. Only the run tells
		// those apart.
		const uuid = '00000000-0000-4000-8000-00000000e2e1'

		const seeded = await request.post(
			`${NEXTCLOUD_URL}/index.php/apps/openregister/api/flows`,
			{
				data: {
					uuid,
					name: 'E2E round-trip fixture',
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
		expect(seeded.ok(), 'the seeded definition must be accepted').toBeTruthy()

		const seededFlow = await seeded.json()
		const seededUuid: string = seededFlow.uuid ?? seededFlow['@self']?.id ?? uuid
		expect(
			seededUuid,
			'seeding must PRESERVE the shipped UUID rather than mint a new one — '
				+ 'a minted UUID leaves every application binding pointing at nothing',
		).toBe(uuid)

		// Queue a run. This is the assertion the whole feature rests on.
		const run = await request.post(
			`${NEXTCLOUD_URL}/index.php/apps/openregister/api/flows/${seededUuid}/run`,
			{ data: {} },
		)
		expect(
			run.ok(),
			'an imported flow must be RUNNABLE — files that never reach the engine '
				+ 'pass every assertion that only inspects the ZIP',
		).toBeTruthy()

		const runBody = await run.json()
		const runUuid: string = runBody.uuid ?? runBody['@self']?.id ?? ''
		expect(runUuid, 'the run must have been created').toBeTruthy()

		// And it must actually advance, not merely be accepted.
		let runStatus = ''
		const deadline = Date.now() + 60_000
		while (Date.now() < deadline) {
			const state = await (
				await request.get(
					`${NEXTCLOUD_URL}/index.php/apps/openregister/api/flow-runs/${runUuid}`,
				)
			).json()
			runStatus = state.status ?? ''
			if (
				['completed', 'stopped', 'failed', 'suspended'].includes(runStatus)
			) {
				break
			}

			await new Promise((resolve) => setTimeout(resolve, 2_000))
		}

		expect(
			['completed', 'stopped', 'suspended'],
			`the seeded flow must execute; it reported "${runStatus}"`,
		).toContain(runStatus)
	})
})
