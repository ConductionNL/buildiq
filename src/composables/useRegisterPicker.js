// SPDX-License-Identifier: EUPL-1.2
/**
 * useRegisterPicker — composable that fetches registers + schemas for
 * the page-editor's register / schema dropdowns.
 *
 * Hybrid register model (per design.md + locked decision 3):
 *  - The page editor consumes Application records from the SHARED
 *    `openbuild` register (one record per virtual app).
 *  - The manifest each Application produces references schemas living
 *    in the PER-APP register `openbuild-{slug}`. So when the user picks
 *    a schema for a page binding, this composable shows the registers
 *    available to that per-app namespace — i.e. `openbuild-{slug}` plus
 *    any other registers the user explicitly references.
 *
 * Why a composable and not raw axios in each sub-editor:
 *  - Centralises the OR REST URL shape (single edit-point for path changes).
 *  - Honours the ADR-004 hard rule "Do not use custom stores; use Options
 *    API with createObjectStore". Register / schema metadata is loaded
 *    via @nextcloud/router + buildHeaders so request-token + CSRF are
 *    consistent across pickers; no direct axios import in the consumers.
 */
import { generateUrl } from '@nextcloud/router'
import { getRequestToken } from '@nextcloud/auth'

/**
 * The registers the pages editor can bind a page to: the app's own register,
 * plus any register an existing page already points at, plus any declared
 * `dataRegisters` binding.
 *
 * Passed to `fetchDataSources()` so it fans schema requests out over a handful of
 * registers instead of every register on the instance. Shared by both hosts
 * (builder.js and BuilderHost.vue) so the two cannot drift apart again.
 *
 * Take `perAppRegister` from `registerSlugForApp(slug, versionSlug)` — the app's
 * register is per-VERSION (`openbuild-{slug}-{version}`), so the bare
 * `openbuild-{slug}` is usually not a register at all.
 *
 * @param {string} perAppRegister - the app's own (per-version) register slug.
 * @param {?object} manifest - the resolved manifest (its pages' `config.register`).
 * @param {Array<{register: string}>} [dataRegisters] - declared shared-register bindings.
 * @return {string[]} - deduped register slugs.
 */
export function registerScope(perAppRegister, manifest, dataRegisters = []) {
	const scope = new Set()
	if (perAppRegister) scope.add(perAppRegister)

	const pages = (manifest && Array.isArray(manifest.pages)) ? manifest.pages : []
	pages.forEach((p) => {
		const register = p && p.config && p.config.register
		if (register) scope.add(register)
	})

	;(Array.isArray(dataRegisters) ? dataRegisters : []).forEach((b) => {
		if (b && b.register) scope.add(b.register)
	})

	return [...scope]
}

const PICKER_HEADERS = () => ({
	'Content-Type': 'application/json',
	Accept: 'application/json',
	requesttoken: getRequestToken(),
})

/**
 * Fetch helpers for the register / schema pickers used by IndexPageEditor
 * and DetailPageEditor. The composable returns four async functions; the
 * caller stores the results in component data (Options API) so this stays
 * a pure data-flow helper with no Vue state-binding magic.
 *
 * @param {object} [opts] - Options.
 * @param {string} [opts.appSlug] - Current Application slug. When set, the
 *   picker filters to the per-app register `openbuild-{slug}` first.
 * @param {Array<{register: string, label?: string}>} [opts.dataRegisters] -
 *   The Application's declared shared data-register bindings
 *   (`Application.dataRegisters`, data-registers-schema-declaration). When
 *   set, `fetchRegisters()` labels matching entries with
 *   `binding.label ?? binding.register` and hoists them after the per-app
 *   register. Absent/empty is a no-op — `fetchRegisters()` then returns
 *   output byte-identical to the pre-existing (perApp-only) behaviour.
 * @return {object} - { fetchRegisters, fetchSchemas, fetchSchemaProperties,
 *   resolveAppRegister }.
 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-1
 * @spec openspec/changes/data-registers-runtime/tasks.md#task-1.1
 */
