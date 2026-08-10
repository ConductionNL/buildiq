// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * E2E coverage for the docudesk-document-templates spec — the UI-driven
 * scenarios (REQ-DDT-002 builder attach + preview, REQ-DDT-003/004 runtime
 * generate + download, REQ-DDT-005 graceful absence).
 *
 * UN-QUARANTINED 2026-07-30 (builder scenarios). Every test in this file used
 * to be an unconditional `test.skip` whose body was `goto('/applications')` +
 * `expect(main).toBeVisible()` — the titles claimed to drive the Documents
 * section and the runtime detail surface, but none of them did. Removing the
 * `.skip` alone would have produced twelve green tests asserting nothing, so
 * the builder scenarios below are written against the real surfaces instead.
 *
 * They need Docudesk installed AND its template register configured: until
 * `template_register` / `template_schema` are set, `GET api/templates` answers
 * `notConfigured` and the picker is permanently empty. globalSetup configures
 * it and seeds the "Bevestigingsbrief" / "Besluit" fixtures — see
 * tests/e2e/global-setup.ts.
 *
 * The runtime scenarios (REQ-DDT-003/004 and the runtime half of REQ-DDT-005)
 * remain skipped, now with their REAL reason recorded rather than the stale
 * "#41 builder UI not functional" one: `DocumentActions` filters attachments by
 * `object['@self'].schema`, which OpenRegister returns as the NUMERIC schema id
 * ("21"), while a `runtime.documents[]` entry declares a schema SLUG
 * ("hello-message"). The two never match, so the surface renders nothing for
 * every real object regardless of configuration. That is a product defect, not
 * a test-harness one, and is reported rather than papered over. Their logic is
 * covered by tests/components/DocumentActions.spec.js and
 * tests/composables/useDocudeskDocument.spec.js in the meantime.
 */

import { test, expect } from '@playwright/test'
import { ensureApp, dismissOverlays, suppressSupportDialog } from '../support/appFixture'
import { readStagedManifest } from '../support/stagedManifest'
import { E2E_BASE_URL as BASE } from '../support/baseUrl'
import { confirmAction } from '../support/confirmDialog'

const APP_SLUG = 'pw-docudesk'
const SCHEMA_SLUG = 'hello-message'
const TEMPLATE_NAME = 'Bevestigingsbrief'

