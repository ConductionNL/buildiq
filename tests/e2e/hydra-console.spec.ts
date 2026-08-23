// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { test, expect, Page, APIRequestContext } from '@playwright/test'

/**
 * E2E — the `hydra-console` Buildiq virtual app, live, against the
 * real `hydra-cache` register (register 2512).
 *
 * ROUTING FORM (read this before touching a `goto` here)
 * ------------------------------------------------------
 * `src/builder.js` boots the runtime with
 *   `new VueRouter({ mode: 'history', base: generateUrl('/apps/buildiq/builder/' + slug) })`
 * — a HISTORY router, NOT a hash router. A path-form `goto` is therefore the
 * correct navigation form and really does land on the requested page. To make
 * that verifiable rather than assumed, every helper below asserts BOTH:
 *   1. the post-boot `location.href` still matches the requested path, and
 *   2. a page-unique marker is on screen, while the Dashboard-only KPI caption
 *      "pipeline runs in the cache" is absent.
 * (2) is the one that matters: a router that silently fell back to `/` would
 * still leave the URL alone in some setups, but it could never hide the
 * Dashboard caption. Do not delete it.
 *
 * DATA
 * ----
 * Read-only. Expected counts are fetched from the OpenRegister API at run time
 * and cross-checked against what the UI paints, so a reseed changes both sides
 * together; the *business* keys the seed guarantees (`decidesk`,
 * `p2-agenda-management`, finding `b1`, gate `spdx-headers`) are hard-asserted.
 *
 * RUNNING
 * -------
 *   BUILDIQ_SEED_CMD=true npx playwright test tests/e2e/hydra-console.spec.ts
 *
 * `BUILDIQ_SEED_CMD=true` neutralises globalSetup's hello-world `occ` seed —
 * this suite needs no fixture seeding and must not mutate the instance.
 *
 * KNOWN-BROKEN BEHAVIOUR ENCODED HERE (see the individual tests)
 *  - the three ChangeDetail command buttons render but cannot fire
 *    (ConductionNL/openconnector#1068) — asserted as render-only.
 *  - the hermiq agent leaf widget paints nothing at all
 *    (nc-vue beta.221 never calls `mount()` for `renderMode:'mount'`,
 *    hermiq#42 / #44) — asserted as the honest absent state.
 *  - the audit-trail widget can never show data: CnAuditTrailCard requests
 *    `…/{id}/audit-trail` (singular) while OpenRegister routes
 *    `…/{id}/audit-trails` (plural) → 404 → permanent "No audit entries yet".
 *    Encoded with `test.fail()` so it is recorded as a known defect and flips
 *    to an *unexpected pass* the moment it is fixed.
 */

const APP_BASE = '/apps/buildiq/builder/hydra-console'
const OR_API = '/index.php/apps/openregister/api/objects/hydra-cache'

/** Detail/index pages hydrate over several sequential OR round-trips on a
 *  loaded dev instance; 20 s+ to first paint of the data widgets is normal. */
const SLOW = 60_000

/** Caption that only ever appears on the Dashboard's "Cycles" KPI card. */
const DASHBOARD_ONLY = 'pipeline runs in the cache'

