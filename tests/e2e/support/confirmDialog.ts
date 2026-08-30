/**
 * Shared e2e helper: answer a `ConfirmActionDialog` ask.
 *
 * WHY THIS EXISTS — a test that was written against a browser primitive the
 * product no longer uses.
 *
 * Six destructive actions used to be guarded by `window.confirm()`. Playwright
 * auto-DISMISSES a native dialog unless a handler accepts it, so the specs
 * driving those actions carried
 *
 *     page.once('dialog', (dialog) => dialog.accept())
 *
 * immediately before the click. #163 replaced all six with
 * `src/dialogs/ConfirmActionDialog.vue` (gate-34 window-confirm: 7 → 0) — a real
 * in-page NcDialog. It did not update these two specs.
 *
 * The resulting failure is quiet in the worst way. `page.once('dialog', …)`
 * against a page that never opens a native dialog throws NOTHING; the handler
 * simply never fires. The click then opens the in-page dialog, the spec never
 * answers it, the destructive continuation never runs, and the assertion that
 * the row is gone fails — accusing the DELETE endpoint, which was never called.
 * Both failures on run 31386150604 read exactly that way ("Expected: 0,
 * Received: 1" on a list that was never asked to change).
 *
 * So the helper drives the dialog the product actually renders, and it ASSERTS
 * the dialog appeared before answering it. That assertion is the point: if a
 * future change removes the confirmation step, the click would silently succeed
 * and a helper that merely "clicks confirm if present" would keep passing over
 * a vanished safety prompt. Here it goes red.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

import { expect, type Locator, type Page } from '@playwright/test'

/**
 * Wait for a `ConfirmActionDialog` and press its confirming button.
 *
 * The dialog is located by its ACCESSIBLE NAME (NcDialog's `name` prop, wired
 * through `aria-labelledby`) rather than by a CSS class, so this also pins that
 * the ask is announced to assistive technology — an unnamed dialog would fail
 * to match here rather than pass unnoticed.
 *
 * @param page         Playwright page.
 * @param title        The dialog's `name` prop, i.e. its accessible name.
 * @param confirmLabel The confirming button's label (`confirm-label` prop).
 * @return {Promise<void>}
 */
export async function confirmAction(
	page: Page,
	title: string | RegExp,
	confirmLabel: string | RegExp,
): Promise<void> {
	const dialog: Locator = page.getByRole('dialog', { name: title })
	// The ask itself is part of the contract — see the header. A missing dialog
	// must fail here, not be tolerated as "nothing to confirm".
	await expect(dialog).toBeVisible({ timeout: 10_000 })
	await dialog.getByRole('button', { name: confirmLabel }).click()
}
