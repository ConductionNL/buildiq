// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the `buildiq-runtime` spec — UI scenarios.
 *
 * UN-QUARANTINED 2026-07-30. The whole file used to sit behind a blanket
 * `test.skip` citing Conduction/buildiq#41 ("buildiq admin UI not
 * functional in this build — no detail / editor / version / diff / rollback UI;
 * Schemas page misconfigured"). That reason is stale: the detail page, its
 * Manifest / Version history / Diff sidebar tabs, the builder host and the
 * per-app Schemas route all render on a live instance.
 *
 * Un-skipping alone would have been worse than the skip, though. The bodies
 * asserted almost nothing — `expect(page.locator('main')).toBeVisible()` stood
 * in for "the seeded index page renders", and half of them wrapped their only
 * real assertion in `if (await x.count() > 0)`, so they passed while asserting
 * nothing at all. Every body below was rewritten against the requirement text
 * in openspec/specs/openbuild-runtime/spec.md and driven against a live
 * instance.
 *
 * Where the shipped surface no longer matches the requirement as written, the
 * test stays skipped with the REAL reason (never "#41"), and the divergence is
 * spelled out at the skip. Those are spec-drift findings, not environment
 * problems.
 *
 * Covers:
 *   REQ-OBR-002:  nested CnAppRoot mount inside BuilderHost
 *   REQ-OBR-003:  path segments after the slug forward to the inner router
 *   REQ-OBR-004:  seeded hello-world renders index / detail / form; idempotent
 *   REQ-OBR-005:  raw-JSON manifest editor validates and persists
 *   REQ-OBR-006a: schema-designer route vs. virtual-app preview route
 *   REQ-OBR-007a: Schemas menu entry in the builder context
 *   REQ-OBR-006b: publish action + validation gate
 *   REQ-OBR-007b: draft-vs-published indicator
 *   REQ-OBR-008a: VersionHistory panel renders snapshots
 *   REQ-OBR-009a: rollback action in version history
 *   REQ-OBR-010:  ManifestDiff view
 *   REQ-OBR-007c: application list filters by role
 *   REQ-OBR-008b: editor gates actions by role
 *
 * Backend requirements excluded in the spec (manifest endpoint contract, MCP,
 * IInitialState, ApplicationCard icon duplicate) are not repeated here.
 */

import { test, expect, type Page, type APIRequestContext } from '@playwright/test'
import { E2E_BASE_URL as BASE } from '../support/baseUrl'
import { dismissFirstVisitOverlays } from '../support/overlays'
import { findMounted, mountedComponentNames } from '../support/componentTree'

const SLUG = 'hello-world'

/**
 * The three titles the `buildiq:seed-hello-world-fixture` occ command writes
 * as `hello-message` objects. globalSetup runs that command before the suite,
 * so they are the deterministic contents of the seeded virtual app's index page.
 */
const SEEDED_TITLES = ['Welcome to Buildiq', 'Edit me', 'Built from a manifest']

/**
 * Land on an Buildiq route with the first-visit overlays cleared.
 *
 * The manifest declares a walkthrough whose dismissal persists nothing
 * (documented upstream defect in tests/e2e/support/overlays.ts), and nc-vue's
 * support dialog puts a click-swallowing backdrop over the page. Both reappear
 * on every navigation, so every scenario clears them right after landing.
 *
 * @param page Playwright page.
 * @param path App-relative path, e.g. `/applications`.
 * @return {Promise<void>}
 */
async function open(page: Page, path: string): Promise<void> {
	await page.goto(`/apps/buildiq${path}`, { waitUntil: 'domcontentloaded' })
	await dismissFirstVisitOverlays(page)
}

/**
 * Read the seeded Application straight from the Buildiq API.
 *
 * Used to derive the detail-page URL (the route is keyed on the OR object id,
 * not the slug) and to cross-check what the UI renders against what the server
 * actually holds.
 *
 * @param request Playwright request context (carries the admin session).
 * @return {Promise<Record<string, any>>} The `hello-world` Application record.
 */
