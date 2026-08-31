// SPDX-License-Identifier: EUPL-1.2
/**
 * manifestDependencies — shared helpers to auto-manage the manifest v2
 * `dependencies[]` array on save. Used by the workflow-attachment feature
 * (REQ-PWA-006) and the connector data-source feature (REQ-OCAS-005) and
 * their sibling runtime-block features; the helpers are deliberately generic
 * (`appId` parameter) so each feature reuses the same add/remove logic
 * instead of re-implementing it.
 *
 * Strategy: a dependency is auto-ADDED when ≥1 binding for the app exists,
 * and auto-REMOVED when the last binding is gone — but ONLY entries this
 * layer added are tracked for removal, via a non-enumerable marker on the
 * manifest (`_buildiqAutoDeps`). A dependency a builder added manually (or
 * that predates this layer) is never silently removed.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-5.1
 */

const MARKER = '_buildiqAutoDeps'

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
 * Whether the manifest declares at least one document attachment.
 *
 * @param {object} manifest - the manifest.
 * @return {boolean}
 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-005
 */
export function hasDocumentAttachment(manifest) {
	const documents = manifest && manifest.runtime && manifest.runtime.documents
	return Array.isArray(documents) && documents.length > 0
}

/**
 * Whether the manifest has at least one page/widget connector binding.
 *
 * @param {object} manifest - the manifest.
 * @return {boolean}
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
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
		return (
			Array.isArray(cfg.widgets)
			&& cfg.widgets.some((w) => w && w.dataSource && w.dataSource.connector)
		)
	})
}

/**
 * Ensure `appId` is present in `dependencies[]` exactly once, recording that
 * this layer added it so it can be auto-removed later.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @param {string} appId - the dependency app id, e.g. `procest` or `openconnector`.
 * @return {object} - the manifest.
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
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
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
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
 * Reconcile the `docudesk` dependency against the manifest's document
 * attachments: add when ≥1 attachment, auto-remove when none remain.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @return {object} - the manifest.
 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-005
 */
export function reconcileDocumentDependency(manifest) {
	if (hasDocumentAttachment(manifest)) {
		return ensureDependency(manifest, 'filinq')
	}
	return removeAutoDependency(manifest, 'filinq')
}

/**
 * Reconcile the `openconnector` dependency against the manifest's connector
 * bindings: add when ≥1 binding, auto-remove when none remain.
 *
 * @param {object} manifest - the manifest (mutated and returned).
 * @return {object} - the manifest.
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
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
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-006
 * @spec openspec/changes/openconnector-api-sources/specs/openconnector-api-sources/spec.md#req-ocas-005
 */
export function stripDependencyMarker(manifest) {
	if (manifest && manifest[MARKER] !== undefined) {
		delete manifest[MARKER]
	}
	return manifest
}
