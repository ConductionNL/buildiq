## 1. Schema fragment (register.d)

- [x] 1.1 Grep `lib/Settings/register.d/*.json` and `lib/Settings/openbuild_register.json` for any existing `dataRegisters` property name on the `Application` schema (ADR-012 dedup check) before authoring the fragment
- [x] 1.2 Create `lib/Settings/register.d/20-data-registers.json` declaring the optional `dataRegisters` array property on the `Application` schema — shape, titles, and descriptions exactly per design.md Decision 1 and specs `REQ-OBA-010` (array of `{ register, label? }`, `additionalProperties: false` on the item, English title + description on every property and sub-property per gate-28)
- [x] 1.3 Confirm the fragment's JSON path is scoped to `components.schemas.Application.properties.dataRegisters` only — no edit to `Application.required`, no edit to any other `Application` property, no edit to `ApplicationVersion` or any other schema

## 2. Seed-data fixtures

- [x] 2.1 Add the two design.md seed-data examples (the `spectr` Application and the generic-municipality Application) as fixture JSON reusable by the follower spec's tests and by manual QA — no live object is created by this change

## Quality reminders (run before requesting review — not tracked as tasks)

- Run `openspec validate data-registers-schema-declaration --strict` and resolve any structural errors.
- Validate both seed-data fixtures (task 2.1) against the merged `Application` schema with a jq/ajv check — both must pass.
- Confirm the existing seeded `hello-world` Application (no `dataRegisters` field) still validates against the merged schema — the property must be truly optional.
- Confirm `SettingsService::doLoadConfiguration()` picks up the new fragment on the next repair-step run and that OpenRegister's `ConfigurationService::importFromApp()` re-imports without error (ADR-037 fragment-hash version bump).

## Acceptance Criteria

- The `Application` schema in the `openbuild` register exposes an optional `dataRegisters` array property matching design.md Decision 1's shape after the repair step runs.
- An Application saved with a valid `dataRegisters` binding (single or multiple entries) round-trips byte-for-byte via OR REST.
- An Application saved without `dataRegisters` is accepted and reads back as an empty array — full back-compat with every pre-existing Application.
- A `dataRegisters` entry missing the required `register` key is rejected with a 4xx.
- A `dataRegisters` entry carrying an unrecognised sub-property is rejected with a 4xx (`additionalProperties: false`).
- A `dataRegisters` entry whose `register` value fails the kebab-case pattern is rejected with a 4xx.
- No PHP, Vue, or route file is touched by this change — only `lib/Settings/register.d/20-data-registers.json` plus seed-data fixture files.
