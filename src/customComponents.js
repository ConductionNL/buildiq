// SPDX-License-Identifier: EUPL-1.2
//
// Custom-component registry for OpenBuilt's manifest-driven app shell.
//
// Most of OpenBuilt's surfaces are now built-in manifest page types — the
// Dashboard is `type: "dashboard"` and the Virtual apps list/detail are
// `type: "index"` / `type: "detail"` (with custom cards, sidebar tabs and an
// actions bar resolved from this registry). The remaining `type: "custom"`
// pages are the tooling UIs that have no generic CRUD equivalent (schema
// designer, page designer, export-jobs list, virtual-app host).
// Keep this file SHORT — adding entries should be a deliberate decision;
// moving a page onto a built-in manifest type is the right direction.
//
// Resolution order at runtime (CnPageRenderer):
//   1. Built-in page types          (CnIndexPage, CnDetailPage, CnDashboardPage, …)
//   2. Built-in widget types        (data, metadata, audit-trail, version-info, …)
//   3. customComponents (this file) ← consumer-injected components
//      (also resolves `pages[].config.cardComponent`, `…sidebarTabs[].component`
//       and `…actionsComponent`).
//
// See ADR-024 (app manifest) and docs/migrating-to-manifest.md in
// @conduction/nextcloud-vue.

// Virtual apps — index card + detail sidebar tabs + detail actions.
import ApplicationCard from './components/ApplicationCard.vue'
import ApplicationManifestTab from './components/tabs/ApplicationManifestTab.vue'
import ApplicationVersionsTab from './components/tabs/ApplicationVersionsTab.vue'
import ApplicationDiffTab from './components/tabs/ApplicationDiffTab.vue'
import ApplicationIconTab from './components/tabs/ApplicationIconTab.vue'
import ApplicationDetailActions from './components/ApplicationDetailActions.vue'
// Maintainer-dashboard main area for VirtualAppDetail
// (openbuilt-app-detail-overview REQ-OBADO-001) — replaces the
// generic CnDetailPage data widget. Owns hero strip + version pill
// tabs + window toggle + KPI grid + activity chart + structural
// widgets. Registered as the page entry's `headerComponent` in
// src/manifest.json.
import ApplicationDetailHeader from './components/applicationDetail/ApplicationDetailHeader.vue'
// Virtual apps index "Add application" actions bar — opens the four-step
// creation wizard (openbuilt-app-creation-wizard REQ-OBWIZ-001).
// Referenced by the VirtualApps page's config.actionsComponent.
import VirtualAppsActions from './components/VirtualAppsActions.vue'
// Tooling pages that stay `type: "custom"`.
import SchemaDesignerView from './views/SchemaDesigner.vue'
import PageDesignerView from './views/PageDesignerHost.vue'
import ExportJobsView from './views/ExportJobsList.vue'
import BuilderHostView from './views/BuilderHost.vue'
import TemplateGalleryView from './views/TemplateGallery.vue'
// Features & Roadmap page — thin wrapper around the lib's
// CnFeaturesAndRoadmapView (in-product roadmap surface powered by
// OpenRegister's github-issue-proxy). See ConductionNL/hydra#251.
import FeaturesRoadmapView from './views/FeaturesRoadmap.vue'

export default {
	// VirtualApps (`type: index`) card — name, status pill, version, "live"
	// marker, caller's role; click navigates to VirtualAppDetail.
	ApplicationCard,
	// VirtualAppDetail (`type: detail`) sidebar tabs: raw-JSON manifest
	// editor (the visual designer lives at /builder/:slug/pages), version
	// history (+ rollback), the manifest diff, and icon upload/preview.
	ApplicationManifestTab,
	ApplicationVersionsTab,
	ApplicationDiffTab,
	ApplicationIconTab,
	// VirtualApps index actions bar — "Add application" button that opens the
	// four-step CreateApplicationWizard (openbuilt-app-creation-wizard).
	VirtualAppsActions,
	// VirtualAppDetail actions bar — Publish (OR lifecycle transition),
	// Manage permissions (PermissionsModal, ADR-004 modal isolation),
	// Design pages, Open virtual app.
	ApplicationDetailActions,
	// VirtualAppDetail headerComponent (openbuilt-app-detail-overview
	// REQ-OBADO-001 / REQ-OBADO-011) — purpose-built maintainer
	// dashboard replacing the generic main-area data widget.
	ApplicationDetailHeader,
	// Visual schema designer for a virtual app's register
	// (/builder/:slug/schemas[/:schemaId] and the paramless /schemas
	// shortcut, which defaults to the hello-world seed app).
	SchemaDesignerView,
	// Visual manifest page designer for a virtual app
	// (/builder/:slug/pages) — PageDesignerHost loads the app's manifest,
	// hands it to the controlled PageDesigner (three-pane page-list /
	// per-type sub-editor / validator side-panel, REQ-OBPD-003), and
	// persists edits back.
	PageDesignerView,
	// Export-jobs list — status of Phase-2 "export to real app" runs.
	ExportJobsView,
	// Virtual-app host — mounts a nested CnAppRoot rendering the virtual
	// app's manifest from GET /api/applications/{slug}/manifest.
	BuilderHostView,
	// Template gallery — browse seeded ApplicationTemplate records and
	// clone one into a new virtual app (openbuilt-templates-marketplace).
	TemplateGalleryView,
	// Features & Roadmap page (lib's CnFeaturesAndRoadmapView).
	FeaturesRoadmap: FeaturesRoadmapView,
}
