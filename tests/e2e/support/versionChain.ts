/**
 * Shared e2e fixture: an application with a real multi-version chain.
 *
 * Several suites need more than one ApplicationVersion to assert anything at
 * all — the version pill strip, `?_version=` routing, the Promote affordance
 * (which by definition cannot appear on the terminal production pill) and
 * publish/rollback history. The seeded `hello-world` app has exactly one
 * version, `production`, so those scenarios either skipped themselves or, worse,
 * asserted nothing. This provisions a dedicated fixture app carrying
 *
 *     development --promotesTo--> staging --promotesTo--> production
 *
 * so the pills render in chain order and production is genuinely terminal.
 *
 * Never point this at `hello-world`: promotion is a data-affecting operation and
 * other suites assert against that app's shape.
 *
 * Two contract details, both learned by driving the API rather than reading it:
 *   - `POST /api/applications/{slug}/versions` REQUIRES a `manifest`; omitting it
 *     fails 422 "The required property (manifest) is missing".
 *   - `register` may be omitted: the controller inherits production's register
 *     (manifest-only versioning, REQ-OBV-107), which is what we want — the chain
 *     shares data rather than minting a register per version.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

import type { Page } from '@playwright/test'

import { ensureApp } from './appFixture.ts'

/** The upstream-to-downstream chain this fixture guarantees. */
export const CHAIN = ['development', 'staging', 'production'] as const

/** A minimal manifest that satisfies the version schema. */
const EMPTY_MANIFEST = { version: '1.0.0', menu: [], pages: [] }

/**
 * Ensure `slug` exists AND carries a development -> staging -> production chain.
 * Idempotent: existing versions are left untouched.
 *
 * @param page Playwright page (authenticated via the shared admin storageState).
 * @param slug The app slug (lower-kebab).
 * @param name The app display name.
 * @return {Promise<void>}
 */
export async function ensureVersionChain(
	page: Page,
	slug: string,
	name: string,
): Promise<void> {
	// The wizard creates the app with its `production` version.
	await ensureApp(page, slug, name)

	const result = await page.evaluate(
		async ({ slug, manifest }) => {
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
			const versionsUrl = `/index.php/apps/buildiq/api/applications/${slug}/versions`

			const list = async () => {
				const resp = await fetch(versionsUrl, { headers })
				const data = await resp.json().catch(() => null)
				return Array.isArray(data) ? data : (data?.results ?? [])
			}

			// Build downstream-first so each new version can point at the one it
			// promotes into: staging -> production, then development -> staging.
			for (const [versionSlug, semver, promotesToSlug] of [
				['staging', '0.2.0', 'production'],
				['development', '0.3.0', 'staging'],
			]) {
				const current = await list()
				if (current.some((v) => v?.slug === versionSlug)) {
					continue
				}
				const upstream = current.find((v) => v?.slug === promotesToSlug)
				const resp = await fetch(versionsUrl, {
					method: 'POST',
					headers,
					body: JSON.stringify({
						slug: versionSlug,
						name: versionSlug,
						semver,
						status: 'draft',
						manifest,
						...(upstream
							? { promotesTo: upstream.id ?? upstream.uuid }
							: {}),
					}),
				})
				if (!resp.ok) {
					return `error creating ${versionSlug}: ${resp.status} ${(await resp.text()).slice(0, 200)}`
				}
			}

			// READ THE CHAIN BACK BEFORE CLAIMING IT EXISTS.
			//
			// Every POST above can answer 2xx and the list still come back short:
			// the create and the subsequent read are separate requests, and a
			// read-after-write lag returns a chain that is not there yet. Polling
			// here costs at most a second and turns an intermittent failure in a
			// spec into a deterministic one in the seed.
			let slugs: string[] = []
			for (let attempt = 0; attempt < 10; attempt++) {
				slugs = (await list()).map((v) => String(v?.slug))
				if (
					['development', 'staging', 'production'].every((s) =>
						slugs.includes(s),
					)
				) {
					break
				}
				await new Promise((r) => setTimeout(r, 300))
			}
			return slugs.join(',')
		},
		{ slug, manifest: EMPTY_MANIFEST },
	)

	if (typeof result === 'string' && result.startsWith('error')) {
		throw new Error(`ensureVersionChain(${slug}) failed — ${result}`)
	}

	// 🔴 A CREATE THAT RETURNED 2xx IS NOT A CHAIN THAT EXISTS.
	//
	// This used to check only that no POST reported an error, and returned the
	// slug list without ever looking at it. So a run where the creates all
	// succeeded but the chain read back short passed the seed and failed later
	// in the spec, on
	//
	//   Error: the seeded development -> staging -> production chain must be
	//   listed        Expected: 3   Received: <fewer>
	//
	// which reads as a broken version-history panel rather than as a seed that
	// did not land. Measured on development 2026-08-31: buildiq E2E
	// 1 failed / 200 passed, and the SAME commit re-run passed 201 — the
	// signature of a race, not a regression.
	//
	// Asserting the state the spec depends on makes the failure name its own
	// cause, and keeps this helper honest if the versions API changes shape.
	const found = typeof result === 'string' ? result.split(',').filter(Boolean) : []
	const missing = ['development', 'staging', 'production'].filter(
		(s) => found.includes(s) === false,
	)
	if (missing.length > 0) {
		throw new Error(
			`ensureVersionChain(${slug}) did not produce the full chain — `
				+ `missing [${missing.join(', ')}], found [${found.join(', ') || 'nothing'}]. `
				+ `The version-history specs assert three rows, so they would fail on the `
				+ `panel rather than on this seed.`,
		)
	}
}

/**
 * The app's versions, newest API shape normalised to a plain array.
 *
 * @param page Playwright page.
 * @param slug The app slug.
 * @return {Promise<Array<Record<string, unknown>>>} The ApplicationVersion rows.
 */
export async function listVersions(
	page: Page,
	slug: string,
): Promise<Array<Record<string, unknown>>> {
	return page.evaluate(async (slug) => {
		const resp = await fetch(
			`/index.php/apps/buildiq/api/applications/${slug}/versions`,
			{
				headers: { 'OCS-APIRequest': 'true' },
			},
		)
		const data = await resp.json().catch(() => null)
		return Array.isArray(data) ? data : (data?.results ?? [])
	}, slug)
}
