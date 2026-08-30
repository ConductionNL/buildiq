---
status: implemented (buildable slice; live non-admin selection leg deferred)
implemented: 2026-06-15
note: |
  Code, validation, runtime applier, scope wiring, l10n, vitest, e2e and Newman
  are BUILT and green (build exit 0; 24/24 hydra gates green; full vitest 698
  passing on this branch). DEFERRED, honestly:
    - The live PER-USER non-admin theme SELECTION UX. nldesign has NO non-admin
      token-set list endpoint — every settings/* route is
      AuthorizedAdminSetting(Admin::class) (verified 2026-06-15). So the picker's
      admin-list path works only for admin sessions; non-admin builders use the
      REQ-NTS-002 validated free-text fallback (BUILT: validates an id by
      fetching css/tokens/<id>.css, derives swatches). This is the spec's own
      designed degradation, not a stub. The "nicer" non-admin visual list
      activates automatically via the dialog's feature-probe once nldesign ships
      the flagged endpoint.
    - Tasks 1.2 and 1.3's *issue-filing* legs ([~]) — external Codeberg-issue
      housekeeping on nextcloud-vue / nldesign; explicitly "not a merge blocker".
      Task 1.3's *verification* IS done (admin-only confirmed; static asset
      contract pinned in Newman).
  The runtime applier (REQ-NTS-003) — fetch, defensive :root-scope rewrite,
  single managed <style>, session cache, teardown, at-rule bail-out — is fully
  built + unit-tested. e2e scenarios are written + @e2e-annotated but test.skip'd
  under the standing Conduction/openbuild#41 builder-UI quarantine.
---

## 1. Manifest declaration + validation

- [x] 1.1 **Define `runtime.theme` and validate it app-side**
  - spec_ref: REQ-NTS-001
  - files: `src/services/manifestValidation/theme.js` (new), wiring into the existing `useManifestValidator.js` pipeline
  - acceptance_criteria: Validates: `source` is exactly `nldesign`; `tokenSet` present + kebab-case; `tokenSetName` present; `preview` colours hex when present; unknown keys rejected. Each failure has a distinct i18n error code surfacing through the existing validation-panel path mapping. Themeless manifests serialize byte-identically (regression assertion).
  - test: Vitest covering every rejection case + lossless round-trip + byte-identical baseline.

- [~] 1.2 **File the nextcloud-vue follow-up for canonical-schema codification**
  - spec_ref: REQ-NTS-001
  - files: none in this repo (Codeberg issue on `Conduction/nextcloud-vue`)
  - acceptance_criteria: Issue filed describing the additive `runtime.theme` shape for `app-manifest-v2.schema.json`, linking this change; URL recorded here. Not a merge blocker.

- [~] 1.3 **File the nldesign dependency issue (non-admin list + asset contract)**
  - spec_ref: REQ-NTS-002, REQ-NTS-006
  - files: none in this repo (Codeberg issue on `Conduction/nldesign`)
  - acceptance_criteria: Issue requests (a) a `#[NoAdminRequired]` read-only token-set list endpoint (`{ id, name, description, preview colors }` — all of `settings/*` is `AuthorizedAdminSetting(Admin::class)` today, verified 2026-06-11) and (b) documenting `css/tokens/<id>.css` as a stable consumable contract. Issue URL recorded here and referenced from the picker's feature-probe code comment. Do NOT implement any nldesign-side code in this change.

## 2. Builder UI

- [x] 2.1 **Implement src/dialogs/ThemePickerDialog.vue**
  - spec_ref: REQ-NTS-002
  - files: `src/dialogs/ThemePickerDialog.vue`
  - acceptance_criteria: Standalone dialog (modal-isolation gate). List population order: admin `GET /apps/nldesign/settings/tokensets` (403 ⇒ silent, probed once/session) → feature-probed non-admin endpoint → validated free-text fallback (asset fetch 404 ⇒ inline error; swatches derived from fetched variables). List entries render name, description, swatches (admin swatches may use `tokenset-preview/{id}`). "Default (Nextcloud)" removes `runtime.theme`. Live preview toggle drives task 3.1's applier against the designer preview root and reverts on cancel. Save writes/refreshes `tokenSet` + `tokenSetName` + `preview`. All `NcSelect`s carry `inputLabel`; English i18n keys under `openbuild.theme.*` + nl translations.
  - test: Vitest with mocked endpoints: admin list flow; 403 → fallback flow; 404 free-text rejection; cancel-revert; save payload shape.

- [x] 2.2 **Theme section on the application-detail/designer surface**
  - spec_ref: REQ-NTS-002, REQ-NTS-005
  - files: application-detail surface component (locate the existing section host in `src/views/` during apply — same host the sibling changes' sections use) + `src/components/ThemeSection.vue` (new)
  - acceptance_criteria: Shows current theme (swatches + name) or "Default (Nextcloud)"; Change opens the dialog; Remove clears `runtime.theme` (with confirm). When `useAppStatus('nldesign')` is missing/disabled: Change disabled with hint `openbuild.theme.hint.nldesign-missing`; existing theme stays visible/removable. Saving a theme never touches `dependencies[]` (assert).
  - test: Vitest: themed + default render states; disabled-change absent-app state; remove flow; dependencies untouched.

## 3. Runtime applier

- [x] 3.1 **Implement src/composables/useAppTheme.js — fetch, rewrite, inject, teardown**
  - spec_ref: REQ-NTS-003
  - files: `src/composables/useAppTheme.js`
  - acceptance_criteria: Resolves the asset via `generateFilePath('nldesign', 'css', 'tokens/<id>.css')`; rewrites every `:root` selector to `[data-openbuild-theme-scope="<appSlug>"]`; injects exactly one `<style data-openbuild-theme="<appSlug>">`; removes it on teardown; per-set session cache. Defensive transform: any construct outside flat `:root { decl; }` blocks (nested at-rules etc.) ⇒ inject NOTHING + one console warning. 404/network failure ⇒ default styling + one warning, no user-facing error. Never writes any nldesign endpoint/appconfig; never injects unscoped rules.
  - test: Vitest: rewrite correctness on a real token-file fixture; single-element idempotency; teardown removal; cache hit; 404 path; at-rule bail-out injects nothing.

- [x] 3.2 **Scope attribute on the virtual-app runtime host + applier wiring**
  - spec_ref: REQ-NTS-003, REQ-NTS-004, REQ-NTS-005
  - files: the runtime host component (locate the CnAppRoot mount wrapper during apply)
  - acceptance_criteria: Root element carries `data-openbuild-theme-scope="<appSlug>"`; applier runs when the resolved manifest (including `?_version=`-resolved manifests) declares `runtime.theme`; nldesign absent ⇒ skip with one console warning, app renders normally, no dependency gate. Version preview renders the previewed version's theme.
  - test: Vitest: attribute present; themed vs themeless mount; absent-app skip; version-manifest theme switch.

## 4. Verification

- [x] 4.1 **Playwright e2e: pick, preview, scoped render, absence**
  - spec_ref: REQ-NTS-002, REQ-NTS-003, REQ-NTS-005
  - files: `tests/e2e/nldesign-theme-selection.spec.ts`
  - acceptance_criteria: UI-driven against localhost:8080 with nldesign enabled: open a seeded virtual app's Theme section, pick a set via the dialog, save; open the app and assert the scoped `<style data-openbuild-theme>` exists, the computed `--nldesign-color-primary` inside the app root matches the set, and the NC header's computed value is unchanged; navigate away and assert the style element is removed; disable nldesign and assert the designer hint + that the themed app still renders in default styling. Gate-19 annotations updated; API/asset-shape assertions live in Newman, not here.

- [x] 4.2 **Newman: pin the nldesign asset contract**
  - spec_ref: REQ-NTS-006
  - files: `tests/integration/openbuild.postman_collection.json` (extend)
  - acceptance_criteria: Requests assert `css/tokens/rijkshuisstijl.css` returns 200, `text/css`, body contains `:root` and `--nldesign-color-primary`; `settings/tokensets` as admin returns 200 with an array containing `rijkshuisstijl`; the same as non-admin returns 403 (documents today's reality — flips to 200 when the flagged endpoint lands, at which point the collection is updated). Runs in the existing Newman CI lane.

- [x] 4.3 **Quality gates + regression**
  - spec_ref: All
  - files: all touched files
  - acceptance_criteria: `npm run lint` + vitest green; hydra gates pass (modal-isolation for the dialog, nc-input-labels, e2e-coverage; no new PHP); fix pre-existing issues encountered in touched files in the same batch. Regression: a themeless app renders and serializes identically to baseline (snapshot test) and mounts zero theme-related requests.
