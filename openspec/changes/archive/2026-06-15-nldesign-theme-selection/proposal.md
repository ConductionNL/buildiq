---
kind: code
depends_on: []
chain:
  - nldesign-theme-selection
---

## Why

OpenBuild's app-store description promises that citizen developers compose apps from "NL Design-thema's … via a visual interface". The 2026-06-11 feature re-evaluation found the promise unbacked: the only spec hit on theming is an unrelated diff-view styling line in `openbuild-runtime`. What exists today is the **external nldesign app's instance-global theming** — an admin picks one token set and `\OCP\Util::addStyle('nldesign', 'tokens/<id>')` injects its `:root` CSS variables on every Nextcloud page for every app. There is no way for a builder to say "*this* virtual app renders in the Rijkshuisstijl, *that* one in Gemeente Amsterdam's tokens", which is the actual promise (one municipality hosting apps for multiple brands/organisaties is the nldesign README's own use case).

The integration surface largely exists on the nldesign side:

- **Token-set catalogue**: `token-sets.json` defines ~36 curated sets (`nextcloud`, `rijkshuisstijl`, `amsterdam`, `utrecht`, …) with id, name, description, and theming metadata.
- **Per-set CSS variable files**: `css/tokens/<id>.css` — plain static assets (the very files nldesign injects globally), each defining the `--nldesign-*` variable set under `:root`. Static app assets are web-served without any controller, so a scoped consumer can fetch them with the NC session.
- **Resolved-preview + list endpoints**: `GET /apps/nldesign/settings/tokensets` and `GET /apps/nldesign/settings/tokenset-preview/{tokenSetId}` exist but are **`#[AuthorizedAdminSetting(Admin::class)]` — admin-only** (verified in `nldesign/lib/Controller/SettingsController.php` 2026-06-11). A non-admin builder cannot list token sets through them. That gap is flagged as an explicit nldesign dependency below, NOT silently assumed.

OpenBuild adds the missing glue: a per-app theme declaration in the v2 manifest, a visual theme picker in the builder, and a runtime applier that loads the chosen token set's CSS **scoped to the virtual app's render root** — never touching the NC chrome, other apps, or nldesign's instance-global choice.

## What Changes

- **NEW** Manifest v2 theme declaration: a `theme` object carried in the manifest's `runtime` block — `{ source: "nldesign", tokenSet, tokenSetName, preview?: { primaryColor, backgroundColor } }`. Declarative only; validated app-side by openbuild's manifest validation layer (canonical-schema codification filed as a `nextcloud-vue` follow-up, riding `additionalProperties: true` exactly like `openconnector-api-sources` and `procest-workflow-attachments`).
- **NEW** `src/dialogs/ThemePickerDialog.vue` — builder UI (standalone dialog per the modal-isolation rule) opened from a "Theme" section on the application-detail/designer surface: a token-set picker with name, description, and colour swatches; "Default (Nextcloud)" to clear the theme; live preview-in-designer toggle. Picker population strategy per REQ-NTS-002 (admin endpoint when available → flagged non-admin endpoint once it exists → validated free-text fallback).
- **NEW** `src/composables/useAppTheme.js` — runtime applier: fetches the chosen set's `css/tokens/<id>.css` static asset, rewrites its `:root` selector to the virtual app's scope attribute, injects exactly one managed `<style data-openbuild-theme>` element, and removes it on app leave/teardown. Cached per token set for the session; fetch failure degrades to default styling with a single console warning.
- **MODIFIED** Virtual-app runtime host — carries a `data-openbuild-theme-scope` attribute on its root element so the rewritten CSS has a stable, collision-free scope target.
- **NEW** Capability check + graceful absence: nldesign missing/disabled disables the picker with a hint; at runtime a themed manifest on an nldesign-less instance renders in default styling. The theme is **deliberately NOT added to manifest `dependencies[]`** — it is a progressive enhancement, never a gate (divergence from the procest/openconnector pattern, motivated in design.md Decision 4).
- **NO** new openbuild PHP; **NO** nldesign code changes inside this change (the non-admin list/preview endpoint is a filed dependency, not an assumption); **NO** mutation of nldesign's instance-global token set, overrides, or theming values — openbuild only ever reads.

### Capabilities

#### New Capabilities

- `nldesign-theme-selection`: the manifest `runtime.theme` declaration, the ThemePickerDialog builder UI, the `useAppTheme` scoped runtime applier, and capability-checked graceful absence of nldesign.

#### Modified Capabilities

- `openbuild-page-designer`: the application-detail/designer surface gains the Theme section + live designer preview. Existing flows untouched; everything is additive and absent when no theme is set.

## Impact

- **New frontend code**: ~700 LOC (dialog ~250, applier composable ~200, theme section ~120, validation ~80, scope wiring ~50) + Vitest suites. Zero new PHP.
- **Integration contract (pinned to nldesign's existing surface)** — openbuild reads exactly:
  1. `GET <static>/apps/nldesign/css/tokens/{tokenSet}.css` (resolved via `@nextcloud/router` `generateFilePath`) — the token-variable payload; the only call the *runtime* ever makes.
  2. `GET /apps/nldesign/settings/tokensets` + `GET /apps/nldesign/settings/tokenset-preview/{id}` — builder picker/swatches, **admin-only today**; used only when the session is admin (or once the flagged non-admin endpoint lands).
  All reads ride the caller's NC session. Openbuild never POSTs to nldesign.
- **Explicit nldesign dependencies (flagged, NOT assumed)**:
  1. **Non-admin read-only token-set list** — the builder picker needs `{ id, name, description, preview colors }` for non-admin builders. No such route exists today (all of `settings/*` is `AuthorizedAdminSetting`). A Codeberg issue MUST be filed during apply requesting e.g. `GET /apps/nldesign/api/tokensets` with `#[NoAdminRequired]` (read-only metadata, no secrets). Until it lands, non-admin builders get the REQ-NTS-002 validated free-text fallback.
  2. **Stability of the `css/tokens/<id>.css` asset paths** — these files are nldesign's own injection source so they are de-facto stable, but the issue also asks nldesign to document them as a consumable contract.
- **Security**: openbuild stores only a token-set id + display snapshot in the manifest. The applier injects CSS custom-property declarations parsed from nldesign's own shipped stylesheet; the `:root` rewrite is a selector-prefix transform, no style values are user-authored, and nothing is `eval`ed. A 404 on the asset (removed/renamed set) degrades to default styling.
- **No breaking changes** — purely additive; apps without a theme serialize byte-identical manifests; nldesign's instance-global theming continues to work unchanged underneath (the scoped variables simply win specificity inside the app root).

## Open Questions

- **OQ-1**: Per-app logo/slogan branding (token sets carry a `logo` in their theming metadata) — deferred; v1 scopes CSS variables only.
- **OQ-2**: Custom per-app token overrides (a colour picker on top of the chosen set, mirroring nldesign's instance-level `custom-overrides.css`) — deferred to v2; needs a manifest-carried override block and contrast/WCAG validation.
- **OQ-3**: Should the exporter bake the token CSS into the exported standalone app (no nldesign dependency after graduation) or keep the runtime fetch? v1 keeps the same `useAppTheme` fetch path in exported apps; baking is an exporter follow-up.
