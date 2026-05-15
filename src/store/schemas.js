// SPDX-License-Identifier: EUPL-1.2
/**
 * Schemas store for the OpenBuilt schema designer.
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
 * `openbuilt-{slug}` (design OQ-2 provisional decision: one register per
 * virtual app).
 *
 * Until chain #3 lands the endpoints, the store is still importable and
 * its surface stable; the apply phase of this spec is gated on chain #3
 * per tasks.md §7.
 */
import { createObjectStore } from '@conduction/nextcloud-vue'

const STORE_ID = 'openbuilt-schemas'

// Runtime schema CRUD lives under /api/registers (chain #3) — not the
// /api/objects surface that backs ordinary OR object CRUD.
const RUNTIME_SCHEMA_BASE_URL = '/apps/openregister/api/registers'

const useSchemasStoreRaw = createObjectStore(STORE_ID, {
	baseUrl: RUNTIME_SCHEMA_BASE_URL,
})

/**
 * Resolve the per-virtual-app register slug for a built-app slug + version.
 *
 * REQ-OBVR-007: when a versionSlug is provided, the register name follows
 * spec C's naming convention: `openbuilt-{appSlug}-{versionSlug}`.
 * (e.g. `openbuilt-hello-world-staging`).
 *
 * When no versionSlug is provided, falls back to the old per-app register
 * `openbuilt-{appSlug}` for backwards compatibility with apps created before
 * spec C's per-version register model was introduced.
 *
 * @param {string}           appSlug     Virtual app slug (e.g. `hello-world`).
 * @param {string|undefined} [versionSlug] Optional version slug (e.g. `staging`).
 * @return {string} Register slug (e.g. `openbuilt-hello-world-staging`).
 */
export function registerSlugForApp(appSlug, versionSlug) {
	if (versionSlug && versionSlug !== '') {
		// Per-version register: openbuilt-{appSlug}-{versionSlug} (spec C / ADR-002).
		return `openbuilt-${appSlug}-${versionSlug}`
	}
	// Backwards-compat: legacy per-app register (no version suffix).
	return `openbuilt-${appSlug}`
}

/**
 * Get the schemas store, lazily registering the `schema` object type
 * for the given virtual app's register namespace on first call.
 *
 * REQ-OBVR-007: accepts an optional `versionSlug` and routes to the
 * correct per-version register `openbuilt-{appSlug}-{versionSlug}` when
 * provided (spec C's naming convention). Falls back to `openbuilt-{appSlug}`
 * when versionSlug is absent.
 *
 * @param {string}           appSlug    Virtual app slug.
 * @param {string|undefined} [versionSlug] Optional version slug.
 * @return {object} Pinia store instance.
 */
export function useSchemasStore(appSlug, versionSlug) {
	const store = useSchemasStoreRaw()
	const register = registerSlugForApp(appSlug, versionSlug)
	const type = 'schema'

	// Register the object type once per (store, type, register) tuple. The base
	// store records the register/schema pair under the type slug; here
	// we use literal `schema` for both because chain #3's endpoint shape
	// expects `/registers/{register}/schemas[/{slug}]`.
	// Re-register when register changes (different version selected).
	if (!store.objectTypeRegistry[type]
		|| store.objectTypeRegistry[type].register !== register) {
		store.registerObjectType(type, 'schemas', register)
	}
	return store
}

export { STORE_ID }
