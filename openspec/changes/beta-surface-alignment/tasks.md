## 1. Verify claims against `lib/` and `src/` at HEAD

> Delta fix-up 2026-10-07: the dependency scenario now matches the code (openregister named in a comment inside `<dependencies>`, no `<app>` element, because the App Store schema rejects it), and 2.3 is rewritten to that. 4.x hold on the rewritten `docs/intro.md`. LEFT OPEN, needs a choice: the product page (EN and NL, conduction-website) shows `version="v0.10"` while `appinfo/info.xml` is `0.7.15` (the `v0.10.0-dev` tags of August were abandoned and the line restarted at 0.7). Either the page moves to `v0.7`, or the app's version line moves to 0.10; the scenario "Version drift is corrected" holds once one of the two happens.

- [x] 1.1 Confirm `composer.json` license (`EUPL-1.2`) vs. `info.xml`
      `<licence>agpl</licence>` mismatch, and cross-check SPDX headers.
- [x] 1.2 Grep `lib/`/`src/` for `launchpad` — only an unrelated placeholder
      string; confirm no LaunchPad integration exists.
- [x] 1.3 Grep `lib/`/`src/` for `n8n` — only two comments noting it as an
      external, undelegated concern; confirm no n8n integration code.
- [x] 1.4 Confirm Procest workflow integration is real (`WorkflowAttachmentsSection.vue`,
      `useProcestCase.js`, `ProcestCaseStatusPanel.vue`, `procestLinks.js`).
- [x] 1.5 Confirm DocuDesk and NL Design theme integrations are real (frontend
      composables/components under `src/`).
- [x] 1.6 Confirm export claims: ZIP (`ExportService::packageZip`) and GitHub
      push (`GitHubPushService`) both have concrete implementations.
- [x] 1.7 Confirm config-over-code / fork-free override claim
      (`AppOverrideService` delta-only manifest overrides).
- [x] 1.8 Confirm the 8 MCP tool IDs/names in `lib/Mcp/BuildiqToolProvider.php`
      match the product page's `McpToolShelf` exactly.
- [x] 1.9 Confirm docs deploy topology (`docs/docusaurus.config.js` `url:`)
      to catch the NL page's dead docs link.

## 2. Fix `appinfo/info.xml`

- [x] 2.1 Correct `<licence>agpl</licence>` → `<licence>EUPL-1.2</licence>`.
- [x] 2.2 Remove fabricated "LaunchPad dashboards" from EN+NL `<description>`;
      rename "Conduction ecosystem" → "Technical Core" to match canonical
      fleet vocabulary (`connext.mdx`).
- [x] 2.3 Name openregister as a hard dependency in a comment inside `<dependencies>` (no `<app>` element: the App Store schema has no such child); `src/manifest.json` declares it machine-readably.
- [x] 2.4 Confirm `img/app.svg` matches the white-fill/24×24 convention (no
      change needed).

## 3. Fix product page (EN + NL)

- [x] 3.1 Bump `version` from `v0.3` to `v0.5` (info.xml `0.5.40` is the
      source of truth).
- [x] 3.2 Replace "n8n workflows" with "Procest workflows" in hero tagline/intro.
- [x] 3.3 Mention config-over-code manifest overrides and GitHub export
      alongside the existing ZIP-export/RBAC claims.
- [x] 3.4 Fix NL page's dead `docs.conduction.nl/buildiq` link →
      `openbuild.conduction.nl`.

## 4. Fix docs

- [x] 4.1 `docs/intro.md` frontmatter no longer names Pipelinq (the page was
      rewritten; its description names navigation, pages, data and flows).
- [x] 4.2 No n8n claim left in `docs/intro.md`; `docs/elements/flows.md` says the
      earlier n8n wording was wrong.
- [x] 4.3 `docs/intro.md` says overrides survive upgrades as a delta, and that
      an app exports as a ZIP or publishes to GitHub.

## 5. Record the change

- [x] 5.1 Write `proposal.md` documenting the canonical feature list and every
      reconciliation (verified vs. removed claims).
- [x] 5.2 Write this `tasks.md`.
- [x] 5.3 Write `specs/beta-alignment/spec.md` delta.
