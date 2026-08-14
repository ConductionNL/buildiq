/**
 * OpenBuild v2 component registry (ADR-036).
 *
 * Kind-tagged map passed as the `registry` prop to CnAppRoot. CnPageRenderer
 * resolves every manifest-referenced component name (type:"custom" pages,
 * cardComponent, headerComponent, actionsComponent, sidebarTabs[].component)
 * against this map. Only entries with `kind === "page"` are used for page and
 * slot-override dispatch — the `kind` field is the discriminator CnPageRenderer
 * keys on (see `resolveCustomComponent` in CnPageRenderer.vue). Future entry
 * kinds (`"modal"`, `"widget"`, `"form-field"`, `"cell-renderer"`) will be
 * added here as the library ships support for them.
 *
 * Replace the deprecated `customComponents` prop: all components previously
 * passed through `customComponents` are now registered here with
 * `kind: "page"` so CnPageRenderer resolves them through a single v2 path and
 * CnAppRoot stops emitting the "customComponents is deprecated" console warning.
 *
 * Resolution order at runtime (CnPageRenderer):
 *   1. Built-in page types          (CnIndexPage, CnDetailPage, CnDashboardPage, …)
 *   2. Built-in widget types        (data, metadata, audit-trail, version-info, …)
 *   3. registry (this file)         ← all consumer-injected components (ADR-036)
 *
 * See ADR-024 (app manifest) and ADR-036 (manifest v2 kind-tagged registry).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// ── Virtual apps — index card ─────────────────────────────────────────────────

import AppDeleteDialogSlot from './components/AppDeleteDialogSlot.vue'
// VirtualApps index card — name, status pill, version, "live" marker, caller's
// role; click navigates to VirtualAppDetail.
import ApplicationCard from './components/ApplicationCard.vue'
// ── Virtual apps — detail sidebar tabs ───────────────────────────────────────
// VirtualAppDetail before-body dashboard — KPI grid + activity chart +
// structural widgets, rendered in CnDetailPage's #before-body slot (in the page
// body, below the action-menu line, above the auto Data/Related sections).
import ApplicationDetailDashboard from './components/applicationDetail/ApplicationDetailDashboard.vue'
// VirtualAppDetail headerComponent (openbuild-app-detail-overview
// REQ-OBADO-001 / REQ-OBADO-011) — identity + controls header: hero strip +
// version pill tabs + window toggle. The analytics (KPI grid, activity chart,
// structural widgets) live in the body dashboard below (grid-built page).
import ApplicationDetailHeader from './components/applicationDetail/ApplicationDetailHeader.vue'
// VirtualAppDetail actions bar — Publish (OR lifecycle transition), Manage
// permissions (PermissionsModal, ADR-004 modal isolation), Design pages, Open
// virtual app.
import ApplicationDetailActions from './components/ApplicationDetailActions.vue'
// VirtualAppDetail sidebar tab: manifest diff between versions.
import ApplicationDiffTab from './components/tabs/ApplicationDiffTab.vue'
// ── Virtual apps — actions components ────────────────────────────────────────
// VirtualAppDetail sidebar tab: icon upload and preview.
import ApplicationIconTab from './components/tabs/ApplicationIconTab.vue'
// VirtualAppDetail sidebar tab: raw-JSON manifest editor (the visual designer
// lives at /builder/:slug/pages).
import ApplicationManifestTab from './components/tabs/ApplicationManifestTab.vue'
// ── Virtual apps — detail header ──────────────────────────────────────────────
// VirtualAppDetail sidebar tab: version history + rollback.
import ApplicationVersionsTab from './components/tabs/ApplicationVersionsTab.vue'
// Export jobs tab — wraps ExportJobsList as the "Exports" sidebar tab on the
// VirtualAppDetail page (spec openbuild-exporter task 9.2).
import ExportJobsTab from './components/tabs/ExportJobsTab.vue'
// VirtualApps index actions bar — "Add application" button that opens the
// four-step CreateApplicationWizard (openbuild-app-creation-wizard).
import VirtualAppsActions from './components/VirtualAppsActions.vue'
// ── Custom page components (kind: "page") ────────────────────────────────────
// Agent workspace — named, tool-scoped AI agents reusing the ai-copilot
// plan/execute engine + CopilotPanel chat UX, with a transparent per-run
// tool-call log (spec agent-workspace).
import AgentsPageView from './views/AgentsPage.vue'
// Automation designer — the unified "when X happens, do Y" surface composing
// trigger + optional condition + actions, compiled to the existing
// notifications/lifecycle/schedules/rules-engine primitives (spec
// automation-designer).
import AutomationsPageView from './views/AutomationsPage.vue'
// Virtual-app host — nested CnAppRoot rendering a virtual app's own manifest.
import BuilderHostView from './views/BuilderHost.vue'
// DashboardIndex — custom dashboard view (KPI cards + Recent apps + Quick start),
// modelled on the DocuDesk dashboard pattern; mounted via the type:"custom"
// Dashboard manifest page to avoid the dashboard-in-dashboard antipattern.
import DashboardIndex from './views/DashboardIndex.vue'
// ManifestLayersDetail — routed Manifest detail page (delta layers + per-layer
// OR version history + rollback), reached from the app-detail Manifest widget
// (layered-versioned-app-deltas).
import ManifestLayersDetail from './views/ManifestLayersDetail.vue'
// Visual manifest page designer — three-pane editor that reads and writes a
// virtual app's manifest via PATCH (REQ-OBPD-003).
import PageDesignerView from './views/PageDesignerHost.vue'
// ── Dashboard widgets (kind: "widget") ───────────────────────────────────────
//
// These widgetKeys are referenced by the dashboard manifest (slot "body" /
// "sidebar") but are NOT part of CnWidgetGrid's built-in registry
// (object-table, form-renderer, map-viewer, card-grid, data, metadata,
// integration), so the consuming app must register them. CnWidgetGrid resolves
// a widgetKey against this registry before falling back to its built-ins.
//   - audit-trail: recent audit entries for the object (detail sidebar).
// Business-rules engine dashboard — lists RuleSets, opens the decision-table /
// condition-action editors and the test sandbox (spec business-rules-engine).
import RuleSetsPageView from './views/RuleSetsPage.vue'
// Visual schema designer — three-pane canvas with drag-and-drop field
// placement. Handles both /schemas (shortcut) and /builder/:slug/schemas[/:id].
import SchemaDesignerView from './views/SchemaDesigner.vue'
// TemplateGallery — the Templates page as a store-aware gallery: remote store
// search (when a registry is configured) primary + built-in local templates,
// install via CloneTemplateDialog (openbuild-remote-template-store).
import TemplateGalleryView from './views/TemplateGallery.vue'
// Visual walkthrough designer — form-based editor for the manifest `walkthrough`
// block (ADR-043); persists onto the active ApplicationVersion like PageDesigner.
import WalkthroughDesignerView from './views/WalkthroughDesignerHost.vue'

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Wrap a Vue component into the v2 registry shape required by CnAppRoot's
 * `registry` prop.
 *
 * `kind: "page"` is the discriminator CnPageRenderer keys page and
 * slot-override dispatch off (ADR-036 `resolveCustomComponent`).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "page", component }` registry entry.
 */
