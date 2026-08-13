/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — the GitHub template store (github-shop-catalogue /
 * template-catalogue-ui), driven WITH a real GitHub credential.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ⚠️  THIS FILE IS THE ONE DELIBERATE EXCEPTION TO THE INSTANCE-ISOLATION RULE.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Every other spec runs against the disposable container at PLAYWRIGHT_BASE_URL
 * (`ob-vue3-e2e`, :8099) — see tests/e2e/support/baseUrl.ts for why that
 * matters. This one targets the instance that actually HOLDS a GitHub
 * credential (the shared dev box, :8080 by default): the disposable container
 * has no credentials at all (`/api/credentials` → `{"results":[]}`), so the
 * authenticated store path cannot be exercised there.
 *
 * STRICTLY READ-ONLY on that instance, and shaped so it cannot become otherwise:
 * request-level GETs only. It creates no application, version, credential or
 * object, and never drives Install/Clone — that path writes, and belongs on an
 * instance we own.
 *
 * WHY REQUEST-LEVEL AND NOT UI: the shared instance does not run this branch's
 * build — nothing deploys to it from here, by standing rule — so its
 * `/apps/openbuild/templates` page is whatever build happens to be installed,
 * and asserting against that DOM would measure someone else's checkout rather
 * than this code. (Driving it proved the point: `.template-gallery` never
 * mounts there.) The credential's effect is visible in the API contract
 * regardless of frontend build, so that is what is asserted here. The UI-level
 * gallery behaviour — tabs, server-backed search, terminal states — is covered
 * against the disposable instance in tests/e2e/template-gallery.spec.ts.
 *
 * Gated on a capability probe (the same pattern as the Docudesk compose
 * scenario): with no GitHub credential granted to openbuild, these skip with the
 * reason rather than failing or quietly asserting something weaker.
 */

import { test, expect, request as playwrightRequest } from '@playwright/test'

/**
 * The instance that holds the GitHub credential. Overridable so CI can point at
 * whichever instance has one; deliberately NOT read from PLAYWRIGHT_BASE_URL —
 * this file's whole point is that it targets a different instance from the rest
 * of the suite, and silently following the disposable one would make it skip
 * forever without anyone noticing.
 */
const STORE_URL = process.env.OPENBUILD_GITHUB_STORE_URL ?? 'http://localhost:8080'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS =
	process.env.NC_ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/**
 * A read-only API context against the store instance, authenticated with Basic
 * auth rather than the login form: repeated form logins trip Nextcloud's
 * brute-force throttle (it starts bouncing to `/login?direct=1&user=…` and stays
 * tripped for a while — observed on this very instance while writing this), and
 * this file must not depend on globalSetup's storageState, which is minted for a
 * DIFFERENT instance.
 *
 * @return {Promise<import('@playwright/test').APIRequestContext>} the context.
 */
async function storeApi() {
	// An EXPLICIT Authorization header, not `httpCredentials`. Playwright only
	// sends httpCredentials in response to a challenge it recognises, and
	// Nextcloud's 401 here is not satisfied that way: measured side by side on
	// this instance, httpCredentials → 401 with an empty body while the explicit
	// header → 200 with the credential list. The httpCredentials form made the
	// capability probe below silently answer "no credential granted", so these
	// tests skipped on an instance that had one.
	const basic =
		'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	return playwrightRequest.newContext({
		baseURL: STORE_URL,
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Authorization: basic },
	})
}

/**
 * Whether a GitHub credential is granted to openbuild on the store instance.
 *
 * @return {Promise<boolean>} true when the authenticated path is exercisable.
 */
async function githubCredentialGranted(): Promise<boolean> {
	const api = await storeApi()
	try {
		const resp = await api.get('/index.php/apps/openregister/api/credentials')
		if (!resp.ok()) {
			return false
		}
		const body = await resp.json()
		const rows = Array.isArray(body) ? body : (body?.results ?? [])
		return rows.some((row: Record<string, unknown>) => {
			const provider = String(row?.provider ?? row?.type ?? '')
			const apps = (row?.allowedApps ?? row?.apps ?? []) as string[]
			return (
				provider === 'github'
				&& Array.isArray(apps)
				&& apps.includes('openbuild')
			)
		})
	} catch {
		return false
	} finally {
		await api.dispose()
	}
}

test.describe('OpenBuild GitHub template store — authenticated path (read-only)', () => {
	test('a granted GitHub credential is visible to openbuild', async () => {
		test.skip(
			!(await githubCredentialGranted()),
			`no GitHub credential granted to openbuild on ${STORE_URL} — grant one in User settings > Credentials`,
		)

		const api = await storeApi()
		try {
			const resp = await api.get(
				'/index.php/apps/openregister/api/credentials',
			)
			const body = await resp.json()
			const rows = Array.isArray(body) ? body : (body?.results ?? [])
			const github = rows.filter(
				(r: Record<string, unknown>) =>
					String(r?.provider ?? r?.type ?? '') === 'github',
			)

			expect(
				github.length,
				'at least one GitHub credential must exist',
			).toBeGreaterThan(0)
			// The grant is per-app: openbuild must be among the apps allowed to use it.
			expect(
				github.some((r: Record<string, unknown>) =>
					((r?.allowedApps ?? r?.apps ?? []) as string[]).includes(
						'openbuild',
					),
				),
				'openbuild must be allowed to use a GitHub credential',
			).toBe(true)
			// The secret itself must never come back over this API.
			expect(
				JSON.stringify(github),
				'a credential listing must not leak its secret',
			).not.toMatch(/gh[pousr]_[A-Za-z0-9]{16,}/)
		} finally {
			await api.dispose()
		}
	})

	test('the store search runs authenticated — not rate-limited, and returns cards', async () => {
		test.skip(
			!(await githubCredentialGranted()),
			`no GitHub credential granted to openbuild on ${STORE_URL} — grant one in User settings > Credentials`,
		)

		const api = await storeApi()
		try {
			const resp = await api.get(
				'/index.php/apps/openbuild/api/shop/github/search',
				{ params: { q: 'openbuild' } },
			)
			expect(resp.ok(), 'the store search endpoint must answer').toBeTruthy()
			const payload = await resp.json()

			// This is what the credential buys. An ANONYMOUS search rate-limits
			// quickly, and the gallery then renders its "GitHub is rate-limiting
			// anonymous browsing right now" note instead of results.
			expect(payload.outcome, 'an authenticated search must succeed').toBe(
				'ok',
			)
			expect(
				payload.rateLimited,
				'an authenticated search must not be rate-limited',
			).toBeFalsy()
			expect(
				payload.brokerCredentialAvailable,
				'the granted credential must be visible to the store',
			).toBe(true)

			// And it must actually return the catalogue, not an empty success.
			expect(
				Array.isArray(payload.cards),
				'the search must return a cards array',
			).toBe(true)
			expect(
				payload.cards.length,
				'the topic:openbuild-app catalogue must not be empty',
			).toBeGreaterThan(0)

			// Cards are the shape the gallery renders from.
			for (const card of payload.cards) {
				expect(
					card.repo ?? card.slug ?? card.name,
					'every card must be identifiable',
				).toBeTruthy()
			}
		} finally {
			await api.dispose()
		}
	})
})
