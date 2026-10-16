# Design: apps-copy-app-and-page

Read at buildiq development `d21e42f`.

## What exists

- `lib/Controller/ApplicationsController.php:1566` `createFromTemplate()` is
  admin-gated (lines 1573-1585: provisioning a register is an admin-only
  operation in OpenRegister) and rate limited (`#[UserRateLimit(limit: 10,
  period: 3600)]`), validates the name and slug with `validateCloneRequest()`
  (line 2111) and hands a template array to `installFromTemplateArray()`
  (line 1670), the shared seam that creates the `buildiq-{newSlug}` register,
  deep-copies companion schemas (`cloneCompanionSchemas()`, line 2303),
  rewrites schema references in the manifest and persists the Application
  (`persistApplication()`, line 1966). Route: `appinfo/routes.php:50`.
- `src/services/templateCapture.js` strips the source app's slug prefix from
  companion schemas and manifest references: the inverse of the clone's
  namespacing, pure and unit-tested (REQ-SAT-002, REQ-SAT-004).
- `src/components/ApplicationDetailActions.vue:325` offers "Save as template",
  gated by `canSaveAsTemplate()` (line 437, owners and editors).
  `src/components/VirtualAppsActions.vue` holds the app list header actions.
- `src/components/page-editor/PageListEditor.vue:67-130` renders a row per page
  with title, id, route, type tag, permission and remove.
- `src/components/page-editor/fields/RegistrationFormList.vue:375`
  `addForm()` saves a new `registrationForm` through `saveRegistrationForm()`
  and opens it.

## D1. Copy an app through the template seam, server side

A new action `POST /api/applications/{slug}/copy` builds a template array in
memory from the source app's current version (its manifest and its companion
schemas), applies the same de-namespacing `templateCapture.js` does, and calls
`installFromTemplateArray()`. The copy therefore gets exactly what a clone from
template gets: its own register, namespaced schemas, rewritten references, the
caller as owner. Nothing is written to the template catalogue. The de-namespace
step is ported to a small PHP class with the same test cases as the JS module,
so both sides agree on the round trip.

The action carries the same gates as `createFromTemplate()`: signed in, admin
(register provisioning), `#[UserRateLimit(limit: 10, period: 3600)]`, and the
caller must be an owner or editor of the source app. The route is added to
`appinfo/routes.php` beside `from-template`.

## D2. Copy a page in the page list

A copy button on each page row deep-copies the page, suffixes `id` with
`-copy` (then `-copy-2`, and so on, against the existing ids), prefixes the
title with "Copy of", appends `-copy` to the route's last static segment and
inserts it below the source. The existing unique-id and route validation then
runs as for any page. Menu entries are not copied: a copied page is not in the
menu until the maker adds it.

## D3. Copy a registration form as a draft

A copy button on each form row calls `saveRegistrationForm()` with the source's
fields, steps, logic, sections, presets, audience and channel, `name`
"Copy of {name}", `status: draft` and `isDefault: false`, so the one-default
rule is never broken by a copy. The copy opens in the editor, like a new form.

## Risks

- Copying a large app provisions a register and schemas like a template clone;
  the rate limit and admin gate stay the same.
- A copied page with a relative route that other pages link to keeps its old
  links pointing at the source. The copy says so in its confirmation.

## What it does not do

- It copies no records, no versions other than the current one, and no GitHub
  link.
