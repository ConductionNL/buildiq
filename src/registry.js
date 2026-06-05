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

// VirtualApps index card — name, status pill, version, "live" marker, caller's
// role; click navigates to VirtualAppDetail.
import ApplicationCard from './components/ApplicationCard.vue'

// ── Virtual apps — detail sidebar tabs ───────────────────────────────────────

// VirtualAppDetail sidebar tab: raw-JSON manifest editor (the visual designer
// lives at /builder/:slug/pages).
import ApplicationManifestTab from './components/tabs/ApplicationManifestTab.vue'

// VirtualAppDetail sidebar tab: version history + rollback.
import ApplicationVersionsTab from './components/tabs/ApplicationVersionsTab.vue'

// VirtualAppDetail sidebar tab: manifest diff between versions.
import ApplicationDiffTab from './components/tabs/ApplicationDiffTab.vue'

// VirtualAppDetail sidebar tab: icon upload and preview.
import ApplicationIconTab from './components/tabs/ApplicationIconTab.vue'

// ── Virtual apps — actions components ────────────────────────────────────────

// VirtualApps index actions bar — "Add application" button that opens the
// four-step CreateApplicationWizard (openbuild-app-creation-wizard).
import VirtualAppsActions from './components/VirtualAppsActions.vue'

// VirtualAppDetail actions bar — Publish (OR lifecycle transition), Manage
// permissions (PermissionsModal, ADR-004 modal isolation), Design pages, Open
// virtual app.
import ApplicationDetailActions from './components/ApplicationDetailActions.vue'

// ── Virtual apps — detail header ──────────────────────────────────────────────

// VirtualAppDetail headerComponent (openbuild-app-detail-overview
// REQ-OBADO-001 / REQ-OBADO-011) — purpose-built maintainer dashboard
// replacing the generic main-area data widget. Owns hero strip + version pill
// tabs + window toggle + KPI grid + activity chart + structural widgets.
import ApplicationDetailHeader from './components/applicationDetail/ApplicationDetailHeader.vue'

// ── Custom page components (kind: "page") ────────────────────────────────────

// Visual schema designer — three-pane canvas with drag-and-drop field
// placement. Handles both /schemas (shortcut) and /builder/:slug/schemas[/:id].
import SchemaDesignerView from './views/SchemaDesigner.vue'

// Visual manifest page designer — three-pane editor that reads and writes a
// virtual app's manifest via PATCH (REQ-OBPD-003).
import PageDesignerView from './views/PageDesignerHost.vue'

// Virtual-app host — nested CnAppRoot rendering a virtual app's own manifest.
import BuilderHostView from './views/BuilderHost.vue'

// Business-rules engine dashboard — lists RuleSets, opens the decision-table /
// condition-action editors and the test sandbox (spec business-rules-engine).
import RuleSetsPageView from './views/RuleSetsPage.vue'

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

// ── Registry export ──────────────────────────────────────────────────────────

export default {
	// VirtualApps index card component (kind "page" — resolved as a card slot).
	ApplicationCard: page(ApplicationCard),

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

	// Custom page components — resolved by CnPageRenderer for type:"custom" pages.
	SchemaDesignerView: page(SchemaDesignerView),
	PageDesignerView: page(PageDesignerView),
	BuilderHostView: page(BuilderHostView),

	// Business-rules engine dashboard (type:"custom" page).
	RuleSetsPageView: page(RuleSetsPageView),
}
