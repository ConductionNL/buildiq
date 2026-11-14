---
sidebar_position: 7
title: Compare and roll back a version
description: Switch between the development and production versions of your app, compare their manifests, and roll one back.
---

# Compare and roll back a version

Every Buildiq app carries versions. Two of them do the day to day work: **development**, where you and the AI build, and **production**, what everyone else opens. The sidebar holds the history, the diff and the roll back.

## Goal

By the end you will have switched between your app's versions, read the diff between them, and rolled one version's manifest back onto the app.

## Prerequisites

- An app with at least one saved page (see [Design a page](./04-design-page.md)).
- Two versions on that app. An app created from a template gets **Development** and **Production**. A seeded example may carry production only, and then there is nothing to compare.
- Owner or editor rights on the app. A non-production version is hidden from everyone else.

## Steps

1. Go to **Apps** and open your app. Under the title sit the version pills: **Development** and **\* Production**. The asterisk marks the production version. Clicking a pill switches the page to that version and writes `?_version=` into the URL.

   ![The app detail page with its version pills](/screenshots/tutorials/user/07-version-snapshots-01.png)

2. Open the sidebar and pick the **Version history** tab. Each row shows the version name, its semver, its status, and a **Production** badge on the live one. The actions on a row are **Open**, **Edit**, **Release**, **Promote** and **Roll back**.

   ![The version history tab](/screenshots/tutorials/user/07-version-snapshots-02.png)

3. Make a change you can undo. Click **Edit** on the **Development** row, change a page, and click **Save pages**. The designer writes to the version you opened it on, so development now differs from production.

   ![A change saved on the development version](/screenshots/tutorials/user/07-version-snapshots-03.png)

4. Switch to the **Diff** tab. It opens on **Compare** set to Development and **With** set to Production, which is the comparison you make before promoting. The **Manifest diff** below names both sides and highlights what moved.

   ![The diff tab](/screenshots/tutorials/user/07-version-snapshots-04.png)

5. Happy with the change? Use the **›** button beside the Development pill, or **Promote** on its row, to move it into production. Unhappy? Click **Roll back** on the row you want back and confirm. Rolling back copies that version's manifest onto the app's current draft. The history is append only, so nothing is lost.

   ![After a roll back](/screenshots/tutorials/user/07-version-snapshots-05.png)

## Verification

The roll back worked when the page designer loads with the pages, menu and schemas of the version you rolled to, and the running app renders them. Check the app's status: a roll back leaves it as a draft, so it never republishes behind your back.

## Common issues

| Symptom | Fix |
|---|---|
| "This app has one version, so there is nothing to compare yet." | The app carries production only. Create a draft version first, or work on an app made from a template. |
| No version pills under the title | Non-production versions are visible to owners and editors only. Ask the owner for rights on the app. |
| "Rollback failed" under the version list | The version you picked has no stored manifest. Pick a version that was saved at least once. |
| The roll back restored the pages but not the data | Versions cover the manifest: pages, menu, schemas and data sources. Records are not in it. Record history lives in OpenRegister's audit trail. |
| The diff is empty | Both sides hold the same manifest. Save a change in the designer between the two versions first. |

## Reference

- [Preview and run your app](./06-preview-app.md), how `?_version=` decides what you see.
- [Export your app](./08-export-app.md), pick the version you want to ship.
- [The manifest](../../elements/manifest.md), what exactly a version holds.
