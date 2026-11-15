---
kind: code
depends_on: []
---

# Proposal: lifecycle-create-states

## Summary

OpenRegister now refuses a create whose lifecycle value is neither the
schema's `initial` nor one of its `createStates` (openregister#5400). buildiq
creates an `ApplicationVersion` already `published` in three places:

- `AppOverrideService::createHybridApp()`;
- the `MigrateAppOverridesToHybrid` repair, which goes through it;
- the `SeedHelloWorldFixture` command, which seeds the end-to-end tests.

The schema now lists `published` in `x-openregister-lifecycle.createStates`.
An OpenRegister without #5400 ignores the key, so this can merge before #5400
or with it.
