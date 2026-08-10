/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright end-to-end spec for openspec change `builder-undo-redo`
 * (REQ-BUR-001 .. REQ-BUR-005; REQ-BUR-006/007 are engine-seam contracts
 * excluded from e2e per the spec's `@e2e exclude` notes — see
 * openspec/specs/builder-undo-redo/spec.md).
 *
 * Follows the same conventions as the other designer e2e suites
 * (`schema-designer.spec.ts`, `schema-access-scopes.spec.ts`,
 * `versionRouting.spec.ts`): shared admin `storageState` from
 * `tests/e2e/global-setup.ts` (no per-spec login), a create-if-not-present
 * app helper, and the same Conduction/openbuild#41 quarantine — the
 * designer admin UI does not render in this build yet, so this suite is
 * skipped until #41 is fixed. One test per scenario, named with the
 * requirement id.
 *
 * Scenario → task mapping (openspec/changes/builder-undo-redo/tasks.md §7):
 *   7.1 REQ-BUR-001 undo restores / redo re-applies, no network write
 *   7.2 REQ-BUR-001 a new edit after undo truncates the redo tail
 *   7.3 REQ-BUR-002 toolbar disabled states across the edit/undo cycle
 *   7.4 REQ-BUR-003 Ctrl+Z / Ctrl+Shift+Z outside editable fields
 *   7.5 REQ-BUR-003 Ctrl+Z inside a text field leaves draft history untouched
 *   7.6 REQ-BUR-004 history survives a sub-editor (page) switch
 *   7.7 REQ-BUR-004 save resets the session history
 *   7.8 REQ-BUR-004 a version switch resets the session history
 *   7.9 REQ-BUR-005 schema designer: undo a field add, undo a discard,
 *       save resets the schema session history
 */

import { test, expect, type Page } from '@playwright/test'
import { ensureApp as ensureAppFixture, dismissOverlays, suppressSupportDialog } from './support/appFixture'
import { ensureVersionChain } from './support/versionChain'
import { saveSchemaAndAwait } from './support/schemaSave'

// PLAYWRIGHT_BASE_URL wins — see tests/e2e/support/baseUrl.ts.
import { E2E_BASE_URL as BASE_URL } from './support/baseUrl'
const APP_SLUG = 'pw-undo-redo'
const SCHEMA_SLUG = 'undo-redo-record'

/**
 * Ensure the `pw-undo-redo` virtual app exists (idempotent across runs).
 *
 * This used to be a local copy of the obsolete "Add application" button +
 * flat slug/title form flow — the exact flow tests/e2e/support/appFixture.ts
 * was introduced to replace. App creation moved to the multi-step wizard, the
 * button is now labelled "Add app", and the `isVisible()` guard therefore
 * always saw `false` and silently skipped creation, leaving the app absent.
 * This file was missed when the other specs were migrated to the shared
 * fixture (it is quarantined behind a describe.skip, so the dead flow never
 * surfaced). Delegate to the shared helper, which calls the atomic wizard
 * endpoint and checks the applications LIST for idempotency.
 *
 * @param page Playwright page.
 * @return {Promise<void>}
 */
async function ensureApp(page: Page): Promise<void> {
	await ensureAppFixture(page, APP_SLUG, 'PW Undo Redo')
}

/**
 * Locate a toolbar tool button by its visible label ("Undo" / "Redo") in
 * the page designer — the buttons render `↶ Undo` / `↷ Redo`.
 *
 * @param page Playwright page.
 * @param label "Undo" or "Redo".
 */
function pageDesignerButton(page: Page, label: 'Undo' | 'Redo') {
	return page.locator('.page-designer__tool-btn').filter({ hasText: new RegExp(label, 'i') })
}

