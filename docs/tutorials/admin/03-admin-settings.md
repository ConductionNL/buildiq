---
sidebar_position: 3
title: Manage OpenBuilt settings
description: The three things every OpenBuilt admin touches — version info, OpenRegister wiring and the support contact.
---

# Manage OpenBuilt settings

OpenBuilt's admin settings page (in Nextcloud at **Settings → Administration → OpenBuilt**) is short on purpose. It surfaces only the configuration an admin needs to know about: the running version, the OpenRegister register OpenBuilt writes to, and the support contact end users will see.

## Goal

By the end you will have confirmed the running OpenBuilt version is up to date, set the OpenRegister register, and confirmed the support contact details users see.

## Prerequisites

- Your account is in the *admin* group.
- The OpenRegister app is installed and enabled.

## Steps

1. Open **Settings → Administration → OpenBuilt** in Nextcloud. The page is split into three sections: *Version Information*, *Support* and *Configuration*.

   ![OpenBuilt admin settings page](/screenshots/tutorials/admin/03-admin-settings-01.png)

2. Confirm **Version Information**. The section shows the running app version (for example `0.2.0`) and an *Up to date* badge if the running version matches the latest release on the Nextcloud app store.

   ![Version information section](/screenshots/tutorials/admin/03-admin-settings-02.png)

3. Confirm **Support**. The block shows the support email (`support@conduction.nl` by default). This is what users see if they hit the *Contact support* link from inside OpenBuilt.

   ![Support section](/screenshots/tutorials/admin/03-admin-settings-03.png)

4. Scroll to **Configuration**. The **Register** dropdown maps OpenBuilt to the OpenRegister register that holds application, schema, template, version and export records. On a fresh install it is pre-set to the `openbuilt` register imported by the app's repair step.

   ![Configuration section](/screenshots/tutorials/admin/03-admin-settings-04.png)

5. To rotate to a different register (only relevant when you are running multiple OpenBuilt instances against the same Nextcloud), pick the new register and click **Save**. The change takes effect on the next API call.

   ![Configuration saved](/screenshots/tutorials/admin/03-admin-settings-05.png)

## Verification

The settings page is healthy when: the *Up to date* badge is green, the Support block shows your team's contact details, the Register dropdown points at a register that exists on this Nextcloud, and the save toast confirms the change persisted.

## Common issues

| Symptom | Fix |
|---|---|
| Register dropdown is empty | OpenRegister has no `openbuilt` register imported yet — run `php occ openbuilt:repair` on the host. |
| Version Information shows *Outdated* | Update OpenBuilt via the Nextcloud app store. |
| Save button does nothing | The configuration field is read-only on locked instances — check `config.php` for `'config_is_read_only' => true`. |

## Reference

- [Manage who can build (RBAC)](./01-rbac.md) — same settings page, builder-groups picker.
- [Manage the template catalogue](./02-template-catalogue.md) — what builders see in the gallery.
