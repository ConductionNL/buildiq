# Tasks: competitor-parity-2026-09

- [ ] 1.1 Build `forms-per-case-type`. Archive it on merge and tick it here.
- [ ] 1.2 Build `case-page-layout-per-case-type`. Archive it on merge and tick it here.
- [ ] 2.1 Ask the dossiq lane for the field half of row B16: `order`, `group` and `placeholder` on `propertyDefinition`, in its CT-1 change.
- [ ] 2.2 Ask the dossiq lane and the openregister lane for the portal flag on a property, which D16 puts on the field and not on the form.
- [ ] 2.3 Ask the nextcloud-vue lane for `runtime-detail-layout`, so `CnDetailPage` merges a provider answer over its manifest config.
- [ ] 2.4 Tell the portaliq lane that `isPublic` and `confirmationText` arrive on the `buildiq-registration-form` leaf, and that cluster 51 keeps the captcha, the address check and the reuse of earlier case data.
- [ ] 3.1 Tell the dossiq lane that its task list and its upload dialog may read `buildiq-page-layout`, and that both keep their manifest shape when nothing answers.
- [x] 3.2 Record that the consolidated candidates C-tasks-and-phases-33 and C-access-and-privacy-37 are split: buildiq stores and serves, clusters 53 and 58 render.
  > Recorded 24 Nov in `proposal.md` under "Two decisions that change how the clusters are read".
- [ ] 4.1 Add any later buildiq change that cites the gap register or the discovery sweep to the index above, in the same PR that opens it.
- [ ] 5.1 Build `screen-overrides-as-a-patch-with-fall-through` (gap-register rows 11.43 and 11.51), after `case-page-layout-per-case-type`. Archive it on merge and tick it here.
- [ ] 5.2 Tell the dossiq lane which audience to ask the `buildiq-page-layout` leaf with on the desk channel.
- [ ] 5.3 Tell the portaliq lane to ask that leaf with the `portal` audience for a public visitor.
- [x] 5.4 Ask the `app-delta-override` lane whether its fail-soft orphaned-delta skip should become loud, the way a drifted screen override now is.
  > Answered 24 Nov by the app-delta-override lane: the skip stays fail-soft in what is served, and is shown to the maintainers. An app delta patches a whole app manifest, so withholding it as D3 of the screen overrides does would revert every page of a derived app to its base for one vanished key; skipping only the patch whose key is gone keeps the rest of the maker's work. The orphaned paths are shown in the page designer and, since #1858, on the app's Manifest layers page. A notification to the maintainers on new orphans is not built; it can follow as its own change if drift turns out to go unseen.
