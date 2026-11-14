---
kind: code
---

# Proposal: lifecycle-git-branches

## Why

buildiq matrix, row `lc-git-branching`, "Work on an app in branches and merge
them, like source code.", rated `no`. No tender, featureRequest or roadmap row
carries it. Two competitors rated `yes`:

- Appsmith: "app/client/src/git/components/BranchList (create and switch
  branches) and app/client/src/git/components/OpsModal/OpsModalView.tsx:114
  TabMerge merges branches; mounted through
  app/client/src/ce/pages/AppIDE/components/AppIDEModals.tsx:16 GitModals.
  Branch protection is Enterprise".
- Mendix: "multiple development lines merged back into the main line, built on
  Git" (https://docs.mendix.com/refguide/version-control/).

What buildiq does today, per the matrix `built.evidence`:
"lib/Controller/GitHubSyncController.php + lib/Service/GitHubPushService.php/GitHubAppSyncService.php:
link/push/pull/status against a single repo, no branch parameter or merge
concept". Reading the code confirms the shape and adds one detail. Push always
goes to the app's default branch (`GitHubAppSyncService::branchOf()`,
`lib/Service/GitHubAppSyncService.php:1291-1298`). Pull already takes any ref,
checked against `REF_PATTERN` (`lib/Controller/GitHubSyncController.php:68`), and
makes a new draft version from it (`pull()`, line 434), stamping `sourceRef` and
`commitSha` on it. A version comparison exists (`applications#diffVersions`,
`appinfo/routes.php:69`, drawn by `src/components/ManifestDiff.vue`). What is
missing is a line of work of one's own and a way to bring it back: a version
tied to its own branch, and a merge.

Buildiq already has the unit a branch maps onto. An app has versions
(`ApplicationVersion`), each with its own manifest, and a draft version is a line
of work that does not touch production. This change makes a version a branch.

## What changes

- "Start a branch" on any version makes a new draft version with a name the maker
  chooses, a copy of the source's manifest and schemas in its own register, and a
  record of where it started (`branchBase`).
- When the app is linked to GitHub, each version pushes to its own branch
  (`githubBranch`), and the production version keeps the default branch. A branch
  that does not exist yet is made from the default branch head.
- "Merge into" merges one version into another draft version. Changes made on
  one side are taken; a page, menu item or schema changed on both sides is a
  conflict, shown side by side, and the maker picks a side for each. Nothing is
  written until every conflict has a side.
- A merge never writes a published or production version, and it refuses when
  the target changed after the preview.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | lc-git-branching | Work on an app in branches and merge them, like source code. | no | a version tied to its own branch, and a three-way merge between versions with conflict choice |

## Existing work it builds on

- `github-app-sync` (open): its requirements "Push serializes via the repo
  format and routes every call through the broker", "Pull creates a new draft
  ApplicationVersion, never overwriting production" and "Every GitHub write is
  broker-routed with the token never in Buildiq". Branch pushes go through the
  same broker path.
- `openspec/specs/github-app-repo-format`: the files a push writes.
- `openspec/specs/application-versions` and `openspec/specs/version-lifecycle-ui`:
  versions, their draft and published states, and the single production version.
- `openspec/specs/version-promotion`: promotion copies a version onto the next in
  the chain. A merge is the other direction: it combines two lines.
- `openspec/specs/schema-designer-ui` and the breaking-change confirmation in
  `src/dialogs/BreakingSchemaChangeDialog.vue`: a merged schema is saved the way
  the designer saves one.

## Sibling halves

None. Branch creation and pushes use the GitHub broker path buildiq already
calls; the merge runs on buildiq's own manifests and on schemas through
OpenRegister's schema API.

## Out of scope

- Opening pull requests on GitHub, and merging on GitHub.
- Merging records. A branch carries configuration; its register holds schema
  copies, not production data.
- Branch protection rules.
