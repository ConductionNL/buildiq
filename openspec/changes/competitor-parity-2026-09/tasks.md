# Tasks: competitor-parity-2026-09

- [ ] 1.1 Build `forms-per-case-type`. Archive it on merge and tick it here.
- [x] 1.2 Build `case-page-layout-per-case-type`. Archive it on merge and tick it here.
  > Archived 25 Nov as `openspec/changes/archive/2026-11-25-case-page-layout-per-case-type` (main spec `openspec/specs/page-layout-per-type`); the CnDetailPage merge is nextcloud-vue `runtime-detail-layout`.
- [x] 2.1 Ask the dossiq lane for the field half of row B16: `order`, `group` and `placeholder` on `propertyDefinition`, in its CT-1 change.
  > Asked 25 Nov: dossiq ConductionNL/dossiq#6918 (competitor-parity-2026-09 task 15, shapes in buildiq-consumer-halves.md), task 15.6.
- [ ] 2.2 Ask the dossiq lane and the openregister lane for the portal flag on a property, which D16 puts on the field and not on the form.
- [x] 2.3 Ask the nextcloud-vue lane for `runtime-detail-layout`, so `CnDetailPage` merges a provider answer over its manifest config.
  > Done 25 Nov: nextcloud-vue ConductionNL/nextcloud-vue#2351 opened the change `runtime-detail-layout` (proposal, design D1-D4, spec delta `detail-page-layout-provider`, tasks): `CnDetailPage.layoutLeaf` reads the leaf for the loaded object and merges `items[0]` over `headerFields`, `sidebarTabs` and the widgets per part, hands `taskList`/`uploadFields` on through `layout-resolved`, and renders the manifest unchanged without an answer. Its code tasks are the nextcloud-vue lane's.
- [x] 2.4 Tell the portaliq lane that `isPublic` and `confirmationText` arrive on the `buildiq-registration-form` leaf, and that cluster 51 keeps the captcha, the address check and the reuse of earlier case data.
  > Told 25 Nov: portaliq ConductionNL/portaliq#2705 (competitor-parity-2026-09 task 17, shapes in buildiq-consumer-halves.md), task 17.1 (captcha, address check and reuse of earlier case data kept as they are).
- [x] 3.1 Tell the dossiq lane that its task list and its upload dialog may read `buildiq-page-layout`, and that both keep their manifest shape when nothing answers.
  > Told 25 Nov: dossiq ConductionNL/dossiq#6918 (competitor-parity-2026-09 task 15, shapes in buildiq-consumer-halves.md), task 15.3.
- [x] 3.2 Record that the consolidated candidates C-tasks-and-phases-33 and C-access-and-privacy-37 are split: buildiq stores and serves, clusters 53 and 58 render.
  > Recorded 24 Nov in `proposal.md` under "Two decisions that change how the clusters are read".
- [ ] 4.1 Add any later buildiq change that cites the gap register or the discovery sweep to the index above, in the same PR that opens it.
- [ ] 5.1 Build `screen-overrides-as-a-patch-with-fall-through` (gap-register rows 11.43 and 11.51), after `case-page-layout-per-case-type`. Archive it on merge and tick it here.
- [x] 5.2 Tell the dossiq lane which audience to ask the `buildiq-page-layout` leaf with on the desk channel.
  > Told 25 Nov: dossiq ConductionNL/dossiq#6918 (competitor-parity-2026-09 task 15, shapes in buildiq-consumer-halves.md), task 15.1.
- [x] 5.3 Tell the portaliq lane to ask that leaf with the `portal` audience for a public visitor.
  > Told 25 Nov: portaliq ConductionNL/portaliq#2705 (competitor-parity-2026-09 task 17, shapes in buildiq-consumer-halves.md), task 17.3.
- [x] 5.4 Ask the `app-delta-override` lane whether its fail-soft orphaned-delta skip should become loud, the way a drifted screen override now is.
  > Answered 24 Nov by the app-delta-override lane: the skip stays fail-soft in what is served, and is shown to the maintainers. An app delta patches a whole app manifest, so withholding it as D3 of the screen overrides does would revert every page of a derived app to its base for one vanished key; skipping only the patch whose key is gone keeps the rest of the maker's work. The orphaned paths are shown in the page designer and, since #1858, on the app's Manifest layers page. A notification to the maintainers on new orphans is not built; it can follow as its own change if drift turns out to go unseen.
