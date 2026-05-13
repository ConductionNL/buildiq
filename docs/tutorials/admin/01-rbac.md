---
sidebar_position: 1
title: Manage who can build (RBAC)
description: Decide which Nextcloud groups can open the builder, edit virtual apps, publish, and export.
---

# Manage who can build (RBAC)

OpenBuilt scopes builder access through Nextcloud groups. Anyone in the *admin* group has the full ride; everyone else needs explicit membership in an OpenBuilt builder group. This is what stops an end user from opening the schema designer for an app they only meant to *use*.

## Goal

By the end you will have nominated a Nextcloud group as the *OpenBuilt builders* group, added a user to it, and confirmed they can open the page designer.

## Prerequisites

- You are in the Nextcloud *admin* group.
- The user (or users) you want to give builder access exist on the Nextcloud and are in at least one group.
- A sense of the access matrix you want — for most teams *one builder group per virtual app* is overkill; a single *OpenBuilt builders* group is the right starting point.

## Steps

1. Open **Settings → Administration → OpenBuilt** in Nextcloud. The OpenBuilt admin settings page opens with three sections: *Version Information*, *Support* and *Configuration*.

   ![OpenBuilt admin settings](/screenshots/tutorials/admin/01-rbac-01.png)

2. Scroll to **Configuration**. Find the **Builder groups** dropdown — it is a multi-select picker of Nextcloud groups; on a fresh install it is empty, which is why only *admin* can open the builder today.

   ![Builder groups picker](/screenshots/tutorials/admin/01-rbac-02.png)

3. Pick the group you want to nominate (for example *openbuilt-builders*). If the group does not exist yet, create it first under **Settings → Users → Groups**.

   ![Group picked](/screenshots/tutorials/admin/01-rbac-03.png)

4. Click **Save**. The setting persists to `IAppConfig`; the next call to the OpenBuilt API checks the new group membership.

   ![Configuration saved](/screenshots/tutorials/admin/01-rbac-04.png)

5. Add the user you want to give builder access to that group (under **Settings → Users**). Ask them to reload OpenBuilt — they should now see the **Open builder** / **Edit pages** controls on every virtual app.

   ![User can open builder](/screenshots/tutorials/admin/01-rbac-05.png)

## Verification

The RBAC change is good when: a user in the nominated group can open `/apps/openbuilt/builder/\<slug\>/pages` without a 403, and a user *not* in the group gets the 403 they should.

## Common issues

| Symptom | Fix |
|---|---|
| Save button does nothing | The configuration field is read-only on locked instances — check `config.php` for `'config_is_read_only' => true` and remove it. |
| User still sees a 403 after group add | Nextcloud caches group memberships per session — ask the user to log out and back in. |
| Multiple builder groups conflict | Membership is OR-ed across groups: being in *any* of the listed groups gives builder access. Trim the list if that is broader than you intended. |

## Reference

- [Manage the template catalogue](./02-template-catalogue.md) — same picker controls who can publish templates.
- [Admin settings](./03-admin-settings.md) — version info, register, support contact.
