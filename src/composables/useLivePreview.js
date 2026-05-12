// SPDX-License-Identifier: EUPL-1.2
/**
 * useLivePreview — feature-detect the in-memory `useAppManifest(appId,
 * manifestObject)` overload from chain spec #2 and expose:
 *   - `available` — true when the overload is present (function arity >= 2).
 *   - `previewProps(slug, inflightManifest)` — props for the sandbox
 *     CnAppRoot mount. Returns `null` when the overload is unavailable.
 *
 * Implements REQ-OBPD-008 fallback logic.
 *
 * Feature detection inspects `useAppManifest.length` at module-load time;
 * the imported function is treated as a static dependency, not a runtime
 * registry. When chain spec #2 ships, the bumped library export changes
 * the arity from 1 (legacy: only `appId`) to 2 (new: appId + manifest),
 * and `available` flips to true without any editor code change.
 */
import { computed } from 'vue'
import { useAppManifest } from '@conduction/nextcloud-vue'

export function useLivePreview() {
	// Spec #2 adds a second positional `manifestObject` parameter; arity
	// is the discriminator. Older versions ship as a 1-arg function.
	const fnArity
		= typeof useAppManifest === 'function' ? useAppManifest.length : 0
	const available = computed(() => fnArity >= 2)

	/**
	 * Build the prop bag for the sandbox CnAppRoot mount.
	 *
	 * @param {string} slug - The Application's slug.
	 * @param {object} inflightManifest - The unsaved manifest blob.
	 * @return {object|null} props or null when preview is unavailable.
	 */
	function previewProps(slug, inflightManifest) {
		if (!available.value) {
			return null
		}
		const key = manifestHash(inflightManifest)
		return {
			appId: `openbuilt-preview-${slug}`,
			manifest: inflightManifest,
			key,
		}
	}

	return { available, previewProps }
}

/**
 * Cheap content hash for keying the sandbox CnAppRoot mount. Stable for
 * identical-content edits (mitigates "preview pane re-mounts thrash" risk
 * in design.md).
 *
 * @param {object} manifest - manifest blob.
 * @return {string} stable hash string.
 */
function manifestHash(manifest) {
	try {
		const json = JSON.stringify(manifest)
		let hash = 0
		for (let i = 0; i < json.length; i++) {
			hash = ((hash << 5) - hash) + json.charCodeAt(i)
			hash |= 0
		}
		return String(hash)
	} catch {
		return String(Date.now())
	}
}
