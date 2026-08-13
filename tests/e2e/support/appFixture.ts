/**
 * Shared e2e fixture helper: create an OpenBuild virtual app idempotently.
 *
 * Replaces the obsolete "Add application" button + slug-field UI flow that
 * several specs still drove in their `beforeEach`. App creation moved to a
 * multi-step wizard / template-clone dialog (openbuild-app-creation-wizard),
 * so the old flat form no longer exists — the `if (addAppButton.isVisible())`
 * guard those specs used silently skipped creation, leaving the app absent and
 * every downstream step failing on a non-existent app.
 *
 * This calls the atomic wizard endpoint (`POST /api/applications/wizard` —
 * Application + production version + register in one transaction) via an
 * in-page `fetch` so the request carries BOTH the session cookie and the CSRF
 * `requesttoken` the plain AppFramework route requires (a bare
 * `page.request.post` with only `OCS-APIRequest` is rejected by the CSRF
 * middleware on non-OCS routes).
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

import type { Page } from '@playwright/test'

/**
 * Ensure an OpenBuild virtual app exists (create it if absent). Idempotent.
 *
 * @param page     Playwright page (authenticated via the shared admin storageState).
 * @param slug     The app slug (lower-kebab).
 * @param name     The app display name.
 * @param versions Version slugs to provision, in order. Defaults to a single
 *                 `production` version, which is what every caller wanted until
 *                 the RBAC suites needed a DRAFT to author on: REQ-AUTD-008
 *                 distinguishes "editor may author on a non-production version"
 *                 from "editor may not enable on production", and a fixture with
 *                 only one version cannot express that difference at all. The
 *                 wizard treats the LAST entry as the production one.
 * @return {Promise<void>}
 */
