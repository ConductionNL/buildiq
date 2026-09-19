/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from Buildiq's `lib/Settings/connections.json`, with `app` equal to
 * `buildiq`. Buildiq writes no row: a store settings save asks integriq to
 * resolve again, a store search reports what it met, and integriq decides the
 * status. So this spec needs integriq installed and synced, and reads the rows
 * from `/apps/openregister/api/objects/integriq/app_connection?app=buildiq`.
 *
 * `app` is a BARE filter key. The objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * WHAT A RED HERE USUALLY MEANS. An empty list in the first test means
 * integriq has not synced the declaration, or refused it whole. A store row
 * that stays on Error after a save means integriq does not yet retire older
 * observations on a refresh (hydra#674).
 *
 * Every write here is a Buildiq setting, snapshotted first and put back in a
 * `finally`. The CI seed sets `registry_url` to an `.invalid` host, which no
 * search can reach, so the restore leaves the instance as the seed left it.
 *
 * Written, not yet run: the CI instance needs integriq with hydra#674 first
 * (tasks.md 5.1).
 *
 * Locale: nothing forces the E2E language, so statuses are read from the API
 * and rows are found by their declared titles, which are not translated.
 *
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#the-page-lists-only-the-rows-of-buildiq
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#add-integration-goes-to-integriq
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#a-saved-registry-url-reads-configured
 * @e2e openspec/changes/adopt-connection-registry/specs/app-connections/spec.md#a-store-that-cannot-be-reached-reads-error
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'

/** Buildiq's app root, relative to the configured base URL. */
const APP = '/index.php/apps/buildiq'

/** Integriq's objects endpoint for Buildiq's connection rows. */
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection?app=buildiq&_limit=50'

/** Buildiq's settings endpoint, the one writer of the store keys besides the setup wizard. */
const SETTINGS_API = `${APP}/api/settings`

/** Buildiq's template store search. */
const STORE_SEARCH_API = `${APP}/api/store/templates`

/** Headers that pass Nextcloud's CSRF check on a request context. */
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/** The declared keys and titles, in declared order. */
const DECLARED = [
	{ key: 'store', title: 'Template store' },
	{ key: 'github', title: 'GitHub' },
	{ key: 'documents', title: 'Document generation' },
	{ key: 'rule-webhooks', title: 'Rule webhooks' },
]

/**
 * Buildiq's connection rows, keyed by connection key.
 *
 * @param request An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(
	request: APIRequestContext,
): Promise<Record<string, Record<string, unknown>>> {
	const res = await request.get(CONNECTIONS_API, {
		headers: { Accept: 'application/json' },
	})
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, Record<string, unknown>> = {}
	for (const row of (body.results ?? []) as Record<string, unknown>[]) {
		// A row from another app here means the bare filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe('buildiq')
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * The store row's status and message as one string, or '' when it cannot be read.
 *
 * Reads without asserting: a throw inside `expect.poll` ends the poll instead
 * of retrying it.
 *
 * @param request An admin request context.
 * @return `{status} {statusMessage}`.
 */
async function storeRow(request: APIRequestContext): Promise<string> {
	const list = await request.get(CONNECTIONS_API, {
		headers: { Accept: 'application/json' },
	})
	const rows = list.ok() ? ((await list.json()).results ?? []) : []
	const store = rows.find(
		(row: Record<string, unknown>) =>
			row.key === 'store' && row.app === 'buildiq',
	)
	return `${String(store?.status ?? '')} ${String(store?.statusMessage ?? '')}`
}

/**
 * Run a block with `registry_url` set, and put the previous value back afterwards.
 *
 * @param request An admin request context.
 * @param url The registry URL to save.
 * @param block What to do while it is saved.
 */
async function withRegistryUrl(
	request: APIRequestContext,
	url: string,
	block: () => Promise<void>,
): Promise<void> {
	const before = await request.get(SETTINGS_API, { headers: HEADERS })
	expect(before.ok(), `settings read -> ${before.status()}`).toBeTruthy()
	const body = await before.json()
	const previous = String(body?.registry_url ?? body?.config?.registry_url ?? '')

	try {
		const saved = await request.post(SETTINGS_API, {
			headers: HEADERS,
			data: { registry_url: url },
		})
		expect(saved.ok(), `settings save -> ${saved.status()}`).toBeTruthy()
		await block()
	} finally {
		await request.post(SETTINGS_API, {
			headers: HEADERS,
			data: { registry_url: previous },
		})
	}
}

/**
 * Open the Integrations page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: Page): Promise<void> {
	await page.goto(`${APP}/settings/integrations?app=buildiq`, { timeout: 60_000 })
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

test.describe('Integrations over the connection registry', () => {
	test("lists the four declared connections, all of them Buildiq's", async ({
		page,
	}) => {
		const byKey = await rowsByKey(page.request)
		expect(Object.keys(byKey).sort()).toEqual(DECLARED.map((d) => d.key).sort())
		expect(String(byKey.store?.settingsUrl ?? '')).toBe(
			'/settings/admin/buildiq#section-store',
		)

		await openIntegrations(page)
		for (const { title } of DECLARED) {
			await expect(
				page.getByRole('row', { name: new RegExp(title, 'i') }),
			).toHaveCount(1)
		}
	})

	test('reads Configured once a registry URL is saved', async ({ page }) => {
		await withRegistryUrl(
			page.request,
			'https://store.example.invalid/index.php',
			async () => {
				await expect
					.poll(() => storeRow(page.request), { timeout: 15_000 })
					.toBe('configured Required settings are filled.')
			},
		)
	})

	test('reads Error naming the host once a search cannot reach the store', async ({
		page,
	}) => {
		await withRegistryUrl(
			page.request,
			'https://store.example.invalid/index.php',
			async () => {
				// One search. Its own answer is not under test: it answers the
				// unreachable outcome, as it did before this change. What it reports is.
				const search = await page.request.get(STORE_SEARCH_API, {
					headers: HEADERS,
					failOnStatusCode: false,
				})
				expect(search.status(), `store search -> ${search.status()}`).toBe(
					200,
				)
				expect((await search.json()).outcome).toBe('store_unreachable')

				await expect
					.poll(() => storeRow(page.request), { timeout: 15_000 })
					.toBe(
						'error The last search could not reach the template store at store.example.invalid.',
					)
			},
		)
	})

	test('sends Add integration to integriq instead of offering a form', async ({
		page,
	}) => {
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		// The action lives in the overflow menu. English and Dutch are the two
		// catalogues this change ships, and nothing forces the E2E locale.
		await page.locator('[data-testid="cn-actions"] button').first().click()
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=buildiq&link=1$/, {
				timeout: 30_000,
			}),
			page
				.getByRole('menuitem', {
					name: /Add integration|Integratie toevoegen/i,
				})
				.click(),
		])
	})
})
