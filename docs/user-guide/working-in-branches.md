---
sidebar_position: 10
title: Working in branches
description: Start a branch of an app version, push it to its own Git branch, and merge it back with a choice per conflict.
---

# Working in branches

A branch is a line of work of your own: you change an app without touching the
version your colleagues use, and merge the change back when it is ready.

## Start a branch

Open the app's **Version history** and choose **Start a branch** on the version
you want to start from, for example `development`. Give the branch a name such as
`permit-fees` (lowercase letters, digits and dashes).

The branch is a new draft version:

- it has the same pages, menu and settings as the version it came from;
- its schemas are copied into a register of its own, without any records, so
  changing a field in the branch does not change the version it came from;
- it remembers where it started, so it can be merged back later.

You need the owner or editor role on the app to start a branch.

## Publish a branch to GitHub

When the app is linked to a GitHub repository, every version is published to its
own Git branch. The **GitHub** dialog lists the branch of each version. The
production version keeps the default branch (for example `main`); a branch such as
`permit-fees` is published to the Git branch `permit-fees`, which is made from the
default branch the first time. Publishing a branch never changes `main`.

## Merge a branch

Choose **Merge into** on the branch and pick the draft version to merge it into,
then **Preview**. Nothing is written yet.

- A page, menu item, setting or schema field changed on one side only is taken
  from that side.
- One changed on both sides in different ways, or removed on one side and changed
  on the other, is a **conflict**. The dialog shows both sides next to each other.
  Pick **Keep target** or **Take branch** for every conflict; **Merge** stays off
  until each one has a side.
- The order of keys and the formatting of the manifest never count as a change.
- When the merge removes a field or a schema, changes a field's type or makes a
  field required, records already stored can break. The dialog lists those changes
  and asks you to confirm them first.

Choose **Merge** to write the result. The target's version number moves as with
any other edit, and the next merge of the same two versions starts from here.

## What a merge refuses

- **Production and published versions.** Production changes through release and
  promotion only, so it never takes a merge. Merge into a draft and release that.
- **A target that changed after the preview.** When a colleague saved the target
  in between, the merge writes nothing and asks you to preview again.
- **Two versions with no common start.** A merge needs one of the two to have been
  branched from the other.
- **A viewer.** Merging needs the owner or editor role.

A merge combines configuration only: pages, menus, settings and schemas. It never
copies records between versions, and it opens no pull request on GitHub.