function escapeRe(s: string): string {
	return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/** GET an OpenRegister objects endpoint and return the parsed body. */
async function orGet(request: APIRequestContext, path: string): Promise<any> {
	const res = await request.get(`${OR_API}${path}`, {
		headers: { 'OCS-APIRequest': 'true' },
	})
	expect(res.ok(), `${OR_API}${path} must respond 2xx`).toBeTruthy()
	return res.json()
}

/**
 * Navigate to a hydra-console page and PROVE we landed on it.
 *
 * @param page       Playwright page.
 * @param path       Path relative to the app base, e.g. '/changes'.
 * @param marker     A locator-less text fragment unique to the target page.
 * @param isDashboard Whether the target IS the Dashboard (skips the negative check).
 */
async function openConsolePage(
	page: Page,
	path: string,
	marker: string | RegExp,
	isDashboard = false,
): Promise<void> {
	await page.goto(`${APP_BASE}${path}`)

	// The app shell must have booted (menu comes from the manifest).
	await expect(
		page
			.getByRole('link', { name: 'Findings' })
			.or(page.getByText('Pipeline', { exact: true }))
			.first(),
		'the hydra-console app shell must boot',
	).toBeVisible({ timeout: SLOW })

	// The router must not have rewritten us somewhere else. `/` normalises to a
	// trailing slash; sub-paths keep their exact form.
	await expect(page).toHaveURL(new RegExp(`${escapeRe(APP_BASE + path)}/?$`))

	// The page's own content must be on screen…
	await expect(
		page.getByText(marker).first(),
		`page "${path}" must render its own marker`,
	).toBeVisible({ timeout: SLOW })

	// …and, unless this IS the Dashboard, the Dashboard must not be.
	if (isDashboard === false) {
		await expect(
			page.getByText(DASHBOARD_ONLY),
			`page "${path}" must NOT be silently rendering the Dashboard`,
		).toHaveCount(0)
	}
}

/**
 * Whether the `hydra-cache` register is actually present on this instance.
 *
 * Resolved once in beforeAll and asserted per test, so the suite reports
 * "environment-gated" rather than a wall of identical 404s.
 */
let hydraCacheAvailable = false

/**
 * Probe for the `hydra-cache` register this whole suite reads from.
 *
 * A 2xx on a minimal `finding` query means the register exists and is
 * queryable; OpenRegister answers `{"message":"Register not found:
 * 'hydra-cache'"}` with 404 when it does not. Only a genuine absence is
 * treated as "unavailable" — any OTHER non-2xx (500, 401, …) deliberately
 * leaves the flag true so a broken-but-present register still fails loudly
 * instead of being quietly skipped.
 *
 * @param request Playwright API request context.
 * @return {Promise<boolean>} True when the register is present and queryable.
 */
async function probeHydraCache(request: APIRequestContext): Promise<boolean> {
	const res = await request
		.get(`${OR_API}/finding?_limit=1`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		.catch(() => null)
	if (res === null) {
		return false
	}
	if (res.ok() === true) {
		return true
	}
	if (res.status() === 404) {
		const body = await res.text().catch(() => '')
		// Only a "Register not found" 404 means absent. A 404 from anything else
		// (a moved route, say) is a real failure and must not silence the suite.
		return /register not found/i.test(body) === false
	}
	return true
}

test.describe('hydra-console — live console over the hydra-cache register', () => {
	test.describe.configure({ timeout: 180_000 })

	test.beforeAll(async ({ request }) => {
		hydraCacheAvailable = await probeHydraCache(request)
	})

	// This suite is READ-ONLY against an externally provisioned register
	// (`hydra-cache`, register 2512 on the shared dev instance) that it
	// deliberately refuses to create — see the DATA note in the file header.
	// Nothing in this repository seeds it: `hydra-console.spec.ts` is the only
	// file that mentions `hydra-cache` at all. On an instance without it every
	// test here fails with the same `must respond 2xx` 404, which is an
	// environment gap, not a defect in Buildiq.
	//
	// The guard below is a REAL capability probe: it performs an actual query
	// and fires only when OpenRegister reports the register missing. It is not
	// a blanket `.skip` and not one of the status-code guards elsewhere in this
	// repo that can never distinguish "cannot run" from "is broken" — point it
	// at an instance that HAS hydra-cache and every test runs and asserts.
	test.beforeEach(() => {
		test.skip(
			hydraCacheAvailable === false,
			'hydra-cache register is not provisioned on this instance — this suite is '
				+ 'read-only against an externally seeded register and creates no data. '
				+ 'Seed hydra-cache (or point PLAYWRIGHT_BASE_URL at an instance that has '
				+ 'it) to run these tests.',
		)
	})

	test("Dashboard KPI cards paint the register's REAL counts", async ({
		page,
		request,
	}) => {
		// Ground truth first — the UI must match the API, and both must be non-empty.
		const [cycles, needsInput, openFindings, changes] = await Promise.all([
			orGet(request, '/cycle?_limit=1'),
			orGet(request, '/cycle?_limit=1&outcome=needs-input'),
			orGet(request, '/finding?_limit=1&status=open'),
			orGet(request, '/change?_limit=1'),
		])
		for (const [label, body] of Object.entries({
			cycles,
			needsInput,
			openFindings,
			changes,
		})) {
			expect(
				body.total,
				`seed precondition: ${label} must be > 0`,
			).toBeGreaterThan(0)
		}

		await openConsolePage(page, '/', DASHBOARD_ONLY, true)

		const card = (caption: string) =>
			page.locator('.cn-grid__item').filter({ hasText: caption })

		await expect(card('pipeline runs in the cache')).toHaveText(
			new RegExp(`Cycles\\s*${cycles.total}\\s*pipeline runs in the cache`),
			{ timeout: SLOW },
		)
		await expect(card('escalated to a human')).toHaveText(
			new RegExp(`Needs input\\s*${needsInput.total}\\s*escalated to a human`),
			{ timeout: SLOW },
		)
		await expect(card('still waiting on a fix')).toHaveText(
			new RegExp(
				`Open findings\\s*${openFindings.total}\\s*still waiting on a fix`,
			),
			{ timeout: SLOW },
		)
		await expect(card('specs the pipeline is tracking')).toHaveText(
			new RegExp(
				`Changes\\s*${changes.total}\\s*specs the pipeline is tracking`,
			),
			{ timeout: SLOW },
		)
	})

	test('Dashboard charts paint real facet buckets, not empty canvases', async ({
		page,
		request,
	}) => {
		// The gate facet is the bucket set behind "Findings by gate".
		const facet = await orGet(
			request,
			'/finding?_limit=1&_facets%5Bgate%5D%5Btype%5D=terms',
		)
		const gateBuckets: Array<{ value: string }> =
			facet?.facets?.gate?.data?.buckets ?? []
		expect(
			gateBuckets.length,
			'seed precondition: gate facet must have buckets',
		).toBeGreaterThan(0)
		expect(
			gateBuckets.map((b) => b.value),
			'seed precondition: the spdx-headers gate bucket must exist',
		).toContain('spdx-headers')

		await openConsolePage(page, '/', DASHBOARD_ONLY, true)

		// Every apexcharts canvas the manifest declares must exist…
		await expect(page.locator('.apexcharts-canvas')).toHaveCount(6, {
			timeout: SLOW,
		})

		// …and carry real category labels. These strings only exist because the
		// seeded objects exist; an empty chart cannot produce them.
		for (const bucket of gateBuckets.map((b) => b.value)) {
			await expect(
				page
					.locator('.apexcharts-canvas')
					.filter({ hasText: bucket })
					.first(),
				`the "Findings by gate" chart must render the "${bucket}" bucket`,
			).toBeVisible({ timeout: SLOW })
		}
		// Cross-chart spot checks against other schemas' facets.
		await expect(
			page
				.locator('.apexcharts-canvas')
				.filter({ hasText: 'decidesk' })
				.first(),
			'the "Changes by app" chart must render the decidesk bucket',
		).toBeVisible({ timeout: SLOW })
		await expect(
			page
				.locator('.apexcharts-canvas')
				.filter({ hasText: 'CRITICAL' })
				.first(),
			'the "Findings by severity" chart must render the CRITICAL bucket',
		).toBeVisible({ timeout: SLOW })
	})

	test('Changes index lists the seeded change row', async ({ page, request }) => {
		const changes = await orGet(request, '/change?_limit=20')
		const seeded = changes.results.find(
			(c: any) => c.slug === 'p2-agenda-management',
		)
		expect(
			seeded,
			'seed precondition: change p2-agenda-management must exist',
		).toBeTruthy()

		await openConsolePage(page, '/changes', 'p2-agenda-management')

		// The row must carry the seeded field values, not just be a table shell.
		const row = page.getByRole('row').filter({ hasText: 'p2-agenda-management' })
		await expect(row).toHaveCount(1)
		await expect(row).toContainText('decidesk')
		await expect(row).toContainText('needs-input')
		// Result-count banner proves the store actually loaded rows.
		await expect(
			page.getByText(new RegExp(`Showing \\d+ of ${changes.total}`)),
		).toBeVisible({ timeout: SLOW })
	})

	test('Findings index lists every seeded finding, including the spdx-headers CRITICAL', async ({
		page,
		request,
	}) => {
		const findings = await orGet(request, '/finding?_limit=50')
		expect(
			findings.total,
			'seed precondition: findings must exist',
		).toBeGreaterThan(0)

		await openConsolePage(page, '/findings', 'spdx-headers')

		await expect(
			page.getByText(new RegExp(`Showing \\d+ of ${findings.total}`)),
		).toBeVisible({ timeout: SLOW })

		const critical = page.getByRole('row').filter({ hasText: 'spdx-headers' })
		await expect(critical).toHaveCount(1)
		await expect(critical).toContainText('CRITICAL')
		await expect(critical).toContainText('missing SPDX-License-Identifier')
		await expect(critical).toContainText('lib/Service/MeetingService.php')

		// One row per seeded finding (header row excluded).
		await expect(page.getByRole('row')).toHaveCount(findings.total + 1, {
			timeout: SLOW,
		})
	})

	test('Cycles index lists the seeded cycle with its real outcome', async ({
		page,
		request,
	}) => {
		const cycles = await orGet(request, '/cycle?_limit=20')
		expect(cycles.total, 'seed precondition: cycles must exist').toBeGreaterThan(
			0,
		)

		await openConsolePage(
			page,
			'/cycles',
			/phpcs failures persisted after reviewer fixes/,
		)

		const row = page
			.getByRole('row')
			.filter({ hasText: 'phpcs failures persisted after reviewer fixes' })
		await expect(row).toHaveCount(1)
		await expect(row).toContainText(/needs-input/i)
		await expect(row).toContainText('3,603')
	})

	test('ChangeDetail renders its widget grid — data, related cycles and the audit-trail widget', async ({
		page,
		request,
	}) => {
		const changes = await orGet(request, '/change?_limit=20')
		const seeded = changes.results.find(
			(c: any) => c.slug === 'p2-agenda-management',
		)
		expect(
			seeded,
			'seed precondition: change p2-agenda-management must exist',
		).toBeTruthy()

		await openConsolePage(page, `/changes/${seeded.id}`, 'p2-agenda-management')

		// 1. The `change-core` data widget, with real field values.
		const dataCard = page
			.locator('.cn-widget-wrapper')
			.filter({ hasText: 'Application' })
		await expect(dataCard).toContainText('decidesk', { timeout: SLOW })
		await expect(dataCard).toContainText('Conduction/decidesk')
		await expect(dataCard).toContainText('feature/p2-agenda-management')
		await expect(dataCard).toContainText('Agenda management for decidesk')

		// 2. The `change-cycles` object-list widget, resolving @objectId.
		const cyclesCard = page
			.locator('.cn-widget-wrapper')
			.filter({ hasText: 'TRIGGER' })
		await expect(cyclesCard).toContainText('build:queued', { timeout: SLOW })
		await expect(cyclesCard).toContainText('needs-input')

		// 3. The `change-audit` audit-trail widget is part of the grid.
		await expect(
			page.getByRole('heading', { name: 'Audit trail' }),
			'the audit-trail widget must be present in the detail grid',
		).toBeVisible({ timeout: SLOW })
	})

	test('ChangeDetail renders the three command buttons (render-only — openconnector#1068)', async ({
		page,
		request,
	}) => {
		// KNOWN-TRUE: these buttons POST to /apps/openconnector/api/endpoint/hydra/label,
		// which cannot authenticate an NC session (ConductionNL/openconnector#1068).
		// This test therefore asserts they RENDER and are enabled — it deliberately
		// does NOT click them. Do not "strengthen" this into a click assertion until
		// #1068 is closed; it would only ever prove the failure toast.
		const changes = await orGet(request, '/change?_limit=20')
		const seeded = changes.results.find(
			(c: any) => c.slug === 'p2-agenda-management',
		)
		expect(
			seeded,
			'seed precondition: change p2-agenda-management must exist',
		).toBeTruthy()
		// "Re-dispatch" carries visibleWhen pipelineState == needs-input.
		expect(
			seeded.pipelineState,
			'seed precondition for the Re-dispatch visibleWhen guard',
		).toBe('needs-input')

		await openConsolePage(page, `/changes/${seeded.id}`, 'p2-agenda-management')

		for (const label of [
			'Re-dispatch',
			'Mark needs-input',
			'Rebuild from scratch',
		]) {
			const button = page.getByRole('button', { name: label, exact: true })
			await expect(button, `header action "${label}" must render`).toBeVisible(
				{ timeout: SLOW },
			)
			await expect(button).toBeEnabled()
		}
	})

	test('CycleDetail renders core data, the stage summary and the findings list', async ({
		page,
		request,
	}) => {
		const cycles = await orGet(request, '/cycle?_limit=20')
		const cycle = cycles.results[0]
		expect(cycle, 'seed precondition: a cycle must exist').toBeTruthy()

		await openConsolePage(
			page,
			`/cycles/${cycle.id}`,
			/phpcs failures persisted after reviewer fixes/,
		)

		// `cycle-core` data widget.
		const coreCard = page
			.locator('.cn-widget-wrapper')
			.filter({ hasText: 'Outcome reason' })
		await expect(coreCard).toContainText('needs-input', { timeout: SLOW })
		await expect(coreCard).toContainText('build:queued')
		await expect(coreCard).toContainText('reviewer-skipped-full-suite')

		// `cycle-stages` data widget — the nested stages array rendered as a table.
		const stagesCard = page
			.locator('.cn-widget-wrapper')
			.filter({ hasText: 'Stage summary' })
		await expect(stagesCard).toContainText('Al Gorithm', { timeout: SLOW })
		await expect(stagesCard).toContainText('Juan Claude van Damme')
		await expect(stagesCard).toContainText('quality-recheck')

		// `cycle-findings` object-list, scoped to this cycle via @objectId.
		const cycleFindings = await orGet(
			request,
			`/finding?_limit=1&cycle=${cycle.id}`,
		)
		expect(
			cycleFindings.total,
			'seed precondition: the cycle must have findings',
		).toBeGreaterThan(0)
		const findingsCard = page
			.locator('.cn-widget-wrapper')
			.filter({ hasText: 'SEVERITY' })
		await expect(findingsCard).toContainText('spdx-headers', { timeout: SLOW })
		await expect(findingsCard).toContainText('fixed_in_stage')

		// audit-trail widget present in the grid.
		await expect(page.getByRole('heading', { name: 'Audit trail' })).toBeVisible(
			{ timeout: SLOW },
		)
	})

	test('FindingDetail renders the seeded b1 finding', async ({
		page,
		request,
	}) => {
		const findings = await orGet(request, '/finding?_limit=50')
		const b1 = findings.results.find((f: any) => f.findingId === 'b1')
		expect(b1, 'seed precondition: finding b1 must exist').toBeTruthy()

		await openConsolePage(
			page,
			`/findings/${b1.id}`,
			'missing SPDX-License-Identifier',
		)

		const card = page
			.locator('.cn-widget-wrapper')
			.filter({ hasText: 'Finding id' })
		await expect(card).toContainText('b1', { timeout: SLOW })
		await expect(card).toContainText('spdx-headers')
		await expect(card).toContainText('CRITICAL')
		await expect(card).toContainText('fixed_in_stage')
		await expect(card).toContainText('lib/Service/MeetingService.php')
		await expect(card).toContainText('Header added during build.')

		await expect(page.getByRole('heading', { name: 'Audit trail' })).toBeVisible(
			{ timeout: SLOW },
		)
	})

	test('hermiq agent leaf widget paints nothing (hermiq#42 / #44)', async ({
		page,
		request,
	}) => {
		// KNOWN-TRUE: the manifest declares an `integration` widget
		// (integrationId `hermiq-agent`, title "Agent") on ChangeDetail,
		// CycleDetail and FindingDetail. nc-vue beta.221 never calls `mount()`
		// for a `renderMode:'mount'` leaf, so the widget produces no card at all
		// — not even an empty shell. This test pins that honest state; when
		// hermiq#42/#44 lands it will fail and must be rewritten to assert the
		// rendered leaf.
		const changes = await orGet(request, '/change?_limit=20')
		const seeded = changes.results.find(
			(c: any) => c.slug === 'p2-agenda-management',
		)
		await openConsolePage(page, `/changes/${seeded.id}`, 'p2-agenda-management')

		// Wait for a sibling widget so the grid is definitely done rendering.
		await expect(page.getByRole('heading', { name: 'Audit trail' })).toBeVisible(
			{ timeout: SLOW },
		)

		await expect(
			page.locator('.cn-widget-wrapper').filter({ hasText: 'Agent' }),
			'hermiq#42/#44: the hermiq-agent leaf currently renders no widget card',
		).toHaveCount(0)
	})

	test("audit-trail widget shows the object's real history", async ({
		page,
		request,
	}) => {
		// DEFECT (open): CnAuditTrailCard.vue:130 builds
		//   `${apiBase}/objects/${register}/${schema}/${objectId}/audit-trail?…`
		// but OpenRegister routes `…/{id}/audit-trails` (PLURAL, appinfo/routes.php
		// `auditTrail#objects`). The singular URL 404s, the widget swallows it and
		// paints "No audit entries yet" — a green-but-dead empty state on top of
		// real audit rows. `test.fail()` records this: it flips to an UNEXPECTED
		// PASS the moment the URL is fixed.
		test.fail()

		const changes = await orGet(request, '/change?_limit=20')
		const seeded = changes.results.find(
			(c: any) => c.slug === 'p2-agenda-management',
		)

		// Prove the audit rows genuinely exist on the plural endpoint.
		const trail = await request.get(
			`${OR_API}/change/${seeded.id}/audit-trails?limit=5`,
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		expect(
			trail.ok(),
			'the PLURAL audit-trails endpoint must respond 2xx',
		).toBeTruthy()
		const trailBody = await trail.json()
		expect(
			trailBody.results.length,
			'the change must have audit entries',
		).toBeGreaterThan(0)

		await openConsolePage(page, `/changes/${seeded.id}`, 'p2-agenda-management')
		await expect(page.getByRole('heading', { name: 'Audit trail' })).toBeVisible(
			{ timeout: SLOW },
		)

		await expect(
			page.getByText('No audit entries yet'),
			'the audit-trail widget must not claim an empty history when audit rows exist',
		).toHaveCount(0, { timeout: SLOW })
	})

	test('detail widgets use their manifest-declared titles', async ({
		page,
		request,
	}) => {
		// DEFECT (open): the manifest titles ChangeDetail's `change-core` widget
		// "Change" and CycleDetail's `cycle-core`/`cycle-stages` "Cycle"/"Stages",
		// but every `type: "data"` widget renders the generic label "Data" —
		// the configured title is dropped by the detail-page renderer. Recorded
		// with `test.fail()`; flips to an unexpected pass when fixed.
		test.fail()

		const changes = await orGet(request, '/change?_limit=20')
		const seeded = changes.results.find(
			(c: any) => c.slug === 'p2-agenda-management',
		)
		await openConsolePage(page, `/changes/${seeded.id}`, 'p2-agenda-management')

		const dataCard = page
			.locator('.cn-widget-wrapper')
			.filter({ hasText: 'Application' })
		await expect(dataCard).toContainText('decidesk', { timeout: SLOW })
		await expect(
			dataCard.locator('.cn-widget-wrapper__title'),
			'the data widget must show its manifest title "Change", not the generic "Data"',
		).toHaveText('Change')
	})
})
