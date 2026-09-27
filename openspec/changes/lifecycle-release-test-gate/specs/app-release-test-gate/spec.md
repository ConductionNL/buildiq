# Spec: app-release-test-gate

## Purpose

A maker knows an app version works before it reaches its users. Each version has
a test suite: structure checks buildiq derives from the app, the tests of the rule
sets the app owns, and record tests the maker writes. Releasing runs the suite and
stops on a failure, unless an owner releases anyway with a reason that is kept.

## ADDED Requirements

### Requirement: The suite checks the app's structure (REQ-BQRT-001)

The test suite SHALL check that every page's register and schema exist, that every
index column and detail field names a property its schema has, and that every menu
item leads to a page of the version. Each failure SHALL name the page or menu item
and what is missing.

#### Scenario: A removed property fails the suite

- **GIVEN** the development version of `vergunningen`, whose index page `permits` has a column `kvkNumber`, and a schema `permit` from which that property was removed
- **WHEN** a maker runs the tests
- **THEN** the report shows one failed structure check: page `permits`, column `kvkNumber`, no such property on `permit`

### Requirement: The suite runs the app's rule-set tests (REQ-BQRT-002)

The suite SHALL run the stored test cases of every rule set whose owner app is this
app, and SHALL report each failing case by rule set and case name. Rule sets of
other apps SHALL NOT be run.

#### Scenario: A fee rule test fails

- **GIVEN** the rule set `permit-fees` owned by `vergunningen`, with a test case "Large build pays 1200" that now returns 900
- **WHEN** a maker runs the tests
- **THEN** the report shows "permit-fees: Large build pays 1200" as failed

### Requirement: A maker writes record tests (REQ-BQRT-003)

A maker with the owner or editor role SHALL be able to add a record test to a schema
of the app: a name, a sample record, whether the record should be accepted or
refused, and for a refusal the fields it should be refused on. The suite SHALL run
each record test against the version's schema without saving the record. While
OpenRegister cannot validate without saving, a record test SHALL be reported as
skipped with that reason, never as passed.

#### Scenario: A permit without an applicant is refused

- **GIVEN** a record test "Permit needs an applicant" on schema `permit` with a record that has no `applicant` and expects a refusal on `applicant`
- **WHEN** a maker runs the tests
- **THEN** the report shows the test as passed, and no permit record was created

### Requirement: Release waits for a green suite (REQ-BQRT-004)

Releasing a version, and promoting a version onto production, SHALL run the suite
first and SHALL stop with the report when any check fails, writing nothing. An
owner SHALL be able to release anyway by giving a reason; an editor SHALL NOT.

#### Scenario: A broken version does not reach production

- **GIVEN** the development version of `vergunningen` with a failing structure check
- **WHEN** the owner clicks "Release"
- **THEN** the release stops, the dialog lists the failed check, and production is unchanged

#### Scenario: An owner releases a hotfix anyway

- **GIVEN** the same failing check, judged harmless by the owner
- **WHEN** the owner chooses "Release anyway" and writes "Column removed on purpose, page fixed in next release"
- **THEN** the version is released, and the test run records the failure, the owner and the reason

### Requirement: Every run is kept (REQ-BQRT-005)

Each run SHALL be stored with the version, time, trigger (manual, release or
promotion), counts, the report and any override. The app's "Tests" tab SHALL show
the latest run per version.

#### Scenario: A maker checks the last run before release

- **GIVEN** a manual run on the development version this morning with 42 passed and 1 skipped
- **WHEN** a maker opens the "Tests" tab of `vergunningen`
- **THEN** the tab shows this morning's run for development with 42 passed, 1 skipped and the reason for the skip