function page(component) {
	return { kind: 'page', component }
}

/**
 * Wrap a sidebar tab component into the v2 registry shape.
 *
 * Sidebar tab components are resolved via the slot-override path of
 * `resolveCustomComponent` which accepts any `kind` value as long as a
 * `component` field is present (ADR-036 kind-agnostic slot resolver).
 * Using `kind: "tab"` instead of the generic `"page"` makes the manifest's
 * intent explicit and avoids false-positive page-dispatch matches (M4 fix).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "tab", component }` registry entry.
 */
function tab(component) {
	return { kind: 'tab', component }
}

/**
 * Wrap a page header component into the v2 registry shape.
 *
 * Header components are resolved via the slot-override path which accepts any
 * `kind` (ADR-036 kind-agnostic slot resolver). Using `kind: "header"` makes
 * the intent clear and prevents accidental page-dispatch (M4 fix).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "header", component }` registry entry.
 */
function header(component) {
	return { kind: 'header', component }
}

/**
 * Wrap an actions-bar component into the v2 registry shape.
 *
 * Actions components are resolved via the slot-override path which accepts any
 * `kind` (ADR-036 kind-agnostic slot resolver). Using `kind: "actions"` makes
 * the intent clear and prevents accidental page-dispatch (M4 fix).
 *
 * @param {object} component Vue component options.
 *
 * @return {object} A `{ kind: "actions", component }` registry entry.
 */
