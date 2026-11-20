// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Playwright e2e: three screen overrides for one case type (change
 * screen-overrides-as-a-patch-with-fall-through, task 4.3, REQ-OBSO-004).
 *
 * WHAT THIS ASSERTS THAT PHPUNIT CANNOT
 * -------------------------------------
 * ScreenOverrideResolutionTest and the validator tests prove, against doubles,
 * that the audience is part of a layout's identity, so a team, a group and a
 * portal override on one type value do not collide. What they cannot show is
 * the authoring path an administrator actually uses: the three saved through
 * `PUT /api/page-layouts` side by side on the same published base, each pinned
 * by the server to that base, listed back by `GET /api/page-layouts` as still
 * applying, and shown in the detail page editor's "Screens for this schema"
 * with who each one is for. tests/e2e/screen-override-drift.spec.ts covers the
 * other end, the provider's answer and the drift.
 *
 * WHAT A PASS HERE DOES NOT PROVE
 * -------------------------------
 * Not which screen a given caller gets: that is resolution, asserted in
 * PHPUnit where team, group and portal callers can be built. Nor the tie
 * refusal of REQ-OBSO-005, whose scenario is excluded from e2e in the delta
 * spec. The overrides are written as the keyed `layoutDelta` the server pins,
 * not typed into the "Applies to" panel: that panel saves the page's one
 * binding, so three screens through it means retyping one binding three times,
 * which tests the form rather than the side by side storage the scenario is
 * about.
 *
 * THE FIXTURE
 * -----------
 * `beforeAll` clones `incident-reporter` into a `pw-` app, binds its detail
 * page to the app's register and `incident` schema, and publishes a
 * schema-wide base of four tabs and a type layout for `incidentType`
 * `vergunning`. `afterAll` deletes every layout this file wrote (they live in
 * buildiq's register, not the app's) and then the app.
 *
 * @spec openspec/changes/screen-overrides-as-a-patch-with-fall-through/specs/screen-override-layers/spec.md
 * @e2e screen-override-layers/requirement-an-override-is-named-and-bound-to-an-audience-req-obso-004/three-screens-for-one-case-type
 */

import type { Page } from '@playwright/test'
import type { TemplateApp } from './support/templateApp.ts'

import { expect, test } from '@playwright/test'
import { dismissOverlays, suppressSupportDialog } from './support/appFixture.ts'
import {
	adminPage,
	api,
	cloneTemplateApp,
	deleteApp,
	fixtureSlug,
	readManifest,
	writeManifest,
} from './support/templateApp.ts'

const LAYOUTS = '/index.php/apps/buildiq/api/page-layouts'
const LAYOUT_OBJECTS = '/index.php/apps/openregister/api/objects/buildiq/pageLayout'
const RUN = Date.now().toString(36)
const TYPE_PROPERTY = 'incidentType'
const TYPE_VALUE = 'vergunning'

const TABS = [
	{ id: 'gegevens', kind: 'fieldGroup', label: 'Gegevens', order: 0, fields: ['location', 'description'] },
	{ id: 'behandeling', kind: 'fieldGroup', label: 'Behandeling', order: 1, fields: ['status', 'severity'] },
	{ id: 'documenten', kind: 'leaf', label: 'Documenten', order: 2, ref: 'files' },
	{ id: 'historie', kind: 'leaf', label: 'Historie', order: 3, ref: 'audit-trail' },
]

/** The three screens of the scenario: handler, front office and portal. */
const SCREENS = [
	{
		key: 'handler',
		name: `Handler screen ${RUN}`,
		audience: { kind: 'team', ref: 'vergunningen' },
		label: 'team: vergunningen',
		layoutDelta: { tabs: { behandeling: { label: 'Behandeling en besluit' } } },
	},
	{
		key: 'front-office',
		name: `Front office screen ${RUN}`,
		audience: { kind: 'group', ref: 'frontoffice' },
		label: 'group: frontoffice',
		layoutDelta: { tabs: { historie: { $op: 'remove' } } },
	},
	{
		key: 'portal',
		name: `Portal screen ${RUN}`,
		audience: { kind: 'portal', ref: 'burgerportaal' },
		label: 'Portal visitors',
		layoutDelta: { tabs: { behandeling: { $op: 'remove' }, historie: { $op: 'remove' }, gegevens: { label: 'Uw aanvraag' } } },
	},
]

test.describe.configure({ mode: 'serial', timeout: 90_000 })

let app: TemplateApp | null = null
let detailPosition = 0
const written: string[] = []

/**
 * The identity every layout of this file shares, bar its type and audience.
 *
 * @return The register, schema and target app.
 */
function scope(): Record<string, string> {
	return { targetApp: app!.slug, register: app!.register, schema: app!.schema }
}

/**
 * Save a layout as the editor does, and remember it for afterAll.
 *
 * @param page   An admin page.
 * @param layout The layout.
 * @return The status and the answer.
 */
async function saveLayout(page: Page, layout: Record<string, unknown>): Promise<{ status: number; json: any }> {
	const saved = await api(page, 'PUT', LAYOUTS, layout)
	if (saved.status === 200) {
		written.push(String(layout.id))
	}
	return saved
}

/**
 * The stored layouts of the fixture schema, with their drift state.
 *
 * @param page An admin page.
 * @return The layouts.
 */
