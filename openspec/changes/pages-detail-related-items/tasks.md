# Tasks: pages-detail-related-items

- [ ] **T01**: Add `fields/RelatedCollectionBuilder.vue` and mount it as the Related items section of `DetailPageEditor.vue`, writing `config.relatedCollections` with the link-field filter of design D1 and the optional columns and limit of D2 (REQ-BQRI-001). Verify: vitest `tests/components/page-editor/RelatedCollectionBuilder.spec.js`, a case that picks schema `requests` and link field `client` and asserts `filter: { client: '@objectId' }`, and a case that leaves limit out at the default.
- [ ] **T02**: Add `fields/RelationLinkBuilder.vue` to the same section, writing `config.relationLinks` (REQ-BQRI-002). Verify: vitest for the saved entry and the inline mark on a missing `fkField`.
- [ ] **T03**: Files checkbox in the Sidebar section writing `config.sidebar.hiddenTabs`, with the boolean-to-object conversion of design D4 (REQ-BQRI-003). Verify: vitest for both sidebar shapes.
- [ ] **T04**: Playwright `tests/e2e/page-detail-related.spec.ts`: a maker adds "Requests of this client" to the client detail page and hides files, publishes, and an app user sees only that client's requests and no files tab.
- [ ] **T05**: English and Dutch strings for both builders and the checkbox; update `docs/` on detail pages.
- [ ] **T06**: Run `openspec validate pages-detail-related-items --strict`.
