# Spec: app-version-branches

## Purpose

A maker works on a change to an app in a branch of its own, without touching the
version others use, and merges it back when it is ready. A branch is a draft
version that remembers where it started, pushes to its own Git branch when the
app is linked to GitHub, and merges into another draft version with a choice per
conflict.

## ADDED Requirements

### Requirement: A maker starts a branch from a version (REQ-BQGB-001)

A maker with the owner or editor role SHALL be able to start a branch from any
version of an app, giving it a name. The branch SHALL be a new draft version with
a copy of the source version's manifest and schemas in its own register, without
records, and SHALL record the source version and a snapshot of what it copied.

#### Scenario: A maker branches off to add fees

- **GIVEN** the app `vergunningen` with the draft version `development`
- **WHEN** a maker chooses "Start a branch" on `development` and names it `permit-fees`
- **THEN** a draft version `permit-fees` exists with the same pages and schemas as `development`, and editing a page in `permit-fees` leaves `development` unchanged

### Requirement: Each version pushes to its own branch (REQ-BQGB-002)

When the app is linked to a GitHub repository, publishing a version SHALL push it
to that version's branch, creating the branch from the default branch head when it
does not exist, through the GitHub broker. The production version SHALL push to
the default branch.

#### Scenario: A maker publishes the branch

- **GIVEN** `vergunningen` linked to `gemeente/vergunningen` with default branch `main`, and the version `permit-fees`
- **WHEN** the maker publishes `permit-fees` from the GitHub dialog
- **THEN** the repository has a branch `permit-fees` with the version's files, and `main` is unchanged

### Requirement: A maker merges a branch and picks a side per conflict (REQ-BQGB-003)

A maker SHALL be able to merge one version into another draft version. The merge
SHALL take every page, menu item, setting and schema property changed on one side
only, and SHALL list as a conflict each one changed differently on both sides or
deleted on one side and changed on the other, showing both sides. Nothing SHALL
be written until the maker has chosen a side for every conflict. Formatting and
key order SHALL never count as a change.

#### Scenario: A branch merges cleanly

- **GIVEN** `permit-fees` added the page `fees`, and `development` changed nothing since the branch started
- **WHEN** the maker merges `permit-fees` into `development`
- **THEN** the preview lists no conflicts, and after applying `development` has the page `fees`

#### Scenario: Both sides changed the same page

- **GIVEN** `permit-fees` and `development` both changed the columns of page `permits` since the branch started
- **WHEN** the maker previews the merge of `permit-fees` into `development`
- **THEN** the dialog lists `permits` as a conflict with both versions side by side, and "Merge" stays off until the maker picks "Keep target" or "Take branch"

### Requirement: A merge only writes a draft that has not moved (REQ-BQGB-004)

A merge SHALL be refused when the target is published or is the production
version, when the caller lacks the owner or editor role, or when the target
changed after the preview. A refused merge SHALL write nothing.

#### Scenario: Production cannot take a merge

- **GIVEN** the production version of `vergunningen`
- **WHEN** a maker tries to merge `permit-fees` into it
- **THEN** the merge is refused with a message that production changes through release and promotion only

#### Scenario: A colleague saved in between

- **GIVEN** a merge preview of `permit-fees` into `development`
- **WHEN** a colleague saves a change to `development` and the maker then applies the merge
- **THEN** the server answers that the target changed, nothing is written, and the dialog offers a new preview
