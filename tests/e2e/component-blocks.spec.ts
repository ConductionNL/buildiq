/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end test for the component-blocks flow: save a configured
 * widget (or a multi-widget page section) as a reusable `ComponentBlock`,
 * insert it into a DIFFERENT app, resolve the schema-dependency prompt, and
 * confirm what lands on the target page (component-blocks tasks.md 7.3).
 *
 * Covers (gate-19 scenario references):
 *   - "Saving a widget captures its config, not its data"
 *   - "Save a single widget as a block"
 *   - "Save a page section as a block"
 *   - "Library lists org-wide blocks"
 *   - "Inserting the same block twice does not collide"
 *   - "Editing the source block does not affect an inserted copy"
 *   - "Cross-app insert with no matching schema requires remap"
 *   - "Unresolved remap inserts a visible placeholder, not a silent drop"
 *   - "Blocks filter shows only blocks"
 *   - "Blocks filter shows blocks without the clone action" (buildiq-template-catalogue)
 *
 * NOT covered here, deliberately: "cross-app insert with a MATCHING schema
 * name needs no prompt" and the resolved-remap path both require the target
 * app to own a schema under a specific slug, which means provisioning schemas
 * into its per-version register as a fixture — API-shape territory. Both are
 * unit-covered in tests/vitest/blockInsert.spec.js (computeSchemaMismatches
 * returns [] on a match; remapBlockRecord rewrites resolved refs) and the
 * Newman collection covers the CRUD/export round-trip.
 *
 * UN-QUARANTINED 2026-07-29. The original never had fixtures: every test
 * navigated to `/builder/e2e-cb-source-${Date.now()}/pages`, an app that does
 * not exist, so the designer had nothing to render — #41's blockers were only
 * half its problem. It now creates two real fixture apps, seeds the source
 * page's `widgets[]` through the manifest API, and resets the block library to
 * a known baseline per run so the suite is idempotent.
 */

import { expect, test } from '@playwright/test'
import {
	dismissOverlays,
	ensureApp,
	suppressSupportDialog,
} from './support/appFixture.ts'
// Merge note (development -> feat/vue-3-migration, 2026-07-30): development's
// un-quarantine reintroduced
//   `process.env.NEXTCLOUD_URL || process.env.NC_BASE_URL || 'http://localhost:8080'`
// which ignores PLAYWRIGHT_BASE_URL entirely. This suite is driven with
// PLAYWRIGHT_BASE_URL=http://localhost:8099 and NC_BASE_URL unset, so that
// expression falls through to :8080 — on a dev box the SHARED `nextcloud`
// container holding other people's checkouts. This spec both READS and WRITES
// (it provisions two fixture apps and component-block objects), so the old form
// would have created fixtures on somebody else's instance while asserting
// against a different one. Keeping our side; see tests/e2e/support/baseUrl.ts
// for the full writeup.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl.ts'
import { readStagedManifest } from './support/stagedManifest.ts'

const SOURCE_APP = 'pw-cb-source'
const TARGET_APP = 'pw-cb-target'
const PAGE_ID = 'e2e-cb-page'
/** Every fixture block slug shares this prefix so the baseline reset can find them. */
const BLOCK_PREFIX = 'pw-cb-'
/** Blocks live as OpenRegister objects, not in the app manifest. */
const BLOCKS_API = '/index.php/apps/openregister/api/objects/buildiq/component-block'
/** blockInsert.js#UNRESOLVED_SCHEMA_PLACEHOLDER — the "needs remap" sentinel. */
const UNRESOLVED = '__needs-remap__'

/** The two widgets seeded onto the source app's page; both bound to one schema. */
const SOURCE_WIDGETS = [
	{
		id: 'invoice-list',
		widgetKey: 'object-list',
		slot: 'main',
		config: { schema: `${SOURCE_APP}-invoice`, title: 'Invoices' },
	},
	{
		id: 'invoice-stat',
		widgetKey: 'stat-card',
		slot: 'main',
		config: { schema: `${SOURCE_APP}-invoice`, metric: 'count' },
	},
]

