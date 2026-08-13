// SPDX-License-Identifier: EUPL-1.2
/**
 * Runtime registry — components that BUILT (virtual) apps resolve at runtime,
 * passed as the `registry` prop to the NESTED CnAppRoot in BuilderHost.
 *
 * Kept separate from the shell `registry.js` (which the manifest test guards
 * as "every entry referenced by openbuild's own manifest") because these
 * entries are referenced by VIRTUAL-app manifests, not openbuild's own shell.
 * Self-contained (no import from `registry.js`) to avoid the
 * registry → BuilderHost → runtimeRegistry import cycle.
 *
 * @spec openspec/changes/procest-workflow-attachments/specs/procest-workflow-attachments/spec.md#req-pwa-004
 * @spec openspec/changes/openconnector-api-sources/tasks.md#task-4.3
 * @spec openspec/changes/docudesk-document-templates/specs/docudesk-document-templates/spec.md#req-ddt-004
 */

// Runtime panel — renders the linked Procest case for the current object on a
// detail page (sidebar tab `procest-case-status`).
import ProcestCaseStatusPanel from './components/runtime/ProcestCaseStatusPanel.vue'

// Runtime widget — renders an OpenConnector-bound data source for a manifest
// page/widget that declares `dataSource.connector` (REQ-OCAS-006).
import ConnectorDataView from './components/runtime/ConnectorDataView.vue'

// Runtime surface — generate-and-download buttons for the Docudesk document
// templates attached to the current object's schema (sidebar tab / detail
// action group `docudesk-document-actions`, REQ-DDT-004).
import DocumentActions from './components/runtime/DocumentActions.vue'

// Runtime widget — "My approvals" (automation-approval-steps task 4.1):
// lists the viewer's pending OpenRegister ApprovalSteps with approve/reject
// actions calling OR's REST API directly.
import MyApprovalsWidget from './components/runtime/MyApprovalsWidget.vue'

// Runtime action — Detail-page `actionsComponent` letting staff mint a
// "track your case" link for the object they are viewing
// (external-form-provisioning REQ-EFP-006). Owner-context only; self-gates
// on the BUILT app's own `runtime.externalForms[]` via the `cnManifest`
// injection CnAppRoot provides, rendering nothing when not enabled for the
// object's schema.
import TrackLinkAction from './components/runtime/TrackLinkAction.vue'

/**
 * Build a slot-override registry entry (CnPageRenderer resolves any `kind`
 * with a `component` via the slot-override path; `kind: "tab"` makes the
 * intent explicit for a sidebar-tab widget).
 *
 * @param {object} component - Vue component options.
 * @return {object} - the registry entry.
 */
function tab(component) {
	return { kind: 'tab', component }
}

/**
 * Build a `kind: "widget"` registry entry (mirrors the shape `registry.js`'s
 * `widget()` helper produces).
 *
 * @param {object} component - Vue component options.
 * @param {string[]} allowedSlots - manifest slots this widget may occupy.
 * @param {string} [note] - ADR-049 `_note` justifying a custom widget when no
 *   built-in type expresses the surface (gate-29 custom-widget-ratchet).
 * @return {object} - the registry entry.
 */
function widget(component, allowedSlots, note) {
	return {
		kind: 'widget',
		component,
		defaultSize: { w: 3, h: 2 },
		minSize: { w: 1, h: 1 },
		maxSize: { w: 12, h: 8 },
		allowedSlots,
		propsSchema: {},
		...(note ? { _note: note } : {}),
	}
}

/**
 * Build a `kind: "actions"` registry entry (Detail-page `config.
 * actionsComponent` slot-override — resolved the same way as `tab()`, any
 * `kind` is accepted by the slot-override path; `"actions"` states intent).
 *
 * @param {object} component - Vue component options.
 * @return {object} - the registry entry.
 */
function actions(component) {
	return { kind: 'actions', component }
}

export const runtimeRegistry = {
	// Sidebar-tab widgetKey for a detail page's `sidebarProps.tabs`.
	'procest-case-status': tab(ProcestCaseStatusPanel),
	// Manifest widgetKey "connector-data" for a connector-bound page/widget.
	'connector-data': widget(ConnectorDataView, ['body', 'sidebar']),
	// Sidebar-tab / detail action group for Docudesk document generation.
	'docudesk-document-actions': tab(DocumentActions),
	// @custom-widget-ratchet exclude automation-approval-steps: "My approvals"
	// calls OR's dedicated /api/approval-steps{,/approve,/reject} endpoints
	// directly with client-side group filtering + per-row approve/reject
	// actions — no built-in object-table/stats-block widget can express a
	// non-object-service REST action target, so no built-in fits (ADR-049
	// Decision 1).
	'my-approvals': widget(
		MyApprovalsWidget,
		['body', 'sidebar'],
		"Approve/reject calls target OpenRegister's dedicated approval-steps endpoints directly (not the generic object-service API a built-in object-table row action can express) — no built-in widget fits.",
	),
	// Detail-page `config.actionsComponent: "TrackLinkAction"` — mint a
	// "track your case" link (external-form-provisioning REQ-EFP-006).
	TrackLinkAction: actions(TrackLinkAction),
}
