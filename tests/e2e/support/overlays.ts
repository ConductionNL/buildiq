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
 * declares `walkthrough.completionConfigKey` and Buildiq serves
 * `GET|PUT /api/preferences/{key}` (GET returns `{"value":null}`, i.e. never
 * written), but dismissing the tour fires no request at all — verified live by
 * capturing the network while clicking "Close tour". CnWalkthrough is rendered
 * by nc-vue's CnAppRoot from the manifest, not by Buildiq, so the fix belongs
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
	await page
		.locator('.cn-walkthrough__dim')
		.waitFor({ state: 'detached', timeout: 5_000 })
		.catch(() => {})
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
	await closeBtn
		.waitFor({ state: 'visible', timeout: 4_000 })
		.then(() => closeBtn.click())
		.catch(() => {})
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
