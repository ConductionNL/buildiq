## ADDED Requirements

### Requirement: Author-supplied SVG icons MUST be sanitized before use

The author-supplied SVG returned by `iconCatalogues.js::resolveAppIcon` SHALL be
passed through `DOMPurify.sanitize(...)` with an SVG profile
(`USE_PROFILES: { svg: true, svgFilters: true }`) before it is previewed and
before it is persisted, so the value is safe on every path. `resolveAppIcon`
currently returns the value verbatim when it begins with `<svg`, and that value
is bound via `v-html` in the creation-wizard previews (`Step1Basics.vue`,
`Step4Review.vue`); although these previews are self-only (the persisted icon is
later served as an `<img>` under `IconController`'s `default-src 'none'` CSP),
the value MUST be sanitized regardless.

#### Scenario: Malicious SVG is sanitized before preview
- **WHEN** an author supplies an SVG containing a `<script>` element or an event
  handler attribute
- **THEN** `resolveAppIcon` returns SVG with the script/handler removed, and the
  preview renders no executable content

#### Scenario: Valid SVG icon is preserved
- **WHEN** an author supplies a well-formed decorative SVG
- **THEN** the sanitized SVG renders identically as an icon
