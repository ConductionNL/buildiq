# Tasks: pages-detail-related-items

- [x] **T01**: Add `fields/RelatedCollectionBuilder.vue` and mount it as the Related items section of `DetailPageEditor.vue`, writing `config.relatedCollections` with the link-field filter of design D1 and the optional columns and limit of D2 (REQ-BQRI-001). Verify: vitest `tests/components/page-editor/RelatedCollectionBuilder.spec.js`, a case that picks schema `requests` and link field `client` and asserts `filter: { client: '@objectId' }`, and a case that leaves limit out at the default.
- [x] **T02**: Add `fields/RelationLinkBuilder.vue` to the same section, writing `config.relationLinks` (REQ-BQRI-002). Verify: vitest for the saved entry and the inline mark on a missing `fkField`.
- [x] **T03**: Files checkbox in the Sidebar section writing `config.sidebar.hiddenTabs`, with the boolean-to-object conversion of design D4 (REQ-BQRI-003). Verify: vitest for both sidebar shapes.
- [x] **T04**: Playwright `tests/e2e/page-detail-related.spec.ts`: a maker adds "Requests of this client" to the client detail page and hides files, publishes, and an app user sees only that client's requests and no files tab. Written 17 Nov (register, schemas, clients and requests seeded through the OpenRegister API; the maker works in the page designer as the admin; the app user is `rbac-viewer`); lint-clean and listed, not run yet: needs a live instance.
  > Written, runs in nightly (24 Nov 2026): the spec is in the repo and runs against the CI and nightly instance; it has not been verified against a live instance from this lane.
- [x] **T05**: English and Dutch strings for both builders and the checkbox; update `docs/` on detail pages.
- [x] **T06**: Run `openspec validate pages-detail-related-items --strict`.
