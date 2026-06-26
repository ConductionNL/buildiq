---
sidebar_position: 2
title: Create an application from a template
description: Clone a template from the gallery to bootstrap a new virtual app with sensible schemas and pages, then refine it in-app.
---

# Create an application from a template

Templates are pre-built virtual apps — schemas, pages, sample data — for
recognisable use cases (a permit workflow, a citizen consultation, an HR
onboarding flow). Cloning one is faster than starting from blank, and you refine
it afterwards **in-app**.

## Goal

By the end you will have cloned a template into an editable draft virtual app,
named it, and opened it for editing.

## Prerequisites

- You completed [Open OpenBuild for the first time](./01-first-launch.md).
- At least one template in the gallery — OpenBuild ships several out of the box.
  Admins add more via [Manage the template catalogue](../admin/02-template-catalogue.md).

## Steps

1. Click **Store** in the left navigation to open the template gallery. It shows one card per template with a category, a short description and a **Use this template** button.

   ![The template gallery](/screenshots/flow/02-templates.png)

2. Pick a card that matches what you're building — the category badge hints at where the template is most at home. Hover for the longer description.

3. Click **Use this template**. A dialog asks for the new application's **Name**, **Slug** (URL-safe, auto-derived from the name) and an optional **Description**.

4. Click **Create**. OpenBuild clones the template's schemas, pages and sample data into a new application record, sets the status to *Draft*, and returns you to the **Apps** list.

5. Open the new app and click **Open** to run it, then **Edit with OpenBuild** to start customising — add/move/configure widgets, edit pages, menu and data, all in place. See [Build your app in-app](./04-design-page.md).

## Verification

The clone is complete when: the new application shows in **Apps** with the name you gave it and status *Draft*, and it opens and runs without a load error.

## Common issues

| Symptom | Fix |
|---|---|
| **Use this template** spins forever | The clone runs as a background job — wait a minute and reload the **Apps** list. |
| Slug field rejects your input | Slugs must be lowercase, hyphen-separated, no spaces or special characters. |
| The cloned app has no schemas | The template's schemas were renamed or deleted on the host since it was authored — pick a different template, or re-import the canonical set from [Manage the template catalogue](../admin/02-template-catalogue.md). |

## Reference

- [Design a schema](./03-design-schema.md) — customise the cloned data model.
- [Build your app in-app](./04-design-page.md) — adjust the cloned screens in place.
- [Manage the template catalogue](../admin/02-template-catalogue.md) — what an admin can do here.
