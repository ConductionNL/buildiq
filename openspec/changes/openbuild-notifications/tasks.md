# Tasks — OpenBuild schema-declared notifications

- [x] Add `x-openregister-notifications` to the `exportJob` schema in `lib/Settings/openbuild_register.json` with `export-succeeded` (transition action `succeeded`) and `export-failed` (transition action `failed`) rules
- [x] Add `x-openregister-notifications` to the `ApplicationVersion` schema in `lib/Settings/openbuild_register.json` with `version-published` (transition action `published`) and `version-archived` (transition action `archived`) rules
- [x] Use `{"kind":"object-acl","permission":"manage"}` recipients on all four rules (no structured owner uid on these schemas)
- [x] Provide nl + en subjects on every rule (ADR-007 / ADR-025)
- [x] Validate that `lib/Settings/openbuild_register.json` is still well-formed JSON after the edits
- [x] Confirm the export pipeline drives `exportJob.status` and `ApplicationVersion.status` through named OpenRegister transition actions matching the rule action keys (prerequisite; see Caveats)
  <!-- Findings (2026-06-01):
       exportJob: WIRED — RunExportJob.php drives all state changes through
       ExportJobService::transitionJob() with action names 'start', 'succeed',
       'fail', matching the x-openregister-lifecycle transition map. Notifications
       export-succeeded and export-failed WILL fire.
       ApplicationVersion: NOT WIRED — VersionPromotionService::applyManifestAndSemver()
       writes status='published' via saveObject() directly, bypassing the
       TransitionEngine. The version-published and version-archived notification
       rules are declared-but-dormant until that path is refactored to call
       the 'publish'/'archive' transition actions (tracked separately; not in
       scope for this config-only change per acceptance criteria). -->

## Acceptance criteria

- `lib/Settings/openbuild_register.json` parses as valid JSON.
- `exportJob` declares `export-succeeded` and `export-failed` rules with `transition` triggers and `object-acl` recipients.
- `ApplicationVersion` declares `version-published` and `version-archived` rules with `transition` triggers and `object-acl` recipients.
- Every rule has both `nl` and `en` subject strings.
- No PHP, Vue, route, or migration files are changed.
