// SPDX-License-Identifier: EUPL-1.2
/**
 * useRegisterPicker — composable that fetches registers + schemas for
 * the page-editor's register / schema dropdowns.
 *
 * Hybrid register model (per design.md + locked decision 3):
 *  - The page editor consumes Application records from the SHARED
 *    `openbuilt` register (one record per virtual app).
 *  - The manifest each Application produces references schemas living
 *    in the PER-APP register `openbuilt-{slug}`. So when the user picks
 *    a schema for a page binding, this composable shows the registers
 *    available to that per-app namespace — i.e. `openbuilt-{slug}` plus
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
 *   picker filters to the per-app register `openbuilt-{slug}` first.
 * @return {object} - { fetchRegisters, fetchSchemas, fetchSchemaProperties,
 *   resolveAppRegister }.
 */
export function useRegisterPicker(opts = {}) {
	const appSlug = opts.appSlug || ''

	/**
	 * Resolve the per-app register slug for the current Application.
	 * Returns `openbuilt-{slug}` when slug is set, falls back to ''.
	 *
	 * @return {string} - the per-app register slug or empty string.
	 */
	function resolveAppRegister() {
		return appSlug ? `openbuilt-${appSlug}` : ''
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

	return {
		fetchRegisters,
		fetchSchemas,
		fetchSchemaProperties,
		resolveAppRegister,
	}
}
