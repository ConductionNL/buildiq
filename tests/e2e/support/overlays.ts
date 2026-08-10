// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Shared e2e helpers for dismissing nc-vue's first-visit overlays.
 *
 * Both overlays below render a full-viewport backdrop that intercepts pointer
 * events, so any spec that CLICKS something after navigating has to clear them
 * first. They are preconditions, not assertions: no spec here is testing the
 * tour or the support dialog, and leaving them up makes an unrelated click
 * retry until the test budget is gone.
 *
 * These were duplicated verbatim in copilot-panel.spec.ts and
 * copilot-wizard-generate.spec.ts; they live here so the next spec that needs
 * them imports rather than re-copies.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

import type { Page } from '@playwright/test'

/**
 * Dismiss the first-visit `CnWalkthrough` tour if it is open.
 *
 * The tour renders `div.cn-walkthrough__dim--full`, which covers the viewport
 * and intercepts pointer events, and its step-tracking fetch can keep the
 * network non-idle so `waitForLoadState('networkidle')` never resolves.
 *
 * KNOWN UPSTREAM DEFECT: closing the tour persists nothing. The manifest
 * declares `walkthrough.completionConfigKey` and OpenBuild serves
 * `GET|PUT /api/preferences/{key}` (GET returns `{"value":null}`, i.e. never
 * written), but dismissing the tour fires no request at all — verified live by
 * capturing the network while clicking "Close tour". CnWalkthrough is rendered
 * by nc-vue's CnAppRoot from the manifest, not by OpenBuild, so the fix belongs
 * in nc-vue. Until then the tour reopens on every visit for every user and each
 * spec must clear it itself.
 *
 * @param page Playwright page.
 * @return {Promise<void>}
 */
export async function dismissWalkthrough(page: Page): Promise<void> {
	const closeBtn = page.getByRole('button', { name: /close tour/i })
	if (await closeBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
		await closeBtn.click()
	}
	await page.locator('.cn-walkthrough__dim').waitFor({ state: 'detached', timeout: 5_000 }).catch(() => {})
}

/**
 * Dismiss nc-vue's first-visit "Support Openbuild" (CnSupportDialog) modal if
 * it is open. Its backdrop (`[data-testid-modal="cn-support-dialog"]`)
 * intercepts pointer events across the whole page.
 *
 * The dialog's own "have I been seen" check is an async round-trip, so it can
 * pop up a beat AFTER the caller already moved on — an instantaneous
 * isVisible() check races it and misses. waitFor() polls.
 *
 * @param page Playwright page.
 * @return {Promise<void>}
 */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const closeBtn = page.getByRole('button', { name: /^close$/i })
	await closeBtn.waitFor({ state: 'visible', timeout: 4_000 }).then(() => closeBtn.click()).catch(() => {})
}

/**
 * Clear every first-visit overlay that can swallow a click.
 *
 * @param page Playwright page.
 * @return {Promise<void>}
 */
export async function dismissFirstVisitOverlays(page: Page): Promise<void> {
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/**
 * Start collecting every response with an HTTP status >= 400, keyed by URL.
 *
 * WHY — an unattributable console line vs a named cause.
 *
 * A sibling repo spent a session on a suite that screened green locally and
 * went red in CI. The difference was a single 404 fired on EVERY page: a probe
 * for an optional ExApp that is installed on the dev container and absent on
 * the runner. Nothing named it. It surfaced only as unrelated selectors timing
 * out, because the failing probe left the page in a state the specs did not
 * expect, and the actual 404 was one line in a console log nobody attributes to
 * anything.
 *
 * This is deliberately a REPORTER, not a gate. It does not fail a test on its
 * own, and that restraint is the point: a suite this size legitimately provokes
 * some 4xx (this very file's docudesk spec ROUTES a 404 to simulate an
 * uninstalled app), so a collector that failed on any >= 400 would manufacture
 * red over correct behaviour — the advisory-vs-blocking mistake. What it does
 * is make the list available so a failing assertion can carry it, turning
 * "a selector timed out" into "a selector timed out, and these four requests
 * were refused while it waited".
 *
 * Call once, right after the page is created; read with the returned accessor.
 *
 * @param page Playwright page.
 *
 * @return {() => string[]} Accessor returning `METHOD status URL` lines, newest last.
 */
export function collectFailedResponses(page: Page): () => string[] {
	const failures: string[] = []
	page.on('response', (response) => {
		const status = response.status()
		if (status >= 400) {
			failures.push(`${response.request().method()} ${status} ${response.url()}`)
		}
	})
	return () => [...failures]
}

/**
 * Confirm a destructive action through `src/dialogs/ConfirmActionDialog.vue`.
 *
 * WHY THIS EXISTS — read before replacing it with `page.on('dialog', …)`.
 *
 * These flows USED to be guarded by native `window.confirm()`, and the specs
 * drove them with `page.once('dialog', (d) => d.accept())`. PR #163 replaced
 * all seven native `confirm`/`prompt` calls with real, themable, translatable
 * dialogs (gate-34: `window.confirm` blocks the JS thread, cannot be
 * translated or themed, and renders outside Nextcloud's modal stack).
 *
 * After that change there is NO native dialog for Playwright to accept, so
 * `page.once('dialog', …)` registers a handler for an event that never fires.
 * The handler is not an error and Playwright reports nothing: the click simply
 * opens the Vue dialog, the spec never confirms it, and the destructive action
 * never runs. That is precisely how `automations.spec.ts` and
 * `spec-coverage/docudesk-document-templates.spec.ts` failed — "Expected 0,
 * Received 1" on a row that was never actually deleted.
 *
 * Note the failure DIRECTION was luck, not design. Both of those specs happened
 * to assert that the item DISAPPEARS, so a no-op click went red. A spec that
 * asserted "the list is unchanged" after a cancelled confirm, or that merely
 * checked for a toast, would have gone GREEN over a click that did nothing at
 * all — the same staleness, silently.
 *
 * The dialog is addressed by its ACCESSIBLE NAME and the confirming control by
 * ITS OWN LABEL — never by "the first button in the modal". The row that
 * triggered the flow usually carries a button with the very same label (a
 * "Delete" row action opening a dialog whose confirm is also "Delete"), so an
 * unscoped `getByRole('button', { name: /delete/i })` is a strict-mode
 * violation at best and clicks the wrong control at worst.
 *
 * @param page         Playwright page.
 * @param dialogName   The dialog's accessible name, e.g. 'Delete automation'.
 * @param confirmLabel The confirming button's label, e.g. 'Delete'.
 *
 * @return {Promise<void>}
 */
export async function confirmAction(page: Page, dialogName: string | RegExp, confirmLabel: string | RegExp): Promise<void> {
	const dialog = page.getByRole('dialog', { name: dialogName })
	await dialog.waitFor({ state: 'visible', timeout: 10_000 })
	await dialog.getByRole('button', { name: confirmLabel, exact: typeof confirmLabel === 'string' }).click()
	// The dialog closes only once the parent's handler has run; waiting for it
	// to detach keeps the caller's next assertion from racing the mutation.
	await dialog.waitFor({ state: 'detached', timeout: 20_000 })
}