export async function ensureApp(
	page: Page,
	slug: string,
	name: string,
	versions: string[] = ['production'],
): Promise<void> {
	await page.goto('/apps/openbuild/', { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(500)
	const result = await page.evaluate(
		async ({ slug, name, versions }) => {
			const tok =
				(window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken
				|| document.querySelector('head')?.getAttribute('data-requesttoken')
				|| ''
			const headers = {
				requesttoken: tok,
				'OCS-APIRequest': 'true',
				'Content-Type': 'application/json',
			}
			// Idempotency, via the applications LIST.
			//
			// Do NOT probe `/applications/{slug}/manifest` for this: it 404s for an
			// app that exists but has no resolvable manifest yet, so the check said
			// "absent" for an app that was right there and every run POSTed the
			// wizard again. That left duplicate Application objects (4 after three
			// runs), and re-creating an app whose register already exists takes the
			// wizard ~180s before failing 500 — which then blows the test timeout
			// rather than reporting anything useful.
			const listResp = await fetch(
				'/index.php/apps/openbuild/api/applications',
				{ headers },
			)
			if (listResp.ok) {
				const listed = await listResp.json().catch(() => null)
				const rows = Array.isArray(listed)
					? listed
					: (listed?.results ?? listed?.applications ?? [])
				const found =
					Array.isArray(rows)
					&& rows.some((a) => {
						const s = a?.slug ?? a?.['@self']?.slug
						return s === slug
					})
				if (found) {
					return 'exists'
				}
			}
			const resp = await fetch(
				'/index.php/apps/openbuild/api/applications/wizard',
				{
					method: 'POST',
					headers,
					body: JSON.stringify({
						slug,
						name,
						description: 'e2e fixture app',
						versions: versions.map((v) => ({ slug: v, name: v })),
					}),
				},
			)
			return resp.status === 201
				? 'created'
				: `error:${resp.status}:${(await resp.text()).slice(0, 200)}`
		},
		{ slug, name, versions },
	)
	if (result !== 'exists' && result !== 'created') {
		throw new Error(`ensureApp(${slug}) failed — ${result}`)
	}
}

/**
 * Dismiss any modal overlaying the page — the manifest's onboarding tour
 * (`manifest.tours`) and the first-open support dialog (`CnSupportDialog`), both
 * of which mount a `.modal-mask` a beat after the page settles. Neither is what
 * these specs exercise, but the mask swallows pointer events, so every click on
 * the page underneath retries until the test times out ("<div class=modal-mask>
 * … subtree intercepts pointer events"). Safe to call unconditionally.
 *
 * The closer MUST be looked up inside the mask: searching the whole page and
 * taking `.first()` matches Nextcloud's own "Close navigation" chrome button, so
 * the dialog stayed open while the sidebar toggled.
 *
 * @param page Playwright page.
 * @return {Promise<void>}
 */
export async function dismissOverlays(page: Page): Promise<void> {
	for (let i = 0; i < 4; i++) {
		const mask = page.locator('.modal-mask').first()
		if (
			(await mask.count()) === 0
			|| (await mask.isVisible().catch(() => false)) === false
		) {
			return
		}
		const closer = mask
			.getByRole('button', { name: /close|dismiss|skip|later|not now/i })
			.first()
		if ((await closer.count()) > 0) {
			await closer.click({ timeout: 5_000 }).catch(() => {})
		} else {
			// NcDialog's close control is an icon button; fall back to its own
			// header close, then to ESC.
			const iconClose = mask
				.locator('.modal-container__close, button.icon-close')
				.first()
			if ((await iconClose.count()) > 0) {
				await iconClose.click({ timeout: 5_000 }).catch(() => {})
			} else {
				await page.keyboard.press('Escape').catch(() => {})
			}
		}
		await page.waitForTimeout(700)
	}
}

/**
 * Stop the non-gating first-time-setup wizard from auto-opening.
 *
 * CnAppRoot offers the setup wizard whenever every REQUIRED step is met but at
 * least one OPTIONAL step is not (`optionalSetupGating`, REQ-SETUP-NV-012), and
 * it opens it as a full `modal-mask`. "Non-gating" describes the app phase, not
 * the DOM: the mask covers the shell, so anything behind it — notably the
 * sidebar toggle at z-index 1001 — is unclickable while it is up.
 *
 * OpenBuild trips this permanently. Its `store` step ("Remote template store")
 * is optional and is normally never completed, so `optionalUnmet` is non-empty
 * forever. The wizard is suppressed for real users by a per-browser flag
 * (`cn-setup-wizard-dismissed:{appId}:{setup.version}`) written on dismiss —
 * but every Playwright test gets a FRESH context, so that flag is never present
 * and the wizard opens in every single test.
 *
 * Pre-set the flag for ANY such key before the app boots, rather than racing the
 * dialog after the fact. Keyed on `setup.version`, so this keeps working when
 * that version is bumped.
 *
 * @param page Playwright page.
 * @return {Promise<void>}
 */
export async function suppressSetupWizard(page: Page): Promise<void> {
	await page.addInitScript(() => {
		const prefix = 'cn-setup-wizard-dismissed:'
		try {
			const original = Storage.prototype.getItem
			Storage.prototype.getItem = function getItem(key: string) {
				if (typeof key === 'string' && key.startsWith(prefix)) {
					return '1'
				}
				return original.call(this, key)
			}
		} catch {
			// Non-fatal: the wizard is then handled by dismissOverlays().
		}
	})
}

/**
 * Stop the first-open support dialog from ever mounting.
 *
 * `useSupportDialog` seeds its visibility from a `localStorage` flag keyed
 * `cn-support-dialog-shown:{appSlug}`, and the builder mounts under a
 * preview-scoped slug, so a single hard-coded key does not cover every route.
 * Pre-set the flag for ANY such key before the app boots — far more reliable
 * than racing a dialog that only appears once the page has settled.
 *
 * Call before the first navigation of a test; it applies to every later
 * navigation on the same page.
 *
 * @param page Playwright page.
 * @return {Promise<void>}
 */
export async function suppressSupportDialog(page: Page): Promise<void> {
	await page.addInitScript(() => {
		const prefix = 'cn-support-dialog-shown:'
		try {
			const original = Storage.prototype.getItem
			Storage.prototype.getItem = function getItem(key: string) {
				if (typeof key === 'string' && key.startsWith(prefix)) {
					return '1'
				}
				return original.call(this, key)
			}
		} catch {
			// Non-fatal: the dialog is then handled by dismissOverlays().
		}
	})
}
