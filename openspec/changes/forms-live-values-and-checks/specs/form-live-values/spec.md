# Spec: form-live-values

## Purpose

A form a maker builds prefills what is already known, calculates what follows
from the answers, and tells the person filling it in straight away when a
condition is not met and why. Calculations and checks come from rule sets, the
same ones buildiq already authors and tests, and the server has the last word.

## ADDED Requirements

### Requirement: A field can be prefilled from the user or the record (REQ-BQLV-001)

A form field SHALL accept a `default` that is a literal, a user token (`@me`,
`@me.displayName`, `@me.email`), `@today`, or `@object.<field>` when the form
opens from a record. The form SHALL show the resolved value when it opens, and
the person SHALL be able to change it.

#### Scenario: The applicant's e-mail is filled in

- **GIVEN** a maker set the default of `applicantEmail` to "Your e-mail" (`@me.email`) on the permit form
- **WHEN** a signed-in colleague opens the form in the published app
- **THEN** `applicantEmail` shows the colleague's e-mail address and can be edited

### Requirement: A field can be calculated from a rule set (REQ-BQLV-002)

A form field SHALL accept `calculate` with a rule set, an output and the answers
it reads. The form SHALL re-evaluate it when one of those answers changes and
show the output read-only.

#### Scenario: The fee follows the size of the event

- **GIVEN** a rule set `event-fee` with a decision table from `attendees` to `fee`, and a form field `fee` calculated from it
- **WHEN** an app user enters 250 attendees
- **THEN** `fee` shows the amount the table gives for 250 attendees without the user leaving the field, and changes when they enter 40

### Requirement: A form can check eligibility as it is filled in (REQ-BQLV-003)

A form SHALL accept an eligibility check: a rule set, the output value that
means eligible, the output that explains, and whether submit waits for a pass.
The form SHALL show the explanation of an unmet condition beside the form while
it is filled in, and with `blockSubmit` SHALL keep submit disabled with that
explanation.

#### Scenario: The applicant learns why they do not qualify

- **GIVEN** a loan form with an eligibility check on `loan-eligibility`, passing when `decision` is `approved` and explaining with `reason`, and submit blocked while unmet
- **WHEN** an applicant enters a monthly income below the table's threshold
- **THEN** the form shows "Eligibility criteria not met" beside the form and the submit button stays disabled

### Requirement: Live evaluation leaves no log trail (REQ-BQLV-004)

`POST /api/rules/{ruleSetSlug}/evaluate` SHALL accept `mode: preview`, which
applies the same authentication, RBAC, rate limit and size guard, and SHALL NOT
write a rule execution log entry.

#### Scenario: Typing in a form writes no execution log

- **GIVEN** a form with a calculated fee
- **WHEN** an app user changes the attendees field five times
- **THEN** no rule execution log entry is written for those five evaluations

### Requirement: The server recomputes before a save (REQ-BQLV-005)

When a record is created or updated in a schema that a form with calculated
fields targets, buildiq SHALL re-evaluate each calculated field with the
record's values and store the server's result. A blocking eligibility check
that fails on the server SHALL refuse the save with its explanation.

#### Scenario: A fee changed in the browser is corrected

- **GIVEN** a submitted permit whose `fee` was altered in the browser to 0
- **WHEN** the record is saved
- **THEN** the stored `fee` is the amount the rule set gives for the submitted attendees, and the evaluation is logged