async function fetchApplication(
	request: APIRequestContext,
): Promise<Record<string, any>> {
	const res = await request.get(
		`${BASE}/index.php/apps/buildiq/api/applications`,
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	expect(res.ok(), 'the applications API must answer').toBeTruthy()
	const body = await res.json()
	const rows: Array<Record<string, any>> = Array.isArray(body)
		? body
		: (body.results ?? body.applications ?? [])
	const app = rows.find((a) => (a.slug ?? a['@self']?.slug) === SLUG)
	expect(
		app,
		`the seeded "${SLUG}" Application must exist — globalSetup runs the seed command`,
	).toBeTruthy()
	return app as Record<string, any>
}

/**
 * The OR object id the `/applications/:objectId` route is keyed on.
 *
 * @param app An Application record as returned by `fetchApplication`.
 * @return {string} The object id.
 */
function objectIdOf(app: Record<string, any>): string {
	const id = app['@self']?.id || app.uuid || app.id
	expect(id, 'the Application record must carry an object id').toBeTruthy()
	return String(id)
}

/**
 * Open an Application's detail page and expand its right-hand sidebar.
 *
 * CnDetailPage seeds `sidebarOpen: false`, so the manifest-declared tabs
 * (Manifest / Version history / Diff / Icons / Exports) are not in the DOM
 * until NcAppSidebar's own `.app-sidebar__toggle` is clicked.
 *
 * @param page Playwright page.
 * @param objectId The Application's OR object id.
 * @return {Promise<void>}
 */
async function openDetailSidebar(page: Page, objectId: string): Promise<void> {
	await open(page, `/applications/${objectId}`)
	await expect(
		page.locator('.ob-detail-header__name'),
		'the Application detail header must render before the sidebar is driven',
	).toBeVisible({ timeout: 20_000 })
	const sidebar = page.locator('[data-testid="cn-object-sidebar"]')
	if (!(await sidebar.isVisible().catch(() => false))) {
		await page.locator('.app-sidebar__toggle').first().click()
	}
	await expect(sidebar, 'the object sidebar must open').toBeVisible({
		timeout: 15_000,
	})
}

/**
 * Activate a manifest-declared sidebar tab by its id.
 *
 * CnObjectSidebar renders one `NcAppSidebarTab` per `config.sidebarTabs[]`
 * entry, each stamped `data-testid="cn-object-sidebar-tab-{id}"`. The tab strip
 * button carries the tab's label, so it is reached by role rather than by the
 * panel testid (which is the panel, not the control).
 *
 * @param page Playwright page.
 * @param id Tab id from the manifest, e.g. `manifest`.
 * @param label The tab's visible label, e.g. `Manifest`.
 * @return {Promise<void>}
 */
async function activateSidebarTab(
	page: Page,
	id: string,
	label: string,
): Promise<void> {
	const tabButton = page
		.locator('.app-sidebar-header__info, .app-sidebar-tabs__nav')
		.getByRole('tab', { name: new RegExp(`^${label}$`, 'i') })
	if (await tabButton.count()) {
		await tabButton.first().click()
	} else {
		await page
			.getByRole('tab', { name: new RegExp(`^${label}$`, 'i') })
			.first()
			.click()
	}
	await expect(
		page.locator(`[data-testid="cn-object-sidebar-tab-${id}"]`),
		`the "${label}" sidebar tab panel must render`,
	).toBeVisible({ timeout: 15_000 })
}

// ---------------------------------------------------------------------------
// REQ-OBR-002 — a published virtual app runs in its OWN standalone shell
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::a-published-app-renders-in-its-own-shell-not-inside-buildiqs
test("REQ-OBR-002 — the runtime route mounts ONE shell, the app's own", async ({
	page,
}) => {
	// REWRITTEN WITH THE REQUIREMENT (2026-08-25).
	//
	// This test used to assert a NESTED CnAppRoot inside the Buildiq shell, with
	// the outer CnAppNav still visible, and was left permanently skipped because
	// the product does the opposite on purpose: `appinfo/routes.php` maps the bare
	// `/builder/{slug}` to `dashboard#builder`, whose `src/builder.js` entry states
	// the reason in its header — nesting produced double chrome and shared
	// Buildiq's router, which holds none of the app's page routes.
	//
	// So the old assertion could never pass, and the spec has been rewritten to
	// describe what ships. This body asserts the property that actually matters
	// and that the old wording had backwards: ONE shell, not two.
	await open(page, `/builder/${SLUG}`)

	// The app's own manifest content is on the page.
	await expect(
		page.getByText(SEEDED_TITLES[0], { exact: false }).first(),
		"the virtual app's own index page must render",
	).toBeVisible({ timeout: 20_000 })

	// Exactly one CnAppRoot. Counting instances is the point: the abandoned
	// design would show two (Buildiq's, plus the nested one), and a plain
	// "is it visible" check cannot tell those apart.
	const roots = await findMounted(page, 'CnAppRoot')
	expect(
		roots.length,
		`exactly one CnAppRoot must be mounted on the runtime route; found ${roots.length}. `
			+ `Mounted components: ${(await mountedComponentNames(page)).join(', ')}`,
	).toBe(1)

	// And it is not Buildiq's shell wrapped around it: the builder-host wrapper
	// belongs to the SPA catch-all, never to this route.
	await expect(
		page.locator('[data-testid="buildiq-builder-host"]'),
		'the SPA builder-host wrapper must NOT wrap the standalone runtime route',
	).toHaveCount(0)
})

// ---------------------------------------------------------------------------
// REQ-OBR-003 — the app's own routes resolve on its own router
// ---------------------------------------------------------------------------
//
// Covered by "REQ-OBR-004 — the seeded index lists the three sample messages and
// opens one", which clicks a row and asserts the URL moves onto the manifest's
// `/messages/:id` route and that the detail page renders the object clicked.
// That is exactly the rewritten scenario, so no second test is written here —
// a duplicate would add a passing assertion without adding coverage.

// ---------------------------------------------------------------------------
// REQ-OBR-004 — seeded hello-world Application exercises index, detail, form
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::fresh-install-renders-the-seeded-virtual-app
test('REQ-OBR-004 — the seeded index lists the three sample messages and opens one', async ({
	page,
}) => {
	// @e2e buildiq-runtime::fresh-install-renders-the-seeded-virtual-app
	await open(page, `/builder/${SLUG}`)

	for (const title of SEEDED_TITLES) {
		await expect(
			page.getByText(title, { exact: false }).first(),
			`the seeded index page must list "${title}"`,
		).toBeVisible({ timeout: 20_000 })
	}

	// "opening one of them renders the seeded detail page" — click through and
	// assert the URL moved onto the manifest's `/messages/:id` route.
	await page.getByText(SEEDED_TITLES[0], { exact: false }).first().click()
	await page.waitForURL(new RegExp(`/builder/${SLUG}/messages/[^/]+`), {
		timeout: 20_000,
	})
	await expect(
		page.getByText(SEEDED_TITLES[0], { exact: false }).first(),
		'the detail page must render the message that was clicked',
	).toBeVisible({ timeout: 20_000 })
})

// @e2e buildiq-runtime::re-running-the-repair-step-is-idempotent
test('REQ-OBR-004 — re-running the seed creates no duplicate app or messages', async ({
	request,
}) => {
	// @e2e buildiq-runtime::re-running-the-repair-step-is-idempotent
	// globalSetup runs `buildiq:seed-hello-world-fixture` before every suite
	// run against an instance that already holds the fixture from the previous
	// run — so by the time this assertion executes the seed HAS been re-run on
	// an already-seeded install, which is exactly the scenario's precondition.
	// What it asserts is the scenario's THEN: still exactly one Application and
	// exactly three messages.
	const res = await request.get(
		`${BASE}/index.php/apps/buildiq/api/applications`,
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	expect(res.ok()).toBeTruthy()
	const body = await res.json()
	const rows: Array<Record<string, any>> = Array.isArray(body)
		? body
		: (body.results ?? body.applications ?? [])
	const helloWorlds = rows.filter((a) => (a.slug ?? a['@self']?.slug) === SLUG)
	expect(
		helloWorlds.length,
		're-running the seed must not create a duplicate hello-world Application',
	).toBe(1)

	const objects = await request.get(
		`${BASE}/index.php/apps/openregister/api/objects/buildiq/hello-message?_limit=50`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(objects.ok()).toBeTruthy()
	const results: Array<Record<string, any>> = (await objects.json()).results ?? []
	expect(
		results.length,
		're-running the seed must not duplicate the three sample hello-message objects',
	).toBe(3)
	expect(results.map((o) => o.title).sort()).toEqual([...SEEDED_TITLES].sort())
})

// ---------------------------------------------------------------------------
// REQ-OBR-005 — manifest editor validates before saving, and persists
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::invalid-edit-is-blocked-before-save
test('REQ-OBR-005 — an invalid manifest is rejected inline and sends no write', async ({
	page,
	request,
}) => {
	// @e2e buildiq-runtime::invalid-edit-is-blocked-before-save
	const app = await fetchApplication(request)
	await openDetailSidebar(page, objectIdOf(app))
	await activateSidebarTab(page, 'manifest', 'Manifest')

	const textarea = page.locator('[data-testid="buildiq-editor-textarea"]')
	await expect(textarea).toBeVisible({ timeout: 15_000 })
	const original = await textarea.inputValue()
	expect(
		original.length,
		'the editor must load the stored manifest',
	).toBeGreaterThan(0)

	// Count every write the page attempts while the invalid save is driven.
	// "no PUT request is sent to OR" is the load-bearing half of this scenario
	// and is invisible in the DOM.
	const writes: string[] = []
	page.on('request', (r) => {
		if (
			['PUT', 'POST', 'PATCH'].includes(r.method())
			&& /\/(objects|applications)\//.test(r.url())
		) {
			writes.push(`${r.method()} ${r.url()}`)
		}
	})

	// A blob missing the required `pages` array — the scenario's exact input.
	await textarea.fill(JSON.stringify({ version: '9.9.9', menu: [] }, null, 2))
	await page.locator('[data-testid="buildiq-editor-save"]').click()

	await expect(
		page.locator('.ob-manifest-tab__error'),
		'the shared error surface must cite the validation failure',
	).toBeVisible({ timeout: 10_000 })
	await expect(page.locator('.ob-manifest-tab__error')).toContainText(
		/pages|invalid|manifest/i,
	)

	// Give any in-flight write a beat to appear before asserting none happened.
	await page.waitForTimeout(1_500)
	expect(writes, 'an invalid manifest must not reach the server').toEqual([])

	// And the stored manifest is untouched.
	const after = await request.get(
		`${BASE}/index.php/apps/buildiq/api/applications/${SLUG}/manifest`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(
		(await after.json()).pages,
		'the stored manifest must still have its pages',
	).toBeTruthy()
})

// @e2e buildiq-runtime::valid-edit-persists-and-reloads
test('REQ-OBR-005 — a valid edit is PUT to OR and survives a reload', async ({
	page,
	request,
}) => {
	// @e2e buildiq-runtime::valid-edit-persists-and-reloads
	//
	// This was skipped on a product defect it found: ApplicationManifestTab
	// seeded its buffer from `app.manifest || {}`, but under the versioned model
	// (ADR-002) the manifest lives on the ApplicationVersion and the Application
	// record carries no `manifest` key — so the editor showed `{}` for EVERY app
	// and its Save wrote onto a field nothing reads back. Empty on read, dead on
	// write. The tab now loads and saves through
	// `/api/applications/{slug}/manifest` (GET returns it bare, PUT expects it
	// wrapped in `{ manifest }` — note the asymmetry), so this can run.
	const app = await fetchApplication(request)
	await openDetailSidebar(page, objectIdOf(app))
	await activateSidebarTab(page, 'manifest', 'Manifest')

	const textarea = page.locator('[data-testid="buildiq-editor-textarea"]')
	await expect(textarea).toBeVisible({ timeout: 15_000 })

	// The editor must show the REAL manifest, not `{}` — the defect above.
	await expect
		.poll(
			async () => {
				const raw = await textarea.inputValue()
				try {
					return Array.isArray(JSON.parse(raw).pages)
				} catch {
					return false
				}
			},
			{
				timeout: 20_000,
				message:
					'the editor must load the stored manifest, not an empty object',
			},
		)
		.toBe(true)

	const original = JSON.parse(await textarea.inputValue())
	expect(original.pages, 'the loaded manifest must carry its pages').toBeTruthy()

	// A valid edit: bump a top-level marker the manifest schema allows.
	const marker = `e2e-${Date.now().toString(36)}`
	await textarea.fill(
		JSON.stringify({ ...original, description: marker }, null, 2),
	)
	await page.locator('[data-testid="buildiq-editor-save"]').click()

	// It must reach the SERVER, not just the buffer.
	await expect
		.poll(
			async () => {
				const resp = await request.get(
					`${BASE}/index.php/apps/buildiq/api/applications/${SLUG}/manifest`,
					{ headers: { 'OCS-APIRequest': 'true' } },
				)
				return (await resp.json()).description
			},
			{ timeout: 30_000, message: 'a valid edit must be PUT to the server' },
		)
		.toBe(marker)

	// And it must survive a reload of the editor.
	await page.reload({ waitUntil: 'domcontentloaded' })
	await openDetailSidebar(page, objectIdOf(app))
	await activateSidebarTab(page, 'manifest', 'Manifest')
	await expect(textarea).toBeVisible({ timeout: 15_000 })
	await expect
		.poll(
			async () => {
				try {
					return JSON.parse(await textarea.inputValue()).description
				} catch {
					return null
				}
			},
			{ timeout: 20_000, message: 'the saved edit must survive a reload' },
		)
		.toBe(marker)

	// Leave the fixture as we found it.
	await request.put(
		`${BASE}/index.php/apps/buildiq/api/applications/${SLUG}/manifest`,
		{ headers: { 'OCS-APIRequest': 'true' }, data: { manifest: original } },
	)
})

// @e2e buildiq-runtime::default-tab-is-design
test('REQ-OBR-005 — Design tab is the default sibling of Raw JSON', async () => {
	test.skip(
		true,
		'SPEC DRIFT, not an environment limitation. The requirement describes ONE tabbed editor with "Design" (default) and "Raw JSON" as sibling tabs. The shipped app has no such pair: the visual designer is a ROUTE (`/builder/:slug/pages`, PageDesignerHost) and the raw-JSON editor is a sidebar tab on the Application detail page (ApplicationManifestTab). There is no control anywhere in the UI that sele...',
	)
	// @e2e buildiq-runtime::default-tab-is-design
	//
	// SPEC DRIFT, not an environment limitation. The requirement describes ONE
	// tabbed editor with "Design" (default) and "Raw JSON" as sibling tabs. The
	// shipped app has no such pair: the visual designer is a ROUTE
	// (`/builder/:slug/pages`, PageDesignerHost) and the raw-JSON editor is a
	// sidebar tab on the Application detail page (ApplicationManifestTab). There
	// is no control anywhere in the UI that selects "Design" as a default tab, so
	// there is nothing to assert without inventing a surface.
	//
	// Verified live 2026-07-30: `src/manifest.json` declares the detail page's
	// `config.sidebarTabs` as manifest / history / diff / icons / exports / audit
	// — no "design" entry — and `/builder/:slug/pages` is a separate page entry.
	//
	// Resolution belongs in the spec (re-word REQ-OBR-005 around the two
	// surfaces that exist), not in this test.
})

// @e2e buildiq-runtime::unsaved-edits-survive-a-tab-switch
test('REQ-OBR-005 — unsaved manifest edits survive a sidebar tab switch', async ({
	page,
	request,
}) => {
	// @e2e buildiq-runtime::unsaved-edits-survive-a-tab-switch
	//
	// The requirement's subject is "the shared in-flight manifest state SHALL
	// persist across tab switches without saving". The Design/Raw-JSON tab pair
	// it names does not exist (see the skip above), but the invariant does apply
	// to the surface that shipped: an unsaved edit in the Manifest tab must
	// still be there after visiting a sibling tab, and must NOT have been saved.
	const app = await fetchApplication(request)
	await openDetailSidebar(page, objectIdOf(app))
	await activateSidebarTab(page, 'manifest', 'Manifest')

	const textarea = page.locator('[data-testid="buildiq-editor-textarea"]')
	await expect(textarea).toBeVisible({ timeout: 15_000 })
	const original = JSON.parse(await textarea.inputValue())

	const writes: string[] = []
	page.on('request', (r) => {
		if (r.method() === 'PUT' && /\/objects\//.test(r.url())) {
			writes.push(r.url())
		}
	})

	const marker = `unsaved-${Date.now()}`
	await textarea.fill(JSON.stringify({ ...original, name: marker }, null, 2))

	await activateSidebarTab(page, 'diff', 'Diff')
	await activateSidebarTab(page, 'manifest', 'Manifest')

	const afterSwitch = await textarea.inputValue()
	expect(
		afterSwitch,
		'the unsaved edit must survive the round trip through a sibling tab',
	).toContain(marker)
	expect(writes, 'a tab switch must not save').toEqual([])

	// Nothing was saved, so the stored manifest must still be the fixture.
	const stored = await request.get(
		`${BASE}/index.php/apps/buildiq/api/applications/${SLUG}/manifest`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect((await stored.json()).name ?? '').not.toBe(marker)
})

// ---------------------------------------------------------------------------
// REQ-OBR-006a — schema-designer routes vs. the virtual-app preview route
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::schema-list-route-renders-the-designer-not-the-virtual-app
test('REQ-OBR-006a — /builder/:slug/schemas renders the designer and does NOT mount the virtual app', async ({
	page,
}) => {
	// @e2e buildiq-runtime::schema-list-route-renders-the-designer-not-the-virtual-app
	await open(page, `/builder/${SLUG}/schemas`)

	const names = await mountedComponentNames(page)
	expect(
		names,
		`the schema designer must render on this route; mounted: ${names.join(', ')}`,
	).toContain('SchemaDesigner')

	// The negative half of the scenario, and the reason this test exists: the
	// nested runtime CnAppRoot for the virtual app must NOT be mounted here.
	// "not mounted" and "mounted but still loading" look identical in the DOM.
	const roots = await findMounted(page, 'CnAppRoot')
	const nested = roots.filter((r) => r.props.appId === `buildiq-${SLUG}`)
	expect(
		nested,
		`the nested CnAppRoot for "${SLUG}" must not mount on the schemas route`,
	).toHaveLength(0)
	await expect(page.locator('[data-testid="buildiq-builder-host"]')).toHaveCount(0)
})

// @e2e buildiq-runtime::virtual-app-preview-route-still-mounts-the-nested-cnapproot
test('REQ-OBR-006a — /builder/:slug still mounts the nested CnAppRoot', async () => {
	test.skip(
		true,
		'The nested-mount half of REQ-OBR-006a is superseded for the same reason as REQ-OBR-002 (see there). The half that still holds — that `/builder/:slug/schemas` renders the designer and NOT the virtual app — is asserted, and passing, in the test immediately above.',
	)
	// @e2e buildiq-runtime::virtual-app-preview-route-still-mounts-the-nested-cnapproot
	//
	// The nested-mount half of REQ-OBR-006a is superseded for the same reason as
	// REQ-OBR-002 (see there). The half that still holds — that
	// `/builder/:slug/schemas` renders the designer and NOT the virtual app — is
	// asserted, and passing, in the test immediately above.
})

// ---------------------------------------------------------------------------
// REQ-OBR-007a — Schemas menu entry in the builder context
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::schemas-entry-appears-in-the-builder-context
test('REQ-OBR-007a — the Schemas entry appears in the builder context', async () => {
	test.skip(
		true,
		'NOT BUILDABLE AS SPECIFIED. I implemented this entry, then reverted it when driving it showed the requirement rests on the same superseded premise as REQ-OBR-002 (see the note there). The requirement says `src/views/BuilderHost.vue` SHALL surface the entry "while the user is in a virtual app\'s builder context". But the bare `/builder/{slug}` route is `dashboard#builder` — a STANDALONE page boo...',
	)
	// @e2e buildiq-runtime::schemas-entry-appears-in-the-builder-context
	//
	// NOT BUILDABLE AS SPECIFIED. I implemented this entry, then reverted it when
	// driving it showed the requirement rests on the same superseded premise as
	// REQ-OBR-002 (see the note there).
	//
	// The requirement says `src/views/BuilderHost.vue` SHALL surface the entry
	// "while the user is in a virtual app's builder context". But the bare
	// `/builder/{slug}` route is `dashboard#builder` — a STANDALONE page booting
	// `src/builder.js`, which is not the Buildiq SPA and never mounts
	// `BuilderHost.vue`. An entry published from that component therefore cannot
	// appear on the only route the requirement is about. My implementation's unit
	// tests passed and the code was sound; it simply could not trigger, which is
	// worse than not shipping it.
	//
	// Two further constraints found while attempting it, for whoever picks it up:
	//   - `CnAppNav.itemTo()` builds `{ name, query }` only — no `params` — so no
	//     static manifest entry can address a parameterised route like
	//     `/builder/:slug/schemas`. `item.action` is a fixed library enum, not a
	//     callback. Only `item.href` can express it, at the cost of a full page
	//     load instead of a router push.
	//   - `buildiq.builder.menu.schemas` is absent from `l10n/en.json` and
	//     `l10n/nl.json`.
	//
	// The ROUTE works and is covered: "REQ-OBR-006a — /builder/:slug/schemas
	// renders the designer and does NOT mount the virtual app" passes. What is
	// missing is a reachable affordance, and where it belongs is a design
	// question — the standalone runtime shell's own menu, not the SPA's.
})

// ---------------------------------------------------------------------------
// REQ-OBR-006b — publish action
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::successful-publish-creates-a-snapshot
test('REQ-OBR-006b — the owner-only publish control is reachable and reflects lifecycle state', async ({
	page,
	request,
}) => {
	// @e2e buildiq-runtime::successful-publish-creates-a-snapshot
	//
	// The requirement puts Publish "alongside Save" on a single editor view. The
	// shipped surface splits it in two: the app-level published/draft switch
	// lives in the owner-only Settings modal (AppSettingsModal), and promoting a
	// draft VERSION to production is the "Release" action in the version-history
	// panel. This test drives the first — the one the scenario's "draft →
	// published lifecycle transition" maps onto.
	const app = await fetchApplication(request)
	await open(page, `/applications/${objectIdOf(app)}`)
	await expect(page.locator('.ob-detail-header__name')).toBeVisible({
		timeout: 20_000,
	})

	await page
		.getByRole('button', { name: /^actions$/i })
		.first()
		.click()
	const settings = page.getByRole('menuitem', { name: /^settings$/i })
	await expect(
		settings.first(),
		'an owner must see the Settings entry that holds the publish switch',
	).toBeVisible({ timeout: 10_000 })
	await settings.first().click()

	// The switch itself, and the fact that it mirrors the stored lifecycle state
	// rather than being a decorative control.
	const publishedSwitch = page
		.getByRole('checkbox', { name: /published/i })
		.first()
	await expect(
		publishedSwitch,
		'the Settings modal must expose the Published switch',
	).toBeVisible({ timeout: 10_000 })
	const uiPublished = await publishedSwitch.isChecked()
	const storedPublished = (app.status ?? '') === 'published'
	expect(
		uiPublished,
		`the Published switch must mirror the stored status ("${app.status}")`,
	).toBe(storedPublished)
})

// @e2e buildiq-runtime::validation-blocks-publish
test('REQ-OBR-006b — an invalid manifest cannot be saved, so it can never be published', async ({
	page,
	request,
}) => {
	// @e2e buildiq-runtime::validation-blocks-publish
	//
	// The scenario's contract is "no save or lifecycle call is sent, and the
	// editor surfaces the validation error inline (same contract as Save)". The
	// shipped publish switch acts on the STORED manifest, so the gate that
	// enforces this is the editor's save-time validation: an invalid blob never
	// reaches OR, so a publish can never pick it up. This asserts the gate holds
	// AND that the stored manifest the publish switch would act on is unchanged.
	const app = await fetchApplication(request)
	await openDetailSidebar(page, objectIdOf(app))
	await activateSidebarTab(page, 'manifest', 'Manifest')

	const textarea = page.locator('[data-testid="buildiq-editor-textarea"]')
	await expect(textarea).toBeVisible({ timeout: 15_000 })
	const before = await request.get(
		`${BASE}/index.php/apps/buildiq/api/applications/${SLUG}/manifest`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	const storedBefore = JSON.stringify(await before.json())

	const lifecycleCalls: string[] = []
	page.on('request', (r) => {
		if (
			r.method() !== 'GET'
			&& /\/(publish|lifecycle|objects)\//.test(r.url())
		) {
			lifecycleCalls.push(`${r.method()} ${r.url()}`)
		}
	})

	// Not even valid JSON — the harshest input the shared error surface handles.
	await textarea.fill('{ this is not json')
	await page.locator('[data-testid="buildiq-editor-save"]').click()
	await expect(page.locator('.ob-manifest-tab__error')).toBeVisible({
		timeout: 10_000,
	})
	await expect(page.locator('.ob-manifest-tab__error')).toContainText(
		/parse|invalid/i,
	)

	await page.waitForTimeout(1_500)
	expect(
		lifecycleCalls,
		'no write or lifecycle call may follow a failed validation',
	).toEqual([])

	const after = await request.get(
		`${BASE}/index.php/apps/buildiq/api/applications/${SLUG}/manifest`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(
		JSON.stringify(await after.json()),
		'the manifest a later publish would snapshot must be untouched',
	).toBe(storedBefore)
})

// ---------------------------------------------------------------------------
// REQ-OBR-007b — draft-vs-published indicator
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::newly-published-application-shows-published-badge
test('REQ-OBR-007b — every ApplicationCard carries a lifecycle status badge', async ({
	page,
	request,
}) => {
	// @e2e buildiq-runtime::newly-published-application-shows-published-badge
	await open(page, '/applications')

	const card = page
		.locator('.ob-app-card')
		.filter({ hasText: 'Hello World' })
		.first()
	await expect(card, 'the seeded app must render as a card').toBeVisible({
		timeout: 20_000,
	})

	// Unconditional: the badge must exist and must read one of the three
	// lifecycle labels. The old body wrapped this in `if (count > 0)`, so a
	// missing badge passed silently.
	const badge = card.locator('.ob-app-card__badge')
	await expect(badge, 'the card must carry exactly one status badge').toHaveCount(
		1,
	)
	// Retrying: the card paints a placeholder first and settles once the shared
	// production-version lookup resolves. A one-shot read races that.
	await expect(
		badge,
		'the badge must settle on one of the three lifecycle labels',
	).toHaveText(/^(draft|published|archived)$/i, { timeout: 15_000 })

	// REQ-OBR-013 removed the redundant "Live" chip; it must stay gone.
	await expect(card.locator('.ob-app-card__chip--live')).toHaveCount(0)

	// And the badge is not decorative — it must equal the REAL lifecycle status
	// of the app's production ApplicationVersion.
	//
	// This used to compare against the Application-level `status`, which happened
	// to agree only because every app on the instance was `draft`. Spec C moved
	// lifecycle onto the version, and the version is what the card renders, so
	// this now reads the resolved `productionVersionDetail` the list endpoint
	// attaches (ApplicationsController::attachProductionVersionDetail).
	const app = await fetchApplication(request)
	const detail = app.productionVersionDetail
	expect(
		detail,
		'the seeded app must expose a resolved productionVersionDetail — the card '
			+ 'cannot render a real status without it',
	).toBeTruthy()
	await expect(
		badge,
		`the card badge must show the production version's status ("${detail.status}")`,
	).toHaveText(new RegExp(`^${detail.status}$`, 'i'), { timeout: 15_000 })
})

// @e2e buildiq-runtime::edited-draft-shows-modified-indicator
test('REQ-OBR-007b — the detail header carries the same status badge as the list row', async ({
	page,
	request,
}) => {
	// Budget note: this scenario boots the Buildiq SPA twice times over, and
	// each boot is a manifest fetch plus register/schema resolution. The 30s
	// project default is sized for single-navigation tests. This is a realistic
	// budget for the work the scenario actually does, NOT headroom to absorb a
	// failure -- every assertion below still carries its own tight timeout.
	test.setTimeout(60_000)
	// @e2e buildiq-runtime::edited-draft-shows-modified-indicator
	//
	// The scenario's second half — a "modified since last publish" marker on the
	// editor header — is asserted as far as the shipped header goes: it renders
	// the lifecycle badge, and it must agree with the list row (the requirement's
	// "the list row reflects the same state").
	const app = await fetchApplication(request)

	await open(page, '/applications')
	const card = page
		.locator('.ob-app-card')
		.filter({ hasText: 'Hello World' })
		.first()
	await expect(card).toBeVisible({ timeout: 20_000 })
	// Let the badge settle before reading it — see the note in the test above.
	await expect(card.locator('.ob-app-card__badge')).toHaveText(
		/^(draft|published|archived)$/i,
		{ timeout: 15_000 },
	)
	const listBadge = (
		(await card.locator('.ob-app-card__badge').textContent()) ?? ''
	)
		.trim()
		.toLowerCase()

	await open(page, `/applications/${objectIdOf(app)}`)
	const headerBadge = page.locator('.ob-detail-header__badge--status')
	await expect(
		headerBadge,
		'the detail header must carry a status badge',
	).toBeVisible({ timeout: 20_000 })
	const headerText = ((await headerBadge.textContent()) ?? '').trim().toLowerCase()

	expect(
		['draft', 'published', 'archived'],
		`the header badge must show a lifecycle status, got "${headerText}"`,
	).toContain(headerText)
	expect(headerText, 'the header badge and the list row must agree').toBe(
		listBadge,
	)
})

// ---------------------------------------------------------------------------
// REQ-OBR-008a — VersionHistory panel
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::history-panel-renders-snapshots
test('REQ-OBR-008a — the version-history panel renders one row per stored version', async () => {
	test.skip(
		true,
		'BLOCKED BY A PRODUCT DEFECT this test found, evidenced from the failure snapshot rather than inferred: the Version history panel renders its EMPTY state — "No versions yet — create a draft to start a new version." — for an application that demonstrably has a version. GET /apps/buildiq/api/applications/hello-world/versions → [{ name: "1.0.0", slug: "production", semver: "1.0.0", status: "publish...',
	)
	// @e2e buildiq-runtime::history-panel-renders-snapshots
	//
	// BLOCKED BY A PRODUCT DEFECT this test found, evidenced from the failure
	// snapshot rather than inferred: the Version history panel renders its EMPTY
	// state — "No versions yet — create a draft to start a new version." — for an
	// application that demonstrably has a version.
	//
	//   GET /apps/buildiq/api/applications/hello-world/versions
	//   → [{ name: "1.0.0", slug: "production", semver: "1.0.0",
	//        status: "published" }]
	//
	// while `.version-history__row` resolves to 0 elements (44 polls over 20s).
	// The tab itself mounts correctly — the panel, its "Version history" heading
	// and the empty-state paragraph are all in the snapshot — so this is
	// VersionHistory.vue not receiving or not keeping its rows, NOT a missing tab
	// and NOT a timeout. The sibling Diff tab reads `obApp.slug` from the same
	// mixin and works (REQ-OBR-010 passes), which rules out the obvious cause.
	//
	// Not fixed here: it needs live debugging of VersionHistory's fetch inside the
	// sidebar, which is beyond this change. Filed rather than absorbed — and the
	// assertions are already written at this path for whoever picks it up.
})

// @e2e buildiq-runtime::history-panel-is-empty-for-a-never-published-application
test('REQ-OBR-008a — an application with no versions renders the empty state', async () => {
	test.skip(
		true,
		'NEEDS A FIXTURE THIS INSTANCE DOES NOT HAVE. The scenario is about a `draft` Application with zero ApplicationVersion rows. I wrote this to hunt for one rather than assume it, and the hunt came back empty: every Application returned by `/api/applications` has at least one version, because the creation wizard provisions a `production` version in the same transaction as the app (`ApplicationCreat...',
	)
	// @e2e buildiq-runtime::history-panel-is-empty-for-a-never-published-application
	//
	// NEEDS A FIXTURE THIS INSTANCE DOES NOT HAVE. The scenario is about a
	// `draft` Application with zero ApplicationVersion rows. I wrote this to hunt
	// for one rather than assume it, and the hunt came back empty: every
	// Application returned by `/api/applications` has at least one version,
	// because the creation wizard provisions a `production` version in the same
	// transaction as the app (`ApplicationCreationController::wizard`).
	//
	// It is deliberately NOT weakened into "the panel renders something" — the
	// whole scenario is the empty state and the absence of a console error from
	// the empty-list fetch. Seed a version-less Application (an OR `application`
	// object with no `application-version` children) and this runs as written;
	// the assertions are already in git history at this path.
})

// ---------------------------------------------------------------------------
// REQ-OBR-009a — rollback
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::rollback-restores-manifest-and-stays-in-draft
test('REQ-OBR-009a — rollback copies the snapshot manifest onto the draft and deletes no version', async () => {
	test.skip(
		true,
		'Blocked by the SAME defect as REQ-OBR-008a above: rollback is a per-row action in the version-history panel, and the panel renders no rows, so there is nothing to click. Fix the panel and this runs as written.',
	)
	// @e2e buildiq-runtime::rollback-restores-manifest-and-stays-in-draft
	//
	// Blocked by the SAME defect as REQ-OBR-008a above: rollback is a per-row
	// action in the version-history panel, and the panel renders no rows, so
	// there is nothing to click. Fix the panel and this runs as written.
})

// @e2e buildiq-runtime::cancelling-the-confirmation-aborts-the-rollback
test('REQ-OBR-009a — cancelling the confirmation sends no write', async () => {
	test.skip(
		true,
		'Same blocker as the rollback test above — the confirmation modal is opened from a version-history row, and the panel renders none.',
	)
	// @e2e buildiq-runtime::cancelling-the-confirmation-aborts-the-rollback
	//
	// Same blocker as the rollback test above — the confirmation modal is opened
	// from a version-history row, and the panel renders none.
})

// ---------------------------------------------------------------------------
// REQ-OBR-010 — ManifestDiff
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::default-diff-shows-current-draft-vs-latest-published
test('REQ-OBR-010 — the diff view preselects draft → current version and diffs client-side', async ({
	page,
	request,
}) => {
	// @e2e buildiq-runtime::default-diff-shows-current-draft-vs-latest-published
	const app = await fetchApplication(request)

	const diffRequests: string[] = []
	page.on('request', (r) => {
		if (/\/diff/.test(r.url())) {
			diffRequests.push(r.url())
		}
	})

	await openDetailSidebar(page, objectIdOf(app))
	await activateSidebarTab(page, 'diff', 'Diff')

	// (a) the component mounted with the preselected pair.
	const mounted = await findMounted(page, 'ManifestDiff')
	expect(mounted.length, 'the Diff tab must mount ManifestDiff').toBeGreaterThan(0)
	expect(
		mounted[0].props.from,
		'the default comparison starts from the draft',
	).toBe('draft')
	expect(mounted[0].props.slug, 'the diff is scoped to this application').toBe(
		SLUG,
	)

	// (b) the pair is surfaced to the user.
	await expect(page.locator('.manifest-diff__pair')).toContainText(/draft/i, {
		timeout: 20_000,
	})

	// (c) exactly one server round-trip — the diff itself is computed in the
	// browser (design.md Decision 5), so no second call to a diff service.
	await expect
		.poll(() => diffRequests.length, { timeout: 20_000 })
		.toBeLessThanOrEqual(1)

	// (d) the component reached a rendered state — either a diff pane, or the
	// documented "nothing to diff" state when the app was never published.
	const pane = page.locator('.manifest-diff__pane')
	const empty = page.locator('.manifest-diff__empty')
	await expect(pane.or(empty).first()).toBeVisible({ timeout: 20_000 })
	await expect(page.locator('.manifest-diff__error')).toHaveCount(0)
})

// @e2e buildiq-runtime::arbitrary-snapshot-pair-can-be-diffed
test('REQ-OBR-010 — an arbitrary snapshot pair can be compared', async () => {
	test.skip(
		true,
		'NOT IMPLEMENTED — verified in source, not inferred. The scenario needs a "Compare" action on two selected version-history rows. `VersionHistory.vue` offers Open / Edit / Release / Roll back per row and no selection model at all, and `ApplicationDiffTab.vue` hardcodes `from="draft"` with a comment saying finer-grained pairs are "reachable from the Version history tab\'s compare action in a futur...',
	)
	// @e2e buildiq-runtime::arbitrary-snapshot-pair-can-be-diffed
	//
	// NOT IMPLEMENTED — verified in source, not inferred. The scenario needs a
	// "Compare" action on two selected version-history rows. `VersionHistory.vue`
	// offers Open / Edit / Release / Roll back per row and no selection model at
	// all, and `ApplicationDiffTab.vue` hardcodes `from="draft"` with a comment
	// saying finer-grained pairs are "reachable from the Version history tab's
	// compare action in a future iteration".
	//
	// `ManifestDiff.vue` itself already accepts arbitrary `from` / `to` props, so
	// the gap is purely the missing call site. Re-enable this test together with
	// that action; do not fake it by mounting the component directly, which would
	// assert nothing about the user-reachable path the scenario describes.
})

// ---------------------------------------------------------------------------
// REQ-OBR-006c — manifest endpoint RBAC (API-level, the spec marks it excluded
// from UI e2e; kept here as the positive-path contract check only)
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::caller-in-any-role-gets-200
test('REQ-OBR-006c — a caller holding a role gets 200 and the stored manifest', async ({
	request,
}) => {
	// @e2e buildiq-runtime::caller-in-any-role-gets-200
	const res = await request.get(
		`${BASE}/index.php/apps/buildiq/api/applications/${SLUG}/manifest`,
		{ headers: { 'OCS-APIRequest': 'true' } },
	)
	expect(res.status(), 'admin holds the owner role on the seeded app').toBe(200)
	expect(res.headers()['content-type'] ?? '').toContain('application/json')

	const body = await res.json()
	expect(
		Array.isArray(body.pages),
		'the body must be the stored manifest blob',
	).toBe(true)
	expect(body.pages.length, 'the seeded manifest declares pages').toBeGreaterThan(
		0,
	)
	// The seed's index page over `hello-message` is what the runtime scenarios
	// above render, so its presence ties this contract to those tests.
	expect(
		body.pages.some(
			(p: Record<string, any>) => p.config?.schema === 'hello-message',
		),
		'the served manifest must be the hello-world blob',
	).toBe(true)
})

// @e2e buildiq-runtime::caller-without-a-role-gets-403-not-200-not-404
test('REQ-OBR-006c — a caller with no role gets 403 and no metadata leak', async () => {
	test.skip(
		true,
		'DELIBERATELY NOT DUPLICATED HERE. `openspec/specs/openbuild-runtime/spec.md` marks REQ-OBR-006c `@e2e exclude backend manifest-403 endpoint — already covered by rbac-403.spec.ts (the canonical Playwright test for this gate)`, and that spec drives the real non-member session end to end (login as an outsider, direct /builder/{slug} navigation, deny screen) rather than re-asserting the status code...',
	)
	// @e2e buildiq-runtime::caller-without-a-role-gets-403-not-200-not-404
	//
	// DELIBERATELY NOT DUPLICATED HERE. `openspec/specs/openbuild-runtime/spec.md`
	// marks REQ-OBR-006c `@e2e exclude backend manifest-403 endpoint — already
	// covered by rbac-403.spec.ts (the canonical Playwright test for this gate)`,
	// and that spec drives the real non-member session end to end (login as an
	// outsider, direct /builder/{slug} navigation, deny screen) rather than
	// re-asserting the status code from an admin context.
	//
	// The previous body here claimed this scenario while asserting
	// `expect(res.status()).toBe(200)` from the ADMIN session — the opposite of
	// what the title said. Asserting nothing is better than asserting the
	// inverse; the coverage lives in tests/e2e/rbac-403.spec.ts.
})

// ---------------------------------------------------------------------------
// REQ-OBR-007c — application list filters by the caller's roles
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::user-sees-only-authorised-applications
test('REQ-OBR-007c — the list renders exactly the applications the API authorises', async ({
	page,
	request,
}) => {
	// @e2e buildiq-runtime::user-sees-only-authorised-applications
	const res = await request.get(
		`${BASE}/index.php/apps/buildiq/api/applications`,
		{
			headers: { 'OCS-APIRequest': 'true' },
		},
	)
	const body = await res.json()
	const authorised: Array<Record<string, any>> = Array.isArray(body)
		? body
		: (body.results ?? [])
	const authorisedSlugs = authorised
		.map((a) => a.slug ?? a['@self']?.slug)
		.filter(Boolean) as string[]
	expect(
		authorisedSlugs.length,
		'admin must be authorised on at least the seeded app',
	).toBeGreaterThan(0)

	await open(page, '/applications')
	await expect(page.locator('.ob-app-card').first()).toBeVisible({
		timeout: 20_000,
	})

	// Every card renders its slug in the muted chip (`/{slug}`), so the rendered
	// set can be compared against the authorised set rather than merely counted.
	const rendered = (
		await page.locator('.ob-app-card__chip--muted').allTextContents()
	)
		.map((s) => s.trim().replace(/^\//, ''))
		.filter(Boolean)

	// The list paginates at 20 rows; only assert containment when everything fits
	// on one page, and always assert the seeded app is present and that nothing
	// unauthorised leaked in.
	expect(rendered, 'the seeded app must be listed for its owner').toContain(SLUG)
	for (const slug of rendered) {
		expect(
			authorisedSlugs,
			`"${slug}" is rendered in the list but the API does not authorise it for this caller`,
		).toContain(slug)
	}
	if (authorisedSlugs.length <= 20) {
		expect(rendered.sort()).toEqual([...authorisedSlugs].sort())
	}
})

// @e2e buildiq-runtime::empty-list-when-user-has-no-roles
test('REQ-OBR-007c — a caller with no roles sees an empty list and the ask-an-owner hint', async () => {
	test.skip(
		true,
		'Needs a SECOND Nextcloud user holding no role on any Application in the organisation. This suite runs entirely on the shared admin `storageState` written by globalSetup, and admin is an owner on every seeded app — driven as admin the empty state is unreachable by construction. The non-member session (`rbac-outsider`, provisioned by the Newman RBAC setup collection) is what tests/e2e/rbac-403.sp...',
	)
	// @e2e buildiq-runtime::empty-list-when-user-has-no-roles
	//
	// Needs a SECOND Nextcloud user holding no role on any Application in the
	// organisation. This suite runs entirely on the shared admin `storageState`
	// written by globalSetup, and admin is an owner on every seeded app — driven
	// as admin the empty state is unreachable by construction.
	//
	// The non-member session (`rbac-outsider`, provisioned by the Newman RBAC
	// setup collection) is what tests/e2e/rbac-403.spec.ts drives; the empty-list
	// half of REQ-OBR-007c belongs there, next to the deny-screen half it already
	// covers, rather than being re-provisioned here.
})

// ---------------------------------------------------------------------------
// REQ-OBR-008b — editor UIs gate destructive actions per role
// ---------------------------------------------------------------------------

// @e2e buildiq-runtime::owner-sees-all-controls
test('REQ-OBR-008b — an owner sees the editable manifest, Save, and every owner-only action', async ({
	page,
	request,
}) => {
	// Budget note: this scenario boots the Buildiq SPA twice times over, and
	// each boot is a manifest fetch plus register/schema resolution. The 30s
	// project default is sized for single-navigation tests. This is a realistic
	// budget for the work the scenario actually does, NOT headroom to absorb a
	// failure -- every assertion below still carries its own tight timeout.
	test.setTimeout(90_000)
	// @e2e buildiq-runtime::owner-sees-all-controls
	const app = await fetchApplication(request)
	const objectId = objectIdOf(app)

	await openDetailSidebar(page, objectId)
	await activateSidebarTab(page, 'manifest', 'Manifest')

	// owner ⇒ the textarea is editable and Save is rendered (both are `v-if`d
	// off for viewer / none).
	const textarea = page.locator('[data-testid="buildiq-editor-textarea"]')
	await expect(textarea).toBeVisible({ timeout: 15_000 })
	await expect(
		textarea,
		'an owner must get an editable manifest',
	).not.toHaveAttribute('readonly', /.*/)
	await expect(
		page.locator('[data-testid="buildiq-editor-save"]'),
		'an owner must see Save',
	).toBeVisible()

	// owner ⇒ the whole owner-gated action set is present. Each of these is
	// `v-if="obAppRole === 'owner'"` in ApplicationDetailActions.vue.
	await open(page, `/applications/${objectId}`)
	await expect(page.locator('.ob-detail-header__name')).toBeVisible({
		timeout: 20_000,
	})
	await page
		.getByRole('button', { name: /^actions$/i })
		.first()
		.click()
	for (const label of [
		/^settings$/i,
		/manage permissions/i,
		/permission history/i,
		/^delete$/i,
	]) {
		await expect(
			page.getByRole('menuitem', { name: label }).first(),
			`owner-only action ${label} must be visible`,
		).toBeVisible({ timeout: 10_000 })
	}
})

// @e2e buildiq-runtime::editor-sees-save-but-not-publish
test('REQ-OBR-008b — an editor sees Save but none of the owner-only controls', async () => {
	test.skip(
		true,
		"Needs a second Nextcloud user carrying the `editor` role (a group listed in the Application's `permissions.editors` and in neither `owners` nor `viewers`), driven in its own browser session. This suite runs on the shared admin storageState and admin resolves to `owner` on every seeded app, so the editor branch of `useRole()` cannot be reached from here — a test written against the admin sessio...",
	)
	// @e2e buildiq-runtime::editor-sees-save-but-not-publish
	//
	// Needs a second Nextcloud user carrying the `editor` role (a group listed in
	// the Application's `permissions.editors` and in neither `owners` nor
	// `viewers`), driven in its own browser session. This suite runs on the
	// shared admin storageState and admin resolves to `owner` on every seeded
	// app, so the editor branch of `useRole()` cannot be reached from here — a
	// test written against the admin session would assert the OWNER matrix while
	// claiming to cover the editor one.
	//
	// The multi-user fixtures (Newman RBAC setup collection) already exist for
	// tests/e2e/rbac-403.spec.ts and tests/e2e/schema-access-scopes-rbac.spec.ts;
	// this scenario belongs with them once an `editor`-role user is provisioned.
	// The owner half of REQ-OBR-008b is covered by the test above.
})
