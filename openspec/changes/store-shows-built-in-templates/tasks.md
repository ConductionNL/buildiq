## 1. Store lists built-in templates

- [x] 1.1 `TemplateGallery` fetches `application-template` records from the `buildiq` register on mount, next to the GitHub search.
- [x] 1.2 Render a "Built-in templates" section above the GitHub search: title, category badge, use case, description and **Use this template**. Seeded templates first, then organisation templates with their badge.
- [x] 1.3 Show a clear empty state when no template is seeded, pointing admins at the setup wizard. Show a warning note when loading fails.

## 2. Use this template

- [x] 2.1 **Use this template** opens `CloneTemplateDialog` in local mode, seeded with the template.
- [x] 2.2 In local mode the dialog shows an optional **Description** field. The slug follows the name until the user edits the slug.
- [x] 2.3 On submit the gallery posts `{ name, slug, description }` to `POST /api/applications/from-template/{templateSlug}`, closes the dialog and opens the new app. An error shows inside the dialog.

## 3. Clone endpoint description

- [x] 3.1 `ApplicationsController::createFromTemplate` passes an optional `description` to the new Application, falling back to the template's description.

## 4. Verification

- [x] 4.1 Vitest: the gallery lists the built-in templates with category badges, seeded ones first, and the GitHub cards still render.
- [x] 4.2 Vitest: Use this template opens the dialog in local mode; submit posts name, slug and description and redirects.
- [x] 4.3 Vitest: the dialog's slug follows the name until edited, and the description field shows in local mode only.
- [x] 4.4 PHPUnit: the controller writes the given description, or the template's description when none is given.
