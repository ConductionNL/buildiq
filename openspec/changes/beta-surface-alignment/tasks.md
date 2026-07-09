## 1. Verify claims against `lib/` and `src/` at HEAD

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
- [x] 1.8 Confirm the 8 MCP tool IDs/names in `lib/Mcp/OpenBuildToolProvider.php`
      match the product page's `McpToolShelf` exactly.
- [x] 1.9 Confirm docs deploy topology (`docs/docusaurus.config.js` `url:`)
      to catch the NL page's dead docs link.

## 2. Fix `appinfo/info.xml`

- [x] 2.1 Correct `<licence>agpl</licence>` → `<licence>EUPL-1.2</licence>`.
- [x] 2.2 Remove fabricated "LaunchPad dashboards" from EN+NL `<description>`;
      rename "Conduction ecosystem" → "Technical Core" to match canonical
      fleet vocabulary (`connext.mdx`).
- [x] 2.3 Add `<app>openregister</app>` to `<dependencies>`.
- [x] 2.4 Confirm `img/app.svg` matches the white-fill/24×24 convention (no
      change needed).

## 3. Fix product page (EN + NL)

- [x] 3.1 Bump `version` from `v0.3` to `v0.5` (info.xml `0.5.40` is the
      source of truth).
- [x] 3.2 Replace "n8n workflows" with "Procest workflows" in hero tagline/intro.
- [x] 3.3 Mention config-over-code manifest overrides and GitHub export
      alongside the existing ZIP-export/RBAC claims.
- [x] 3.4 Fix NL page's dead `docs.conduction.nl/openbuild` link →
      `openbuild.conduction.nl`.

## 4. Fix docs

- [x] 4.1 Correct `docs/intro.md` frontmatter description ("Pipelinq" → "Procest
      workflows").
- [x] 4.2 Replace "n8n workflow" with "Procest workflow" in the Data wiring bullet.
- [x] 4.3 Add a config-over-code bullet and extend the export bullet to
      mention GitHub push, not just ZIP.

## 5. Record the change

- [x] 5.1 Write `proposal.md` documenting the canonical feature list and every
      reconciliation (verified vs. removed claims).
- [x] 5.2 Write this `tasks.md`.
- [x] 5.3 Write `specs/beta-alignment/spec.md` delta.
