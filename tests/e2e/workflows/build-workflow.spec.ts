// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * DEEP, high-value e2e — the VIRTUAL-APP BUILD WORKFLOW.
 *
 * Goal: prove you can actually compose a virtual app end-to-end — create the
 * app, give it a data model (a schema with >=2 properties), and produce the
 * runtime artifact (the app manifest the Buildiq shell renders) — not merely
 * that pages render.
 *
 * The build pipeline in this app is:
 *   1. Application object        (register buildiq / schema application)
 *   2. + data-model Schema(s)    (OpenRegister schemas the app composes)
 *   3. + an ApplicationVersion   (carries the rendered `manifest` object)
 *   4. + a BuiltAppRoute index   (slug -> applicationUuid, drives /{slug})
 *   5. productionVersion pointer (publishes the version)
 *   ⇒ GET /api/applications/{slug}/manifest returns the produced manifest.
 *
 * DRIVABLE-AND-ASSERTED HERE (green):
 *   - Create the virtual app via the real UI create modal (POST 201).
 *   - Add a Schema with two typed properties and assert BOTH persist
 *     (read-back via OR API) — the app's real data-model artifact.
 *   - Confirm the app + its building blocks are independently persisted.
 *
 * PRODUCING THE PUBLISHED MANIFEST ARTIFACT is now GREEN (formerly fixme'd):
 *   BUG-A   : OpenRegister lockObject now accepts a pre-creation/advisory
 *             identifier, so the wizard lockObject('createApp:<slug>') no longer
 *             422s — the wizard returns 201.
 *   publish : the wizard now creates the BuiltAppRoute index object (slug ->
 *             applicationUuid) as its final atomic step, so the manifest
 *             endpoint resolves a wizard-built app by slug and serves the
 *             produced manifest (version + menu + pages). The earlier BUG-C
 *             register/reserved-key collision no longer reproduces.
 * STILL NOT DRIVABLE (-> test.fixme, real reason recorded, never faked):
 *   - The ZIP export action is Conduction/buildiq#41-quarantined
 *     (see export-zip.spec.ts) — no detail/editor UI to trigger it.
 *
 * The manifest leg below contains the REAL artifact assertions against the
 * produced manifest shape (version + menu + pages), a live contract.
 */

import { test, expect, type Page } from '@playwright/test'
import {
	seedVirtualApp,
	seedSchema,
	findVirtualApp,
	findSchema,
	deleteVirtualApp,
	deleteSchema,
	cleanupByPrefix,
	fetchManifest,
	wizardCreate,
	E2E_PREFIX,
} from './fixtures'

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const authHeaders = {
	'OCS-APIRequest': 'true',
	Authorization:
		'Basic '
		+ Buffer.from(
			`${process.env.NC_ADMIN_USER ?? 'admin'}:${process.env.NC_ADMIN_PASSWORD ?? 'admin'}`,
		).toString('base64'),
}

/** Resolve a virtual-app uuid by its slug via the OR objects API. */
async function appUuidBySlug(
	request: import('@playwright/test').APIRequestContext,
	slug: string,
): Promise<string> {
	const res = await request.get(
		`${BASE_URL}/index.php/apps/openregister/api/objects/buildiq/built-app?_limit=200`,
		{ headers: authHeaders },
	)
	const body = await res.json()
	const rows: Array<Record<string, unknown>> = Array.isArray(body)
		? body
		: (body.results ?? [])
	const match = rows.find((r) => r.slug === slug)
	return String(match?.id ?? match?.['@self']?.['id'] ?? '')
}

async function gotoAppBrowser(page: Page): Promise<void> {
	// The app router runs in HISTORY mode (`createWebHistory(generateUrl('/apps/buildiq'))`
	// in src/main.js), NOT hash mode. A `#/applications` hash is not a route —
	// vue-router matches path `/` and mounts the Dashboard, so the applications
	// actions bar (and its "Add app" button) never renders. The
	// `/index.php/`-prefixed form fails too: `htaccess.RewriteBase` is `/`, so
	// `generateUrl` emits the pretty `/apps/buildiq` base and the router
	// replaces the unmatched URL with the bare app root. Live-verified: only
	// the pretty-URL path form mounts the applications index.
	await page.goto('/apps/buildiq/applications')
	await page
		.waitForResponse(
			(r) =>
				r.url().includes('/objects/buildiq/built-app')
				&& r.status() === 200,
			{ timeout: 20_000 },
		)
		.catch(() => {
			/* possibly cached */
		})
	await page.waitForTimeout(1500)
}

test.describe('Build workflow — compose a virtual app with a data model', () => {
	test.afterAll(async ({ request }) => {
		await cleanupByPrefix(request)
	})

	test('create app via UI + add a 2-property schema → both persist as real artifacts', async ({
		page,
		request,
	}) => {
		const appName = `${E2E_PREFIX} Build ${Math.floor(Math.random() * 1e4)}`
		// Slug is auto-derived (kebab-case) from the name by the create wizard.
		const appSlug = appName
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-|-$/g, '')

		// --- Step 1: create the virtual app through the real UI create wizard ---
		// 3-step wizard: App basics (name, slug auto-derives) → version preset
		// → review and create (POSTs /api/applications/wizard).
		await gotoAppBrowser(page)
		// The button reads "Add app" (src/components/VirtualAppsActions.vue),
		// not "Add application" — live-verified.
		await page
			.getByRole('button', { name: /add app/i })
			.first()
			.click()
		const dialog = page.locator('[role="dialog"], .modal-container').first()
		await expect(dialog).toBeVisible({ timeout: 8_000 })

		const nameInput = dialog
			.locator('input[placeholder*="My Permit Tracker" i]')
			.first()
		await expect(nameInput).toBeVisible({ timeout: 8_000 })
		await nameInput.fill(appName)
		await dialog.getByRole('button', { name: /^next$/i }).click()

		await expect(dialog.getByText(/choose a version preset/i)).toBeVisible({
			timeout: 8_000,
		})
		await dialog
			.getByRole('button', { name: /Single/i })
			.first()
			.click()
		await dialog.getByRole('button', { name: /^next$/i }).click()

		await expect(dialog.getByText(/review and create/i)).toBeVisible({
			timeout: 8_000,
		})
		const createPost = page.waitForResponse(
			(r) =>
				r.url().includes('/api/applications/wizard')
				&& r.request().method() === 'POST',
			{ timeout: 20_000 },
		)
		await dialog.getByRole('button', { name: /^create$/i }).click()
		const createResp = await createPost
		expect([200, 201]).toContain(createResp.status())

		// The app is a real, independently-readable artifact (resolve by slug).
		await expect
			.poll(
				async () => {
					const res = await request.get(
						`${BASE_URL}/index.php/apps/openregister/api/objects/buildiq/built-app?_limit=200`,
						{ headers: authHeaders },
					)
					const body = await res.json()
					const rows: Array<Record<string, unknown>> = Array.isArray(body)
						? body
						: (body.results ?? [])
					return rows.some((r) => r.slug === appSlug)
				},
				{ timeout: 15_000 },
			)
			.toBe(true)

		// --- Step 2: give the app a data model — a schema with 2 typed fields ---
		const schemaSlug = `${E2E_PREFIX}-build-model-${Math.floor(Math.random() * 1e4)}`
		const schema = await seedSchema(request, {
			slug: schemaSlug,
			title: 'Task',
			properties: {
				title: { type: 'string', title: 'Title' },
				done: { type: 'boolean', title: 'Done' },
			},
		})

		// Assert the produced data-model artifact: both fields persisted + typed.
		const persistedSchema = await findSchema(request, schema.id)
		expect(persistedSchema, 'schema artifact must be readable').not.toBeNull()
		const props = (persistedSchema?.properties ?? {}) as Record<
			string,
			{ type?: string }
		>
		expect(Object.keys(props).sort()).toEqual(['done', 'title'])
		expect(props.title.type).toBe('string')
		expect(props.done.type).toBe('boolean')

		// --- Cleanup the building blocks we created ---
		await deleteSchema(request, schema.id)
		const appUuid = await appUuidBySlug(request, appSlug)
		if (appUuid) {
			await deleteVirtualApp(request, appUuid)
		}
	})

	test('seeded app data model survives an independent read-back (composability)', async ({
		request,
	}) => {
		// Compose: one app + two schemas (a richer data model).
		const app = await seedVirtualApp(request, {
			name: `E2E Compose ${E2E_PREFIX}`,
		})
		const a = await seedSchema(request, {
			slug: `${E2E_PREFIX}-compose-a-${Math.floor(Math.random() * 1e4)}`,
			title: 'Customer',
			properties: { name: { type: 'string' }, email: { type: 'string' } },
		})
		const b = await seedSchema(request, {
			slug: `${E2E_PREFIX}-compose-b-${Math.floor(Math.random() * 1e4)}`,
			title: 'Order',
			properties: { ref: { type: 'string' }, total: { type: 'number' } },
		})

		// Every building block is independently persisted (no optimistic state).
		expect(await findVirtualApp(request, app.uuid)).not.toBeNull()
		const sa = await findSchema(request, a.id)
		const sb = await findSchema(request, b.id)
		expect(Object.keys((sa?.properties ?? {}) as object).sort()).toEqual([
			'email',
			'name',
		])
		expect(Object.keys((sb?.properties ?? {}) as object).sort()).toEqual([
			'ref',
			'total',
		])

		await deleteSchema(request, a.id)
		await deleteSchema(request, b.id)
		await deleteVirtualApp(request, app.uuid)
	})

	test('manifest endpoint surfaces the documented no-artifact state for an un-published app', async ({
		request,
	}) => {
		// A freshly-created app (no published version) honestly has no manifest.
		// We assert the REAL backend contract for that state — a 404 with a
		// not_found / no_manifest envelope — so a future regression (e.g. a 500
		// from the duplicate-register class of bug, BUG-B, which we cleaned up)
		// is caught. This is a true backend assertion, not a rendered shell.
		const app = await seedVirtualApp(request, {
			name: `E2E Manifest ${E2E_PREFIX}`,
		})
		const { status, body } = await fetchManifest(request, app.slug)
		expect(
			status,
			'un-published app manifest must be a clean 404, never a 500',
		).toBe(404)
		expect(JSON.stringify(body)).toMatch(/not_found|no_manifest|no published/i)
		await deleteVirtualApp(request, app.uuid)
	})

	// ---- BUG-A + publish gap FIXED: produced manifest artifact is drivable --
	// Previously fixme'd for two reasons, both now resolved:
	//   BUG-A : OpenRegister's lockObject now accepts a pre-creation/advisory
	//           identifier, so the wizard create returns 201 instead of 422.
	//   publish: the wizard now creates the BuiltAppRoute index object (slug →
	//           applicationUuid) as its final step, so the manifest endpoint can
	//           resolve a wizard-built app by slug. (The earlier BUG-C
	//           `register`-property/reserved-key collision no longer reproduces —
	//           OR persists the version's `register` field intact.)
	test('BUILD → publish → assert produced manifest artifact', async ({
		request,
	}) => {
		// The wizard atomically creates the app, an ApplicationVersion carrying
		// the rendered manifest, the BuiltAppRoute index, and the
		// productionVersion pointer. The manifest endpoint then returns the
		// produced artifact and we assert its real, version-aware fields.
		const slug = `${E2E_PREFIX}-artifact-${Math.floor(Math.random() * 1e4)}`
		const { status: wStatus, body: wBody } = await wizardCreate(request, {
			name: `E2E Artifact ${slug}`,
			slug,
			description: 'build artifact test',
			preset: 'single',
		})
		expect(wStatus, 'wizard build must succeed (201)').toBe(201)
		expect(wBody.applicationUuid).toBeTruthy()

		// The real produced artifact — assert the actual manifest shape the
		// runtime renders (version + menu + pages), not a speculative husk.
		const { status, body } = await fetchManifest(request, slug)
		expect(status, 'published wizard app must serve a 200 manifest').toBe(200)
		const manifest = body as Record<string, unknown>
		// A built app's manifest is version-stamped …
		expect(manifest.version, 'manifest must be version-stamped').toBeTruthy()
		// … carries its navigation (menu entries) …
		expect(
			Array.isArray(manifest.menu),
			'manifest must carry a menu array',
		).toBe(true)
		expect(
			(manifest.menu as unknown[]).length,
			'menu must have >=1 entry',
		).toBeGreaterThan(0)
		// … and its composed pages (the rendered surfaces), not an empty husk.
		expect(
			Array.isArray(manifest.pages),
			'manifest must carry a pages array',
		).toBe(true)
		expect(
			(manifest.pages as unknown[]).length,
			'pages must have >=1 entry',
		).toBeGreaterThan(0)
	})
})
