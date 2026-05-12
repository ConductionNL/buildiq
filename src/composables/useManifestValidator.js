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
 * Sub-editors call `register(pathPrefix)` on mount and
 * `unregister(pathPrefix)` on unmount (via the `pageEditorValidation`
 * mixin + the `pageEditorValidator` object PageDesigner provides). After
 * each validator pass the composable groups error paths by registered
 * prefix and exposes `errorMap` (`{ prefix → { hasError, message } }`)
 * and `errorFor(prefix)` for the sub-editors' inline marks.
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
	 * Register a field against a JSON-Pointer path prefix. When the
	 * validator reports an error path that starts with this prefix the
	 * sub-editor reads it back via `errorMap` / `errorsByPrefix` and
	 * paints an inline mark next to the field (task 5.5 / REQ-OBPD-011).
	 *
	 * @param {string} pathPrefix - JSON-Pointer prefix like `/pages/0/config/columns`.
	 * @param {object} [fieldRef] - optional opaque handle (kept for callers
	 *   that want to stash a DOM ref alongside the registration).
	 */
	function register(pathPrefix, fieldRef = true) {
		fieldRefs.set(pathPrefix, fieldRef)
	}

	function unregister(pathPrefix) {
		fieldRefs.delete(pathPrefix)
	}

	const hasErrors = computed(() => errors.value.length > 0)

	/**
	 * Errors whose JSON-Pointer path starts with `prefix`. A `/`, space
	 * or `:` boundary is required after the prefix so `/pages/1` does not
	 * also swallow `/pages/10/...`; the space / colon cases tolerate
	 * validators that append a "<pointer> is required" suffix.
	 *
	 * @param {string} prefix - JSON-Pointer prefix.
	 * @return {Array<string>}
	 */
	function matchingErrors(prefix) {
		return errors.value.filter((e) => {
			if (typeof e !== 'string') {
				return false
			}
			return e === prefix
				|| e.startsWith(prefix + '/')
				|| e.startsWith(prefix + ' ')
				|| e.startsWith(prefix + ':')
		})
	}

	/**
	 * For each registered prefix, the list of error strings whose path
	 * starts with it. Kept for callers that want every matching error.
	 */
	const errorsByPrefix = computed(() => {
		const result = new Map()
		for (const prefix of fieldRefs.keys()) {
			const hits = matchingErrors(prefix)
			if (hits.length) {
				result.set(prefix, hits)
			}
		}
		return result
	})

	/**
	 * For each registered prefix, a `{ hasError, message }` bag — the
	 * compact shape sub-editors hand to `<InlineFieldMark>`. `message` is
	 * the first matching error string (the side-panel list is the full
	 * overview). Prefixes with no matching error are still present with
	 * `hasError: false` so callers can bind unconditionally.
	 *
	 * @return {Map<string, {hasError: boolean, message: string}>}
	 */
	const errorMap = computed(() => {
		const result = new Map()
		for (const prefix of fieldRefs.keys()) {
			const hits = matchingErrors(prefix)
			result.set(prefix, {
				hasError: hits.length > 0,
				message: hits.length ? hits[0] : '',
			})
		}
		return result
	})

	/**
	 * `{ hasError, message }` for a single prefix (convenience accessor
	 * over `errorMap`; returns the empty bag for unregistered prefixes).
	 *
	 * @param {string} prefix - JSON-Pointer prefix.
	 * @return {{hasError: boolean, message: string}}
	 */
	function errorFor(prefix) {
		return errorMap.value.get(prefix) || { hasError: false, message: '' }
	}

	return {
		errors,
		hasErrors,
		isValidating,
		validate,
		register,
		unregister,
		errorsByPrefix,
		errorMap,
		errorFor,
		DEBOUNCE_MS,
	}
}
