---
kind: umbrella
depends_on: []
---

# Proposal: competitor-parity-2026-09

## Summary

This is the buildiq half of the dossiq competitor parity programme. Nothing
here is implemented. Each indexed change carries its own `proposal.md`,
`design.md`, `specs/` and `tasks.md`.

Ruben's ownership rule governs the split, and the depth study states it
plainly: "A property definition, a code list, a rule or a computed value
belongs to openregister's schema and rules abstractions. A form belongs to
buildiq or Nextcloud Forms. A registry lookup belongs to integriq. dossiq
owns the case-type editor surface and the fase, resultaat and rol
semantics." dossiq reaches 100% comparability with the competition, and the
logic that is buildiq's is specified here.

## The sources of record

Both live in ConductionNL/market-intelligence.

- The gap register, `procest/_gaps/`, written 2026-09-13. Row Q1.15 opened
  `forms-per-case-type`, and finding B09 with matrix rows 11.6 to 11.8
  opened `case-page-layout-per-case-type`.
- The round 4 discovery sweep, `procest/_round4/discovery/`, written
  2026-09-14. Thirty-six systems read, 631 consolidated candidates, 70
  clusters in `build-plan.md`, a nine-cluster depth study in
  `casetype-configurability.md`, 22 decisions in `decisions.md`. Ruben
  answered all 22 on 2026-09-14 and lifted the build hold.

The ownership rule gives buildiq no cluster of the 70. It gives buildiq one
cluster of the depth study's nine, CT-6, "The form is not a form", at size
L, in wave 3. Both of buildiq's parity changes carry it.

## The changes

| change | rows and candidates | size | decision | dossiq consumer |
|---|---|---|---|---|
| `case-page-layout-per-case-type` | CT-6 rows B16 and C16, matrix 11.6, 11.7 and 11.8; D-casetype-21, D-casetype-22, D-casetype-26; candidates C-tasks-and-phases-33 and C-access-and-privacy-37, whose record halves stay in clusters 53 and 58 | L | D16 | dossiq places the `buildiq-page-layout` leaf once. Its case page, its task list and its upload dialog each ask for the case type in hand, and each keeps its manifest shape when buildiq is absent |
| `forms-per-case-type` | gap register row Q1.15; CT-6 rows C13 and B16, matrix row 1.1; candidate C-intake-16, whose portal half stays in cluster 51 | M | D16 | dossiq's create dialog asks for the `internal` default on the `desk` channel and its portal journey for the `client` default on the `portal` channel. Both write `case.intakeChannel` from the served form instead of after the write |

## The pending-proposal rows, added 2026-09-14

The corpus batch file
`procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md` proposes 98 rows
under decision D1. Two of them are buildiq's, both in the configuration area,
both rated `no` for dossiq. They are the mechanism under
`case-page-layout-per-case-type`: how a customer's screen configuration is
stored, and how many of them there may be.

Neither is covered. `app-delta-override`, `layered-versioned-app-deltas`,
`app-override-persistence` and `case-page-layout-per-case-type` were read in
full. The patch mechanism exists for an application manifest and not for a
layout, its drift handling is fail-soft by design ("SHALL be skipped (base
drift), resolution SHALL NOT fail"), its layers are keyed by identity rather
than bound to a named audience, and the layout object replaces whole: "A
case-type header SHALL replace the schema-wide header whole, and SHALL NOT
merge field by field."

Every competitor column on these rows is `unread`, and the corpus says so
itself: "`no` is a reading of a product somebody opened, and filling these
cells with it would fabricate thirty readings per row." Neither row carries a
cross-reference. No competitor claim rests on them.

| change | rows | size | consumers |
|---|---|---|---|
| `screen-overrides-as-a-patch-with-fall-through` | 11.43, 11.51 | M | dossiq asks with the desk audience, portaliq with the `portal` audience, both on the leaf `case-page-layout-per-case-type` already specifies. To be specified in each |

One change rather than two: both rows describe the same object, the stored
screen override. A named override bound to a team is a patch over the screen
below it, so the fall-through of 11.51 is built out of the patch of 11.43.
Splitting them would specify the layering twice.

## Build order

1. `forms-per-case-type`. It is the smaller of the two and it closes a `must`
   row the whole corpus reads partial: "nobody ships different forms per
   channel".
2. `case-page-layout-per-case-type`. Size L, and the build plan puts it in
   wave 3 for that reason: "none of them blocks a tender answer".
3. `screen-overrides-as-a-patch-with-fall-through`. It patches the object the
   layout change creates, so it follows it.

## Halves another app carries

- **dossiq**, for both. Row B16 splits: field order, grouping and placeholder
  on `propertyDefinition` are dossiq's, sized S, and ride its CT-1 change.
  D16 puts the portal flag on the field, so dossiq and openregister carry
  that half too.
- **portaliq**, for cluster 51. It renders a public form and owns the
  captcha, the address check and the reuse of earlier case data. Cluster 51's
  own mechanism line names this repo's change beside portaliq
  `embedded-intake-form` (portaliq#539).
- **nextcloud-vue**, for the render. `CnDetailPage` merging a runtime layout
  over its manifest config is tracked there as `runtime-detail-layout`, and
  cluster 58 owns the high-contrast render.

## Two decisions that change how the clusters are read

- **D6 was answered relevance-led.** Every `must` candidate enters the corpus
  as a row, whatever its passer count. That admits C-tasks-and-phases-33, a
  `must` with one driven passer, Valtimo.
- **D21 admits documented candidates, labelled.** D-casetype-21, D-casetype-22
  and D-casetype-26 come from the depth study's second read, which names a
  passer and does not rate dossiq. They are cited as such, and never counted
  in a driven tally.
