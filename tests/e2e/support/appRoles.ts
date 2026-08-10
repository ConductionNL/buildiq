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
 * ✅ THE BLOCKER THIS FILE USED TO CARRY IS CLOSED — openbuild#76, 2026-08-01.
 *
 * The warning that stood here said a member still could not LIST the
 * application, and concluded that "this helper is groundwork, not a fix, and
 * the role-scoped scenarios stay skipped". That is no longer true, and leaving
 * it in place is an active instruction to skip scenarios that now run.
 *
 * The cause was one layer BELOW openbuild: every openbuild schema declared
 * `{create, update, delete: ["admin"]}` and NO `read`, so OpenRegister's own
 * SQL gate filtered the object out for every non-admin — the app-level grants
 * this helper writes landed correctly and were then discarded underneath.
 * `read: ["authenticated"]` on all 15 schemas (6 in the monolith, 9 in
 * `register.d/` fragments) fixed it; anonymous stays excluded because
 * `authenticated` requires `$userId !== null`. Measured after the re-import:
 * `rbac-editor` and `rbac-viewer` went 0 -> 21 objects on the OR object API and
 * each sees exactly the 1 application it holds a role on, while `rbac-outsider`
 * still sees 0 and anonymous still gets 401.
 *
 * So both layers now behave as designed, and a grant made here is visible to
 * the grantee. `versionRouting` 9.2 was un-skipped on the back of it.
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
 * Idempotent: a principal already present is not duplicated, and existing
 * entries in every role are preserved — including `owners`, which is why the
 * admin that created the app never loses its own grant when a second owner is
 * added.
 *
 * `owners` is accepted for REQ-AUTD-008, which needs a NON-admin owner: the
 * scenario is "the editor is refused on the production version and an OWNER
 * succeeds where the editor was rejected", and if that owner were the admin the
 * success could come from the admin bypass rather than from the ownership
 * grant — the test would pass without proving anything about ownership.
 *
 * @param page       Playwright page (authenticated as an owner/admin).
 * @param slug       The application slug.
 * @param principals `user:`/`group:` prefixed entries to add per role.
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
