// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Router helpers for OpenBuilt version-aware navigation.
//
// `buildVersionedRoute` (REQ-OBVR-006 / design.md Decision 6):
// A pure helper that constructs a Vue Router route-location object, forwarding
// the active `?_version=` query parameter when present.
//
// WHY this helper exists:
// Without it, every `$router.push({ name: 'schemas' })` call silently strips
// `?_version=staging` from the URL when the admin navigates between builder
// sub-sections. This helper makes version-forwarding the default; callers that
// legitimately do NOT need version forwarding should add a TODO comment
// explaining the intent so reviewers can confirm the decision.
//
// Usage:
//   import { buildVersionedRoute } from '../router/helpers.js'
//
//   // With a version — produces { name, params, query: { _version: 'staging' } }
//   this.$router.push(buildVersionedRoute('SchemaDesignerList', { slug }, 'staging'))
//
//   // Without a version — produces { name, params, query: {} }
//   this.$router.push(buildVersionedRoute('SchemaDesignerList', { slug }, undefined))
//
// NOTE on the `_version` param name: the leading underscore is OpenBuilt's
// system-reserved namespace marker for query params. It prevents collision with
// user-defined `?version=` params that citizen developers may add to their own
// virtual apps' routes. See design.md Decision 1.

/**
 * Build a Vue Router route-location object, forwarding the active `?_version=`
 * query parameter when a current version is provided.
 *
 * This is a pure function with no side effects — it does not interact with the
 * router instance and is safely unit-testable.
 *
 * @param {string}           routeName      The route name (matches `page.id` in manifest.json).
 * @param {object}           [params]       Route params (e.g. `{ slug: 'hello-world' }`).
 * @param {string|undefined} [currentVersion] The current version slug from `$route.query._version`.
 *                                          When provided, the returned object carries
 *                                          `query: { _version: currentVersion }`. When absent or
 *                                          undefined, the returned object carries `query: {}`.
 * @return {{ name: string, params: object, query: object }} A Vue Router RouteLocationRaw.
 */
export function buildVersionedRoute(routeName, params = {}, currentVersion = undefined) {
	return {
		name: routeName,
		params,
		query: currentVersion ? { _version: currentVersion } : {},
	}
}
