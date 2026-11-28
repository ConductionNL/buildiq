## Why

Buildiq ships four templates (Permit Tracker, Stakeholder Consultation, Employee Onboarding, Incident Reporter). `Repair\SeedApplicationTemplates` and the setup wizard's `seed-templates` action still seed them as `application-template` records. Since `github-shop-catalogue`, the Store at `/apps/buildiq/templates` lists only GitHub repositories, so no screen shows those templates. The two tutorials a new user follows (`docs/tutorials/user/02-create-from-template.md`, `docs/tutorials/admin/02-template-catalogue.md`) both open on a gallery of those four cards, with category badges and a **Use this template** button. On a live instance the Store shows GitHub results only (Incident Reporter and MCP Demo), and the create-from-template flow the tutorials describe has no entry point.

The amended `template-catalogue-ui` requirement records the removal as shipped behaviour and calls a return of local templates "a product decision". This change makes that decision: the Store lists the built-in templates again, next to the GitHub search. It does not replace the search.

## What Changes

- **The Templates tab lists built-in templates first.** `TemplateGallery` reads the `application-template` records from the `buildiq` register and shows one card each, with the title, a category badge, the use case and the description. Seeded templates come first. Organisation templates (`isSeeded: false`) follow with an "Organisation template" badge (REQ-SAT-005). The GitHub search stays below them, unchanged.
- **Use this template.** Each built-in card has a **Use this template** button. It opens `CloneTemplateDialog` in local mode with **Name**, **Slug** and an optional **Description**. The slug follows the name until the user edits the slug. **Create** posts to `POST /api/applications/from-template/{templateSlug}`, which creates the app as a draft, and the Store then opens the new app.
- **The clone endpoint accepts a description.** `createFromTemplate` passes an optional `description` to the new Application. Without one it falls back to the template's description, so a clone never starts empty.
- **No BREAKING changes.** The GitHub install path, the Blocks tab and the clone endpoint's existing fields behave as before.

## Capabilities

### Modified Capabilities

- `template-catalogue-ui`: the Templates tab carries a built-in templates section above the GitHub search. The clone dialog gains a description field and a slug that follows the name.

## Impact

- `src/views/TemplateGallery.vue`: the built-in section and the local clone submit.
- `src/modals/CloneTemplateDialog.vue`: the description field (local mode only) and the name-following slug.
- `lib/Controller/ApplicationsController.php`: the optional `description` on `createFromTemplate`.
- Tests: `tests/views/TemplateGallery.spec.js`, `tests/modals/CloneTemplateDialog*.spec.js`, and the controller unit test.
- Out of scope: Edit and Delete for organisation templates (REQ-SAT-005), the template detail page, and Retire. Retire is a separate change.
