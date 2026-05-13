---
sidebar_position: 1
title: Open OpenBuilt for the first time
description: Open OpenBuilt, walk the navigation, and confirm the seed Hello World virtual app loaded.
---

# Open OpenBuilt for the first time

A first look at OpenBuilt — what the app is for, what the navigation gives you, and how to tell the seed *Hello World* virtual app is ready to play with.

## Goal

By the end you will have opened OpenBuilt, recognised the dashboard tiles and the left-hand navigation, and seen the seed *Hello World* virtual app in the Virtual apps list.

## Prerequisites

- A Nextcloud account on an instance where the **OpenBuilt** app is installed and enabled.
- The **OpenRegister** app installed and enabled — OpenBuilt stores virtual apps, schemas, templates and version snapshots in OpenRegister.
- The OpenBuilt repair step has run, so the *Hello World* seed virtual app is present (the repair runs once on first enable; an admin can re-trigger it from **Nextcloud admin → Overview**).

## Steps

1. Open the Nextcloud app menu in the top bar and pick **OpenBuilt**. You land on the dashboard.

   ![OpenBuilt dashboard](/screenshots/tutorials/user/01-first-launch-01.png)

2. Read the dashboard tiles — *Virtual apps*, *Published*, *Templates*, *Published versions*. They show counts pulled from OpenRegister; on a fresh install the *Virtual apps* and *Published* tiles read `1` (the seed Hello World app), *Templates* reads however many the template gallery ships and *Published versions* sits on the snapshot count.

   ![Dashboard stat tiles](/screenshots/tutorials/user/01-first-launch-02.png)

3. Open the left-hand navigation. The entries map one-to-one onto what OpenBuilt manages: **Virtual apps** (your draft and published apps), **Schemas** (the OpenRegister schemas you can re-use across apps), **Templates** (the gallery you can clone from), **Exports** (zipped manifest exports). Below the divider sit **Documentation** and **Features & roadmap**.

   ![OpenBuilt navigation](/screenshots/tutorials/user/01-first-launch-03.png)

4. Click **Virtual apps**. The list view opens with a *Cards / Table* toggle, an **Add Application** button, and the OpenRegister side filters. The seed *Hello World* row is the one to start poking at.

   ![Virtual apps list, Hello World seeded](/screenshots/tutorials/user/01-first-launch-04.png)

## Verification

You are set up correctly when: the OpenBuilt dashboard renders without an error banner, the left navigation lists the entries above, and **Virtual apps** shows at least the *Hello World* row.

## Common issues

| Symptom | Fix |
|---|---|
| "OpenRegister is not installed or enabled" banner | Install and enable the OpenRegister app, then reload OpenBuilt. |
| Virtual apps list is empty | The repair step did not run — an admin re-enables OpenBuilt, or runs `php occ openbuilt:repair` on the host. |
| OpenBuilt is missing from the app menu | The app is not enabled for your account — ask an administrator to enable it (and check it is not restricted to a group you are not in). |

## Reference

- [Clone from a template](./02-create-from-template.md) — the natural next step.
- [Admin settings](../admin/03-admin-settings.md) — register, version, support contact.