test.describe('docudesk-document-templates — builder surfaces', () => {
	// The designer is a three-pane desktop surface; at the default 1280x720 the
	// Documents section lands well below the fold and its controls never settle.
	test.use({ viewport: { width: 1600, height: 1200 } })

	/**
	 * Replace the fixture app's manifest with a known baseline.
	 *
	 * `PageDesignerHost.appSchemas` reads `manifest.schemas` (not the register),
	 * so embedding one schema here is what makes the dialog's schema picker
	 * offer anything at all.
	 *
	 * @param page Playwright page.
	 * @param documents Seed value for `runtime.documents[]`.
	 * @return {Promise<void>}
	 */
	async function seedManifest(page, documents: object[] = []): Promise<void> {
		const base = `${BASE}/index.php/apps/openbuild/api/applications/${APP_SLUG}/manifest`
		const current = await page.request.get(base, { headers: { 'OCS-APIRequest': 'true' } })
		expect(current.ok(), 'GET fixture manifest must succeed').toBeTruthy()
		const manifest = await current.json()
		const next = {
			...manifest,
			schemas: [{ slug: SCHEMA_SLUG, title: 'Hello Message', properties: { title: { type: 'string' } } }],
			runtime: { ...(manifest.runtime || {}), ...(documents.length ? { documents } : {}) },
		}
		if (documents.length === 0 && next.runtime) {
			delete next.runtime.documents
		}
		const written = await page.request.put(base, {
			headers: { 'OCS-APIRequest': 'true' },
			data: { manifest: next },
		})
		expect(written.ok(), 'PUT fixture manifest must succeed').toBeTruthy()
	}

	/**
	 * Open the fixture app's page designer and scroll the Documents section in.
	 *
	 * @param page Playwright page.
	 * @return {Promise<void>}
	 */
	async function openDesigner(page): Promise<void> {
		await page.goto(`${BASE}/apps/openbuild/builder/${APP_SLUG}/pages?_version=production`, {
			waitUntil: 'domcontentloaded',
		})
		await page.waitForSelector('.page-designer__left', { timeout: 60_000 })
		await dismissOverlays(page)
		await page.locator('.ob-documents-section').scrollIntoViewIfNeeded()
		await expect(page.locator('.ob-documents-section')).toBeVisible({ timeout: 30_000 })
	}

	/**
	 * Pick an option from one of the dialog's NcSelects by its input label.
	 *
	 * Options are matched on TEXT, not on accessible name: NcSelect wraps an
	 * option's label in nested elements, and the accessible-name computation
	 * joins those nodes with a space — the "Bevestigingsbrief" option computes
	 * as "Bevestigi ngsbrief", so `getByRole('option', { name })` never matches.
	 * `hasText` reads textContent, which has no such seam.
	 *
	 * @param page Playwright page.
	 * @param inputLabel The select's `inputLabel` text.
	 * @param optionText The option to choose.
	 * @return {Promise<void>}
	 */
	async function pickOption(page, inputLabel: RegExp, optionText: RegExp): Promise<void> {
		await page.getByRole('combobox', { name: inputLabel }).click()
		await page.getByRole('option').filter({ hasText: optionText }).first().click()
	}

	/**
	 * Open the attach/edit dialog and wait until its template list has actually
	 * arrived.
	 *
	 * The dialog fetches `GET api/templates` from its `open` watcher, so the
	 * picker renders "No results" until that resolves. Opening the dropdown
	 * before then made the option locator time out on a list that was merely
	 * late — observed intermittently, and it is a race, not a slow assertion, so
	 * it is fixed by waiting for the precondition rather than by a longer wait.
	 *
	 * @param page Playwright page.
	 * @param trigger Accessible name of the button that opens the dialog.
	 * @return {Promise<void>}
	 */
	async function openDialog(page, trigger: RegExp): Promise<void> {
		const templates = page.waitForResponse(
			(resp) => /\/apps\/docudesk\/api\/templates(\?.*)?$/.test(resp.url()) && resp.request().method() === 'GET',
			{ timeout: 20_000 },
		)
		await page.getByRole('button', { name: trigger }).click()
		const resp = await templates
		expect(resp.status(), 'the template list must load before picking one').toBe(200)
		// The list is rendered from the resolved promise a tick later.
		await expect(page.locator('.ob-document-attach')).toBeVisible()
	}

	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await ensureApp(page, APP_SLUG, 'PW Docudesk')
	})

	// @e2e docudesk-document-templates::attaching-a-template-writes-the-manifest-entry
	test('REQ-DDT-002 — attach a Docudesk template via the Documents section', async ({ page }) => {
		await seedManifest(page)
		await openDesigner(page)

		await expect(page.locator('.ob-documents-section__empty')).toBeVisible()
		await openDialog(page, /attach template/i)

		await pickOption(page, /^template$/i, new RegExp(TEMPLATE_NAME, 'i'))
		await pickOption(page, /^schema$/i, /hello message/i)
		await page.getByRole('textbox', { name: /action label/i }).fill('Generate confirmation letter')
		await page.getByRole('button', { name: /^save$/i }).click()

		// The Documents section lists the new attachment.
		const item = page.locator('.ob-documents-section__item')
		await expect(item).toHaveCount(1)
		await expect(item.first()).toContainText('Generate confirmation letter')
		await expect(item.first()).toContainText(TEMPLATE_NAME)

		// …and the in-flight manifest carries the entry, with the template's own
		// UUID and name snapshot (not just the label the user typed).
		const staged = await readStagedManifest(page)
		const documents = staged.runtime.documents
		expect(documents).toHaveLength(1)
		expect(documents[0].schema).toBe(SCHEMA_SLUG)
		expect(documents[0].label).toBe('Generate confirmation letter')
		expect(documents[0].templateName).toBe(TEMPLATE_NAME)
		expect(documents[0].templateId).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i)
	})

	// @e2e docudesk-document-templates::preview-renders-before-committing
	test('REQ-DDT-002 — preview renders the template before saving', async ({ page }) => {
		await seedManifest(page)
		await openDesigner(page)

		await openDialog(page, /attach template/i)
		await pickOption(page, /^template$/i, new RegExp(TEMPLATE_NAME, 'i'))

		// Assert the CONTRACT (REQ-DDT-006 pins preview to this exact route),
		// then assert the result is presented without committing anything.
		const previewRequest = page.waitForRequest(
			(req) => /\/apps\/docudesk\/api\/templates\/[^/]+\/preview$/.test(req.url()) && req.method() === 'POST',
			{ timeout: 20_000 },
		)
		await page.getByRole('button', { name: /preview with sample data/i }).click()
		await previewRequest

		// Either a rendered body or the explicit failure message — both are
		// "presented", and neither may silently do nothing.
		await expect(
			page.locator('.ob-document-attach__preview-body, .ob-document-attach__preview .ob-document-attach__error'),
		).toBeVisible({ timeout: 20_000 })

		// Nothing was saved: the manifest is still attachment-free.
		const staged = await readStagedManifest(page)
		expect(staged.runtime?.documents ?? []).toHaveLength(0)
	})

	// @e2e docudesk-document-templates::edit-warns-about-a-deleted-template
	test('REQ-DDT-002 — editing warns when the template was deleted', async ({ page }) => {
		// An attachment whose template no longer exists in Docudesk: the UUID is
		// well-formed but unknown, so the dialog's snapshot refresh 404s.
		await seedManifest(page, [{
			id: 'pw-gone',
			schema: SCHEMA_SLUG,
			templateId: '00000000-0000-4000-8000-000000000000',
			templateName: 'Verwijderde brief',
			label: 'Generate deleted letter',
		}])
		await openDesigner(page)

		await expect(page.locator('.ob-documents-section__item')).toHaveCount(1)

		// REQ-DDT-006 pins the existence check to `GET api/templates/{id}`.
		// Assert the call AND its status, so a warning that fails to appear is
		// distinguishable from a check that was never made.
		const snapshotResponse = page.waitForResponse(
			(resp) => /\/apps\/docudesk\/api\/templates\/00000000-0000-4000-8000-000000000000$/.test(resp.url()),
			{ timeout: 20_000 },
		)
		await page.locator('.ob-documents-section__item').first().getByRole('button', { name: /^edit$/i }).click()
		expect((await snapshotResponse).status(), 'a deleted template must 404').toBe(404)

		await expect(page.locator('.ob-document-attach__warn')).toContainText(/no longer exists/i, { timeout: 20_000 })
		// The builder can still recover — the template picker stays usable.
		await expect(page.getByRole('combobox', { name: /^template$/i })).toBeVisible()
	})

	// @e2e docudesk-document-templates::dependency-auto-added-on-save
	test('REQ-DDT-005 — docudesk dependency auto-added on save', async ({ page }) => {
		await seedManifest(page, [{
			id: 'pw-dep',
			schema: SCHEMA_SLUG,
			templateId: '11111111-1111-4111-8111-111111111111',
			templateName: TEMPLATE_NAME,
			label: 'Generate confirmation letter',
		}])
		await openDesigner(page)

		/**
		 * Read the persisted `dependencies` array.
		 *
		 * Fetched from inside the page rather than through `page.request`: the
		 * poll below re-reads on every attempt, and a `page.request` response
		 * object is disposed once a newer one supersedes it ("Response has been
		 * disposed" on `.json()`). The in-page fetch also rides the session the
		 * save itself used.
		 *
		 * @return {Promise<string[]>} The stored dependencies.
		 */
		const persistedDependencies = async (): Promise<string[]> => {
			return page.evaluate(async (slug) => {
				const resp = await fetch(`/index.php/apps/openbuild/api/applications/${slug}/manifest`, {
					headers: { 'OCS-APIRequest': 'true' },
				})
				if (!resp.ok) {
					return []
				}
				return (await resp.json()).dependencies || []
			}, APP_SLUG)
		}

		await page.getByRole('button', { name: /save pages/i }).click()
		await expect.poll(
			async () => (await persistedDependencies()).filter((d) => d === 'docudesk').length,
			{ timeout: 20_000 },
		).toBe(1)

		// Re-saving must not duplicate the entry.
		await page.getByRole('button', { name: /save pages/i }).click()
		await expect(page.locator('.page-designer-host__toast')).toBeVisible({ timeout: 20_000 })
		expect((await persistedDependencies()).filter((d) => d === 'docudesk')).toHaveLength(1)
	})

	// @e2e docudesk-document-templates::designer-degrades-when-docudesk-is-missing
	test('REQ-DDT-005 — designer degrades when Docudesk is missing', async ({ page }) => {
		await seedManifest(page, [{
			id: 'pw-existing',
			schema: SCHEMA_SLUG,
			templateId: '22222222-2222-4222-8222-222222222222',
			templateName: TEMPLATE_NAME,
			label: 'Generate confirmation letter',
		}])

		// Simulate absence the way `useAppStatus` actually decides it. It has TWO
		// signals and both must say absent: first the server-injected
		// `OC.appswebroots` map (a synchronous positive — Docudesk IS installed
		// here, so routing alone left the Add button enabled), then a probe of
		// `/apps/docudesk/api` where only 404/501 counts as absent. Removing the
		// webroots entry and 404-ing the probe is exactly the state an
		// uninstalled Docudesk produces, rather than a bespoke test flag.
		await page.addInitScript(() => {
			const install = () => {
				const oc = (window as unknown as { OC?: { appswebroots?: Record<string, string> } }).OC
				if (oc && oc.appswebroots) {
					delete oc.appswebroots.docudesk
				}
			}
			install()
			document.addEventListener('DOMContentLoaded', install)
		})
		await page.route('**/apps/docudesk/api', (route) => route.fulfill({ status: 404, body: '' }))
		await openDesigner(page)

		// The Add action is disabled, with the missing-app hint.
		await expect(page.getByRole('button', { name: /attach template/i })).toBeDisabled({ timeout: 20_000 })
		await expect(page.locator('.ob-documents-section__hint')).toContainText(/not available/i)

		// The existing attachment stays listed, and detachable.
		const item = page.locator('.ob-documents-section__item')
		await expect(item).toHaveCount(1)
		await expect(item.first()).toContainText('Generate confirmation letter')
		// Detaching asks for confirmation. That ask WAS `window.confirm`; #163
		// replaced it with an in-page ConfirmActionDialog, and a
		// `page.once('dialog', …)` handler against a page that opens no native
		// dialog never fires and never complains — the click then left the ask on
		// screen unanswered, the manifest was never written, and the assertion
		// below failed as though detach were broken. See
		// tests/e2e/support/confirmDialog.ts.
		await item.first().getByRole('button', { name: /^detach$/i }).click()
		await confirmAction(page, 'Detach template', /^detach$/i)
		await expect(page.locator('.ob-documents-section__item')).toHaveCount(0)
	})
})

