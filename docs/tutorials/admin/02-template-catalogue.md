---
sidebar_position: 2
title: Curate the template catalogue
description: Promote an exported virtual app into the template gallery so other builders can clone from it.
---

# Curate the template catalogue

The template gallery is where new virtual apps start. The four templates that ship with OpenBuilt (*Permit Tracker*, *Stakeholder Consultation*, *Employee Onboarding*, *Incident Reporter*) are a baseline; in a real deployment you will add your own and retire the ones that do not fit.

## Goal

By the end you will have promoted a finished virtual app into the template gallery, picked its category and description, and confirmed it shows up under **Templates** for everyone with builder access.

## Prerequisites

- You are an OpenBuilt admin (in the *admin* group, or in a group nominated under [Manage who can build (RBAC)](./01-rbac.md)).
- A virtual app that is *finished enough* to be a template — schemas stable, pages saved, a small set of well-chosen sample records.
- A clear category to slot it under (*GOVERNMENT SERVICES*, *CITIZEN ENGAGEMENT*, *INTERNAL OPERATIONS*, *FIELD WORK*, or a custom category).

## Steps

1. Open **Templates** in the OpenBuilt left navigation. The gallery shows the current set, with category badges and **Use this template** buttons.

   ![Template gallery](/screenshots/tutorials/admin/02-template-catalogue-01.png)

2. To add a new template, open the source virtual app and click **Actions → Promote to template** (or open **Virtual apps**, tick the row, and pick **Promote to template** from the bulk-action menu).

   ![Promote-to-template action](/screenshots/tutorials/admin/02-template-catalogue-02.png)

3. The dialog asks for the template's **Name** (defaults to the app's name), **Slug**, **Category**, **Description** (one-liner shown on the card) and a longer **Body** (markdown, shown on hover and on the template-detail page).

   ![Promote dialog](/screenshots/tutorials/admin/02-template-catalogue-03.png)

4. Click **Promote**. OpenBuilt freezes the current manifest, copies it under a new `application-template` record, and the new card lands in the gallery at the top of its category.

   ![New card in the gallery](/screenshots/tutorials/admin/02-template-catalogue-04.png)

5. To retire a template, click the card to open its detail page, then **Actions → Retire**. The template stops appearing in the gallery, existing apps that were cloned from it stay untouched.

   ![Retire template](/screenshots/tutorials/admin/02-template-catalogue-05.png)

## Verification

The promotion worked when: the new card shows up under the right category on the **Templates** gallery, builders can click **Use this template** and land in [Create an application from a template](../user/02-create-from-template.md), and the resulting clone matches the source app.

## Common issues

| Symptom | Fix |
|---|---|
| **Promote to template** is missing from the Actions menu | You are not in a builder/admin group — see [Manage who can build (RBAC)](./01-rbac.md). |
| Category dropdown does not list yours | Custom categories are stored on `application-template` records — add the category to the first record manually via OpenRegister, future promotions pick it up. |
| Template appears in the gallery but **Use this template** errors | The frozen manifest references a register that does not exist on the target instance — re-promote with the *Include registers* tick. |

## Reference

- [Create an application from a template](../user/02-create-from-template.md) — the user flow your template feeds.
- [Export the app](../user/08-export-app.md) — alternative to promoting (downloadable ZIP).
