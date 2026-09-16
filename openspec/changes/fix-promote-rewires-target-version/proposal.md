---
kind: code
---

## Why

Promoting development to production answered 200 and left production broken.
Production's pages still read and wrote the development register, so production
listed development's records. The production register was handed development's
schema ids, so a field added in development never reached the production schemas.
Production's own schemas were left attached to no register, so deleting the app
with its data left them behind.

## What changes

- `VersionPromotionService` matches every schema namespaced to the source version
  (`{app}-{source}-{name}`) to its target counterpart (`{app}-{target}-{name}`). An
  existing counterpart takes the source definition. A missing one is created. Shared
  schemas stay as they are. The target register lists the target's own schemas.
- The manifest copied onto the target has every `register` and `schema` value that
  names the source version rewritten to the target version. Values naming anything
  else (a bound data register, a shared schema) are left alone.
- Rows copied by `start-with-source-data` land in the target version's schemas.
- `ApplicationDeletionService` also finds a version's schemas by their namespaced
  slug, so schemas a register no longer lists are drained and deleted with the data.

No new routes or schemas. No breaking changes for callers of the promote endpoint.

## Capabilities

### Modified

- `version-promotion`: REQ-OBVP-005 now describes carrying the schema set over to
  the target version's own schemas and rewiring the manifest.
