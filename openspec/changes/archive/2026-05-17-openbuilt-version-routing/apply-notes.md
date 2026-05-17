# Apply Notes — openbuilt-version-routing

Applied: 2026-05-15

## Implementation Decisions

### OR compound-filter limitation (PHP fallback path)

`findVersionBySlug()` first tries an OR `searchObjects` with a compound filter on
`application` (relation UUID) + `slug`. In practice, OR's compound relation-filter
can be unreliable depending on the OR version in the environment. The service falls
back to `findVersionBySlugFallback()` which fetches all ApplicationVersions for the
register and filters PHP-side. This is slightly less efficient but always correct.

### Vue 2.7 Composition API import path

`ref` and `watch` are imported from `'vue'` (not `'@vue/composition-api'`) because
this project uses Vue 2.7 which ships the Composition API built-in. All four builder
views use the Options API; `useApplicationVersion.js` is a standalone composable that
only uses `ref` (no `watch` inside the composable itself — watchers live in the
callers to avoid memory leaks on options-API components).

### No CnAppRoot error prop

`BuilderHost.vue` uses `<CnAppRoot>` which does not expose an `error` prop. The
version-not-found state is implemented as a `<div class="builder-host__version-not-found">`
rendered by `v-if="versionNotFound"` above the `<CnAppRoot>`. When versionNotFound is
true, CnAppRoot is not rendered (v-else-if pattern).

### PHPCS NamedParameters sniff — test files excluded

The phpcs.xml `<file>lib</file>` directive means only `lib/` is scanned by
`composer phpcs`. The PHPUnit test files under `tests/` are not scanned and
intentionally use positional arguments for PHPUnit methods (`createMock`,
`assertSame`, etc.) to match the existing test file style in the codebase.

### Pre-existing quality violations fixed

Several pre-existing PHPMD violations were fixed as part of this apply:
- `ExportsController::isAuthorisedForApplication()` — CC=10 → extracted `fallbackAuthoriseViaOrLookup()`
- `AppNavigationService::isVisibleForCurrentUser()` — CC=12, NPath=1088, ElseExpression → extracted `flattenPermissions()` + `principalsMatchGroups()`
- `IconService::resolveIconDark()` — CC=11 → extracted `streamForIconField()`
- `ApplicationsController::getManifest()` — CC=10 → extracted `resolveVersionedManifestResponse()`
- `ApplicationsController::listMine()` — CC=10 → extracted `filterApplicationsByRole()`
- `ApplicationsController::collectAuthorisedGroups()` — CC=11 → extracted `classifyPrincipal()`

### Pre-existing JS bugs fixed

- `PromoteVersionDialog.vue` — `''s` escaped apostrophe inside JS `t()` calls caused
  parse error; fixed by switching to double-quoted strings. `<template #actions>` inside
  native `<div>`/`<form>` is invalid; moved to direct NcDialog child.
- `ApplicationCard.vue` — missing "Live" badge for `currentVersion`; missing click/keyup
  event emitters (tests expected them); replaced `<router-link>` with `<div role="link">` +
  `onCardActivate()` method to support keyboard accessibility + emit contract.
- `IconUploadSection.vue` — multiple extra-space key-spacing and no-multi-spaces ESLint errors.
- `ApplicationCard.vue` — self-closing `<img/>` on void element.

### $manifestResolverService → $manifestResolver

The property was renamed from `$manifestResolverService` (22 chars, over PHPMD LongVariable
threshold of 20) to `$manifestResolver` (16 chars). No functional change.