export function useRegisterPicker(opts = {}) {
	const appSlug = opts.appSlug || ''
	const dataRegisters = Array.isArray(opts.dataRegisters) ? opts.dataRegisters : []

	/**
	 * Resolve the per-app register slug for the current Application.
	 * Returns `openbuild-{slug}` when slug is set, falls back to ''.
	 *
	 * @return {string} - the per-app register slug or empty string.
	 */
	function resolveAppRegister() {
		return appSlug ? `openbuild-${appSlug}` : ''
	}

	/**
	 * Fetch the list of registers available to the page editor. When the
	 * current Application has a slug, the per-app register is hoisted to
	 * the top so picker UX defaults to the right namespace. When the
	 * Application declares `dataRegisters` bindings (design.md Decision 1),
	 * matching entries are labelled with `binding.label ?? binding.register`
	 * and hoisted immediately after the per-app register, in declaration
	 * order; every other entry keeps OR's original relative order.
	 *
	 * @return {Promise<Array>} - registers list.
	 * @spec openspec/changes/data-registers-runtime/tasks.md#task-1.1
	 */
	async function fetchRegisters() {
		try {
			const url = generateUrl('/apps/openregister/api/registers')
			const response = await fetch(url, { headers: PICKER_HEADERS() })
			if (!response.ok) {
				return []
			}
			const data = await response.json()
			const list = (data && (data.results || data)) || []
			if (!Array.isArray(list)) {
				return []
			}

			// Hoist the per-app register so it is the obvious default.
			const perApp = resolveAppRegister()

			// No dataRegisters bindings declared — regression-safe default:
			// byte-identical to the pre-existing (perApp-only) behaviour.
			if (dataRegisters.length === 0) {
				if (!perApp) {
					return list
				}
				const sorted = [...list].sort((a, b) => {
					if ((a.slug || a.id) === perApp) return -1
					if ((b.slug || b.id) === perApp) return 1
					return 0
				})
				return sorted
			}

			// Map<registerSlug, label> — label ?? register per design.md.
			const labelByRegister = new Map()
			dataRegisters.forEach((binding) => {
				if (binding && binding.register) {
					labelByRegister.set(binding.register, binding.label ?? binding.register)
				}
			})

			// Declaration order of each binding, for the "matching entries,
			// in the order the Application declared them" tier.
			const declarationOrder = new Map()
			dataRegisters.forEach((binding, index) => {
				if (binding && binding.register && !declarationOrder.has(binding.register)) {
					declarationOrder.set(binding.register, index)
				}
			})

			const labelled = list.map((entry) => {
				const key = entry && (entry.slug || entry.id)
				if (key && labelByRegister.has(key)) {
					return { ...entry, label: labelByRegister.get(key) }
				}
				return entry
			})

			// Tier 0: per-app register. Tier 1: dataRegisters bindings (in
			// declaration order). Tier 2: everything else (OR's order).
			function tierFor(entry) {
				const key = entry && (entry.slug || entry.id)
				if (perApp && key === perApp) {
					return 0
				}
				if (key && declarationOrder.has(key)) {
					return 1
				}
				return 2
			}

			const indexed = labelled.map((entry, originalIndex) => ({ entry, originalIndex }))
			indexed.sort((a, b) => {
				const tierA = tierFor(a.entry)
				const tierB = tierFor(b.entry)
				if (tierA !== tierB) {
					return tierA - tierB
				}
				if (tierA === 1) {
					const keyA = a.entry.slug || a.entry.id
					const keyB = b.entry.slug || b.entry.id
					return declarationOrder.get(keyA) - declarationOrder.get(keyB)
				}
				// Tiers 0 (singleton) and 2 keep the original relative order.
				return a.originalIndex - b.originalIndex
			})

			return indexed.map((i) => i.entry)
		} catch {
			return []
		}
	}

	/**
	 * Fetch the schemas in a given register.
	 *
	 * @param {string} register - register slug or id.
	 * @return {Promise<Array>} - schemas list.
	 */
	async function fetchSchemas(register) {
		if (!register) {
			return []
		}
		try {
			const url = generateUrl(`/apps/openregister/api/registers/${register}/schemas`)
			const response = await fetch(url, { headers: PICKER_HEADERS() })
			if (!response.ok) {
				return []
			}
			const data = await response.json()
			const list = (data && (data.results || data)) || []
			return Array.isArray(list) ? list : []
		} catch {
			return []
		}
	}

	/**
	 * Fetch the JSON-schema `properties` map for a register / schema pair.
	 *
	 * @param {string} register - register slug.
	 * @param {string} schema - schema slug.
	 * @return {Promise<object>} - properties map (empty object on failure).
	 */
	async function fetchSchemaProperties(register, schema) {
		if (!register || !schema) {
			return {}
		}
		try {
			const url = generateUrl(`/apps/openregister/api/registers/${register}/schemas/${schema}`)
			const response = await fetch(url, { headers: PICKER_HEADERS() })
			if (!response.ok) {
				return {}
			}
			const data = await response.json()
			return (data && data.properties) || (data && data.schema && data.schema.properties) || {}
		} catch {
			return {}
		}
	}

	/**
	 * Build the `dataSources` object consumed by the library's page-config /
	 * edit-pages modals (CnAppRoot's `dataSourcesLoader`). Its presence flips the
	 * Register / Schema / Columns fields from free-text slug inputs to searchable
	 * NcSelect dropdowns.
	 *
	 * The schemas endpoint returns each schema's `properties` inline (see
	 * OpenRegister `Schema::jsonSerialize`), so column names come for free from
	 * the property keys — no extra per-schema request.
	 *
	 * @param {string[]} [scope] - Register slugs to fan schema requests out over,
	 *   from `registerScope(...)`. Without it EVERY register on the instance is
	 *   queried, one schema request each — dozens on a populated instance. The
	 *   pages editor only ever needs a handful, so its hosts pass an explicit scope.
	 * @return {Promise<{registers: Array<{value: string, label: string,
	 *   schemas: Array<{value: string, label: string, columns: string[]}>}>}>}
	 *   - the data-sources map (empty `registers` on failure).
	 */
	async function fetchDataSources(scope = null) {
		const registers = await fetchRegisters()
		if (!Array.isArray(registers) || registers.length === 0) {
			return { registers: [] }
		}
		const wanted = Array.isArray(scope) && scope.length ? scope.filter(Boolean) : null
		const inScope = wanted
			? registers.filter((r) => wanted.includes(r.slug || r.id))
			: registers
		const mapped = await Promise.all(inScope.map(async (r) => {
			const registerSlug = r.slug || r.id
			const schemas = await fetchSchemas(registerSlug)
			return {
				value: registerSlug,
				label: r.title || registerSlug,
				schemas: (Array.isArray(schemas) ? schemas : []).map((s) => ({
					value: s.slug || s.id,
					label: s.title || s.slug || s.id,
					columns: Object.keys((s && s.properties) || {}),
				})),
			}
		}))
		return { registers: mapped }
	}

	return {
		fetchRegisters,
		fetchSchemas,
		fetchSchemaProperties,
		fetchDataSources,
		resolveAppRegister,
	}
}
