// SPDX-License-Identifier: EUPL-1.2
/**
 * deltaEditing — the page designer on a version that extends a base
 * (app-delta-override D6).
 *
 *  - A version with a `baseRef` stores only its `manifestDelta`; the server
 *    serves that delta merged over the live base.
 *  - The designer asks the server for the base, edits the merged manifest, and
 *    saves `diffManifest(base, edited)`, the minimal delta. Its preview shows
 *    the edited manifest, which `mergeManifestDelta(base, delta)` reproduces,
 *    so the preview equals what the server will serve.
 *  - The delta paths that target nothing in the base are shown as a warning;
 *    they never stop a save.
 *
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */

import { diffManifest, mergeManifestDelta } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Whether a version extends a base, so the designer saves a delta.
 *
 * @param {object|null} version The ApplicationVersion.
 * @return {boolean} True when it carries a `baseRef` with a kind and an id.
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
export function isDeltaVersion(version) {
	const ref = version && version.baseRef
	return Boolean(ref && typeof ref === 'object' && ref.kind && ref.id)
}

/**
 * The stored delta of a version, as an object.
 *
 * @param {object|null} version The ApplicationVersion.
 * @return {object} The delta, or {} when it has none.
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
export function deltaOf(version) {
	const delta = version && version.manifestDelta
	return delta && typeof delta === 'object' && !Array.isArray(delta) ? delta : {}
}

/**
 * Ask the server for the base a version extends.
 *
 * @param {string} appSlug The app slug.
 * @param {string} versionSlug The version slug, or '' for production.
 * @return {Promise<{base: object|null, orphanedDeltaPaths: string[]}>} The base, or no base.
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
export async function fetchDeltaBase(appSlug, versionSlug) {
	const url = generateUrl(`/apps/buildiq/api/applications/${encodeURIComponent(appSlug)}/manifest-base`)
	const params = versionSlug ? { _version: versionSlug } : {}
	const { data } = await axios.get(url, { params })
	const base = data && data.base && typeof data.base === 'object' ? data.base : null
	const orphans = data && Array.isArray(data.orphanedDeltaPaths) ? data.orphanedDeltaPaths : []
	return { base, orphanedDeltaPaths: orphans }
}

/**
 * The manifest the designer edits: the delta merged over the base.
 *
 * @param {object} base The base manifest.
 * @param {object} delta The stored delta.
 * @return {object} The merged manifest.
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.2
 */
export function editorManifest(base, delta) {
	return mergeManifestDelta(base, delta).manifest
}

/**
 * The delta to store for an edited manifest: only its differences from the base.
 *
 * @param {object} base The base manifest.
 * @param {object} edited The edited manifest.
 * @return {object} The minimal keyed delta.
 * @spec openspec/changes/app-delta-override/tasks.md#task-5.1
 */
export function deltaToSave(base, edited) {
	return diffManifest(base, edited)
}
