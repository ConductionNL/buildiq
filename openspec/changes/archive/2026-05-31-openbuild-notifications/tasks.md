# Tasks — OpenBuild schema-declared notifications

- [x] Add `x-openregister-notifications` to the `exportJob` schema in `lib/Settings/openbuild_register.json` with `export-succeeded` (transition action `succeed`) and `export-failed` (transition action `fail`) rules
- [x] Add `x-openregister-notifications` to the `ApplicationVersion` schema in `lib/Settings/openbuild_register.json` with `version-published` (transition action `publish`) and `version-archived` (transition action `archive`) rules
- [x] Use `{"kind":"object-acl","permission":"manage"}` recipients on all four rules (no structured owner uid on these schemas)
- [x] Provide nl + en subjects on every rule (ADR-007 / ADR-025)
- [x] Validate that `lib/Settings/openbuild_register.json` is still well-formed JSON after the edits
- [x] Confirm the export pipeline drives `exportJob.status` and `ApplicationVersion.status` through named OpenRegister transition actions matching the rule action keys (prerequisite; see Caveats). **Outcome:** the rule action keys were corrected from destination-state names (`succeeded`/`failed`/`published`/`archived`) to the actual lifecycle transition action names (`succeed`/`fail`/`publish`/`archive`) — OR's `AnnotationNotificationDispatcher::matches()` compares against the transition NAME, not the state. The `exportJob` pipeline (`RunExportJob` → `ExportJobService::transitionJob()`) already drives `start`/`succeed`/`fail` through OR's `TransitionEngine`, so `exportJob` rules fire end-to-end. `ApplicationVersion` publish/archive currently write `status` directly in `VersionPromotionService` (no `ObjectTransitionedEvent`), so those rules stay declared-but-dormant until that pipeline is routed through `TransitionEngine` (out of scope here; the keys are already correct, so the rules light up automatically once it is). Alignment pinned by `ApplicationVersionLifecycleSchemaTest::testNotificationActionsMatchLifecycleTransitionNames`.

## Acceptance criteria

- `lib/Settings/openbuild_register.json` parses as valid JSON.
- `exportJob` declares `export-succeeded` and `export-failed` rules with `transition` triggers (action keys `succeed` / `fail`) and `object-acl` recipients.
- `ApplicationVersion` declares `version-published` and `version-archived` rules with `transition` triggers (action keys `publish` / `archive`) and `object-acl` recipients.
- Every `transition` rule's `trigger.action` matches a transition name declared in the same schema's `x-openregister-lifecycle.transitions` (the engine matches the transition action name, not the destination state). Pinned by a unit test.
- Every rule has both `nl` and `en` subject strings.
- No Vue, route, or migration files are changed. The only PHP touched is a unit test (`tests/Unit/ApplicationVersionLifecycleSchemaTest.php`) that pins the action-name alignment; no production PHP is changed.
