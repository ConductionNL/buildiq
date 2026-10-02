# Spec: template-catalogue-ui

## Purpose

Adds to the `template-catalogue-ui` capability: a maker browses and filters the
app store's templates by category, across the built-in, organisation and GitHub
sources.

## ADDED Requirements

### Requirement: Templates can be filtered and browsed by category (REQ-BQGL-001)

The "Templates" view of the app store SHALL offer a category filter and SHALL
group built-in and organisation templates under a heading per category, with
templates without a category under "Other". The filter SHALL also narrow the
GitHub cards by the category their descriptor carries. The chosen category SHALL
be kept in the `category` query parameter.

#### Scenario: A maker looks for field work templates

- **GIVEN** a maker on the app store with built-in templates in two categories and GitHub apps in three
- **WHEN** they pick "Field work" in the category filter
- **THEN** only field work templates and field work GitHub apps show, and the address ends in `?category=field-work`

#### Scenario: A shared link opens the same view

- **GIVEN** a colleague who receives a link to the app store ending in `?category=citizen-engagement`
- **WHEN** they open it
- **THEN** the filter shows "Citizen engagement" and the list is narrowed to it
