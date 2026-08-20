/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright e2e — the GitHub shop's actual write path
 * (`POST /apps/openbuild/api/shop/github/install`), the one thing
 * tests/e2e/github-store.spec.ts explicitly does NOT cover by design (that
 * file is deliberately read-only against the shared instance; this file is
 * the "belongs on an instance we own" counterpart it points to).
 *
 * Runs against PLAYWRIGHT_BASE_URL — the disposable instance, per
 * tests/e2e/support/baseUrl.ts's own rationale: a write path must never touch
 * the shared instance's data. Gated on the same capability-probe pattern as
 * github-store.spec.ts: with no GitHub credential granted to openbuild on the
 * disposable instance, these skip with the reason rather than failing or
 * fabricating a false pass.
 *
 * WHY THIS MATTERS: this exact path (fresh install of a real GitHub app-repo
 * — register + connectors/automations/flows + skills + agents channels) was
 * previously verified only by hand, once, in a throwaway session — never by
 * an automated, repeatable check. This file makes that verification durable.
 */

import { expect, request as playwrightRequest, test } from '@playwright/test'

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8099'
const ADMIN_USER = process.env.NC_ADMIN_USER ?? 'admin'
const ADMIN_PASS =
	process.env.NC_ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin'

/**
 * The repo to install from. Defaults to a real, known-good app-repo-format-v2
 * repo (buildiq-spectr — small, stable, and does not carry the flows/agents
 * channels' known FlowAndAgentExportBundler register-mismatch gap, so a clean
 * run here is a genuine green signal rather than one masked by that separate,
 * already-tracked limitation). Overridable so this can also be pointed at
 * buildiq-hydra to exercise the flows channel specifically.
 */
const INSTALL_OWNER = process.env.OPENBUILD_INSTALL_TEST_OWNER ?? 'ConductionNL'
const INSTALL_REPO = process.env.OPENBUILD_INSTALL_TEST_REPO ?? 'buildiq-spectr'

/**
 * A read-write API context against the disposable instance, Basic-auth per
 * github-store.spec.ts's own finding: httpCredentials is not honoured the
 * way an explicit Authorization header is on this stack.
 *
 * @return {Promise<import('@playwright/test').APIRequestContext>} the context.
 */
async function installApi() {
	const basic =
		'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64')
	return playwrightRequest.newContext({
		baseURL: BASE_URL,
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Authorization: basic },
	})
}

/**
 * The granted `github` credential's id, when one is allowed for openbuild on
 * the disposable instance under test — or null when none is. Same probe shape
 * as github-store.spec.ts's `githubCredentialGranted()`, but resolving the id
 * rather than a boolean: `ShopController::githubInstall()` only routes the
 * repo-file-map fetch through the credential broker when the REQUEST itself
 * carries a `credentialId` (see `credentialParam()` and
 * `GitHubCatalogService::get()` — with no id, it falls straight to an
 * anonymous request). Granting a credential is necessary but not sufficient;
 * the install call below has to forward this id, or a private source repo
 * 404s exactly like a genuinely unreachable one.
 *
 * @return {Promise<string|null>} the credential id, or null when absent.
 */
async function resolveGithubCredentialId(): Promise<string | null> {
	const api = await installApi()
	try {
		const resp = await api.get('/index.php/apps/openregister/api/credentials')
		if (!resp.ok()) {
			return null
		}
		const body = await resp.json()
		const rows = Array.isArray(body) ? body : (body?.results ?? [])
		const match = rows.find((row: Record<string, unknown>) => {
			const provider = String(row?.provider ?? row?.type ?? '')
			const apps = (row?.allowedApps ?? row?.apps ?? []) as string[]
			return (
				provider === 'github'
				&& Array.isArray(apps)
				&& apps.includes('openbuild')
			)
		})
		return (match?.id as string | undefined) ?? null
	} catch {
		return null
	} finally {
		await api.dispose()
	}
}

/**
 * Whether a GitHub credential is granted to openbuild on the disposable
 * instance under test. Mirrors github-store.spec.ts's probe exactly — same
 * shape, different instance.
 *
 * @return {Promise<boolean>} true when the write path is exercisable.
 */
async function githubCredentialGranted(): Promise<boolean> {
	return (await resolveGithubCredentialId()) !== null
}

/**
 * A kebab-case slug unique to this run, so repeat runs never collide with a
 * previous run's leftover application on a long-lived disposable instance.
 *
 * @return {string} a fresh slug.
 */
function freshSlug(): string {
	return `e2e-github-install-${Date.now()}-${Math.floor(Math.random() * 10000)}`
}