/*
 * Runtime scenarios (REQ-DDT-003, REQ-DDT-004, and the runtime half of
 * REQ-DDT-005).
 *
 * DEFECT NOW FIXED; BODIES STILL TO BE WRITTEN.
 *
 * These carried the stale "#41 builder UI not functional" reason. The real
 * blocker was a product defect, since fixed:
 *
 *   `DocumentActions.schemaAttachments` filtered on
 *   `object['@self'].schema === attachment.schema`. OpenRegister returns
 *   `@self.schema` as the NUMERIC schema id — measured on this instance, a
 *   `hello-message` object carries `"@self": { "register": "15", "schema": "21" }`
 *   — while a `runtime.documents[]` entry declares the schema SLUG
 *   (`"hello-message"`), which is what the attach dialog writes and what
 *   REQ-DDT-001 specifies. `"21" === "hello-message"` is never true, so the
 *   surface rendered nothing for every object, on every app, silently.
 *
 * Fixed 2026-07-30 by matching on a normalised key set (src/utils/objectSchemaKeys.js):
 * `CnDetailPage` provides `cnObjectContext`, whose `schema` is the manifest's
 * `config.schema` — the slug vocabulary the entries use. What the attach dialog
 * WRITES is unchanged; the numeric side was the wrong one. Locked by two
 * regression tests in tests/components/DocumentActions.spec.js that use the REAL
 * `@self` envelope — note the pre-existing unit fixture used a SLUG in
 * `@self.schema`, which is precisely why this suite stayed green while the
 * surface was 100% dead.
 *
 * WHY THESE STAY SKIPPED FOR NOW: their bodies are stubs — `goto` plus
 * `expect(page.locator('main')).toBeVisible()` — so un-skipping them would
 * report coverage of download, filename interpolation, the 403 path, the
 * double-click guard and button ordering while asserting none of it. Writing
 * real bodies needs a seeded `runtime.documents[]` on the fixture app and a
 * Docudesk template per scenario; that is follow-on work, deliberately not
 * faked here. The logic itself is covered by vitest
 * (useDocudeskDocument.spec.js, DocumentActions.spec.js) and by Newman.
 *
 * A separate wiring defect on the same surface — the widget never received
 * `attachments` at all, because CnPageRenderer's slot-override path hands a
 * registry component the detail surface's own props — was fixed earlier in
 * DocumentActions.vue.
 */

