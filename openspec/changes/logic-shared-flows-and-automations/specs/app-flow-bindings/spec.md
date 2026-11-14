# Spec: app-flow-bindings

## Purpose

A maker attaches an existing flow or a shared automation to an app and says where
it runs: on the app's records when they change, or from a record by an app user.
Buildiq wires it with a small flow of its own and never edits the attached one.

## ADDED Requirements

### Requirement: A bound flow declares where it runs (REQ-BQSF-001)

Each flow binding on an application SHALL accept `runOn[]` entries naming one of
the app version's schemas with an object event, or `from-record` with a schema.
The app settings dialog SHALL offer a "Run on" editor per bound flow that lists
only the app version's schemas.

#### Scenario: A maker runs a shared archive flow on closed cases

- **GIVEN** a maker in the settings of app `permits` with flow `Archiveer dossier` bound
- **WHEN** they add "Run on" `vergunning` with event updated and save
- **THEN** the binding carries `runOn` with schema `vergunning` and event `object.updated`

### Requirement: A binding runs the flow on the app's records (REQ-BQSF-002)

For each `runOn` entry buildiq SHALL save a wrapper flow with the matching
trigger on the app's register and schema and an `openregister.sub-flow` step that
calls the bound flow, SHALL bind the wrapper to the app, and SHALL remove it when
the entry or the binding is removed. The bound flow SHALL NOT be modified.

#### Scenario: A record change runs the attached flow

- **GIVEN** the binding from REQ-BQSF-001
- **WHEN** an app user updates a `vergunning`
- **THEN** the wrapper flow runs and starts `Archiveer dossier` with that record, and `Archiveer dossier` itself is unchanged

### Requirement: An automation can be shared with other apps (REQ-BQSF-003)

An automation SHALL carry `shared`, settable only by an `owners` member of its
app. The compiler SHALL refuse to share an automation whose steps write a schema
of the source app. Other apps' flow pickers SHALL list shared automations under
"Shared automations" with the source app's name.

#### Scenario: A second app attaches a shared automation

- **GIVEN** app `permits` with a shared automation "Stuur bevestiging"
- **WHEN** a maker of app `subsidies` opens the flow picker
- **THEN** "Stuur bevestiging (permits)" is listed under "Shared automations" and can be bound with a "Run on" entry

#### Scenario: An editor cannot share

- **GIVEN** a colleague with the `editors` role on app `permits`
- **WHEN** they try to mark an automation shared
- **THEN** the save is refused and the automation stays unshared

### Requirement: An automation can call another (REQ-BQSF-004)

The composer SHALL offer "Run another automation", naming a shared automation or
one of the same app. It SHALL compile to `openregister.sub-flow` that waits for
the result. A direct self reference SHALL be refused at compile.

#### Scenario: A step reuses a shared check

- **GIVEN** a shared automation "Controleer adres" that sets `adresGeldig`
- **WHEN** a maker adds "Run another automation" with it before a record step that reads `adresGeldig`
- **THEN** the compiled flow holds a sub-flow step that waits, followed by the record step

### Requirement: The app shows where its flows run (REQ-BQSF-005)

The flows widget on the app detail page SHALL show, for each bound flow, its
`runOn` entries and the status and time of its last run.

#### Scenario: A maker sees a failed run

- **GIVEN** a bound flow whose last run failed
- **WHEN** a maker opens the app detail page
- **THEN** the flows widget shows the flow with "Runs on vergunning, updated" and "Last run failed" with its time
