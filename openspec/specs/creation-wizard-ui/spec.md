---
retrofit: true
---

# creation-wizard-ui Specification

## Purpose

The Create Application Wizard is OpenBuild's four-step modal that provisions the
full ADR-002 chain (Application + production ApplicationVersion + register +
schemas) in one atomic backend call. `CreateApplicationWizard` is the host;
`Step1Basics` captures identity, `Step2Preset` picks a starter, `Step3Custom`
authors schema rows, `Step4Review` previews. `IconUploadSection` is the shared
light/dark SVG upload control.

This capability is observed behaviour of those components. It is the frontend
half of the `application-creation-wizard` backend capability.

## Requirements

### Requirement: Wizard host sequences steps, gates navigation, merges payload and submits

@e2e exclude retrofit component-contract spec — `displayStep`, `currentStepValid`, `allStepsValid`, `mergePayload`, `resetState`, `onSubmit` are component-state contracts verified by Vitest unit tests; wizard open/submit integration is covered by the application-creation-wizard Playwright tests

`CreateApplicationWizard` SHALL track the current step (`displayStep`,
`visibleStepCount`), gate forward navigation on per-step validity
(`currentStepValid`, `allStepsValid`, `goNext`, `goBack`), merge each step's
slice into one payload (`mergePayload`), reset state on open
(`resetState`), manage modal visibility (`onModalShowUpdate`, `onClose`), and
submit the atomic provisioning call (`onSubmit`).

#### Scenario: Block invalid step

- **WHEN** the current step is invalid
- **THEN** the wizard disables "next" until the step validates

#### Scenario: Submit the chain

- **WHEN** all steps are valid and the user submits
- **THEN** the wizard sends the merged payload as one provisioning call

### Requirement: Basics and preset steps validate identity and select a starter

@e2e exclude retrofit component-contract spec — `isValid`, `slugError`, `onNameInput`, `onSlugInput`, `presetOptions`, `selectPreset` are per-step component contracts verified by Vitest unit tests; the integrated slug-validation and preset-selection flows are covered by the application-creation-wizard Playwright tests

`Step1Basics` SHALL validate the name, slug, and description
(`isValid`, `onNameInput`, `onSlugInput`, `slugError`, `onDescriptionInput`,
`onIconChange`), deriving a slug error when the kebab pattern fails.
`Step2Preset` SHALL render the starter-preset options and record the selection
(`presetOptions`, `selectPreset`).

#### Scenario: Reject a bad slug

- **WHEN** the user enters a slug that violates the kebab pattern
- **THEN** the basics step surfaces a slug error and marks itself invalid

#### Scenario: Choose a preset

- **WHEN** the user selects a starter preset
- **THEN** the preset step records the selection for the payload

### Requirement: Custom step authors the schema rows with reorder and slug validation

@e2e exclude retrofit component-contract spec — `addRow`, `removeRow`, `moveUp`, `moveDown`, `onDragStart`, `onDrop`, `slugErrors`, `duplicateSlugs` are step-component contracts verified by Vitest unit tests; custom-chain composition end-to-end is covered by the application-creation-wizard Playwright tests

`Step3Custom` SHALL let the user add/remove/reorder schema rows
(`addRow`, `removeRow`, `moveUp`, `moveDown`, `onDragStart`, `onDragOver`,
`onDrop`, `onDragEnd`), edit each row (`onNameInput`, `onSlugInput`,
`getSlugError`, `updateField` via `handler`), validate slug uniqueness and
correctness (`slugErrors`, `duplicateSlugs`, `isValid`), toggle advanced options
(`toggleAdvanced`), emit the rows upward (`emit`), and seed defaults on
`mounted`.

#### Scenario: Reorder schema rows

- **WHEN** the user drags a schema row to a new position
- **THEN** the row order updates and the emitted rows reflect it

#### Scenario: Reject duplicate slugs

- **WHEN** two schema rows share a slug
- **THEN** the custom step flags the duplicates and marks itself invalid

### Requirement: Review step previews and icon upload validates SVGs

@e2e exclude retrofit component-contract spec — `chainDisplay`, `iconLightUrl`, `validateSvgFile`, `uploadIcon`, `onLightPreviewError` are review-step and icon-upload component contracts verified by Vitest unit tests; SVG-rejection and review-step rendering are covered by the application-creation-wizard Playwright tests

`Step4Review` SHALL render the read-only summary: chain display, light/dark
icon previews, production slug, and version list (`chainDisplay`,
`iconLightUrl`, `iconDarkUrl`, `productionSlug`, `versions`).
`IconUploadSection` SHALL accept light/dark SVG files
(`onLightFileChange`, `onDarkFileChange`, `validateSvgFile`, `uploadIcon`),
preview them (`iconLightUrl`, `iconDarkUrl`, `onLightPreviewError`,
`onDarkPreviewError`), remove them (`removeIcon`, `removeLightIcon`,
`removeDarkIcon`), resolve the target object (`objectUuid`, `handler`), and
reject non-SVG uploads.

#### Scenario: Reject a non-SVG upload

- **WHEN** the user selects a non-SVG file
- **THEN** `validateSvgFile` rejects it and no upload occurs

#### Scenario: Preview the chain

- **WHEN** the user reaches the review step
- **THEN** the step renders the chain, icons, and version list read-only
