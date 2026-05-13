---
sidebar_position: 8
title: Export your app
description: Download a virtual app as a ZIP bundle — manifest + schemas + sample data — that can be imported into another OpenBuilt instance.
---

# Export your app

When the virtual app works the way you want, export it. OpenBuilt produces a ZIP containing the manifest (schemas, pages, menu, data sources) and an optional set of sample records. Another OpenBuilt instance imports the ZIP to recreate the app.

## Goal

By the end you will have requested an export of your app, watched the job complete, and downloaded the resulting ZIP.

## Prerequisites

- A virtual app you want to ship somewhere else.
- Sample data you want to include, if any. The export dialog lets you tick which schemas to dump records from.

## Steps

1. Open **Exports** in the left navigation. The page lists previous export jobs with their status (*Queued*, *Running*, *Completed*, *Failed*) and the downloadable artefact.

   ![Exports list](/screenshots/tutorials/user/08-export-app-01.png)

2. Click **Export application**, or open your app's detail page and click **Actions → Export**. Pick which **Application** to export (the dialog pre-fills if you came from a detail page), pick the **Snapshot** to export from (defaults to the live draft), and tick the schemas whose records you want to include as sample data.

   ![Export application dialog](/screenshots/tutorials/user/08-export-app-02.png)

3. Click **Start export**. The job moves to **Exports** with status *Queued* → *Running*. Small apps export in seconds; apps with thousands of records can take a minute or two.

   ![Export running](/screenshots/tutorials/user/08-export-app-03.png)

4. When the row reaches *Completed*, click the **Download ZIP** action. The bundle contains `manifest.json`, one `schemas/<slug>.json` per schema, one `data/<schema>.jsonl` per included data set, and a `README.md` summarising the export.

   ![Export completed](/screenshots/tutorials/user/08-export-app-04.png)

5. To re-import, copy the ZIP to the target instance, open **Virtual apps → Import application** there, pick the ZIP, and OpenBuilt recreates the app with its schemas, pages, and sample data.

   ![Re-import dialog](/screenshots/tutorials/user/08-export-app-05.png)

## Verification

The export is good when: the export job shows status *Completed* with a non-zero ZIP size and `manifest.json` validates inside the ZIP. Re-importing it onto another instance is the strongest possible test.

## Common issues

| Symptom | Fix |
|---|---|
| Job sits on *Queued* | The Nextcloud background jobs are not running — check `php occ background-job:list` on the host. |
| Job ends in *Failed* | Open the job row to see the error in the *Logs* tab. The two most common causes: the manifest is invalid (fix in the page designer), or a connector source is unreachable (sample-data dump retries it). |
| ZIP is tiny / empty | No schemas were ticked in step 2 — re-run with at least the schemas you care about ticked. |

## Reference

- [Snapshot and roll back a version](./07-version-snapshots.md) — pick which snapshot to export.
- [Template catalogue](../admin/02-template-catalogue.md) — promote an exported app into the catalogue.
