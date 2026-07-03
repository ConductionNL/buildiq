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
 * @return {object} - { fetchRegisters, fetchSchemas, fetchSchemaProperties,
 *   resolveAppRegister }.
 * @spec openspec/changes/retrofit-2026-05-26-frontend-foundation/tasks.md#task-1
 */
export function useRegisterPicker(opts = {}) {
	const appSlug = opts.appSlug || ''

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
	 * the top so picker UX defaults to the right namespace.
	 *
	 * @return {Promise<Array>} - registers list.
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
			if (!perApp) {
				return list
			}
			const sorted = [...list].sort((a, b) => {
				if ((a.slug || a.id) === perApp) return -1
				if ((b.slug || b.id) === perApp) return 1
				return 0
			})
			return sorted
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
	 * edit-pages modals (provided down as `cnDataSources` by CnAppRoot). Its
	 * presence flips the Register / Schema / Columns fields from free-text slug
	 * inputs to searchable NcSelect dropdowns.
	 *
	 * Fetches every register and, in parallel, each register's schemas. The
	 * schemas endpoint returns each schema's `properties` inline (see
	 * OpenRegister `Schema::jsonSerialize`), so column names come for free from
	 * the property keys — no extra per-schema request.
	 *
	 * @return {Promise<{registers: Array<{value: string, label: string,
	 *   schemas: Array<{value: string, label: string, columns: string[]}>}>}>}
	 *   - the data-sources map (empty `registers` on failure).
	 */
	async function fetchDataSources() {
		const registers = await fetchRegisters()
		if (!Array.isArray(registers) || registers.length === 0) {
			return { registers: [] }
		}
		const mapped = await Promise.all(registers.map(async (r) => {
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
