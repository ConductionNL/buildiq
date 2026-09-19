## MODIFIED Requirements

### Requirement: Templates page offers Templates (GitHub) / Blocks tabs

`TemplateGallery` SHALL present a top-level tablist with **Templates** and **Blocks** (`component-blocks`). "Templates" SHALL be the default selected tab.

The "Templates" tab SHALL show two sources, in this order:

1. A **built-in templates** section listing the `ApplicationTemplate` records of the `buildiq` register. Each card SHALL show the template's title, a category badge, its use case and its description, plus a **Use this template** action. Seeded templates (`isSeeded: true`) SHALL be listed before organisation templates. Organisation templates SHALL carry an "Organisation template" badge (REQ-SAT-005). When no template exists the section SHALL say so rather than render an empty grid. When loading fails the section SHALL show a warning note and the GitHub source SHALL keep working.
2. The **GitHub** source described below.

**Use this template** SHALL open `CloneTemplateDialog` in local mode, seeded with that template. On submit the gallery SHALL call `POST /api/applications/from-template/{templateSlug}` with the user-supplied `name`, `slug` and optional `description`, and SHALL open the new application on success. The new application SHALL be created with status `draft`. When the description is left empty, the new application SHALL take the template's description.

@e2e exclude retrofit component-contract spec — the tab-strip source selection and the built-in section are `TemplateGallery` component-state contracts verified by Vitest; the end-to-end per-source install flows are covered by the buildiq-template-catalogue Playwright tests.

#### Scenario: The Templates tab lists the built-in templates above the GitHub search

- **WHEN** the Templates page loads on an instance with the four seeded templates
- **THEN** the page renders exactly two tabs, "Templates" and "Blocks"
- **AND** "Templates" is selected
- **AND** the built-in section lists Permit Tracker, Stakeholder Consultation, Employee Onboarding and Incident Reporter, each with its category badge and a "Use this template" action
- **AND** the GitHub search field and card grid render below the built-in section

#### Scenario: Seeded templates come before organisation templates

- **WHEN** the register holds seeded templates and organisation templates
- **THEN** every seeded template is listed before every organisation template
- **AND** each organisation template carries the "Organisation template" badge

#### Scenario: Use this template creates a draft application

- **WHEN** the user clicks "Use this template" on Permit Tracker, enters the name "My permits" and an optional description, and clicks Create
- **THEN** the gallery posts `{ name: "My permits", slug: "my-permits", description }` to `POST /api/applications/from-template/permit-tracker`
- **AND** on success the dialog closes and the gallery opens the new application, whose status is `draft`

### Requirement: Clone dialog validates, submits and redirects

`CloneTemplateDialog` SHALL open seeded from a template (`open`, `resolvedTitle`), gate submission on a valid target (`canSubmit`), submit the clone (`submit`), surface errors (`setError`), and close (`onClose`). On success the gallery SHALL redirect to the new application.

In local mode (neither `remote` nor `github`) the dialog SHALL also show an optional **Description** field, and SHALL include `description` in the submitted payload. In every mode the slug field SHALL follow the name, derived as a kebab-case slug of at most 32 characters, until the user edits the slug field directly. From then on the slug SHALL stay as the user typed it.

@e2e exclude retrofit component-contract spec — `resolvedTitle`, `canSubmit`, `submit`, `setError`, `onClose` and the slug-follows-name behaviour are dialog-component contracts verified by Vitest unit tests; clone-dialog open/submit/redirect integration is covered by the buildiq-template-catalogue Playwright tests

#### Scenario: Reject an empty target

- **WHEN** the clone target is incomplete
- **THEN** `canSubmit` is false and submission is blocked

#### Scenario: Redirect after clone

- **WHEN** the clone succeeds
- **THEN** the dialog closes and the gallery redirects to the new application

#### Scenario: The slug follows the name until edited

- **WHEN** the user types "Bouwvergunningen Noord" in the name field
- **THEN** the slug field reads `bouwvergunningen-noord`
- **WHEN** the user then types `permits-north` in the slug field and changes the name again
- **THEN** the slug field still reads `permits-north`

#### Scenario: Description is offered for a built-in template only

- **WHEN** the dialog opens for a built-in template
- **THEN** it shows an optional Description field
- **WHEN** the dialog opens for a GitHub app
- **THEN** it shows no Description field
