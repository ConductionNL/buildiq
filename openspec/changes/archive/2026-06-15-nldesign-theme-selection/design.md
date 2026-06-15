## Context

nldesign solves "make this Nextcloud look like my municipality" at instance scope: one admin-chosen token set, injected as `:root` CSS variables on every page via `\OCP\Util::addStyle`. OpenBuild's promise is one level finer: each *virtual app* picks its own NL Design theme, because a single instance routinely hosts apps for multiple brands (a samenwerkingsverband serving several gemeenten, or a citizen-facing app that must carry the Rijkshuisstijl while internal tooling stays stock).

The shape that fits both ADR-022 and the nldesign architecture is *read-only scoped consumption*: nldesign stays the single owner of token sets (curation, WCAG-checked palettes, design-system stylesheets); openbuild reads one set's variable file and re-scopes it to the virtual app's render root. Re-implementing a palette editor, shipping a copy of the token catalogue, or writing to nldesign's instance config are all explicitly off the table.

Two awkward realities shape the design: nldesign's list/preview endpoints are admin-only today (the builder persona is not necessarily an admin), and nldesign may be absent entirely on the instance that renders a themed app.

## Goals / Non-Goals

**Goals:**

- Declarative, manifest-carried per-app theme choice from nldesign's token-set catalogue.
- A visual picker in the builder with colour swatches and live designer preview.
- Runtime application strictly scoped to the virtual app's root — zero leakage into NC chrome, other apps, or other virtual apps on the same page lifecycle.
- Strict consumption of nldesign's **existing surface** (static token CSS + admin endpoints where the session allows); the missing non-admin list endpoint is a filed dependency.
- Graceful absence of nldesign at design time and runtime; theme is an enhancement, never a gate.

**Non-Goals:**

- **Token-set authoring/editing** — token sets are nldesign's; openbuild ships no palette editor and no token data.
- **Instance-global theming** — openbuild never reads or writes nldesign's `token_set` appconfig, overrides, or theming values.
- **Logo/slogan branding, custom per-app overrides, exporter CSS baking** — OQ-1/2/3, deferred.
- **Non-nldesign theme sources** — `theme.source` is a closed enum `"nldesign"` in v1; the field exists so future sources don't break the shape.

## Decisions

### Decision 1 — Theme lives in the manifest `runtime` block as a single `theme` object

`runtime.theme = { source: "nldesign", tokenSet, tokenSetName, preview?: { primaryColor, backgroundColor } }`; absent means default styling.

**Rationale**: the theme is app-composition state that must version, promote, and export with the app — exactly the manifest `runtime` block's job (same home as `workflows[]` from the sibling change). One theme per app (not per page): NL Design compliance is an app-identity property, and per-page theming produces jarring brand flips inside one user journey. The `preview` snapshot lets the gallery/detail surfaces render swatches without any nldesign call. Rides `additionalProperties: true` with the canonical-schema codification filed against `nextcloud-vue` (sibling pattern).

**Alternatives considered**:
- *App-config (OR object) outside the manifest* — rejected: wouldn't travel through version snapshots, promotion, or the exporter; a promoted version could silently change brand.
- *Per-page `theme` overrides* — rejected for v1 (brand consistency; trivial to add later as an override key).

### Decision 2 — Runtime applies the theme by fetching nldesign's own token CSS and re-scoping `:root`

`useAppTheme` fetches `generateFilePath('nldesign', 'css', 'tokens/<tokenSet>.css')`, rewrites the `:root` selector to `[data-openbuild-theme-scope="<appSlug>"]`, and injects one managed `<style>` element; teardown removes it.

**Rationale**: the static token files are the exact artifact nldesign injects globally — consuming them guarantees per-app theming can never drift from instance theming for the same set, and requires zero nldesign code. The selector-prefix rewrite is mechanical (the files are flat `:root { --var: value; }` blocks); CSS custom properties inherit, so scoping the root element themes the whole subtree while higher-specificity scoped declarations cleanly beat any instance-global `:root` values inside the app. The scope attribute (not a class) avoids collision with utility classes and gives the e2e tests a stable hook.

**Alternatives considered**:
- *Reading `token-sets.json` theming metadata and synthesizing variables app-side* — rejected: the JSON carries 2–3 headline colours, not the full `--nldesign-*` set; synthesized themes would diverge from real nldesign rendering.
- *Asking nldesign for a "scoped CSS" endpoint* — rejected for v1: needless partner work when a client-side prefix transform of their shipped asset suffices; revisit only if token files gain at-rules that complicate rewriting.
- *Iframe isolation* — rejected: heavyweight, breaks the manifest shell's routing/composition model.

### Decision 3 — Picker population: admin endpoint → flagged non-admin endpoint → validated free-text fallback

The dialog tries `GET /settings/tokensets` (works for admin sessions today, returns 403 otherwise), then the flagged `GET /api/tokensets` once nldesign ships it; when neither yields a list, it degrades to a free-text token-set id input that validates by fetching the static `css/tokens/<id>.css` (404 = invalid) and renders swatches by parsing the fetched variables.

**Rationale**: per the no-invented-API rule the spec cannot pretend a non-admin list exists; per the report's own constraint the missing API becomes a flagged dependency with a working (if less comfortable) fallback, mirroring `openconnector-api-sources` REQ-OCAS-005's free-text path entry. The 403 probe is cheap and cached per session.

### Decision 4 — Theme does NOT join manifest `dependencies[]`

A themed app on an nldesign-less instance renders in default styling with one console warning; CnAppRoot's dependency gate is never invoked for theming.

**Rationale**: deliberate divergence from the procest/openconnector sibling pattern. Those integrations change *behaviour* (data, cases) — running without them breaks the app's function. A theme changes *presentation only*; blocking citizens out of a working app because a styling package is disabled would invert the severity. The designer still soft-checks `useAppStatus('nldesign')` to disable the picker with a hint.

### Decision 5 — Live preview in the designer reuses the runtime applier

The "preview" toggle in the Theme section mounts the same `useAppTheme` applier against the designer's embedded preview surface, scoped to the preview root.

**Rationale**: one code path means the designer preview can never lie about runtime rendering; it also gives the applier its Vitest surface without booting a full virtual app.

## Risks / Trade-offs

- **CSS rewrite fragility**: a future token file using nested at-rules (`@media`, `@supports`) would need a smarter transform than selector prefixing. Mitigated: current files are flat `:root` blocks; the applier parses defensively and falls back to default styling (never injects partially-rewritten CSS); the flagged nldesign issue asks for the asset shape to be documented as a contract; Newman pins the asset's shape (200 + `:root` + `--nldesign-color-primary`).
- **Admin-only picker today**: non-admin builders get the free-text fallback until the nldesign endpoint lands — acceptable because the dependency is filed and the fallback is fully functional, just less discoverable.
- **Specificity conflicts**: an instance-global nldesign theme sets the same variables on `:root`; the scoped attribute selector wins inside the app root by specificity, but component styles that hardcode colours (ADR-010 violations elsewhere) won't follow the theme. Accepted: that is the pre-existing ADR-010 contract, not this change's job.
- **Stale `tokenSetName`/`preview` snapshots** in the manifest if nldesign renames a set: cosmetic only (the id drives rendering); the picker refreshes snapshots on edit.
