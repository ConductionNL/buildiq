---
retrofit: true
---

# template-catalogue-ui Specification

## Purpose

The template-catalogue UI is OpenBuild's starter-template gallery.
`TemplateGallery` fetches `ApplicationTemplate` records, filters by category,
resolves per-template screenshots, and opens the clone dialog;
`CloneTemplateDialog` validates the clone target, submits, and redirects to the
new application.

This capability is observed behaviour of those components. It is the frontend
half of the `openbuild-template-catalogue` backend capability.

**OpenSpec changes**: [openbuild-remote-template-store](../../changes/openbuild-remote-template-store/)

**Status**: in-progress

## Requirements

### Requirement: Gallery fetches, filters and resolves template screenshots

@e2e exclude retrofit component-contract spec — `categoryOptions`, `categoryLabel`, `filteredTemplates`, `resolveScreenshot`, `openClone`, `onCloneSubmit`, `redirectAfterClone` are component-state contracts verified by Vitest unit tests; gallery filter and clone-redirect integration are covered by the openbuild-template-catalogue Playwright tests

`TemplateGallery` SHALL fetch the available templates (`fetchTemplates`),
expose category filter options and the current filtered set
(`categoryOptions`, `categoryLabel`, `filteredTemplates`), resolve each
template's screenshot with a fallback (`resolveScreenshot`), open the clone
modal (`openClone`), and redirect after a successful clone
(`onCloneSubmit`, `redirectAfterClone`).

#### Scenario: Filter by category

- **WHEN** the user selects a category
- **THEN** the gallery narrows the visible templates to that category

#### Scenario: Open clone

- **WHEN** the user clicks "Use this template"
- **THEN** the gallery opens the clone dialog seeded with that template

### Requirement: Clone dialog validates, submits and redirects

@e2e exclude retrofit component-contract spec — `resolvedTitle`, `canSubmit`, `submit`, `setError`, `onClose` are dialog-component contracts verified by Vitest unit tests; clone-dialog open/submit/redirect integration is covered by the openbuild-template-catalogue Playwright tests

`CloneTemplateDialog` SHALL open seeded from a template (`open`,
`resolvedTitle`), gate submission on a valid target (`canSubmit`), submit the
clone (`submit`), surface errors (`setError`), and close (`onClose`). On success
the gallery SHALL redirect to the new application.

#### Scenario: Reject an empty target

- **WHEN** the clone target is incomplete
- **THEN** `canSubmit` is false and submission is blocked

#### Scenario: Redirect after clone

- **WHEN** the clone succeeds
- **THEN** the dialog closes and the gallery redirects to the new application
