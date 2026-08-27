## ADDED Requirements

### Requirement: Register/schema pickers surface the Application's declared dataRegisters

`useRegisterPicker(opts)` SHALL accept an optional `opts.dataRegisters` array
(shape `{ register, label? }`, default `[]`, matching the `Application`
schema's `dataRegisters` property). `fetchRegisters()` SHALL label every
returned register entry whose slug matches a `dataRegisters` binding with
`binding.label ?? binding.register`, and SHALL order the result as: the
per-app register first (existing behaviour, unchanged), then entries matching
a `dataRegisters` binding in declaration order, then the remaining registers
unchanged. `IndexPageEditor`, `DetailPageEditor`, and `LogsPageEditor` SHALL
accept a `dataRegisters` prop and forward it into their `useRegisterPicker`
call; `PageDesigner` SHALL resolve the active Application's `dataRegisters`
and pass them to the mounted sub-editor. When `dataRegisters` is absent or
empty, `fetchRegisters()` SHALL return output identical to its pre-existing
behaviour — this requirement is additive and introduces no regression for an
Application with no declared bindings.

@e2e exclude component-contract spec — dataRegisters labelling/hoisting
inside `fetchRegisters()` and the prop pass-through at each sub-editor are
composable- and component-contract behaviour verified by Vitest unit tests
(`useRegisterPicker.spec.js`, `IndexPageEditor.spec.js`); overall picker
mounting and rendering inside the designer route is covered by the existing
buildiq-page-designer Playwright tests

#### Scenario: A bound data register is labelled in the picker

- **GIVEN** an Application with
  `dataRegisters: [{ "register": "spectr", "label": "Spectr market intelligence data" }]`
- **WHEN** `IndexPageEditor` mounts and calls `fetchRegisters()`
- **THEN** the `spectr` register entry in the returned list carries
  `label: "Spectr market intelligence data"`

#### Scenario: A bound data register without a label falls back to its slug

- **GIVEN** an Application with `dataRegisters: [{ "register": "spectr" }]`
  (no `label`)
- **WHEN** a register/schema-backed sub-editor calls `fetchRegisters()`
- **THEN** the `spectr` register entry's resolved label is `"spectr"`

#### Scenario: An Application with no dataRegisters is unaffected

- **GIVEN** an Application whose `dataRegisters` is absent
- **WHEN** any of `IndexPageEditor`, `DetailPageEditor`, or `LogsPageEditor`
  mounts and calls `fetchRegisters()`
- **THEN** the returned list is unchanged from the pre-existing behaviour —
  only the per-app register is hoisted, no entry carries a new `label` field
