---
sidebar_position: 1
title: Manage who can build (RBAC)
description: Decide who can view, edit and own each virtual app, and who sees Buildiq at all.
---

# Manage who can build (RBAC)

Buildiq has two access boundaries, and they do different jobs.

Nextcloud's own app restriction decides who sees Buildiq in the top bar. Each app's own permissions decide what a person may do with that app. The second one is the load-bearing boundary. It is enforced on the server, on every request, and it is the one you set per app.

Every virtual app carries three roles: owner, editor and viewer. Each role is a list of Nextcloud groups or users.

## Goal

By the end you will have given a group the editor role on one app, confirmed the change, and read the permission history.

## Prerequisites

- You are an owner of the app you want to change, or you are in the Nextcloud *admin* group.
- The group you want to give access to exists under **Settings → Users → Groups**.
- At least one virtual app exists. If none does, start with [Create a virtual app](../create-a-virtual-app.md).

## Steps

1. Open **Apps** in the Buildiq left navigation and click the card of the app you want to change.

   ![Buildiq admin settings](/screenshots/tutorials/admin/01-rbac-01.png)

2. In the app header, click **Actions** and pick **Manage permissions**. The entry only appears for owners.

   ![Builder groups picker](/screenshots/tutorials/admin/01-rbac-02.png)

3. The **Permissions** dialog opens with three group pickers: **Owners (full control)**, **Editors (can save drafts)** and **Viewers (read-only)**. Add your group to **Editors**.

   ![Group picked](/screenshots/tutorials/admin/01-rbac-03.png)

4. Click **Save permissions**. The dialog refuses an empty owners list, so an app is never orphaned.

   ![Configuration saved](/screenshots/tutorials/admin/01-rbac-04.png)

5. Ask someone in that group to reload Buildiq. They now see the app in **Apps** and can save drafts on it.

   ![User can open builder](/screenshots/tutorials/admin/01-rbac-05.png)

## What each role may do

| Action | viewer | editor | owner |
|---|:---:|:---:|:---:|
| Read the manifest, browse the app | yes | yes | yes |
| Save a manifest draft | no | yes | yes |
| Publish, archive or re-open | no | no | yes |
| Change permissions, transfer ownership | no | no | yes |
| Delete the app | no | no | yes |

## Hide Buildiq from everyone else

The roles above cover one app at a time. To keep Buildiq out of the top bar entirely, use Nextcloud's standard app restriction under **Settings → Apps → Buildiq**, or run:

```bash
occ app:enable buildiq --groups digital-team
```

This is coarse visibility only. It hides the entry, it does not protect the data.

## Verification

The change is good when a member of the group sees the app in **Apps** and can save a draft. Someone with no role on the app gets a `403` from the manifest endpoint, with the body `{"error":"forbidden","code":"buildiq.rbac.no_role"}`.

Open **Actions → Permission history** to read who changed what, and when.

## Common issues

| Symptom | Fix |
|---|---|
| **Manage permissions** is missing from the Actions menu | You are an editor or viewer, not an owner. Ask an owner to add you. |
| **Manage permissions** in the Groups & users widget does nothing | Known issue. Use the **Actions** menu in the app header instead. |
| A user still sees nothing after the group add | Nextcloud caches group membership per session. Ask them to log out and back in. |
| An admin sees an app nobody granted them | Members of the Nextcloud *admin* group can read any manifest. Every use is written to the permission history as an administrator bypass. |

## Reference

- [Curate the template catalogue](./02-template-catalogue.md): owners and editors may save an app as a template.
- [Manage Buildiq settings](./03-admin-settings.md): version, register and support contact.
