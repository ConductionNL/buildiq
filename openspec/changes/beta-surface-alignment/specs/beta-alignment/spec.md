# Capability: beta-alignment

## ADDED Requirements

### Requirement: Public-facing surfaces SHALL only claim verified, shipped capabilities (REQ-BA-001)

Buildiq's `appinfo/info.xml` description, `src/manifest.json` nav/menu
labels, the `conduction.nl/apps/buildiq` product page (EN + NL), and the
`openbuild.conduction.nl` docs MUST only describe composition sources,
license, and features that are demonstrably implemented in `lib/`/`src/` at
the time of writing. A composition source or feature name MUST NOT appear on
a public surface unless it is traceable to a concrete class/component.

#### Scenario: Reviewer checks a composition-source claim against code

- **GIVEN** a claim on the product page (e.g. "Procest workflows")
- **WHEN** the reviewer greps `lib/`/`src/` for the corresponding
  service/component
- **THEN** a concrete implementation MUST exist (e.g. `WorkflowAttachmentsSection.vue`,
  `useProcestCase.js`)

#### Scenario: A fabricated or aspirational integration MUST NOT be marketed

- **GIVEN** a claimed integration (e.g. "LaunchPad dashboards", "n8n workflows")
  with no corresponding service, component, or registered provider in `lib/`/`src/`
- **THEN** that claim MUST NOT appear on `info.xml`, the product page, or the
  docs until it is actually implemented

### Requirement: `info.xml`, product page, and docs SHALL use one shared feature vocabulary, version, and license (REQ-BA-002)

The `<licence>`/`<description>` in `appinfo/info.xml`, the product page's
hero/FeatureList copy, and the docs `intro.md` MUST name the same
capabilities using the same terms (aligned to the fleet's "Technical Core"
vocabulary), the product page's `version` prop MUST match `info.xml`'s
`<version>` (the source of truth), and `<licence>` MUST match `composer.json`'s
declared license and every source file's SPDX header.

#### Scenario: License mismatch is corrected

- **GIVEN** `composer.json` declares `"license": "EUPL-1.2"` and every `lib/`
  file's SPDX header reads `EUPL-1.2`
- **WHEN** `appinfo/info.xml` is reviewed
- **THEN** its `<licence>` element MUST read `EUPL-1.2`, not a different
  license identifier

#### Scenario: Version drift is corrected

- **GIVEN** `info.xml` version `0.5.40`
- **WHEN** the product page hero is rendered
- **THEN** its `version` prop MUST read `v0.5`, not a stale prior value

#### Scenario: A hard runtime dependency is declared

- **GIVEN** `src/manifest.json` declares `"dependencies": ["openregister"]`
  as a hard requirement
- **WHEN** `appinfo/info.xml` `<dependencies>` is reviewed
- **THEN** it MUST include `<app>openregister</app>`
