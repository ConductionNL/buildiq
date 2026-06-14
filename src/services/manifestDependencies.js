// SPDX-License-Identifier: EUPL-1.2
/**
 * manifestDependencies — shared helpers to auto-manage the manifest v2
 * `dependencies[]` array on save. Used by the workflow-attachment feature
 * (REQ-PWA-006) and its sibling runtime-block features; the helpers are
 * deliberately generic (`appId` parameter) so each feature reuses the same
 * add/remove logic instead of re-implementing it.
 *
 * Strategy: a dependency is auto-ADDED when ≥1 binding for the app exists,
 * and auto-REMOVED when the last binding is gone — but ONLY entries this
 * layer added are tracked for removal, via a non-enumerable marker on the
 * manifest (`_openbuildAutoDeps`). A dependency a builder added manually (or
 * that predates this layer) is never silently removed.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 */

const MARKER = '_openbuildAutoDeps'

/**
 * Whether the manifest declares at least one workflow attachment.
 *
 * @param {object} manifest - the manifest.
 * @return {boolean}
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 */
export function hasWorkflowAttachment(manifest) {
	const workflows = manifest && manifest.runtime && manifest.runtime.workflows
	return Array.isArray(workflows) && workflows.length > 0
}

/**
 * Ensure `appId` is present in `dependencies[]` exactly once, recording that
 * this layer added it so it can be auto-removed later.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @param {string} appId - the dependency app id, e.g. `procest`.
 * @return {object} - the manifest.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
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
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
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
 * Reconcile the `procest` dependency against the manifest's workflow
 * attachments: add when ≥1 attachment, auto-remove when none remain.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @return {object} - the manifest.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 */
export function reconcileWorkflowDependency(manifest) {
	if (hasWorkflowAttachment(manifest)) {
		return ensureDependency(manifest, 'procest')
	}
	return removeAutoDependency(manifest, 'procest')
}

/**
 * Strip the non-spec marker before serialization so it never lands in the
 * persisted manifest.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @return {object} - the manifest.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 */
export function stripDependencyMarker(manifest) {
	if (manifest && manifest[MARKER] !== undefined) {
		delete manifest[MARKER]
	}
	return manifest
}
