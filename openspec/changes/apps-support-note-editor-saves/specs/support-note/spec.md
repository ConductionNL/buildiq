# support-note

## ADDED Requirements

### Requirement: The support editor saves the note to the app's manifest (REQ-BQSN-001)

The app detail page SHALL offer "Support & donation" in its actions menu to a
user who may edit the app's versions. Choosing it SHALL load the app's
manifest and open the support editor on a working copy. Done in that editor
MUST write the working copy, with its `support` block, to the app with
`PUT /api/applications/{slug}/manifest`, and only then close the editor.
Closing the editor without Done MUST NOT write anything.

@e2e tests/e2e/support-note-editor.spec.ts

#### Scenario: A maker saves a support note

- **GIVEN** a user who may edit the versions of app "subsidieloket"
- **WHEN** they open "Support & donation", switch on "Show the support note on first open", set the title to "Over het Subsidieloket" and click Done
- **THEN** one `PUT /api/applications/subsidieloket/manifest` is sent
- **AND** reopening the editor shows the title "Over het Subsidieloket" with the switch on

#### Scenario: Closing without Done keeps the saved note

- **GIVEN** app "subsidieloket" whose saved note has the title "Over het Subsidieloket"
- **WHEN** a maker changes the title in the editor and closes it with the close button
- **THEN** no PUT is sent
- **AND** reopening the editor shows "Over het Subsidieloket"

#### Scenario: A viewer does not get the action

- **GIVEN** a user who may only view app "subsidieloket"
- **WHEN** they open the app's actions menu
- **THEN** "Support & donation" is not in it

### Requirement: A failed save keeps the editor open (REQ-BQSN-002)

When the save answers with an error, the editor MUST stay open with the
working copy intact, and the page SHALL show "Could not save the support
note" with the reason. The maker MUST be able to click Done again.

@e2e tests/e2e/support-note-editor.spec.ts

#### Scenario: The server refuses the save

- **GIVEN** the manifest endpoint answers 500 for app "subsidieloket"
- **WHEN** a maker edits the note and clicks Done
- **THEN** the editor stays open with the edited title
- **AND** the page shows "Could not save the support note"

### Requirement: A virtual app shows the note only when it is switched on (REQ-BQSN-003)

A virtual app SHALL show the first-open support note only when its manifest
has `support.enabled` set to `true`. A manifest without a `support` block, or
with the switch off, MUST show no note.

@e2e tests/e2e/support-note-editor.spec.ts

#### Scenario: Switched on, the note shows on first open

- **GIVEN** virtual app "subsidieloket" with `support.enabled: true`
- **WHEN** a user opens it for the first time
- **THEN** the support note shows with the saved title

#### Scenario: No support block, no note

- **GIVEN** virtual app "meldingen" without a `support` block
- **WHEN** a user opens it for the first time
- **THEN** no support note shows
