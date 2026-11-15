---
sidebar_position: 5
title: Connect a register or connector
description: Point a page at an OpenRegister register, or read from an external system through an OpenConnector source.
---

# Connect a register or connector

An app does not have to own its data. A Buildiq page reads from any OpenRegister register on this Nextcloud. It can also read from an OpenConnector source: an HTTP API, a database or a file feed an admin has wired up.

## Goal

By the end you will have pointed one index page at a different register, or at an external source, and seen the live preview list rows from there.

## Prerequisites

- An app with at least one index page (see [Design a page](./04-design-page.md)).
- The register you want to read from exists on this Nextcloud.
- For an external source: the source and its endpoint exist in the connector app. They live under **Connections → Sources** in Integriq, the app formerly called OpenConnector. Ask an admin if the source you need is not there yet.

## Steps

1. Open the page designer. Go to **Apps**, open your app, and click the page you want in the **Pages** card. Buildiq opens `/apps/buildiq/builder/{slug}/pages` with that page selected and keeps the version you were on.

   ![Page designer with the page selected](/screenshots/tutorials/user/05-connect-data-01.png)

2. The editor panel on the right opens on **Index page**. Its first block is **Data source**, with two radio buttons: **OpenRegister** and **OpenConnector**. A new page starts on OpenRegister.

   ![Data source, register mode](/screenshots/tutorials/user/05-connect-data-02.png)

3. To read from another register, pick a different **Register** and then a **Schema**. The schema list only fills once a register is chosen. Below them, **Columns** offers every property of the new schema plus the `@self.*` metadata fields, so rebuild the column list after a switch.

   ![Switched register](/screenshots/tutorials/user/05-connect-data-03.png)

4. To read from an external source, choose **OpenConnector**. Pick a **Source**, then an **Endpoint** from that source. Buildiq never sees the credentials: the source mediates authentication, caching and rate limits.

   ![Connector source picked](/screenshots/tutorials/user/05-connect-data-04.png)

5. Click **Re-fetch sample** under the pickers. Buildiq pulls one sample payload, shows the list root it found, and lets you add fields with **Add field**. Each field maps a display name to a selector, and shows the sample value it resolves to.

6. Click **Save pages**. The **Validation** panel must be empty first: a connector binding with no endpoint or no fields is listed there and the page renders nothing until you fix it.

   ![Page reading from connector](/screenshots/tutorials/user/05-connect-data-05.png)

## Verification

The connection is good when the **Live preview** below the designer lists rows in the right shape, with columns filled and no error banner, and the **Validation** panel says "No validation errors."

## Common issues

| Symptom | Fix |
|---|---|
| **Source** and **Endpoint** are replaced by a plain text box | The connector app is not installed or not enabled here. You can still type an endpoint path, but Buildiq marks the binding unverified and cannot check it. |
| "No OpenConnector endpoints are configured yet." | The connector app is present but holds no endpoints. Add one under **Connections → Sources** in Integriq first. |
| "This source has no endpoints yet." | The source exists, the endpoint does not. Pick another source, or add the endpoint in Integriq. |
| Validation lists `endpoint-required` or `fields-required` | The connector binding is half finished. Pick an endpoint, fetch a sample, and map at least one field. |
| Switching back to OpenRegister asks to confirm | That is expected. "Switching to OpenRegister discards the OpenConnector mapping." Your field mapping is gone once you confirm. |

## Reference

- [Design a page](./04-design-page.md), the page you are repointing.
- [Preview and run your app](./06-preview-app.md), see the data load in the running app.
