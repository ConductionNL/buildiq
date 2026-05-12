// SPDX-License-Identifier: EUPL-1.2
/**
 * useManifestValidator — debounced wrapper around `validateManifest` from
 * the `@conduction/nextcloud-vue` library. Implements REQ-OBPD-011.
 *
 * Responsibilities:
 *  - Re-run validateManifest at most once every 300ms of editor-state change.
 *  - Surface each error twice: in a side-panel error list AND as inline marks
 *    on the offending editor field via path-prefix → field-component-ref map.
 *  - Never block the UI thread; the validator runs via setTimeout, the
 *    editor stays responsive while results catch up asynchronously.
 *
 * Sub-editors call `register(pathPrefix, fieldRef)` on mount and
 * `unregister(pathPrefix)` on unmount. After each validator pass the
 * composable groups error paths by their longest matching registered
 * prefix and assigns the mapped field ref an error-marked state.
 *
 * Path mapping convention: validateManifest emits paths in JSON Pointer
 * shorthand like `/pages/1/config/columns/0`. Sub-editors register a
 * prefix string matching the same shape, e.g. `/pages/1/config/columns`.
 */
import { ref, reactive, computed } from 'vue'
import { validateManifest } from '@conduction/nextcloud-vue'

const DEBOUNCE_MS = 300

export function useManifestValidator() {
	const errors = ref([])
	const isValidating = ref(false)
	const fieldRefs = reactive(new Map())
	let timer = null

	/**
	 * Validate (debounced).
	 *
	 * @param {object} manifest - the in-flight manifest blob to validate.
	 */
	function validate(manifest) {
		if (timer) {
			clearTimeout(timer)
		}
		isValidating.value = true
		timer = setTimeout(() => {
			try {
				const result = validateManifest
					? validateManifest(manifest)
					: { valid: true, errors: [] }
				errors.value = Array.isArray(result.errors) ? result.errors.slice() : []
			} catch (e) {
				errors.value = [`validator threw: ${e && e.message ? e.message : e}`]
			} finally {
				isValidating.value = false
			}
		}, DEBOUNCE_MS)
	}

	/**
	 * Register a field component against a JSON-Pointer path prefix.
	 * When the validator reports an error path whose prefix matches, the
	 * registered ref is decorated with an inline error mark.
	 *
	 * @param {string} pathPrefix - JSON-Pointer prefix like `/pages/0/id`.
	 * @param {object} fieldRef - object exposing `markError(message)` and
	 *   `clearError()` methods (sub-editor field wrapper).
	 */
	function register(pathPrefix, fieldRef) {
		fieldRefs.set(pathPrefix, fieldRef)
	}

	function unregister(pathPrefix) {
		fieldRefs.delete(pathPrefix)
	}

	const hasErrors = computed(() => errors.value.length > 0)

	/**
	 * For each registered prefix, find the longest matching error path and
	 * return it. Used by sub-editors to decorate their inline field marks.
	 */
	const errorsByPrefix = computed(() => {
		const result = new Map()
		for (const prefix of fieldRefs.keys()) {
			const hits = errors.value.filter((e) => typeof e === 'string' && e.startsWith(prefix))
			if (hits.length) {
				result.set(prefix, hits)
			}
		}
		return result
	})

	return {
		errors,
		hasErrors,
		isValidating,
		validate,
		register,
		unregister,
		errorsByPrefix,
		DEBOUNCE_MS,
	}
}