function actions(component) {
	return { kind: 'actions', component }
}

/**
 * Wrap a dialog component that fills a named page slot (e.g. CnIndexPage's
 * `#delete-dialog`, referenced from the manifest as
 * `page.slots["delete-dialog"]`).
 *
 * Resolved via the slot-override path, which accepts any `kind` as long as a
 * `component` is present (ADR-036 kind-agnostic slot resolver). `kind: "modal"`
 * states the intent and keeps the entry out of page dispatch; `propsSchema` is
 * the metadata field CnAppRoot's registry validator expects for that kind.
 *
 * @param {object} component Vue component options.
 * @param {object} [propsSchema] Slot-binding props the dialog receives.
 *
 * @return {object} A `{ kind: "modal", component, propsSchema }` registry entry.
 */
function modal(component, propsSchema = {}) {
	return { kind: 'modal', component, propsSchema }
}

// ── Registry export ──────────────────────────────────────────────────────────

export default {
	// VirtualApps index card component (kind "page" — resolved as a card slot).
	ApplicationCard: page(ApplicationCard),

	// Fills CnIndexPage's `#delete-dialog` slot on the VirtualApps index
	// (manifest `page.slots["delete-dialog"]`). Lives here rather than in
	// App.vue's legacy `customComponents` map so the manifest has ONE
	// registration path — CnAppRoot warns that customComponents is deprecated,
	// and the split let the manifest reference a name the registry never knew.
	AppDeleteDialogSlot: modal(AppDeleteDialogSlot, {
		item: {
			type: 'object',
			description: 'The row targeted for deletion; null when closed.',
		},
		close: { type: 'function', description: 'Closes the delete dialog.' },
	}),

	// VirtualAppDetail sidebar tabs (kind "tab" — resolved via slot-override path).
	ApplicationManifestTab: tab(ApplicationManifestTab),
	ApplicationVersionsTab: tab(ApplicationVersionsTab),
	ApplicationDiffTab: tab(ApplicationDiffTab),
	ApplicationIconTab: tab(ApplicationIconTab),

	// Actions bar components (kind "actions" — resolved via slot-override path).
	VirtualAppsActions: actions(VirtualAppsActions),
	ApplicationDetailActions: actions(ApplicationDetailActions),

	// Header component for the maintainer dashboard (kind "header" — slot-override).
	ApplicationDetailHeader: header(ApplicationDetailHeader),
	ApplicationDetailDashboard: page(ApplicationDetailDashboard),
	// Routed Manifest detail page (type custom; layered-versioned-app-deltas).
	ApplicationManifestDetail: page(ManifestLayersDetail),

	// Custom page components — resolved by CnPageRenderer for type:"custom" pages.
	SchemaDesignerView: page(SchemaDesignerView),
	PageDesignerView: page(PageDesignerView),
	WalkthroughDesignerView: page(WalkthroughDesignerView),
	BuilderHostView: page(BuilderHostView),
	DashboardIndex: page(DashboardIndex),
	TemplateGallery: page(TemplateGalleryView),

	// NOTE: no `audit-trail` entry. VirtualAppDetail's `audit` sidebar tab
	// declares no `component`, so it resolves to nc-vue's built-in audit tab —
	// the app-level registration this used to carry has been dead since that
	// normalisation. Re-register only if a manifest names `audit-trail` again.

	// Business-rules engine dashboard (type:"custom" page).
	RuleSetsPageView: page(RuleSetsPageView),

	// Automation designer dashboard (type:"custom" page).
	AutomationsPageView: page(AutomationsPageView),

	// Agent workspace dashboard (type:"custom" page).
	AgentsPageView: page(AgentsPageView),

	// Export jobs sidebar tab on VirtualAppDetail (spec openbuild-exporter task 9.2).
	ExportJobsTab: tab(ExportJobsTab),
}
