// SPDX-License-Identifier: EUPL-1.2
//
// Custom-component registry for OpenBuilt's manifest-driven app shell.
//
// OpenBuilt's own pages are tooling UIs (a dashboard, the virtual-app
// manager, the schema designer, the export-jobs list, the virtual-app
// host) rather than generic register CRUD, so every page declares
// `type: "custom"` in src/manifest.json and resolves its view here.
// Keep this file SHORT — adding entries should be a deliberate decision;
// removing them (by moving a page onto a built-in manifest type) is the
// right direction.
//
// Resolution order at runtime (CnPageRenderer):
//   1. Built-in page types          (CnIndexPage, CnDetailPage, …)
//   2. Built-in widget types        (version-info, register-mapping, …)
//   3. customComponents (this file) ← consumer-injected components
//
// See ADR-024 (app manifest) and docs/migrating-to-manifest.md in
// @conduction/nextcloud-vue.

import DashboardView from './views/Dashboard.vue'
import ApplicationsView from './views/ApplicationEditor.vue'
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
	// Starter dashboard — sample KPIs / activity placeholders.
	DashboardView,
	// Virtual-app manager — list + detail + Editor/History/Diff tabs,
	// raw-JSON manifest editor, publish, RBAC permissions modal, export.
	ApplicationsView,
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
