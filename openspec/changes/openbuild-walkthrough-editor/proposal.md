# buildiq-walkthrough-editor — visual tour authoring for virtual apps

## Why

Apps created in Buildiq are **virtual** — they have no source tree, so their
walkthrough (ADR-043, `manifest.walkthrough`) can't be hand-authored in a
`manifest.json`. Buildiq already owns visual, in-app authoring of virtual apps:
the create-app wizard, the universal in-app edit shell (ADR-041), per-user/app
manifest deltas, and version promotion. This change adds a **visual tour editor**
on top of that machinery so an app owner can record and edit a product walkthrough
by pointing at real UI — no code, no JSON.

The same editor generalises to the `manifest.setup` block (ADR-042), which is also
manifest data with no visual editor today, so the user can edit setup steps the
same way.

## What changes

1. **"Edit walkthrough" mode** in the Buildiq edit shell (alongside the existing
   edit-page / edit-menu / edit-widget modes). Entering it overlays the running
   app with a recorder: the owner clicks any element (nav item, widget, button,
   page region) and Buildiq resolves it to the most stable `target` it can —
   `nav-item`/`widget`/`action`/`page` from manifest identifiers first, then a
   `data-walkthrough-id` it injects, then a CSS `selector` as last resort.

2. **Step editor panel** — for each recorded step: title, body, optional task line,
   `placement`, `advanceOn` (manual / click-target / route-match(+capture) /
   element-appears / object-created / delay) chosen from a guided picker, an
   `optional`/`allowManualNext` toggle, and a `sinceVersion` assignment defaulting
   to the app's current version. Steps are reorderable (drag); tours are
   add/rename/delete.

3. **Capture recording** — when the owner performs the navigation/action while
   recording, Buildiq observes the route change / created object and pre-fills the
   `advanceOn` + `capture` (e.g. recording a "create product" step auto-suggests
   `object-created: { register, schema: product }` with `capture: { productId: :id }`).

4. **Persistence into the manifest delta** — the authored `walkthrough` block is
   written into the app's manifest via the existing Buildiq delta/version
   persistence (the same path page/menu/widget edits use), scoped per app and
   assignable to an `ApplicationVersion`, so a new app version ships a "what's new"
   tour of just the steps authored against it.

5. **Setup-block reuse** — the same panel edits `manifest.setup` steps (info /
   choice / config-fields / run-action / summary) for virtual apps, closing the
   "setup is manifest-configurable but not visually editable" gap.

## Non-goals

- The abstract engine itself (`CnWalkthrough` / `useWalkthrough` / the schema) —
  that's `cn-walkthrough-engine` (change 1), which this depends on.
- Editing tours for source-tree (non-virtual) apps — those edit `manifest.json`
  directly; the visual editor targets virtual apps (a delta-backed export to a
  fragment is a possible later nicety).
- Authoring concrete app journeys — pipelinq's tour is `pipelinq-getting-started-tour`
  (change 3).

## Consumer impact

Buildiq-only. Reuses ADR-041 edit shell + layered-versioned-app-deltas; no change
to how non-Buildiq apps load or render walkthroughs.
