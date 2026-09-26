## MODIFIED Requirements

### Requirement: Schema diff handling deferred to OR

Every version owns its own copies of the app's schemas, named
`{app}-{version}-{name}` in its own register `openbuild-{app}-{version}`. The
promotion endpoint SHALL carry the source's schema set over to the target version's
own schemas:

- For each source schema named `{app}-{source}-{name}`, the target schema
  `{app}-{target}-{name}` SHALL receive the source schema's definition (title,
  description, properties, required fields, configuration and the other definition
  fields). When no such target schema exists it SHALL be created.
- A source schema that is not namespaced to the source version is shared and SHALL
  be kept as is.
- The target register SHALL list the target's own schemas, never the source's
  namespaced schemas.
- The manifest written onto the target SHALL have every `register` value equal to
  the source register rewritten to the target register, and every `schema` value
  naming a synced source schema (by slug or id) rewritten to its target counterpart.
  Other values SHALL be left unchanged.

OR's own breaking-change handling drives the outcome of each schema update. The
endpoint SHALL NOT implement a buildiq-side schema-diff, dry-run, or breaking-change
preflight. If OR fails a schema update or create, the endpoint SHALL treat that as a
promotion failure (REQ-OBVP-009) and the on-failure status flip applies.

@e2e exclude backend promotion wiring, covered by VersionPromotionServiceTest; the promote dialog flow has its own e2e scenario

**ID:** REQ-OBVP-005

#### Scenario: Promotion carries a new field to the target version's schema

- **GIVEN** an app with versions development and production, each with its own
  `{app}-{version}-order` schema
- **AND** a field `priority` was added to the development schema
- **WHEN** an owner promotes development to production
- **THEN** the production schema `{app}-production-order` has the field `priority`
- **AND** the production register lists `{app}-production-order`, not the
  development schema

#### Scenario: Promotion rewires the target manifest to the target register

- **GIVEN** a development manifest whose pages read `openbuild-{app}-development`
  and `{app}-development-order`
- **WHEN** an owner promotes development to production
- **THEN** the production manifest's pages read `openbuild-{app}-production` and
  `{app}-production-order`
- **AND** a page bound to another register keeps that register

#### Scenario: A schema failure triggers the on-failure flow

- **GIVEN** OR fails to update the target schema
- **WHEN** the promotion endpoint carries the schema set over
- **THEN** the promotion is treated as failed
- **AND** the target's `status` flips to `archived` per REQ-OBVP-009
- **AND** the endpoint returns `500 Internal Server Error`
