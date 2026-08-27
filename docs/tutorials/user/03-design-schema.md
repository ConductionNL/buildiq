---
sidebar_position: 3
title: Design a schema
description: Define the data shape behind a virtual app — properties, types, required fields, references — with the schema designer.
---

# Design a schema

A schema is the data shape behind everything Buildiq stores: the columns in a list, the fields on a form, the body of an API response. Buildiq uses standard OpenRegister schemas, so anything you build here is reachable via OpenRegister's API the moment you save.

## Goal

By the end you will have added at least one new property to a schema in your virtual app — picked its type, marked it required if appropriate, and saved.

## Prerequisites

- A virtual app you can edit. The seed *Hello World* app is fine; otherwise clone from a template (see [Create an application from a template](./02-create-from-template.md)).
- A rough idea of the data shape — what fields the app needs to track for each record.

## Steps

1. Open **Virtual apps**, click your app, and from the detail page click **Open builder → Schemas**, or jump straight to `/apps/buildiq/builder/\<slug\>/schemas`.

   ![Schema designer empty state](/screenshots/tutorials/user/03-design-schema-01.png)

2. Click **Add schema** if no schema exists yet, or pick an existing schema from the left panel. The designer opens with two columns: properties on the left, a JSON / preview panel on the right.

   ![Schema designer overview](/screenshots/tutorials/user/03-design-schema-02.png)

3. Click **Add property**. Pick a name (`title`, `status`, `dueDate`, …), a **Type** (*string*, *integer*, *boolean*, *date*, *array*, *object*, *reference*), and tick **Required** if the field must always have a value.

   ![Add property dialog](/screenshots/tutorials/user/03-design-schema-03.png)

4. For references, pick the target schema from the **References** dropdown. Buildiq stores references as `@self.id` links and the UI renders them as picker fields in the page designer.

   ![Reference property](/screenshots/tutorials/user/03-design-schema-04.png)

5. Click **Save schema**. The schema is written to OpenRegister; the JSON panel updates to show the new property; the schema is immediately usable in [Design a page](./04-design-page.md).

   ![Schema saved](/screenshots/tutorials/user/03-design-schema-05.png)

## Data scopes (row-level access)

Below the field, lifecycle, and relation editors sits **Access** — where you scope who can read, create, update, or delete records of this schema. This is enforced by OpenRegister itself, not just a UI convenience: it is the actual security boundary for the data in this schema.

For each of the four operations you pick exactly one scope kind:

- **Everyone with app access** — the default; no restriction beyond having access to the app at all.
- **Specific groups** — pick one or more Nextcloud groups; only members of those groups (plus admins) may perform the operation.
- **Own records (creator)** and **Condition** (a field-value match against the current user, e.g. "assignee equals me") are advanced scopes offered only when the connected OpenRegister instance advertises support for them. If you don't see these options, your OpenRegister does not support them yet — this is expected on most installs today.

If you scope **Read** to a group you are not a member of (and you are not a Nextcloud admin), the designer shows a warning that saving will make this schema's own records invisible to you. Saving is still allowed — this can be an intentional handover to another team.

Scopes are saved as part of the schema, exactly like fields — they are versioned per `?_version=`, and a scope change on a draft version never affects the production version until it is published. On a production version, only an app **owner** (not an editor) can change Access scopes.

> **Access vs. navigation.** A page or menu item's `permission` field (set elsewhere in the builder) only hides navigation — it is a UX convenience. The **Access** sub-editor here is what actually restricts which records a user can read, create, update, or delete, enforced server-side by OpenRegister.

## Verification

The schema is good when: it appears in the left-panel list with no red badge, the JSON preview validates without error, and the property you added shows up when you open the page designer next.

## Common issues

| Symptom | Fix |
|---|---|
| **Save schema** errors *"property name must be unique"* | A property by that name already exists on this schema — rename or pick the existing one. |
| **Type → Reference** dropdown is empty | No other schema exists in this register yet — create at least one schema first, then come back. |
| The JSON panel shows red squiggles | The schema is invalid JSON Schema — see the error message at the bottom of the panel; usually a misspelled type or a required-without-property. |

## Reference

- [Design a page](./04-design-page.md) — turn the schema into a screen.
- [Connect external data](./05-connect-data.md) — replace storage with an external source.
