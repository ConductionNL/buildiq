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
 * ⚠️ NOT YET SUFFICIENT ON ITS OWN — see Conduction/openbuild#76.
 *
 * This helper does what it says: the grant lands and reads back as
 * `{owners:['user:admin'], editors:['group:rbac-editors'], viewers:['group:rbac-viewers']}`.
 * But a member still cannot LIST the application. Measured on a live instance
 * with everything else verified correct:
 *
 *   - `rbac-viewer` is in `rbac-viewers`, `rbac-editor` in `rbac-editors`
 *     (OCS `cloud/users/{uid}/groups`), and both groups exist;
 *   - PermissionResolver::matchesCaller() classifies `group:` principals and
 *     intersects them with the caller's groups, so the grammar is right;
 *   - yet `GET /api/applications` returns 200 with an EMPTY list for both.
 *
 * So something below openbuild's own permission layer is filtering the object
 * out — most likely OpenRegister-level object visibility, which is a separate
 * grant from the manifest `permissions` block. Until that is resolved this
 * helper is groundwork, not a fix, and the role-scoped scenarios stay skipped.
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 */

import type { Page } from '@playwright/test'

/** OR object API for the openbuild Application schema. */
const OR_APPLICATIONS = '/index.php/apps/openregister/api/objects/openbuild/application'

/**
 * Grant editor / viewer roles on an application to the given principals.
 *
 * Idempotent: a principal already present is not duplicated, and existing
 * owners are preserved.
 *
 * @param page       Playwright page (authenticated as an owner/admin).
 * @param slug       The application slug.
 * @param principals `user:`/`group:` prefixed entries to add per role.
 * @return {Promise<void>}
 */
export async function grantAppRoles(
	page: Page,
	slug: string,
	principals: { editors?: string[], viewers?: string[] },
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
				owners: merge(current.owners, []),
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