// @e2e docudesk-document-templates::generate-downloads-the-document
// STUB BODY (the id-vs-slug defect is fixed; this needs a real body + seeded runtime.documents[]). Logic + request shape covered by vitest (useDocudeskDocument.spec.js) and Newman.
test.skip('REQ-DDT-003 — generate produces a download', async ({ page }) => {
	// @e2e docudesk-document-templates::generate-downloads-the-document
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e docudesk-document-templates::filename-template-interpolates-object-properties
// STUB BODY — see the note above (defect fixed; real body still to be written). Logic covered by vitest (renderFilename + buildFilename).
test.skip('REQ-DDT-003 — filename template interpolates object properties', async ({ page }) => {
	// @e2e docudesk-document-templates::filename-template-interpolates-object-properties
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e docudesk-document-templates::403-renders-a-no-access-toast-not-an-error
// STUB BODY — see the note above (defect fixed; real body still to be written). Logic covered by vitest (403 → no-access error code).
test.skip('REQ-DDT-003 — a 403 renders the no-access message', async ({ page }) => {
	// @e2e docudesk-document-templates::403-renders-a-no-access-toast-not-an-error
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e docudesk-document-templates::double-click-issues-one-request
// STUB BODY — see the note above (defect fixed; real body still to be written). Logic covered by vitest (in-flight guard test).
test.skip('REQ-DDT-003 — double-click issues exactly one request', async ({ page }) => {
	// @e2e docudesk-document-templates::double-click-issues-one-request
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e docudesk-document-templates::two-attachments-render-two-ordered-buttons
// STUB BODY — see the note above (defect fixed; real body still to be written). Logic covered by vitest (DocumentActions ordered-buttons test).
test.skip('REQ-DDT-004 — two attachments render two ordered buttons', async ({ page }) => {
	// @e2e docudesk-document-templates::two-attachments-render-two-ordered-buttons
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e docudesk-document-templates::no-attachments-renders-nothing
// STUB BODY — see the note above (defect fixed; real body still to be written). Logic covered by vitest (DocumentActions empty-render test).
test.skip('REQ-DDT-004 — no attachments renders nothing', async ({ page }) => {
	// @e2e docudesk-document-templates::no-attachments-renders-nothing
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})

// @e2e docudesk-document-templates::runtime-surface-degrades-without-requests
// STUB BODY — see the note above (defect fixed; real body still to be written). Logic covered by vitest (DocumentActions absent-app state issues no request).
test.skip('REQ-DDT-005 — runtime surface degrades without requests', async ({ page }) => {
	// @e2e docudesk-document-templates::runtime-surface-degrades-without-requests
	await page.goto(`${BASE}/apps/openbuild/applications`)
	await expect(page.locator('main')).toBeVisible({ timeout: 10_000 })
})
