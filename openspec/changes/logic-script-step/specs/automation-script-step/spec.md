# Spec: automation-script-step

## Purpose

An app owner adds a script step to an automation when the built-in steps cannot
express what is needed. The script is JavaScript that takes the flow's items and
returns items, and it runs only in OpenRegister's sandboxed code runner.

## ADDED Requirements

### Requirement: The composer offers a script step (REQ-BQSS-001)

The automation composer SHALL offer "Run a script" when the instance setting
"Allow script steps" is on and OpenRegister's node catalogue lists the code step.
The step SHALL hold the code, a timeout between 1 and 30 seconds, and a note.
Otherwise the kind SHALL be disabled with the reason.

#### Scenario: An owner adds a script step

- **GIVEN** an app owner composing an automation, with script steps allowed and the code runner installed
- **WHEN** they add "Run a script", write code that joins `voornaam` and `achternaam` into `naam`, and save
- **THEN** the automation saves with a script step holding that code

#### Scenario: No runner, no script step

- **GIVEN** an instance without OpenRegister's code runner
- **WHEN** a maker opens the action kind list
- **THEN** "Run a script" is disabled and says the code runner is not installed

### Requirement: Only app owners change script steps (REQ-BQSS-002)

Adding, changing or removing a script step SHALL require the `owners` role on the
app without admin bypass, checked on the server in the automation write and the
compile route. An editor SHALL be able to read the code and SHALL be refused a
change with "Only app owners can change a script step."

#### Scenario: An editor cannot change the code

- **GIVEN** a colleague with the `editors` role on app `permits`
- **WHEN** they change the code of a script step and save
- **THEN** the save is refused with "Only app owners can change a script step." and the stored code is unchanged

### Requirement: The script runs only in the code runner (REQ-BQSS-003)

A script step SHALL compile to OpenRegister's code step and to nothing else.
Buildiq SHALL NOT evaluate the code on save, in the dry run or in the browser.
Buildiq SHALL declare no network egress for the step. Turning "Allow script
steps" off SHALL disable every automation holding a script step with the reason
recorded.

#### Scenario: The dry run does not execute the script

- **GIVEN** an automation with a record step and a script step
- **WHEN** a maker runs the dry run
- **THEN** the result lists the script step as "runs in the code runner" and no code is executed

#### Scenario: An administrator turns scripts off

- **GIVEN** two enabled automations with script steps
- **WHEN** an administrator turns "Allow script steps" off
- **THEN** both automations are disabled, and the automations page says script steps are turned off for this instance

### Requirement: A maker tests a script on sample items (REQ-BQSS-004)

The composer SHALL offer "Test script", which runs the code step alone in the
code runner on sample items the maker provides, and SHALL show the returned items
or the error. The test SHALL NOT write any record.

#### Scenario: A test shows the result

- **GIVEN** an owner with a script that uppercases `postcode`
- **WHEN** they enter a sample item with `postcode` `1234ab` and click "Test script"
- **THEN** the composer shows the returned item with `postcode` `1234AB`, and no record changed
