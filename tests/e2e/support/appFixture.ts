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
 * @param page Playwright page (authenticated via the shared admin storageState).
 * @param slug The app slug (lower-kebab).
 * @param name The app display name.
 * @return {Promise<void>}
 */
export async function ensureApp(page: Page, slug: string, name: string): Promise<void> {
	await page.goto('/apps/openbuild/', { waitUntil: 'domcontentloaded' })
	await page.waitForTimeout(500)
	const result = await page.evaluate(async ({ slug, name }) => {
		const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
			|| document.querySelector('head')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: tok, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }
		// Idempotency, via the applications LIST.
		//
		// Do NOT probe `/applications/{slug}/manifest` for this: it 404s for an
		// app that exists but has no resolvable manifest yet, so the check said
		// "absent" for an app that was right there and every run POSTed the
		// wizard again. That left duplicate Application objects (4 after three
		// runs), and re-creating an app whose register already exists takes the
		// wizard ~180s before failing 500 — which then blows the test timeout
		// rather than reporting anything useful.
		const listResp = await fetch('/index.php/apps/openbuild/api/applications', { headers })
		if (listResp.ok) {
			const listed = await listResp.json().catch(() => null)
			const rows = Array.isArray(listed) ? listed : (listed?.results ?? listed?.applications ?? [])
			const found = Array.isArray(rows) && rows.some((a) => {
				const s = a?.slug ?? a?.['@self']?.slug
				return s === slug
			})
			if (found) {
				return 'exists'
			}
		}
		const resp = await fetch('/index.php/apps/openbuild/api/applications/wizard', {
			method: 'POST',
			headers,
			body: JSON.stringify({
				slug,
				name,
				description: 'e2e fixture app',
				versions: [{ slug: 'production', name: 'production' }],
			}),
		})
		return resp.status === 201 ? 'created' : `error:${resp.status}:${(await resp.text()).slice(0, 200)}`
	}, { slug, name })
	if (result !== 'exists' && result !== 'created') {
		throw new Error(`ensureApp(${slug}) failed — ${result}`)
	}
}

/**
 * Dismiss any modal that is overlaying the page — in practice the manifest's
 * onboarding tour (`manifest.tours`), which mounts a `.modal-mask` a beat after
 * the page settles. It is not part of what these specs exercise, but its
 * wrapper swallows pointer events, so a click on the page underneath retries
 * until the test times out ("<div class=modal-wrapper> … subtree intercepts
 * pointer events"). Safe to call unconditionally: a no-op when nothing is open.
 *
 * @param page Playwright page.
 * @return {Promise<void>}
 */
export async function dismissOverlays(page: Page): Promise<void> {
	for (let i = 0; i < 3; i++) {
		const mask = page.locator('.modal-mask').first()
		if (await mask.count() === 0 || await mask.isVisible().catch(() => false) === false) {
			return
		}
		const closer = page.getByRole('button', { name: /close tour|close|dismiss/i }).first()
		if (await closer.count() > 0) {
			await closer.click({ timeout: 5_000 }).catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await page.waitForTimeout(700)
	}
}
