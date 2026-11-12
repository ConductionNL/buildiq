# lifecycle-create-states Specification

## Purpose
Which lifecycle states an application version may be created in, so an app that is installed or migrated already live does not have to be created as a draft and released afterwards.

## Requirements

### Requirement: An application version may be created already published

The `ApplicationVersion` schema SHALL list `published` in
`x-openregister-lifecycle.createStates`, so a hybrid app, its migration
and the hello-world fixture can create their version in `published`.

#### Scenario: a hybrid app is created

- **WHEN** an administrator creates a hybrid app
- **THEN** its version is created in `published`, which the schema lists in `createStates`
- @e2e exclude {register declaration; covered by `ApplicationVersionLifecycleSchemaTest::testAVersionMayBeCreatedPublished`}
