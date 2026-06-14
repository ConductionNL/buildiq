// SPDX-License-Identifier: EUPL-1.2
/**
 * manifestDependencies — shared helpers to auto-manage the manifest v2
 * `dependencies[]` array on save. Used by the connector data-source feature
 * (REQ-OCAS-005) and its sibling runtime-block features; the helpers are
 * deliberately generic (`appId` parameter) so each feature reuses the same
 * add/remove logic instead of re-implementing it.
 *
 * Strategy (locked during apply for REQ-OCAS-005): the dependency is
 * auto-ADDED when ≥1 binding for the app exists, and auto-REMOVED when the
 * last binding is gone — but ONLY entries this layer added are tracked for
 * removal, via a non-enumerable marker on the manifest
 * (`_openbuildAutoDeps`). A dependency a builder added manually (or that
 * predates this layer) is never silently removed.
 *
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-5.1
 */

const MARKER = '_openbuildAutoDeps'

/**
 * Whether the manifest has at least one page/widget connector binding.
 *
 * @param {object} manifest - the manifest.
 * @return {boolean}
 */
export function hasConnectorBinding(manifest) {
	if (!manifest || !Array.isArray(manifest.pages)) {
		return false
	}
	return manifest.pages.some((page) => {
		const cfg = page && page.config
		if (!cfg) {
			return false
		}
		if (cfg.dataSource && cfg.dataSource.connector) {
			return true
		}
		return Array.isArray(cfg.widgets)
			&& cfg.widgets.some((w) => w && w.dataSource && w.dataSource.connector)
	})
}

/**
 * Ensure `appId` is present in `dependencies[]` exactly once, recording that
 * this layer added it so it can be auto-removed later.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @param {string} appId - the dependency app id, e.g. `openconnector`.
 * @return {object} - the manifest.
 */
export function ensureDependency(manifest, appId) {
	if (!manifest || !appId) {
		return manifest
	}
	if (!Array.isArray(manifest.dependencies)) {
		manifest.dependencies = []
	}
	if (!manifest.dependencies.includes(appId)) {
		manifest.dependencies.push(appId)
		const auto = manifest[MARKER] || []
		if (!auto.includes(appId)) {
			auto.push(appId)
		}
		manifest[MARKER] = auto
	}
	return manifest
}

/**
 * Remove an auto-added dependency when no binding requires it any more.
 * Never removes a dependency this layer did not add.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @param {string} appId - the dependency app id.
 * @return {object} - the manifest.
 */
export function removeAutoDependency(manifest, appId) {
	if (!manifest || !appId || !Array.isArray(manifest.dependencies)) {
		return manifest
	}
	const auto = manifest[MARKER] || []
	if (!auto.includes(appId)) {
		return manifest
	}
	manifest.dependencies = manifest.dependencies.filter((d) => d !== appId)
	manifest[MARKER] = auto.filter((d) => d !== appId)
	return manifest
}

/**
 * Reconcile the `openconnector` dependency against the manifest's connector
 * bindings: add when ≥1 binding, auto-remove when none remain.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @return {object} - the manifest.
 */
export function reconcileConnectorDependency(manifest) {
	if (hasConnectorBinding(manifest)) {
		return ensureDependency(manifest, 'openconnector')
	}
	return removeAutoDependency(manifest, 'openconnector')
}

/**
 * Strip the non-spec marker before serialization so it never lands in the
 * persisted manifest.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @return {object} - the manifest.
 */
export function stripDependencyMarker(manifest) {
	if (manifest && manifest[MARKER] !== undefined) {
		delete manifest[MARKER]
	}
	return manifest
}
