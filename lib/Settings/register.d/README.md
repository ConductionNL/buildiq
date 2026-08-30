# Register fragments (ADR-037)

Drop modular OpenRegister configuration fragments here as `*.json` files.

`SettingsService::doLoadConfiguration()` deep-merges every `register.d/*.json`
file (sorted by filename) onto the base `openbuild_register.json` before handing
the result to OpenRegister's `ConfigurationService::importFromApp()`. The merge
folds a fragment-content hash into the imported version, so OpenRegister's
version-gated import re-runs whenever any fragment changes.

## Why

Each OpenSpec change adds its own fragment file instead of editing the shared
`openbuild_register.json` monolith. Concurrent same-app builds therefore touch
disjoint files and never collide on the register on merge.

## Merge rules (`deepMergeConfig`)

- Associative arrays (OpenAPI objects such as `components.schemas` and `paths`)
  merge by key union, recursing on shared keys.
- List arrays are concatenated.
- Scalars in the fragment overwrite the base.

Keep fragments disjoint (own schema names / paths) so they union cleanly.
