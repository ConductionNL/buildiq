// SPDX-License-Identifier: EUPL-1.2
//
// v2 component registry for OpenBuilt — passed as the `registry` prop on
// CnAppRoot. Each entry declares its `kind` so CnAppRoot can validate and
// dispatch components correctly (REQ-MVR-002).
//
// Recognised kinds (RegistryKindError.js):
//   widget       — renderable in page slots (body, sidebar, tab:*, section:*)
//   modal        — openable via cnOpenModal(key, props)
//   page         — full custom page component (for type:"custom" pages)
//   form-field   — field type injected into CnFormPage
//   cell-renderer — column formatter in CnIndexPage tables
//
// All `type: "custom"` pages in manifest.json reference their component via
// `pages[].component`; those components are registered here as kind: "page".
//
// Custom tabs on VirtualAppDetail (ApplicationManifestTab, ApplicationVersionsTab,
// ApplicationDiffTab, ApplicationIconTab) are referenced via
// config.sidebarTabs[].component — those remain resolved through
// customComponents (carry-forward prop) until a dedicated "sidebar-tab" kind
// lands in the lib. They are NOT in this registry.
//
// Custom cards and header/actions components are resolved via customComponents
// too — they are pass-through component references in config.cardComponent,
// pages[].headerComponent, and pages[].actionsComponent.
//
// See ADR-024 (app manifest) and ADR-036 (manifest v2).

// ── Custom page components (kind: "page") ────────────────────────────────────

// Visual schema designer — three-pane canvas with drag-and-drop field
// placement. Handles both /schemas (shortcut) and /builder/:slug/schemas[/:id].
import SchemaDesignerView from './views/SchemaDesigner.vue'

// Template gallery — browse seeded ApplicationTemplate records and clone
// one into a new virtual app (openbuilt-templates-marketplace).

// Visual manifest page designer — three-pane editor that reads and writes
// a virtual app's manifest via PATCH (REQ-OBPD-003).
import PageDesignerView from './views/PageDesignerHost.vue'

// Export-jobs status list — Phase-2 "export to real Nextcloud app" runs.

// Virtual-app host — nested CnAppRoot rendering a virtual app's own manifest.
import BuilderHostView from './views/BuilderHost.vue'

// Features & Roadmap page — wrapper around CnFeaturesAndRoadmapView.

// ── Widget components (kind: "widget") ───────────────────────────────────────

// (No consumer-registered widget kinds in the initial v2 migration.
//  The dashboard stats-block widgets use the lib's built-in "stats-block"
//  widget key and do not need a registry entry here.)

// ── Modal components (kind: "modal") ─────────────────────────────────────────

// (No modal entries for the initial v2 migration.
//  The CreateApplicationWizard and PermissionsModal are opened imperatively
//  by VirtualAppsActions / ApplicationDetailActions components rather than
//  via cnOpenModal(); they will be registered here when those components
//  are refactored to use the modal registry pattern.)

// ── Registry export ──────────────────────────────────────────────────────────

export default {
	// Custom page components — resolved by CnPageRenderer for type:"custom" pages.
	SchemaDesignerView: {
		kind: 'page',
		component: SchemaDesignerView,
	},
	PageDesignerView: {
		kind: 'page',
		component: PageDesignerView,
	},
	BuilderHostView: {
		kind: 'page',
		component: BuilderHostView,
	},
}
