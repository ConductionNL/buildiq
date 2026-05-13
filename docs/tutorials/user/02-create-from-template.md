---
sidebar_position: 2
title: Create an application from a template
description: Clone a template from the gallery to bootstrap a new virtual app with sensible schemas and pages.
---

# Create an application from a template

Templates are pre-built virtual apps — schemas, pages, sample data — for recognisable use cases (a permit workflow, a citizen consultation, an HR onboarding flow). Cloning one is faster than starting from blank.

## Goal

By the end you will have cloned a template into an editable draft virtual app, named it, and opened it for editing.

## Prerequisites

- You completed [Open OpenBuilt for the first time](./01-first-launch.md).
- At least one template in the gallery — OpenBuilt ships four out of the box (*Permit Tracker*, *Stakeholder Consultation*, *Employee Onboarding*, *Incident Reporter*). Admins add more via [Manage the template catalogue](../admin/02-template-catalogue.md).

## Steps

1. Click **Templates** in the left navigation. The gallery shows one card per template with category, short description and a **Use this template** button.

   ![Template gallery](/screenshots/tutorials/user/02-create-from-template-01.png)

2. Pick a card that matches what you are trying to build. Hover for the longer description; the category badge (*GOVERNMENT SERVICES*, *CITIZEN ENGAGEMENT*, *INTERNAL OPERATIONS*, *FIELD WORK*) hints at where the template is most at home.

   ![Template card](/screenshots/tutorials/user/02-create-from-template-02.png)

3. Click **Use this template**. A dialog opens asking for the new application's **Name**, **Slug** (URL-safe identifier, auto-derived from the name) and an optional **Description**.

   ![Use-template dialog](/screenshots/tutorials/user/02-create-from-template-03.png)

4. Click **Create**. OpenBuilt clones the template's schemas, pages and sample data under a new application record, sets the status to *Draft* and returns you to the Virtual apps list.

   ![New app in the list](/screenshots/tutorials/user/02-create-from-template-04.png)

5. Click the new application row. Its detail page opens with sidebar tabs for *Overview*, *Manifest*, *Version history*, *Diff* and *Audit trail*. From here you can drill into the schema designer or the page designer to start customising.

   ![Application detail page](/screenshots/tutorials/user/02-create-from-template-05.png)

## Verification

The clone is complete when: the new application shows in **Virtual apps** with the name you gave it and status *Draft*, and its detail page opens without a load error.

## Common issues

| Symptom | Fix |
|---|---|
| **Use this template** spins forever | The template clone job is queued as a background job — wait a minute and reload the Virtual apps list. |
| Slug field rejects your input | Slugs must be lowercase, hyphen-separated, no spaces or special characters. |
| The cloned app has no schemas | The template's schemas were renamed or deleted on the host since the template was authored — pick a different template, or re-import the canonical template set from [Manage the template catalogue](../admin/02-template-catalogue.md). |

## Reference

- [Design a schema](./03-design-schema.md) — customise the cloned data model.
- [Design a page](./04-design-page.md) — adjust the cloned screens.
- [Manage the template catalogue](../admin/02-template-catalogue.md) — what an admin can do here.
