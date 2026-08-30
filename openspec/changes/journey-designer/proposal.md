---
kind: code
---

# Proposal: journey-designer

## Summary

Add a Journey Designer beside Buildiq's Page Designer: author a `journey`'s
step sequence, branch rules, write mappings and access mode, over `form`
objects authored with the existing form-page editor. Validates mappings against
the target schema at author time and previews the journey through the same
`CnJourney` the portal uses.

Chain link 10 of `hydra/openspec/changes/portaliq-phase-two`. Implements
ADR-085 §6.

## Motivation

Authoring belongs where authoring already is. Buildiq's Page Designer has one
sub-editor per canonical page type, and `FormStepsManager.vue` already authors
`config.steps[]` — the multi-step grammar inside a single form. A journey is
the layer above: several forms, a branch between them, a review, and the
objects the whole thing produces.

Portaliq and nc-vue render journeys; neither should author them. Without a
designer, a journey is hand-written JSON, and the single most dangerous field —
`writes[]`, which causes object creation — gets no validation until a citizen
submits.

## Affected Projects

- [ ] `buildiq` — `JourneyDesigner.vue` and its sub-editors; author-time
      validation of `writes[]` mappings against target schemas; a live preview
      pane mounting `CnJourney`.

## Design notes

**Forms stay in the form editor.** The Journey Designer references `form`
objects; it does not duplicate field authoring.

**Branch rules are authored as `visibleWhen` predicates**, using the closed
operator set. The editor offers the operators the schema allows and nothing
else, so a second grammar cannot be introduced by a UI affordance.

**`writes[]` is validated at author time** — target register and schema must
exist, and every mapped property must exist on that schema. This is the whole
reason the designer is worth building: an invalid mapping discovered at submit
time is discovered by a citizen.

**The preview mounts the real renderer.** A preview that approximates is a
preview that lies; the live-preview pattern already exists in the Page Designer.

## Risks

- **Authoring a journey is a privileged action** — an author can cause writes
  into any register a mapping names. The designer needs its own authorisation,
  not merely the page-designer's.
- **Author-time validation can drift from submit-time validation.** Both must
  call the same validator, or the designer becomes a source of false
  confidence.
- The designer is a large UI surface. It stays one change because a journey
  without its branch editor or without `writes[]` is not reviewable as a whole.
