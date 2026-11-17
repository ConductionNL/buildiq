/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
/**
 * Shared e2e helper: a request context that carries no account at all.
 *
 * Inside a test, `playwright.request.newContext()` starts from the project's
 * `use` options, so it picks up the admin `storageState` and `httpCredentials`
 * from the config. A context built with only a `baseURL` therefore sends the
 * admin session cookie, and a check that "an anonymous caller is refused" is
 * really asking whether admin is refused. Both are cleared here explicitly.
 */
import type { APIRequestContext, PlaywrightWorkerArgs } from '@playwright/test'

import { E2E_BASE_URL } from './baseUrl.ts'

/**
 * A request context with no cookies and no credentials.
 *
 * @param playwright The `playwright` fixture.
 * @return The context; the caller disposes it.
 */
export async function anonymousContext(
	playwright: PlaywrightWorkerArgs['playwright'],
): Promise<APIRequestContext> {
	return playwright.request.newContext({
		baseURL: E2E_BASE_URL,
		storageState: { cookies: [], origins: [] },
		httpCredentials: undefined,
	})
}
