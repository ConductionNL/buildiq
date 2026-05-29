# Tasks — OpenBuild schema-declared notifications

- [x] Add `x-openregister-notifications` to the `exportJob` schema in `lib/Settings/openbuild_register.json` with `export-succeeded` (transition action `succeeded`) and `export-failed` (transition action `failed`) rules
- [x] Add `x-openregister-notifications` to the `ApplicationVersion` schema in `lib/Settings/openbuild_register.json` with `version-published` (transition action `published`) and `version-archived` (transition action `archived`) rules
- [x] Use `{"kind":"object-acl","permission":"manage"}` recipients on all four rules (no structured owner uid on these schemas)
- [x] Provide nl + en subjects on every rule (ADR-007 / ADR-025)
- [x] Validate that `lib/Settings/openbuild_register.json` is still well-formed JSON after the edits
- [ ] Confirm the export pipeline drives `exportJob.status` and `ApplicationVersion.status` through named OpenRegister transition actions matching the rule action keys (prerequisite; see Caveats)

## Acceptance criteria

- `lib/Settings/openbuild_register.json` parses as valid JSON.
- `exportJob` declares `export-succeeded` and `export-failed` rules with `transition` triggers and `object-acl` recipients.
- `ApplicationVersion` declares `version-published` and `version-archived` rules with `transition` triggers and `object-acl` recipients.
- Every rule has both `nl` and `en` subject strings.
- No PHP, Vue, route, or migration files are changed.
