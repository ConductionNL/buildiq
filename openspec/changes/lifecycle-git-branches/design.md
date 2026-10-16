# Design: lifecycle-git-branches

Read at buildiq development `d21e42f`.

## Where it sits

- Versions: the `ApplicationVersion` schema in `lib/Settings/openbuild_register.json`
  (line 462, slug `applicationVersion` at line 507) with `manifest`, `register`,
  `semver`, `status`, `promotesTo`, `commitSha` and `sourceRef` (the last two
  "Optional GitHub provenance"). `POST /api/applications/{appSlug}/versions`
  (`appinfo/routes.php:85`) reaches `ApplicationVersionsController::create()`
  (`lib/Controller/ApplicationVersionsController.php:253`), gated on write roles,
  which shares production's register unless one is given.
  `ApplicationVersionService::onSave()` (`lib/Service/ApplicationVersionService.php:254`)
  bumps `semver` on a manifest change, and `canonicaliseManifest()` (line 183)
  gives a stable form to compare.
- GitHub: `GitHubSyncController` (`lib/Controller/GitHubSyncController.php`),
  routes at `appinfo/routes.php:305-308`, owner gate `requireOwner()` (line 261),
  `REF_PATTERN` (line 68). `GitHubAppSyncService::push()` (line 221) always pushes
  to `branchOf()` (lines 1291-1298: `githubDefaultBranch` or `main`);
  `pull()` (line 434) takes a ref and makes a new draft version.
- Screens: `src/components/tabs/ApplicationVersionsTab.vue` lists versions,
  `src/modals/GitHubSyncModal.vue` shows "Default branch", "Publish" and "Pull"
  (lines 43, 115, 121), and `src/components/ManifestDiff.vue` draws two manifests
  side by side.

## D1. A version is a branch

A new version's own manifest and register already make it a line of work. A
branch is a draft version started with "Start a branch", which:

- copies the source version's manifest and its schemas into a new per-version
  register (`openbuild-{app}-{branch}`), without records;
- stores `branchBase`: the source version's slug, the source manifest's hash from
  `hashManifest()`, and a snapshot of that manifest and of the schemas copied;
- sets `githubBranch` to the branch name when the app is linked.

Both properties are declared in a new fragment
`lib/Settings/register.d/23-version-branches.json`, with the register version
bumped. Starting a branch needs the owner or editor role, like creating a version.

## D2. Each version pushes to its own branch

`push()` takes the version's `githubBranch` and falls back to the default branch
only for the production version and for versions without one. A branch that does
not exist is created from the default branch head through the broker before the
tree is pushed. The branch name is checked against `REF_PATTERN` on the way in.
Pulling stays as it is: a ref goes into a new draft version.

## D3. Three-way merge by key

`lib/Service/VersionMergeService.php` merges a source version into a target
version with the base from D1:

- units are pages (by `id`), menu items (by `id`), other top-level manifest keys
  (by key), and schemas (by slug, then property by name);
- a unit changed on one side only is taken from that side; unchanged on both
  stays; changed on both to the same value is taken once;
- changed on both to different values, or deleted on one side and changed on the
  other, is a conflict.

Units are compared in canonical form (`canonicaliseManifest()`), so key order and
whitespace are never a conflict. The base for merging a branch back into the
version it came from is `branchBase`; for any other pair it is the base of
whichever of the two was branched from the other, and the merge refuses a pair
with no recorded common base.

## D4. Preview first, write once

`POST /api/applications/{appSlug}/versions/{source}/merge/{target}` with
`preview: true` returns the merged units, the conflicts, and the target's current
manifest hash. The dialog (`src/dialogs/MergeVersionDialog.vue`) lists conflicts
with `ManifestDiff` for each and a "Keep target" or "Take branch" choice. The
apply call sends the choices and the hash; the server refuses with 409 when the
target changed since, or when a conflict has no choice. Merged schemas are saved
through OpenRegister's schema API with the same breaking-change confirmation the
designer asks for. The target's manifest save goes through `onSave()`, so the
semver moves as with any edit, and the target's `branchBase` moves to the
source's current state so the next merge starts from here.

## D5. Only drafts take a merge

The target must be a draft version. Merging into a published or the production
version is refused; production still changes only through release and promotion.
Merging needs the owner or editor role on the app.

## Risks

- A long-lived branch drifts. The merge shows the drift as conflicts rather than
  hiding it; there is no automatic rebase.
- Schema merges can break records in the target register. The breaking-change
  confirmation covers what OpenRegister already detects.
- A branch register adds schemas to the instance. Deleting a branch version
  uses `ApplicationVersionService::deleteVersion()` (line 561) with its existing
  register strategies (delete, orphan with a 30-day grace, or keep).

## What it does not do

- It opens no pull request and merges nothing on GitHub.
- It does not merge records.
