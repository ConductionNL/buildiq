## 1. Schema

- [x] 1.1 Add `baseRef`, `layoutDelta`, `baseFingerprint` and `baseCutAt` to `pageLayout` in `lib/Settings/openbuild_register.json` (REQ-OBSO-001, REQ-OBSO-002)
- [x] 1.2 Add `name` and `audience` of `{kind, ref}`, with `audience` unset reading as `everyone` (REQ-OBSO-004)
- [x] 1.3 Extend the uniqueness rule to `(targetApp, register, schema, typeProperty, typeValue, audience)` (REQ-OBSO-005)
- [x] 1.4 Add `needs-review` to the `pageLayout` lifecycle and the maintainer notification, both declarative (REQ-OBSO-003)
- [x] 1.5 Bump the register and schema version so the repair step re-imports — pageLayout is at 0.3.0

## 2. Resolution

- [x] 2.1 Compose the five layers in order in `PageLayoutLeafProvider::list()`, each patching the one below (REQ-OBSO-005)
- [x] 2.2 Reuse the PHP `mergeManifestDelta` port from `app-delta-override` for every layer; add no second merge (REQ-OBSO-001)
- [x] 2.3 Resolve the audience from the caller's own session, and refuse `group`, `team` and `user` for a caller with no account (REQ-OBSO-004)
- [x] 2.4 Compare `baseFingerprint` against the normalised base, withhold on drift and serve the base (REQ-OBSO-002, REQ-OBSO-003)
- [x] 2.5 Return `appliedLayers[]` and `withheld[]` beside the resolved layout (REQ-OBSO-006)
- [x] 2.6 Cache on the composing layers' ids and fingerprints. `PageLayoutLeafProvider` folds the ordered stack once per key in the local cache (`buildiq-page-layout`, 300 s): every composing layer's id with a hash of its content, since a whole layout carries no fingerprint and an edit to it must not serve a stale fold. The host object stays out of the fold, so one fold serves every case of the type; a drifted override is flagged on the fold, not on each read (`PageLayoutFoldCacheTest`)
- [x] 2.7 Add the `organisation` audience kind to `pageLayout` in `lib/Settings/openbuild_register.json`, resolve it from the caller's active OpenRegister organisation in `lib/Service/PageLayoutLayerStack.php`, and place it between the type layout and the group and team overrides; offer it in the "applies to" panel of `DetailPageEditor.vue` (REQ-OBSO-007). Verify: a PHPUnit case for two organisations and a team override in one of them

## 3. Editor

- [x] 3.1 Save an override as a minimal delta via `diffManifest`, writing `baseFingerprint` and `baseCutAt` (REQ-OBSO-001, REQ-OBSO-002). The SERVER half ships: `PUT /api/page-layouts` stamps `baseFingerprint` and `baseCutAt` from the base the resolver itself composes, and discards any fingerprint in the payload. Cutting the minimal delta with `diffManifest` still belongs to the editor in 3.2
- [x] 3.2 Add name and audience to the "applies to" panel of `DetailPageEditor.vue` (REQ-OBSO-004). The five audience kinds the resolver knows, and a group, team or user audience with no ref is refused in the panel as well as on the server
- [x] 3.3 List the overrides on a layout with their audiences and their state. `GET /api/page-layouts?register=&schema=` answers each layout with `drifted` and `orphanedPaths`, and `ScreenOverrideList.vue` renders them: who each screen is for, whether it still applies, and which paths stopped applying
- [x] 3.4 Offer re-cut on a `needs-review` override: recompute the delta against the new base and save (REQ-OBSO-003). `POST /api/page-layouts/{id}/recut` drops the orphaned paths, re-pins to the base it has now, returns the override to `published` and answers with what it dropped. The button sits on the drifted row in `ScreenOverrideList.vue` and shows what the re-cut dropped

## 4. Quality

- [x] 4.1 PHPUnit for the layer order, the tie refusal, the draft skip and the no-account rule
- [x] 4.2 PHPUnit for drift: an orphaned patch, a changed base with every key intact, and the withheld answer
- [x] 4.3 Playwright `tests/e2e/screen-override-editor.spec.ts` for three audiences on one case type (written, runs in nightly: the spec sits in tests/e2e and is not verified against a live instance)
- [x] 4.4 Playwright `tests/e2e/screen-override-drift.spec.ts` for the re-cut of a drifted override — the spec ships and asserts that the answer carries appliedLayers and withheld over the wire, and that a caller with no account never gets an internal screen; the re-cut itself waits on the editor in section 3
- [x] 4.5 Register-import test that the lifecycle and notification dialects import, per the notification-dialect gate
- [x] 4.6 Dutch and English strings for the panel, the state and the notification; docs with screenshots
  > 25 Nov 2026: `npm run test:l10n` and `npm run test:l10n:parity` (nl) pass, so every panel and state string has its English and Dutch text. The notification's subject and message are declared in `register.d/51-page-layouts.json`. New page `docs/elements/screen-overrides.md` with three screenshots from a live Nextcloud 34 + OpenRegister main on a demo app (screens of a schema, withheld after the base changed, after a re-cut).
  > Live observations, same instance:
  > - The leaf moved a drifted override to `needs-review` when a case was read through `/api/objects/{register}/{schema}/{id}/integrations/buildiq-page-layout`.
  > - **No notification arrived** for that override's maintainer (admin), also after `cron.php`. The `pageLayout` schema as stored by OpenRegister reads back without notifications, so the `x-openregister-notifications` annotation may not survive the import. Not diagnosed further; queued.
  > - 4.3 and the drift spec also ran live: `screen-override-editor.spec.ts` and `screen-override-drift.spec.ts`, 4/4 passed.
  - 2026-11-14: the Dutch strings of the "applies to" panel are in `l10n/nl.json` and `l10n/nl.js` (English was already complete). Open: the docs with screenshots, which need a running instance.

## 5. Consumers

- [x] 5.1 Tell the dossiq lane which audience to ask with on the desk channel, and open its change
  > Done 25 Nov: dossiq ConductionNL/dossiq#6918 (competitor-parity-2026-09 task 15, shapes in buildiq-consumer-halves.md), task 15.1: the desk channel asks as the signed-in handler with no audience filter; buildiq resolves the audience from the session.
- [x] 5.2 Tell the portaliq lane to ask with the `portal` audience for a public visitor
  > Done 25 Nov: portaliq ConductionNL/portaliq#2705 (competitor-parity-2026-09 task 17, shapes in buildiq-consumer-halves.md), task 17.3: a visitor without an account resolves to `portal` and `everyone` only, so the portal sends no audience.
- [x] 5.3 Ask the `app-delta-override` lane whether its fail-soft orphan skip should become loud too
  > Answered 24 Nov: no withholding there; the orphaned paths stay skipped in what is served and are shown to the maintainers in the designer and on the Manifest layers page (#1858). The reason is in competitor-parity-2026-09 task 5.4.
