---
kind: code
---

## Why

Three read endpoints behind the app detail page answered wrong for real apps.

- The Manifest tab answered 404 for every app without a route index entry. Apps
  installed by the seed, a template or GitHub have none, Hello World included.
- The Diff tab never showed a diff. It used the same route-only lookup, it
  treated every real version as belonging to another app (it read an
  `applicationUuid` field no version has), and it only took UUIDs while the spec
  names versions by slug.
- The object count read 0 while the data was there. It only counted pages whose
  register equals the version's register, so Hello World (data in `buildiq`)
  and any schema without a page counted nothing.

## What changes

- `ApplicationsController::resolveApplicationBySlug()` falls back to the
  Application's own slug when no route entry exists. RBAC is unchanged.
- `diffVersions()` uses that lookup, accepts version slugs and UUIDs, checks the
  version's `application` relation, and returns `semver` and `name` per side.
- The insights endpoint counts every schema the version's register lists plus
  the schemas its pages name there. A version without a register counts where its
  pages point. The payload gains `schemaCounts` (per schema id and slug) for the
  Schemas widget. The resolution lives in the new `VersionDataSourceResolver`.

No new routes. The insights payload only gains a key.

## Capabilities

### Modified

- `openbuild-runtime`: REQ-OBR-001 resolves a slug without a route entry.
- `application-insights`: REQ-OBAI-003 derives the data sources as above.
