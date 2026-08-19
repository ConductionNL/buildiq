## ADDED Requirements

### Requirement: An app's agents are resolved WITHOUT a binding

The exporter MUST resolve an application's agents by querying `agent`
objects whose `applicationSlug` matches the application's slug, filtered to
the `openbuild` register and `agent` schema BY SLUG, never by numeric
register/schema id.

Numeric register/schema ids in OpenRegister are auto-increment columns
assigned per instance at creation time. They are NOT stable across a fresh
install — the `openbuild` register and `agent` schema get whatever ids
happen to be next on a given instance. A resolver pinned to one instance's
numeric ids matches nothing on any other instance: the underlying
`findAll()` call returns an empty result rather than an error, so the
export completes successfully having silently bundled zero agents.

@e2e exclude pure-backend register/schema-id-vs-slug resolution contract — verified by `FlowAndAgentExportBundlerTest::testAgentsAreResolvedByApplicationSlugRatherThanABinding`, which asserts the `findAll()` filter carries the slugs, and by a live docker-compose round trip on a fresh instance (see tasks.md #4); no Playwright-testable UI surface distinguishes a numeric-id lookup from a slug lookup — both produce the same UI when they happen to agree, which is exactly the defect this requirement guards against

#### Scenario: Agent resolution is portable across instances
- **WHEN** an application's agents are resolved for export
- **THEN** the register/schema filter MUST identify the `openbuild`
  register and `agent` schema by their SLUGS
- **AND** the resolution MUST succeed identically regardless of what
  numeric ids those register and schema rows happen to have on the running
  instance