// UN-QUARANTINED 2026-07-30. #41 is fixed and both of this file's remaining
// blockers were harness debt, not product debt:
//   - app creation went through a local copy of the obsolete "Add application"
//     form whose isVisible() guard silently skipped creation (fixed above, by
//     delegating to the shared wizard fixture);
//   - the first-open support dialog mounts a `.modal-mask` over the whole
//     designer, and `.page-list-editor__add` sits underneath it — every click
//     retried against `<h2 class="dialog__name">` until the test timed out.
//     Measured directly: elementFromPoint over the Add button returned the
//     support dialog, not the button.
test.describe('builder-undo-redo — page designer (REQ-BUR-001..004)', () => {
	test.beforeEach(async ({ page }) => {
		// Before the first navigation: the dialog seeds its own visibility from
		// localStorage, so pre-setting the flag beats racing the mask.
		await suppressSupportDialog(page)
		await ensureApp(page)
	})

	test('REQ-BUR-001: undo restores the previous draft state, redo re-applies it, no network write', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/pages`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		const writes: string[] = []
		page.on('request', (req) => {
			if (['PUT', 'PATCH', 'POST'].includes(req.method()) && req.url().includes('/apps/openregister/')) {
				writes.push(`${req.method()} ${req.url()}`)
			}
		})

		const initialCount = await page.locator('.page-list-editor__row').count()

		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('index')
		await page.getByRole('button', { name: /^confirm$/i }).click()
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount + 1)

		await pageDesignerButton(page, 'Undo').click()
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount)

		await pageDesignerButton(page, 'Redo').click()
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount + 1)

		expect(writes, 'undo/redo must never issue a PUT/PATCH/POST').toHaveLength(0)
	})

	test('REQ-BUR-001: a new edit after undo truncates the redo tail', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/pages`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('index')
		await page.getByRole('button', { name: /^confirm$/i }).click()

		await pageDesignerButton(page, 'Undo').click()
		await expect(pageDesignerButton(page, 'Redo')).toBeEnabled()

		// A different new edit — add a page of a different type — must
		// discard the undone branch.
		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('form')
		await page.getByRole('button', { name: /^confirm$/i }).click()

		await expect(pageDesignerButton(page, 'Redo')).toBeDisabled()
	})

	test('REQ-BUR-002: toolbar disabled states across the edit/undo cycle', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/pages`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		await expect(pageDesignerButton(page, 'Undo')).toBeDisabled()
		await expect(pageDesignerButton(page, 'Redo')).toBeDisabled()

		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('index')
		await page.getByRole('button', { name: /^confirm$/i }).click()

		await expect(pageDesignerButton(page, 'Undo')).toBeEnabled()
		await expect(pageDesignerButton(page, 'Redo')).toBeDisabled()

		await pageDesignerButton(page, 'Undo').click()
		await expect(pageDesignerButton(page, 'Undo')).toBeDisabled()
		await expect(pageDesignerButton(page, 'Redo')).toBeEnabled()
	})

	test('REQ-BUR-003: Ctrl+Z / Ctrl+Shift+Z drive undo and redo outside editable fields', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/pages`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		const initialCount = await page.locator('.page-list-editor__row').count()

		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('index')
		await page.getByRole('button', { name: /^confirm$/i }).click()
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount + 1)

		// Blur into empty space (not an editable field) before dispatching
		// the chord, matching the editable-target guard's contract.
		await page.locator('.page-designer__left').click({ position: { x: 2, y: 2 } })
		await page.keyboard.press('Control+z')
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount)

		await page.keyboard.press('Control+Shift+z')
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount + 1)

		await page.keyboard.press('Control+z')
		await page.keyboard.press('Control+y')
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount + 1)
	})

	test('REQ-BUR-003: Ctrl+Z inside a text field leaves draft-level history untouched', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/pages`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		const initialCount = await page.locator('.page-list-editor__row').count()

		// A draft-level edit — the state we must NOT lose.
		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('index')
		await page.getByRole('button', { name: /^confirm$/i }).click()
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount + 1)

		// Focus a text field belonging to the new row and type + Ctrl+Z.
		const idField = page.locator('.page-list-editor__row').last().locator('.page-list-editor__field').first()
		await idField.click()
		await idField.type('typo')
		await page.keyboard.press('Control+z')

		// The draft-level edit (the added row) must still be present — only
		// the native text-field undo (if any) should have fired.
		await expect(page.locator('.page-list-editor__row')).toHaveCount(initialCount + 1)
	})

	test('REQ-BUR-004: history survives a sub-editor (page) switch', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/pages`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		const rows = page.locator('.page-list-editor__row')
		const initialCount = await rows.count()

		// Edit A: add a page (mounts its sub-editor via SUB_EDITOR_MAP).
		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('detail')
		await page.getByRole('button', { name: /^confirm$/i }).click()
		await expect(rows).toHaveCount(initialCount + 1)

		// Select a different page — pure navigation, must not touch history.
		if (initialCount > 0) {
			await rows.first().click()
		}
		await expect(pageDesignerButton(page, 'Undo')).toBeEnabled()

		await pageDesignerButton(page, 'Undo').click()
		await expect(rows).toHaveCount(initialCount)
	})

	test('REQ-BUR-004: a successful save resets the session history', async ({ page }) => {
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/pages`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('index')
		await page.getByRole('button', { name: /^confirm$/i }).click()
		await expect(pageDesignerButton(page, 'Undo')).toBeEnabled()

		await page.getByRole('button', { name: /save pages/i }).click()
		await expect(page.locator('.page-designer-host__toast')).toBeVisible({ timeout: 10_000 })

		await expect(pageDesignerButton(page, 'Undo')).toBeDisabled()
		await expect(pageDesignerButton(page, 'Redo')).toBeDisabled()

		const countAfterSave = await page.locator('.page-list-editor__row').count()
		await page.keyboard.press('Control+z')
		await expect(page.locator('.page-list-editor__row')).toHaveCount(countAfterSave)
	})

	test('REQ-BUR-004: a version switch resets the session history', async ({ page }) => {
		// THIS TEST USED TO SKIP ITSELF, AND THE REASON WAS FALSE.
		//
		// It probed `GET .../versions/staging`, found nothing, and skipped with
		// "ApplicationVersion 'staging' not seeded — seed one to exercise this
		// scenario". Nothing in CI was ever going to seed it, so the skip was
		// permanent: a guard on a precondition the suite could satisfy for
		// itself, phrased as a fact about the environment.
		//
		// `tests/e2e/support/versionChain.ts::ensureVersionChain()` provisions
		// development -> staging -> production on demand and is proven working
		// in this same job — versionRouting.spec.ts drives `?_version=staging`
		// through it and passes (9.1 / 9.2 / 9.3, run 31083894467). So the
		// scenario was drivable all along; it simply never asked.
		//
		// A DEDICATED SLUG, not `pw-undo-redo`. The other tests in this describe
		// open `/pages` with no `?_version=`, so their default-version
		// resolution depends on how many versions the app has. Growing a chain
		// on the shared slug would leave them running against a different app
		// shape on every run AFTER the first — a fixture that changes what its
		// neighbours test is worse than the skip it replaces.
		const CHAIN_SLUG = 'pw-undo-redo-chain'
		await ensureVersionChain(page, CHAIN_SLUG, 'PW Undo Redo Chain')

		await page.goto(`${BASE_URL}/apps/openbuild/builder/${CHAIN_SLUG}/pages`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		await page.locator('.page-list-editor__add').click()
		await page.locator('.page-list-editor__select').selectOption('index')
		await page.getByRole('button', { name: /^confirm$/i }).click()
		await expect(pageDesignerButton(page, 'Undo')).toBeEnabled()

		await page.goto(
			`${BASE_URL}/apps/openbuild/builder/${CHAIN_SLUG}/pages?_version=staging`,
			{ waitUntil: 'domcontentloaded' },
		)
		await expect(page.locator('.page-designer__left')).toBeVisible({ timeout: 15_000 })

		await expect(pageDesignerButton(page, 'Undo')).toBeDisabled()
		await expect(pageDesignerButton(page, 'Redo')).toBeDisabled()
	})
})

// UN-QUARANTINED 2026-07-30 — same two harness fixes as the block above; the
// Schemas page itself was repaired in openbuild#30/#33/#34/#41.
test.describe('builder-undo-redo — schema designer (REQ-BUR-005)', () => {
	test.beforeEach(async ({ page }) => {
		await suppressSupportDialog(page)
		await ensureApp(page)
	})

	test('REQ-BUR-005: undo a field add, undo a discard, and a save resets the schema session history', async ({ page }) => {
		// `?_version=production` is REQUIRED, not decoration. Without it the
		// designer falls back to the legacy `openbuild-{slug}` register, which a
		// wizard-created app does not have — the schema is then created but the
		// attach fails ("Schema created, but could not be attached to register
		// openbuild-pw-undo-redo", with a Nextcloud login page as the response
		// body), the detail never renders its field editor, and the Add-field
		// click waits out the whole timeout. The real in-app nav carries the same
		// marker via buildVersionedRoute(); see schema-designer.spec.ts.
		await page.goto(`${BASE_URL}/apps/openbuild/builder/${APP_SLUG}/schemas?_version=production`, { waitUntil: 'domcontentloaded' })
		await expect(page.locator('.openbuild-schema-list')).toBeVisible({ timeout: 20_000 })
		await dismissOverlays(page)

		// `.first()` matters: the designer namespaces a created schema to
		// `{app}-{slug}`, which still CONTAINS the bare slug, so every previous
		// run's schema matches this filter too. Without it the click resolves to
		// several rows and dies on strict mode (or lands on a stale duplicate).
		const existingRow = page.locator('.openbuild-schema-list__row').filter({ hasText: SCHEMA_SLUG }).first()
		if ((await page.locator('.openbuild-schema-list__row').filter({ hasText: SCHEMA_SLUG }).count()) === 0) {
			await page.getByRole('button', { name: /add schema/i }).first().click()
			await page.getByLabel(/slug/i).fill(SCHEMA_SLUG)
			await page.getByLabel(/title/i).fill('Undo Redo Record')
			await page.getByRole('button', { name: /add schema|save/i }).last().click()
		} else {
			await existingRow.click()
		}
		await expect(page.getByRole('button', { name: /back to schemas/i })).toBeVisible({ timeout: 10_000 })

		const undoBtn = page.getByRole('button', { name: /^undo/i })
		const redoBtn = page.getByRole('button', { name: /^redo/i })
		await expect(undoBtn).toBeDisabled()
		await expect(redoBtn).toBeDisabled()

		// Add a field → undo → field gone.
		const fieldRows = page.locator('.openbuild-field-editor__row')

		// Let the row list SETTLE before baselining it. This test saves the field
		// it adds, so the fixture schema grows by one property per run and the
		// editor paints its rows progressively; reading `.count()` the instant the
		// detail mounts caught a partially-rendered list and every later
		// `toHaveCount(initialFieldCount ± 1)` was then off by one. Poll until two
		// consecutive reads agree.
		let initialFieldCount = -1
		await expect.poll(async () => {
			const seen = await fieldRows.count()
			const stable = seen === initialFieldCount
			initialFieldCount = seen
			return stable
		}, { timeout: 20_000, message: 'the field-editor row list must settle before baselining' }).toBe(true)
		await page.getByRole('button', { name: /add field/i }).click()
		await expect(fieldRows).toHaveCount(initialFieldCount + 1)
		await expect(undoBtn).toBeEnabled()

		await undoBtn.click()
		await expect(fieldRows).toHaveCount(initialFieldCount)

		// Re-add, then Discard staged edits → undo → the discarded field
		// edit comes back.
		await page.getByRole('button', { name: /add field/i }).click()
		await expect(fieldRows).toHaveCount(initialFieldCount + 1)

		const discardBtn = page.getByRole('button', { name: /discard staged edits/i })
		await expect(discardBtn).toBeEnabled()
		await discardBtn.click()
		await expect(fieldRows).toHaveCount(initialFieldCount)

		await expect(undoBtn).toBeEnabled()
		await undoBtn.click()
		await expect(fieldRows).toHaveCount(initialFieldCount + 1)

		// Name the restored field before saving. Save is gated on
		// `fieldNamesUnique`, which rejects ANY unnamed field — an unnamed
		// property must not reach the schema. The original drove Save straight
		// after the undo, so the button was (correctly) disabled and the click
		// waited out the timeout: test debt, not a product defect.
		//
		// The name must also be unique PER RUN. This test saves, so the field it
		// adds persists into the next run; re-using a fixed name made the second
		// run stage a duplicate, which the same gate (correctly) refuses — the
		// suite passed in isolation and failed on re-run.
		await page.getByLabel('Name', { exact: false }).last().fill(`undo_redo_${Date.now().toString(36)}`)

		// Save → both buttons disabled (new baseline).
		//
		// REQ-BUR-005 re-baselines the history in `SchemaDesigner.save()`'s
		// SUCCESS path only, so the assertions below are only meaningful once
		// the write has actually landed 2xx. `networkidle` proved neither — it
		// never settles on Nextcloud (ADR-074 rule 4) and does not wait for the
		// save XHR, so a 404'd save left the buttons disabled for the wrong
		// reason (nothing was ever staged back) and this test still passed.
		await saveSchemaAndAwait(page)
		await expect(undoBtn).toBeDisabled()
		await expect(redoBtn).toBeDisabled()
	})
})
