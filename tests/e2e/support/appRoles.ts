/**
 * Shared e2e fixture: grant openbuild app roles to the RBAC fixture groups.
 *
 * The permission suites need a NON-owner who is nonetheless a member — an
 * editor or a viewer. globalSetup provisions the users and their groups and
 * mints one session each (tests/e2e/global-setup.ts), but membership of an
 * APPLICATION is a separate thing: an Application carries
 *
 *     permissions: { owners: ['user:admin'], editors: [], viewers: [] }
 *
 * with `user:` / `group:` prefixed entries. Without an entry here every fixture
 * user is an outsider, which is why the role-scoped scenarios could only ever
 * assert the blackout path.
 *
 * There is no openbuild permissions endpoint — the Application is an
 * OpenRegister object, so the grant goes through OR's object API.
 *
 * ⚠️ OR saves are PUT-SEMANTIC: properties omitted from the body are dropped,
 * not left alone. The whole record is therefore read, merged and written back;
 * never PUT a bare `{ permissions }`.
 *
 * ✅ THIS HELPER IS SUFFICIENT ON ITS OWN. An earlier revision of this comment,
 * and issues Conduction/openbuild#171 and #173, claimed the opposite: that a
 * grantee still could not LIST the application. RETRACTED — re-measured
 * 2026-08-11 on a live instance (NC 34, openregister 0.2.17-unstable.36),
 * printing the status code on every probe:
 *
 *   - `GET /apps/openregister/api/objects/openbuild/application` as
 *     `rbac-editor` → 200 with FIVE rows. OR's multitenancy/ownership scoping
 *     does not drop the row, so that hypothesis is dead.
 *   - `GET /apps/openbuild/api/applications` as `rbac-editor`, BEFORE any
 *     grant → 200 `[]`. Correct: the fixture's `permissions` was `null`.
 *   - After PUTting `{owners:['user:rbac-owner'], editors:['user:rbac-editor']}`
 *     onto a WIZARD-CREATED app → `listMine` returns that app for `rbac-editor`
 *     AND for `rbac-owner`.
 *
 * The original claim came from a Playwright locator timing out on an app-picker
 * option, which was then reported as a fact about `GET /api/applications`.
 * 🔑 A UI locator that finds nothing is not evidence about the API underneath
 * it. Two issues, one of them an architecture issue, were written on that
 * inference. If this helper ever appears not to work again, probe the endpoint
 * directly and read the status code before concluding anything.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

import type { Page } from '@playwright/test'

/** OR object API for the openbuild Application schema. */
const OR_APPLICATIONS = '/index.php/apps/openregister/api/objects/openbuild/application'

/**
 * Grant owner / editor / viewer roles on an application to the given principals.
 *
 * Idempotent: a principal already present is not duplicated, and roles the
 * caller does not mention are preserved as they were.
 *
 * `owners` is grantable because REQ-AUTD-008 needs a NON-ADMIN owner: the
 * production-scoped actions run with `allowAdminBypass: false`, so `admin` being
 * the implicit owner proves nothing about the owner path — the suite has to be
 * able to hand ownership to `rbac-owner` and watch a non-admin succeed where an
 * editor was refused.
 *
 * @param page               Playwright page (authenticated as an owner/admin).
 * @param slug               The application slug.
 * @param principals         `user:`/`group:` prefixed entries to add per role.
 * @param principals.owners  Principals to add to `owners`.
 * @param principals.editors Principals to add to `editors`.
 * @param principals.viewers Principals to add to `viewers`.
 * @return {Promise<void>}
 */
export async function grantAppRoles(
	page: Page,
	slug: string,
	principals: { owners?: string[], editors?: string[], viewers?: string[] },
): Promise<void> {
	const result = await page.evaluate(async ({ api, slug, principals }) => {
		const tok = (window as unknown as { OC?: { requestToken?: string } }).OC?.requestToken
			|| document.querySelector('head')?.getAttribute('data-requesttoken')
			|| ''
		const headers = { requesttoken: tok, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json' }

		const listed = await (await fetch(`${api}?_limit=200`, { headers })).json().catch(() => null)
		const rows = Array.isArray(listed) ? listed : (listed?.results ?? [])
		const app = rows.find((r: Record<string, unknown>) => r?.slug === slug)
		if (!app) {
			return `application ${slug} not found`
		}

		const uuid = app['@self']?.id ?? app.id
		const current = app.permissions ?? {}
		const merge = (existing: string[] | undefined, added: string[] | undefined) =>
			[...new Set([...(Array.isArray(existing) ? existing : []), ...(added ?? [])])]

		const next = {
			...app,
			permissions: {
				owners: merge(current.owners, principals.owners),
				editors: merge(current.editors, principals.editors),
				viewers: merge(current.viewers, principals.viewers),
			},
		}
		// OR is PUT-semantic — `next` is the FULL record, not a patch.
		delete next['@self']

		const resp = await fetch(`${api}/${encodeURIComponent(String(uuid))}`, {
			method: 'PUT',
			headers,
			body: JSON.stringify(next),
		})
		if (!resp.ok) {
			return `grant failed: ${resp.status} ${(await resp.text()).slice(0, 200)}`
		}
		return 'ok'
	}, { api: OR_APPLICATIONS, slug, principals })

	if (result !== 'ok') {
		throw new Error(`grantAppRoles(${slug}) — ${result}`)
	}
}
