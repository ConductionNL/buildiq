// SPDX-License-Identifier: EUPL-1.2
/**
 * Schemas store for the Buildiq schema designer.
 *
 * Wraps `createObjectStore` from `@conduction/nextcloud-vue` (memory rule:
 * no bespoke `defineStore` Pinia module layered over `useObjectStore`).
 * Exposes a single Pinia composable that proxies list / get / create /
 * update / delete operations to OR's runtime schema CRUD endpoints
 * provided by chain spec `openregister-runtime-schema-api`:
 *
 *   GET    /index.php/apps/openregister/api/registers/{register}/schemas
 *   GET    /index.php/apps/openregister/api/registers/{register}/schemas/{slug}
 *   POST   /index.php/apps/openregister/api/registers/{register}/schemas
 *   PUT    /index.php/apps/openregister/api/registers/{register}/schemas/{slug}
 *   DELETE /index.php/apps/openregister/api/registers/{register}/schemas/{slug}
 *
 * The base store treats schemas as a registered object type named
 * `schema`, scoped to the per-virtual-app register namespace
 * `buildiq-{slug}` (design OQ-2 provisional decision: one register per
 * virtual app).
 *
 * Until chain #3 lands the endpoints, the store is still importable and
 * its surface stable; the apply phase of this spec is gated on chain #3
 * per tasks.md §7.
 */
import { createObjectStore } from '@conduction/nextcloud-vue'

const STORE_ID = 'openbuild-schemas'

// We point at OR's existing schemas CRUD (`/api/schemas/{id}`) because
// the proposed runtime per-register schema endpoint (`/api/registers/{r}/
// schemas/{slug}`) hasn't shipped on the OR floor yet (spec C chain #3).
//
// `useObjectStore._buildUrl` concatenates `${baseUrl}/${register}/${schema}
// /${id}`. We satisfy that template by splitting `/apps/openregister`,
// `api`, and `schemas` across the baseUrl + register + schema slots, then
// fetching by slug in the id position. The store's `register` slot is no
// longer carrying the OR register name; we keep the per-version register
// info on the type config under `slugs.registerSlug` for callers that
// need to filter the collection.
const SCHEMA_API_BASE_URL = '/apps/openregister'

const useSchemasStoreRaw = createObjectStore(STORE_ID, {
	baseUrl: SCHEMA_API_BASE_URL,
})

/**
 * Resolve the per-virtual-app register slug for a built-app slug + version.
 *
 * REQ-OBVR-007: when a versionSlug is provided, the register name follows
 * spec C's naming convention: `openbuild-{appSlug}-{versionSlug}`
 * (e.g. `openbuild-hello-world-staging`).
 *
 * When no versionSlug is provided, falls back to the old per-app register
 * `openbuild-{appSlug}` for backwards compatibility with apps created before
 * spec C's per-version register model was introduced.
 *
 * WHY THE PREFIX STAYS `openbuild-` AFTER THE APP-ID RENAME. The app id moved
 * to `buildiq` and the CONTROL register moved with it (see
 * `lib/Repair/MigrateRegisterSlug`, whose map is `openbuild => buildiq` and
 * covers that one row only). These PER-APP registers are a different set: a
 * register slug is frozen once objects live under it, and renaming one is a
 * data migration per virtual app, not a find-and-replace. Every existing
 * per-app register on a live instance is named `openbuild-*`, so this prefix is
 * the correct value, not leftover. The docblock previously said `buildiq-`
 * while the code returned `openbuild-`; the code was right.
 *
 * @param {string}           appSlug     Virtual app slug (e.g. `hello-world`).
 * @param {string|undefined} [versionSlug] Optional version slug (e.g. `staging`).
 * @return {string} Register slug (e.g. `openbuild-hello-world-staging`).
 *
 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
 */
export function registerSlugForApp(appSlug, versionSlug) {
	if (versionSlug && versionSlug !== '') {
		// Per-version register: openbuild-{appSlug}-{versionSlug} (spec C / ADR-002).
		return `openbuild-${appSlug}-${versionSlug}`
	}
	// Backwards-compat: legacy per-app register (no version suffix).
	return `openbuild-${appSlug}`
}

/**
 * Get the schemas store, lazily registering the `schema` object type
 * for the given virtual app's register namespace on first call.
 *
 * REQ-OBVR-007: accepts an optional `versionSlug` and routes to the
 * correct per-version register `buildiq-{appSlug}-{versionSlug}` when
 * provided (spec C's naming convention). Falls back to `buildiq-{appSlug}`
 * when versionSlug is absent.
 *
 * @param {string}           appSlug    Virtual app slug.
 * @param {string|undefined} [versionSlug] Optional version slug.
 * @return {object} Pinia store instance.
 *
 * @spec openspec/changes/retrofit-2026-05-25-schema-designer-ui/tasks.md#task-5
 */
export function useSchemasStore(appSlug, versionSlug) {
	const store = useSchemasStoreRaw()
	const register = registerSlugForApp(appSlug, versionSlug)
	const type = 'schema'

	// Slot the URL segments so `_buildUrl` produces
	//   `/apps/openregister/api/schemas[/{slug}]`
	// (OR's existing schemas CRUD).
	// We keep the per-version OR register name on `slugs.registerSlug`
	// so the consumer can filter the global schema collection client-side
	// to only the schemas owned by the selected version's register.
	if (
		!store.objectTypeRegistry[type]
		|| !store.objectTypeRegistry[type].slugs
		|| store.objectTypeRegistry[type].slugs.registerSlug !== register
	) {
		store.registerObjectType(type, 'schemas', 'api', { registerSlug: register })
	}
	return store
}

export { STORE_ID }