test.describe('Buildiq component blocks', () => {
	// The page designer is a three-pane desktop surface; at the default 1280x720
	// the page-list rows land below the fold where a click never settles.
	test.use({ viewport: { width: 1600, height: 1200 } })

	/**
	 * Call a Nextcloud API from inside the page.
	 *
	 * Writes MUST go through an in-page `fetch`: a bare `page.request.post` sends
	 * the session cookie but not the `requesttoken`, and the CSRF middleware
	 * rejects it on these plain AppFramework routes.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @param {string} method - HTTP method.
	 * @param {string} url - absolute path on the instance.
	 * @param {?object} body - JSON body, if any.
	 * @return {Promise<{status: number, data: *}>} status + parsed body.
	 */
	async function api(page, method, url, body = null) {
		return page.evaluate(
			async ({ method, url, body }) => {
				const tok =
					window.OC?.requestToken
					|| document
						.querySelector('head')
						?.getAttribute('data-requesttoken')
					|| ''
				const resp = await fetch(url, {
					method,
					headers: {
						requesttoken: tok,
						'OCS-APIRequest': 'true',
						'Content-Type': 'application/json',
					},
					...(body ? { body: JSON.stringify(body) } : {}),
				})
				const text = await resp.text()
				let data
				try {
					data = JSON.parse(text)
				} catch {
					data = text
				}
				return { status: resp.status, data }
			},
			{ method, url, body },
		)
	}

	/**
	 * Replace an app's page list with a single known page, so each scenario
	 * starts from the same widgets and the suite is idempotent across runs.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @param {string} app - the app slug.
	 * @param {Array<object>} widgets - the page's `widgets[]`.
	 * @return {Promise<number>} Index of the seeded page in `manifest.pages`.
	 */
	async function seedPage(page, app, widgets) {
		const base = `/index.php/apps/buildiq/api/applications/${app}/manifest`
		const current = await api(page, 'GET', base)
		expect(current.status, `GET ${app} manifest`).toBe(200)
		const pages = (current.data.pages || []).filter((p) => p.id !== PAGE_ID)
		pages.push({
			id: PAGE_ID,
			type: 'index',
			route: `/${PAGE_ID}`,
			config: {},
			widgets,
		})
		const written = await api(page, 'PUT', base, {
			manifest: { ...current.data, pages },
		})
		expect(written.status, `PUT ${app} manifest`).toBe(200)
		return pages.length - 1
	}

	/**
	 * Open an app's page designer and select the seeded page.
	 *
	 * Selection is dispatched rather than clicked: the row's inputs carry
	 * `@click.stop`, and `.page-designer__centre` overlaps the left pane further
	 * down the list. Selecting a page is setup, not the behaviour under test.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @param {string} app - the app slug.
	 * @param {number} index - the page index returned by seedPage().
	 * @return {Promise<void>}
	 */
	async function openDesigner(page, app, index) {
		await page.goto(
			`${BASE_URL}/apps/buildiq/builder/${app}/pages?_version=production`,
			{
				waitUntil: 'domcontentloaded',
			},
		)
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)
		const row = page.locator('.page-list-editor__row').nth(index)
		await row.scrollIntoViewIfNeeded()
		await row.dispatchEvent('click')
		await expect(page.locator('.widget-selection-panel')).toBeVisible({
			timeout: 30_000,
		})
	}

	/**
	 * Read the designer's LIVE (staged) manifest — an insert is an in-editor
	 * edit until the page is saved, so this is where it must be observed.
	 *
	 * The component handle lives in `support/stagedManifest.ts` — see the note
	 * there on why the previous `element.__vue__` read was Vue-2-only and had to
	 * become a component-tree walk.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @return {Promise<object>} The staged manifest.
	 */
	async function readStaged(page) {
		return readStagedManifest(page)
	}

	/**
	 * Every ComponentBlock currently visible to the caller.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @return {Promise<Array<object>>} The block records.
	 */
	async function listBlocks(page) {
		const resp = await api(page, 'GET', BLOCKS_API)
		const rows = resp.data?.results ?? resp.data
		return Array.isArray(rows) ? rows : []
	}

	/**
	 * Delete every fixture block, so a run never sees the previous run's
	 * library (and a slug-taken error never blocks the capture scenarios).
	 *
	 * Scoped to the `pw-cb-` prefix — it must never touch a real block.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @return {Promise<void>}
	 */
	async function resetBlocks(page) {
		for (const block of await listBlocks(page)) {
			const slug = block?.slug ?? ''
			if (!String(slug).startsWith(BLOCK_PREFIX)) {
				continue
			}
			const uuid = block?.['@self']?.id ?? block?.id
			if (uuid) {
				await api(
					page,
					'DELETE',
					`${BLOCKS_API}/${encodeURIComponent(String(uuid))}`,
				)
			}
		}
	}

	/**
	 * Write a ComponentBlock straight to the API, in exactly the shape
	 * `captureBlock()` produces (schema refs already de-namespaced). Used by the
	 * INSERT scenarios so they do not re-drive — and re-assert — the capture UI.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @param {object} overrides - fields to override on the default record.
	 * @return {Promise<object>} The stored block record.
	 */
	async function seedBlock(page, overrides = {}) {
		const record = {
			slug: `${BLOCK_PREFIX}seeded`,
			name: 'PW seeded invoice list',
			description: 'fixture block',
			category: 'display',
			schemaDependencies: ['invoice'],
			sourceApplicationSlug: SOURCE_APP,
			fragment: {
				id: 'invoice-list',
				widgetKey: 'object-list',
				slot: 'main',
				config: { schema: 'invoice', title: 'Invoices' },
			},
			...overrides,
		}
		const resp = await api(page, 'POST', BLOCKS_API, record)
		expect([200, 201], `POST block ${record.slug}`).toContain(resp.status)
		return resp.data
	}

	/**
	 * Open the Blocks sidebar and return the card for a named block.
	 *
	 * @param {import('@playwright/test').Page} page - the Playwright page.
	 * @param {string} name - the block's display name.
	 * @return {Promise<import('@playwright/test').Locator>} The block card.
	 */
	async function openBlockLibrary(page, name) {
		await page.getByRole('button', { name: /^Blocks$/ }).click()
		const panel = page.locator('.block-library-panel')
		await expect(panel).toBeVisible({ timeout: 15_000 })
		const card = panel.locator('.block-card').filter({ hasText: name })
		await expect(card).toBeVisible({ timeout: 15_000 })
		return card
	}

	test.beforeEach(async ({ page }) => {
		// The first-open support dialog mounts a mask that swallows every click.
		await suppressSupportDialog(page)
		await ensureApp(page, SOURCE_APP, 'PW CB Source')
		await ensureApp(page, TARGET_APP, 'PW CB Target')
		await resetBlocks(page)
	})

	// @e2e component-blocks::saving-a-widget-captures-its-config-not-its-data
	// @e2e component-blocks::save-a-single-widget-as-a-block
	test('saves a single widget as a block, capturing its config and no object data', async ({
		page,
	}) => {
		const index = await seedPage(page, SOURCE_APP, SOURCE_WIDGETS)
		await openDesigner(page, SOURCE_APP, index)

		const panel = page.locator('.widget-selection-panel')
		await expect(panel.locator('.widget-selection-panel__row')).toHaveCount(2)
		await panel.locator('input[type="checkbox"]').first().check()
		await expect(panel.locator('.widget-selection-panel__save-btn')).toHaveText(
			/Save selected widget as block/i,
		)
		await panel.locator('.widget-selection-panel__save-btn').click()

		const dialog = page.locator('.ob-save-block')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByLabel(/Block name/i).fill('PW invoice list')
		await dialog.getByLabel(/Slug/i).fill(`${BLOCK_PREFIX}invoice-list`)
		await dialog.getByLabel(/Category/i).fill('display')

		// The capture summary names the schema the widget binds to, DE-NAMESPACED
		// (`pw-cb-source-invoice` → `invoice`), and states outright that no object
		// rows travel with the block.
		await expect(dialog.locator('.ob-save-block__schemas')).toContainText(
			'invoice',
		)
		await expect(dialog.locator('.ob-save-block__no-rows')).toContainText(
			/No object data/i,
		)

		await page.getByRole('button', { name: /^Save block$/i }).click()

		await expect
			.poll(async () => (await listBlocks(page)).map((b) => b.slug), {
				timeout: 30_000,
			})
			.toContain(`${BLOCK_PREFIX}invoice-list`)

		const stored = (await listBlocks(page)).find(
			(b) => b.slug === `${BLOCK_PREFIX}invoice-list`,
		)
		expect(stored.schemaDependencies).toEqual(['invoice'])
		expect(stored.sourceApplicationSlug).toBe(SOURCE_APP)
		// Config travels; the source app's namespace does not.
		expect(stored.fragment.config.schema).toBe('invoice')
		expect(stored.fragment.config.title).toBe('Invoices')
		// One widget captured, not the whole page, and no object rows anywhere.
		expect(stored.fragment.widgets).toBeUndefined()
		expect(JSON.stringify(stored.fragment)).not.toContain('results')
	})

	// @e2e component-blocks::save-a-page-section-as-a-block
	test('saves a multi-widget section as a section block', async ({ page }) => {
		const index = await seedPage(page, SOURCE_APP, SOURCE_WIDGETS)
		await openDesigner(page, SOURCE_APP, index)

		const panel = page.locator('.widget-selection-panel')
		await panel.locator('input[type="checkbox"]').nth(0).check()
		await panel.locator('input[type="checkbox"]').nth(1).check()
		// Selecting more than one flips the same affordance to a section capture.
		await expect(panel.locator('.widget-selection-panel__save-btn')).toHaveText(
			/Save selected section as block/i,
		)
		await panel.locator('.widget-selection-panel__save-btn').click()

		const dialog = page.locator('.ob-save-block')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await dialog.getByLabel(/Block name/i).fill('PW invoice section')
		await dialog.getByLabel(/Slug/i).fill(`${BLOCK_PREFIX}invoice-section`)
		await dialog.getByLabel(/Category/i).fill('layout')
		await page.getByRole('button', { name: /^Save block$/i }).click()

		await expect
			.poll(async () => (await listBlocks(page)).map((b) => b.slug), {
				timeout: 30_000,
			})
			.toContain(`${BLOCK_PREFIX}invoice-section`)

		const stored = (await listBlocks(page)).find(
			(b) => b.slug === `${BLOCK_PREFIX}invoice-section`,
		)
		// A section fragment wraps the widgets; both are captured, both rewritten.
		expect(Array.isArray(stored.fragment.widgets)).toBe(true)
		expect(stored.fragment.widgets).toHaveLength(2)
		expect(stored.fragment.widgets.map((w) => w.config.schema)).toEqual([
			'invoice',
			'invoice',
		])
		expect(stored.schemaDependencies).toEqual(['invoice'])
	})

	// @e2e component-blocks::library-lists-org-wide-blocks
	// @e2e component-blocks::cross-app-insert-with-no-matching-schema-requires-remap
	// @e2e component-blocks::unresolved-remap-inserts-a-visible-placeholder-not-a-silent-drop
	test('inserting into another app prompts for a remap and, left unresolved, inserts a visible placeholder', async ({
		page,
	}) => {
		const block = await seedBlock(page)
		const index = await seedPage(page, TARGET_APP, [])
		await openDesigner(page, TARGET_APP, index)

		// The library is org-wide: a block saved from the SOURCE app is listed
		// while designing the TARGET app.
		const card = await openBlockLibrary(page, block.name)
		await card.getByRole('button', { name: /^Insert$/ }).click()

		// The target app owns no schema called `invoice`, so the insert stops and
		// asks — it never binds silently to something that is not there.
		const remap = page.locator('.ob-block-remap')
		await expect(remap).toBeVisible({ timeout: 15_000 })
		await expect(remap.locator('.ob-block-remap__row')).toHaveCount(1)
		await expect(remap.locator('.ob-block-remap__source')).toHaveText('invoice')

		// Confirm WITHOUT mapping it: unresolved must still insert, marked.
		await page.getByRole('button', { name: /^Insert block$/ }).click()

		const manifest = await readStaged(page)
		const target = manifest.pages.find((p) => p.id === PAGE_ID)
		expect(target.widgets).toHaveLength(1)
		expect(target.widgets[0].widgetKey).toBe('object-list')
		expect(target.widgets[0].config.schema).toBe(UNRESOLVED)
		expect(target.widgets[0].config.needsRemap).toBe(true)
		// Config that has nothing to do with the schema survives the insert.
		expect(target.widgets[0].config.title).toBe('Invoices')
	})

	// @e2e component-blocks::inserting-the-same-block-twice-does-not-collide
	test('inserting the same block twice mints distinct widget ids', async ({
		page,
	}) => {
		const block = await seedBlock(page)
		const index = await seedPage(page, TARGET_APP, [])
		await openDesigner(page, TARGET_APP, index)

		const card = await openBlockLibrary(page, block.name)
		for (let i = 0; i < 2; i++) {
			await card.getByRole('button', { name: /^Insert$/ }).click()
			await expect(page.locator('.ob-block-remap')).toBeVisible({
				timeout: 15_000,
			})
			await page.getByRole('button', { name: /^Insert block$/ }).click()
			await expect(page.locator('.ob-block-remap')).toBeHidden({
				timeout: 15_000,
			})
		}

		const manifest = await readStaged(page)
		const target = manifest.pages.find((p) => p.id === PAGE_ID)
		expect(target.widgets).toHaveLength(2)
		const ids = target.widgets.map((w) => w.id)
		expect(new Set(ids).size).toBe(2)
		// Both copies are real, complete widgets — not one widget and one stub.
		expect(target.widgets.map((w) => w.widgetKey)).toEqual([
			'object-list',
			'object-list',
		])
	})

	// @e2e component-blocks::editing-the-source-block-does-not-affect-an-inserted-copy
	test('editing the source block afterwards never changes an already-inserted copy', async ({
		page,
	}) => {
		const block = await seedBlock(page)
		const index = await seedPage(page, TARGET_APP, [])
		await openDesigner(page, TARGET_APP, index)

		const card = await openBlockLibrary(page, block.name)
		await card.getByRole('button', { name: /^Insert$/ }).click()
		await expect(page.locator('.ob-block-remap')).toBeVisible({
			timeout: 15_000,
		})
		await page.getByRole('button', { name: /^Insert block$/ }).click()

		// Persist the insert, so what follows is tested against stored state.
		await page.getByRole('button', { name: /save pages/i }).click()
		await expect
			.poll(
				async () => {
					const resp = await api(
						page,
						'GET',
						`/index.php/apps/buildiq/api/applications/${TARGET_APP}/manifest`,
					)
					return (
						(resp.data.pages || []).find((p) => p.id === PAGE_ID)
							?.widgets?.length ?? 0
					)
				},
				{ timeout: 30_000 },
			)
			.toBe(1)

		const before = (
			await api(
				page,
				'GET',
				`/index.php/apps/buildiq/api/applications/${TARGET_APP}/manifest`,
			)
		).data.pages.find((p) => p.id === PAGE_ID).widgets[0]

		// Now edit the SOURCE block — a deep copy was inserted, so this must not
		// reach back into the copy.
		const uuid = block['@self']?.id ?? block.id
		const edited = await api(
			page,
			'PUT',
			`${BLOCKS_API}/${encodeURIComponent(String(uuid))}`,
			{
				...block,
				name: 'PW seeded invoice list (edited)',
				fragment: {
					...block.fragment,
					widgetKey: 'totally-different-widget',
					config: { ...block.fragment.config, title: 'Changed' },
				},
			},
		)
		expect([200, 201]).toContain(edited.status)

		await page.reload({ waitUntil: 'domcontentloaded' })
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })

		const after = (
			await api(
				page,
				'GET',
				`/index.php/apps/buildiq/api/applications/${TARGET_APP}/manifest`,
			)
		).data.pages.find((p) => p.id === PAGE_ID).widgets[0]
		expect(after).toEqual(before)
		expect(after.widgetKey).toBe('object-list')
		expect(after.config.title).toBe('Invoices')
	})

	// @e2e component-blocks::blocks-filter-shows-only-blocks
	// @e2e openspec/specs/openbuild-template-catalogue/spec.md#blocks-filter-shows-blocks-without-the-clone-action
	//
	// ⚠️ The second anchor used to read `component-blocks::blocks-filter-shows-
	// blocks-without-the-clone-action`. That scenario lives in
	// `buildiq-template-catalogue`, not `component-blocks`, so the ref
	// resolved to NOTHING: gate-19 credited no scenario, reported no error, and
	// the real scenario sat in the uncovered list while a test that proves it
	// was right here. A dangling anchor is silent — it looks exactly like
	// coverage in the file and exactly like an absence in the gate.
	//
	// Found by walking every anchor in the suite against the gate's own parser
	// (script on the fleet board); buildiq had 15 such anchors, 14 of them
	// pointing at `page-designer-ui` scenario names that no longer exist.
	//
	// NOT ASSERTED, stated so nobody reads more into this anchor than it earns:
	// the scenario's THEN says the cards carry "name, description, category and
	// a preview"; this test asserts the NAME and the AND (no clone action). The
	// distinguishing behaviour — browse-only, no clone affordance — is covered.
	test('the template gallery Blocks tab lists blocks, without a clone action', async ({
		page,
	}) => {
		const block = await seedBlock(page)
		await page.goto(`${BASE_URL}/apps/buildiq/templates`, {
			waitUntil: 'domcontentloaded',
		})
		await expect(page.locator('.template-gallery')).toBeVisible({
			timeout: 45_000,
		})
		await dismissOverlays(page)

		await page.getByRole('tab', { name: /^Blocks$/ }).click()
		const cards = page.locator('.template-card')
		await expect(cards.filter({ hasText: block.name })).toBeVisible({
			timeout: 20_000,
		})
		// Browse-only: blocks are inserted from the designer, never cloned into an
		// app from here.
		await expect(
			page.getByRole('button', { name: /Use this template/i }),
		).toHaveCount(0)
	})
})