async function listedLayouts(page: Page): Promise<Array<Record<string, any>>> {
	const listed = await api(page, 'GET', `${LAYOUTS}?register=${encodeURIComponent(app!.register)}&schema=${encodeURIComponent(app!.schema)}`)
	expect(listed.status, JSON.stringify(listed.json).slice(0, 300)).toBe(200)
	return (listed.json?.items ?? []) as Array<Record<string, any>>
}

test.describe('screen override editor', () => {
	test.use({ viewport: { width: 1600, height: 1400 } })

	test.beforeAll(async ({ browser }) => {
		const page = await adminPage(browser)
		try {
			app = await cloneTemplateApp(page, fixtureSlug('screen-overrides'), `PW Screen overrides ${RUN}`)

			// Bind the detail page to the app's own register: the template ships
			// it with an unresolved `{{register}}`.
			const manifest = await readManifest(page, app.slug)
			const pages = (manifest.pages ?? []) as Array<Record<string, any>>
			detailPosition = pages.findIndex((p) => p.type === 'detail')
			expect(detailPosition, 'the template has a detail page').toBeGreaterThanOrEqual(0)
			pages[detailPosition] = {
				...pages[detailPosition],
				config: { ...pages[detailPosition].config, register: app.register, schema: app.schema },
			}
			await writeManifest(page, app.slug, { ...manifest, pages })

			const base = await saveLayout(page, {
				...scope(),
				id: `pw-${RUN}-base`,
				name: `Base screen ${RUN}`,
				typeProperty: '',
				typeValue: '',
				status: 'published',
				tabs: TABS,
			})
			expect(base.status, `the base: ${JSON.stringify(base.json).slice(0, 300)}`).toBe(200)

			const typed = await saveLayout(page, {
				...scope(),
				id: `pw-${RUN}-type`,
				name: `Vergunning screen ${RUN}`,
				typeProperty: TYPE_PROPERTY,
				typeValue: TYPE_VALUE,
				status: 'published',
				tabs: TABS,
			})
			expect(typed.status, `the type layout: ${JSON.stringify(typed.json).slice(0, 300)}`).toBe(200)
		} finally {
			await page.close()
		}
	})

	test.afterAll(async ({ browser }) => {
		const page = await adminPage(browser)
		try {
			// Overrides first, so no base is ever removed from under a patch.
			for (const id of [...written].reverse()) {
				await api(page, 'DELETE', `${LAYOUT_OBJECTS}/${id}`).catch(() => undefined)
			}
			await deleteApp(page, app?.uuid ?? '')
		} finally {
			await page.close()
		}
	})

	test('REQ-OBSO-004: a team, a group and a portal override exist side by side on one base', async ({ page }) => {
		await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })

		for (const screen of SCREENS) {
			const saved = await saveLayout(page, {
				...scope(),
				id: `pw-${RUN}-${screen.key}`,
				name: screen.name,
				typeProperty: TYPE_PROPERTY,
				typeValue: TYPE_VALUE,
				audience: screen.audience,
				status: 'published',
				layoutDelta: screen.layoutDelta,
			})
			// A refusal here is the point of failure worth reading: the audience
			// not being part of the identity makes the second one collide.
			expect(saved.status, `${screen.name}: ${JSON.stringify(saved.json).slice(0, 300)}`).toBe(200)
			expect(saved.json?.layout?.baseFingerprint, `${screen.name} is pinned to the base it was cut against`).toBeTruthy()
		}

		const items = await listedLayouts(page)
		for (const screen of SCREENS) {
			const listed = items.find((item) => item.id === `pw-${RUN}-${screen.key}`)
			expect(listed, `${screen.name} is listed`).toBeTruthy()
			expect(listed).toMatchObject({
				name: screen.name,
				typeProperty: TYPE_PROPERTY,
				typeValue: TYPE_VALUE,
				audience: screen.audience,
				status: 'published',
				drifted: false,
				orphanedPaths: [],
			})
			expect(listed!.layoutDelta).toEqual(screen.layoutDelta)
		}

		// The base they share is untouched by any of them.
		const base = items.find((item) => item.id === `pw-${RUN}-base`)
		expect(base!.tabs.map((tab: Record<string, unknown>) => tab.id)).toEqual(TABS.map((tab) => tab.id))
	})

	test('REQ-OBSO-004: the detail page editor lists the three screens with who each is for', async ({ page }) => {
		await page.goto('/index.php/apps/buildiq/', { waitUntil: 'domcontentloaded' })
		const items = await listedLayouts(page)
		test.skip(
			!SCREENS.every((screen) => items.some((item) => item.id === `pw-${RUN}-${screen.key}`)),
			'the three overrides were not stored by the previous test',
		)

		await suppressSupportDialog(page)
		await page.goto(`/index.php/apps/buildiq/builder/${app!.slug}/pages?_version=production`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)
		await page.locator('.page-list-editor__row').nth(detailPosition).click()

		const list = page.locator('.override-list')
		await expect(list, 'the detail page editor shows the screens for its schema').toBeVisible({ timeout: 30_000 })
		for (const screen of SCREENS) {
			const row = list.locator('.override-list__item').filter({ hasText: screen.name })
			await expect(row, `${screen.name} is listed`).toHaveCount(1, { timeout: 30_000 })
			await expect(row.locator('.override-list__audience')).toHaveText(screen.label)
			await expect(row.locator('.override-list__state')).toHaveText('Applies')
		}
	})
})