test.describe('OpenBuild GitHub shop — fresh install (write path)', () => {
	// @e2e app-channel-application::installing-from-the-shop-applies-its-channels
	test('installing a real app-repo-format-v2 repo creates a working application', async () => {
		// The config's 30s default is measured against the suite's UI specs, not
		// a real, broker-upgraded GitHub install: buildiq-spectr alone declares 65
		// connectors, and GitHubCatalogService fetches each file over its own
		// CredentialBrokerService round-trip. Measured directly against this
		// instance (bypassing Playwright's timeout): ~60s end to end for a
		// genuinely successful 201. 120s leaves headroom without masking a real
		// hang — a install that is actually broken still fails, just later.
		test.setTimeout(120_000)

		const credentialId = await resolveGithubCredentialId()
		test.skip(
			credentialId === null,
			`no GitHub credential granted to openbuild on ${BASE_URL} — grant one before running this spec; see tests/e2e/github-store.spec.ts for the same probe`,
		)

		const api = await installApi()
		const slug = freshSlug()
		try {
			const resp = await api.post(
				'/index.php/apps/openbuild/api/shop/github/install',
				{
					form: {
						owner: INSTALL_OWNER,
						repo: INSTALL_REPO,
						name: `E2E GitHub Install ${slug}`,
						slug,
						// Forwarded so GitHubCatalogService routes the repo-file-map
						// fetch through the credential broker (ShopController's own
						// credentialParam() contract) instead of falling back to an
						// anonymous request — load-bearing for a private source repo
						// like buildiq-spectr, which an anonymous GET 404s on exactly
						// as if it did not exist at all.
						credentialId: credentialId as string,
					},
				},
			)

			expect(
				resp.ok(),
				`install must succeed against a real repo (${INSTALL_OWNER}/${INSTALL_REPO}) — got ${resp.status()}: ${await resp.text()}`,
			).toBeTruthy()

			const payload = await resp.json()

			// installFromTemplateArray()'s success shape:
			// {uuid, slug, register, companionSchemas, channels, warnings} —
			// asserted against the actual controller source, not guessed.
			expect(
				payload?.slug,
				'the response must name the created application',
			).toBeTruthy()
			expect(
				payload?.uuid,
				"the response must carry the new application's uuid",
			).toBeTruthy()

			// `channels` is `AppChannelApplier::apply()`'s return value, which is
			// `ChannelApplyReport::toArray()` verbatim — itself already shaped as
			// `{channels: {<name>: {...}}, needsCredentials, warnings}`. So the
			// per-channel reports live at `payload.channels.channels.<name>`, one
			// level deeper than a plain `{channels: {<name>: ...}}` reading of the
			// controller's own docblock suggests. Verified against a live 201
			// response, not guessed.
			//
			// data-registers is the one channel every app-repo-format-v2 repo
			// carries — a real install must create at least the schema register.
			const registerReport = payload?.channels?.channels?.dataRegisters
			expect(
				registerReport,
				'the channel-apply report must include a dataRegisters entry',
			).toBeTruthy()
			if (registerReport !== undefined) {
				const created =
					registerReport.created ?? registerReport.declared ?? 0
				expect(
					created,
					'at least one data register must be declared/created',
				).toBeGreaterThan(0)
			}

			// Confirm the application is real and queryable — not just an
			// optimistic 200 with nothing actually persisted.
			const verify = await api.get(
				'/index.php/apps/openregister/api/objects/openbuild/application',
				{ params: { _search: slug } },
			)
			expect(
				verify.ok(),
				'the created application must be queryable',
			).toBeTruthy()
			const verifyBody = await verify.json()
			const rows = Array.isArray(verifyBody)
				? verifyBody
				: (verifyBody?.results ?? [])
			expect(
				rows.some((r: Record<string, unknown>) => r?.slug === slug),
				'the installed application must actually exist in OpenRegister',
			).toBe(true)
		} finally {
			await api.dispose()
		}
	})

	test('installing an unreachable repo fails closed with a specific reason, not a silent empty success', async () => {
		test.skip(
			!(await githubCredentialGranted()),
			`no GitHub credential granted to openbuild on ${BASE_URL}`,
		)

		const api = await installApi()
		try {
			const resp = await api.post(
				'/index.php/apps/openbuild/api/shop/github/install',
				{
					form: {
						owner: 'ConductionNL',
						repo: `nonexistent-repo-${Date.now()}`,
						name: 'E2E Should Not Exist',
						slug: freshSlug(),
					},
				},
			)

			// A missing repo must not be indistinguishable from success — this is
			// exactly the failure mode the credential-broker error-swallowing fix
			// (openregister, credential-broker-upstream-diagnostics) exists to
			// prevent one layer down; this asserts the contract holds at the API
			// boundary too.
			expect(
				resp.ok(),
				'an unreachable repo must not report success',
			).toBeFalsy()
			const payload = await resp.json()
			// ShopController::error()'s actual shape: {error: string, detail?: string}.
			expect(
				payload?.error,
				'the failure must carry a specific machine-readable reason',
			).toBeTruthy()
		} finally {
			await api.dispose()
		}
	})
})
