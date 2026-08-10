// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Shared e2e helper: click the schema designer's Save and wait for the write.
 *
 * Every caller here used to do `click()` + `waitForLoadState('networkidle')`.
 * That wait can never resolve on Nextcloud — the notification poll keeps at
 * least one request in flight for the whole session — so it burned its entire
 * timeout budget and then either threw or was swallowed by a `.catch(() => {})`
 * (ADR-074 rule 4). Worse, it asserted nothing: a save that 404'd looked
 * exactly like one that succeeded, which is precisely the defect
 * `SchemaDesigner.save()`'s own comment records (OpenRegister's schema API is
 * read-by-slug but WRITE-BY-ID, so every designer save used to 404 silently).
 *
 * Waiting for the write itself is both deterministic and a STRONGER check: it
 * proves the request was sent AND came back 2xx before the caller reads the
 * result back. Same pattern as `saveAndAwaitPersist()` in
 * tests/e2e/spec-coverage/page-editor-coverage.spec.ts.
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

import { expect, type Page } from '@playwright/test'

/**
 * The write `SchemaDesigner.save()` issues.
 *
 * `src/store/store.js` points `schemaBaseUrl` at
 * `/apps/openregister/api/schemas` and `saveObject()` appends the schema's
 * numeric id, switching to PUT when one is present. Match POST too so a helper
 * used after a CREATE does not wait for a request that is never sent. The
 * pretty and `/index.php`-prefixed forms are both accepted because specs
 * navigate the pretty form while the store builds URLs via `generateUrl()`.
 *
 * @param url    The response URL.
 * @param method The request method.
 * @return {boolean} whether this response is the schema write.
 */
function isSchemaWrite(url: string, method: string): boolean {
	return /\/apps\/openregister\/api\/schemas\/[^/?#]+(\?|#|$)/.test(url)
		&& ['PUT', 'PATCH', 'POST'].includes(method)
}

/**
 * Click Save in the schema designer and wait until the schema write lands 2xx.
 *
 * @param page    Playwright page.
 * @param options Optional overrides.
 * @param options.timeout How long to wait for the write (default 30s).
 * @return {Promise<void>}
 */
export async function saveSchemaAndAwait(
	page: Page,
	options: { timeout?: number } = {},
): Promise<void> {
	const saved = page.waitForResponse(
		(r) => isSchemaWrite(r.url(), r.request().method()),
		{ timeout: options.timeout ?? 30_000 },
	)
	await page.getByRole('button', { name: /^save$/i }).click()
	const res = await saved
	expect(res.ok(), `the schema write must succeed, got HTTP ${res.status()} from ${res.url()}`).toBeTruthy()
}
