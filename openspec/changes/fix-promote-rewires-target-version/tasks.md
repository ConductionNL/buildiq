## 1. Promotion

- [x] 1.1 `VersionPromotionService::forwardSchemaSetToOR()` syncs each namespaced source schema onto its target counterpart (update or create) and sets the target register to the target's own schema ids.
- [x] 1.2 `VersionPromotionService::rewriteManifestWiring()` rewrites `register` / `schema` values from the source version to the target version on the manifest copy.
- [x] 1.3 `copyRowsFromSource()` saves copied rows under the target schema id.
- [x] 1.4 Unit tests: `testMigrateRewiresManifestAndCarriesSchemaChangesToTargetSchemas`, `testStartWithSourceDataCreatesMissingTargetSchemaAndCopiesRowsIntoIt` (both fail on the old code).

## 2. Deletion

- [x] 2.1 `ApplicationDeletionService::findStraySchemaIds()` finds `{app}-{version}-{name}` schemas no register lists, and `deleteRegister()` drains and deletes them.
- [x] 2.2 Unit test: `testDeleteDataTrueDeletesAVersionSchemaNoRegisterLists` (fails on the old code).

## 3. Verify

- [x] 3.1 Reproduce on a live instance: promote development to production with `migrate-existing-data`, then read the production manifest, the production register's schema list and the production schema's properties.
