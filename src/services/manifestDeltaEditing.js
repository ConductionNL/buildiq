// SPDX-License-Identifier: EUPL-1.2
/**
 * manifestDeltaEditing — the page designer's side of a delta-mode app
 * (app-delta-override D6): a version with a `baseRef` is edited as its base
 * with its delta merged over it, previewed through the same canonical
 * `mergeManifestDelta` the server port mirrors, and saved as the minimal
 * `diffManifest(base, edited)`, never as a blob.
 *
 * Pure apart from fetchManifestBase(), which asks the server for the base
 * (GET /api/applications/{slug}/manifest-base, the manifest endpoint's gate).
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */

import { diffManifest, mergeManifestDelta } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Whether a version is edited as base + delta: it names a base, and it is
 * not a hybrid app's version (whose delta the browser merges over the
 * installed fleet app's manifest, elsewhere).
 *
 * @param {object|null} version The ApplicationVersion.
 * @param {object|null} application The Application.
 * @return {boolean}
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
export function isDeltaMode(version, application) {
	const baseRef = version && version.baseRef
	if (!baseRef || typeof baseRef !== 'object' || !String(baseRef.kind || '').trim()) {
		return false
	}
	return !(application && application.appType === 'hybrid')
}

/**
 * The base a version extends, from the server: `{baseRef, base}`.
 *
 * @param {string} slug The app slug.
 * @param {string} [versionSlug] The version slug, empty for production.
 * @return {Promise<{baseRef: object|null, base: object|null}>}
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
export async function fetchManifestBase(slug, versionSlug = '') {
	const url = generateUrl(`/apps/buildiq/api/applications/${encodeURIComponent(slug)}/manifest-base`)
	const { data } = await axios.get(url, versionSlug ? { params: { _version: versionSlug } } : undefined)
	return {
		baseRef: (data && data.baseRef) || null,
		base: (data && data.base && typeof data.base === 'object') ? data.base : null,
	}
}

/**
 * The base the editor diffs against: the resolved base, or, like the server
 * when the base cannot be resolved, the version's stored blob.
 *
 * @param {object|null} resolvedBase The base from the server.
 * @param {object|null} version The ApplicationVersion.
 * @return {object}
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.2
 */
export function editingBase(resolvedBase, version) {
	if (resolvedBase && typeof resolvedBase === 'object') {
		return resolvedBase
	}
	const blob = version && version.manifest
	return blob && typeof blob === 'object' ? blob : {}
}

/**
 * The manifest the designer edits and previews: the base with the stored delta
 * merged over it, and the delta paths that no longer match the base.
 *
 * @param {object} base The editing base.
 * @param {object|null} delta The version's `manifestDelta`.
 * @return {{manifest: object, orphanedDeltaPaths: string[]}}
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.2
 */
export function editableManifest(base, delta) {
	const { manifest, orphanedDeltaPaths } = mergeManifestDelta(base, delta && typeof delta === 'object' ? delta : {})
	return { manifest, orphanedDeltaPaths: Array.isArray(orphanedDeltaPaths) ? orphanedDeltaPaths : [] }
}

/**
 * The minimal delta a save stores: what the edited manifest changes on the base.
 *
 * @param {object} base The editing base.
 * @param {object} edited The edited manifest.
 * @return {object} The delta (empty when nothing changed).
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
export function deltaToSave(base, edited) {
	return diffManifest(base, edited) || {}
}
