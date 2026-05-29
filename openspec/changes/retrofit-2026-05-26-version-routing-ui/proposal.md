# Retrofit — version-routing-ui

Describes observed behaviour of the version-history / promotion / rollback UI —
the `VersionHistory` view, the `PromoteVersionDialog`, the
`RollbackConfirmModal`, and the version composables
(`useApplicationVersion`, `useManifestHistory`, `useApplicationVersion`'s
default helper) — as 4 new REQs.

Code already exists (it implements the `version-routing`, `version-promotion`,
and `openbuild-version-snapshots` backend capabilities). This change
retroactively specifies the frontend behaviour so gate-16 spec-coverage can
trace each method.

## Approach
- Describe observed time-travel listing, compare/rollback gating, promotion
  strategy computation, and version resolution per component/composable.
- REQs match behaviour, not aspiration. No behaviour is changed.
