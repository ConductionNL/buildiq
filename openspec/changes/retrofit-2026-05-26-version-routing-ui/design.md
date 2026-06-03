# Design — version-routing-ui (retrofit)

Retrofit change. Tasks describe retroactive annotation, not new implementation
work. The version-history / promotion / rollback UI already ships; this records
its observed behaviour as numbered REQs so gate-16 spec-coverage can trace each
method to a requirement. No behaviour is changed.

`VersionHistory` lists OR object-time-travel snapshots and offers compare +
rollback. `PromoteVersionDialog` computes a default promotion strategy and gates
the destructive confirm. `RollbackConfirmModal` is the rollback gate. The
composables resolve the active version and load the manifest history.
