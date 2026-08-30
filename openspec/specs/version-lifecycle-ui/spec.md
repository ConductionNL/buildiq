---
status: done
---

# version-lifecycle-ui Specification

**OpenSpec changes**: [version-lifecycle-and-switcher](../../changes/archive/2026-06-26-version-lifecycle-and-switcher/) _(archived 2026-06-26)_

## Purpose

The version-lifecycle-ui capability provides the maintainer cockpit for driving
Buildiq's two-object version model from the UI: the fixed/repointed version list
(slug-based endpoint, real fields), click-to-open and per-row Edit affordances,
the New-draft action (clones production manifest, shares production register), the
Release action (set-as-production + publish + demote-previous, owner-only), and
the "Open app" split-button version switcher. Frontend behaviour plus the thin
backend release/demote wiring it drives.

## Requirements

### Requirement: Version list is reached from the app slug

`ManifestLayersDetail.vue` (the routed Manifest detail page) SHALL resolve the parent
Application's `slug` from the `Application` object it already loads and pass that slug to
`VersionHistory` so the list can call the working slug-based versions endpoint. The view
SHALL NOT rely on `applicationUuid` alone to populate the version list.

**ID:** REQ-OBV-VLU-001

#### Scenario: Slug is passed to the version list

- **WHEN** `ManifestLayersDetail.vue` has loaded its `Application` object with
  `slug: "<slug>"`
- **THEN** it renders `VersionHistory` with that slug available
- **AND** the version list calls `GET /apps/buildiq/api/applications/<slug>/versions`

#### Scenario: Versions render for an app with at least one version

- **GIVEN** an Application `<slug>` with one or more `ApplicationVersion` rows
- **WHEN** the Manifest detail page loads
- **THEN** the version list renders one row per version (not the empty state)

### Requirement: Clicking a version row opens it in the builder

`VersionHistory` SHALL make each version row activatable; activating a row SHALL open the
live builder for the parent app at `?_version={versionSlug}` (the view/use path). The
production version row SHALL open `/apps/buildiq/builder/{slug}` without a `?_version=`
param (canonical production URL).

**ID:** REQ-OBV-VLU-002

#### Scenario: Click a non-production version opens it scoped

- **GIVEN** a version list with a non-production version whose slug is `draft-2`
- **WHEN** the user activates that row
- **THEN** the builder opens at `/apps/buildiq/builder/<slug>?_version=draft-2`

#### Scenario: Click the production version opens the canonical URL

- **GIVEN** the production version row
- **WHEN** the user activates it
- **THEN** the builder opens at `/apps/buildiq/builder/<slug>` with no `?_version=` param

### Requirement: Per-row Edit opens the designer scoped to the version

`VersionHistory` SHALL expose a per-row **Edit** affordance, visible to callers with an
editor or owner role. Activating Edit SHALL navigate to the page/schema designer scoped to
that version via `buildVersionedRoute(<designerRouteName>, { slug }, versionSlug)` so the
`?_version=` param survives in-app navigation.

**ID:** REQ-OBV-VLU-003

#### Scenario: Edit a version opens the designer with the version param

- **GIVEN** a version row whose slug is `draft-2` and a caller with editor role
- **WHEN** the user activates Edit on that row
- **THEN** the app navigates to the designer route with
  `query: { _version: "draft-2" }` for `params: { slug: "<slug>" }`

#### Scenario: Edit is hidden for viewers

- **GIVEN** a caller whose role on the app is viewer only
- **WHEN** the version list renders
- **THEN** no per-row Edit affordance is shown

### Requirement: Production and active versions are visually marked

`VersionHistory` SHALL clearly mark the production version (the row whose uuid equals
`Application.productionVersion`) and SHALL highlight the currently active/selected version
(the one resolved by `useApplicationVersion` for the current `?_version=`).

**ID:** REQ-OBV-VLU-004

#### Scenario: Production version is marked

- **GIVEN** an Application whose `productionVersion` points at the version with slug
  `production`
- **WHEN** the version list renders
- **THEN** the `production` row carries a production marker distinct from the other rows

### Requirement: New-draft action creates a manifest-cloned, shared-register draft

The Manifest-detail / app-detail surface SHALL expose a **New draft** action (visible to
owners and editors). Activating it SHALL create an `ApplicationVersion` via
`POST /apps/buildiq/api/applications/{slug}/versions` with `status: draft`, `manifest`
cloned from the current production version's manifest, `application` set to the parent
Application uuid, and a generated `name`/`slug` (provisional scheme: `"Draft N"` /
`draft-n`). The created draft SHALL share the production version's `register` (it SHALL NOT
mint a per-version register — see `application-versions` delta and design.md Decision 2).

**ID:** REQ-OBV-VLU-005

#### Scenario: New draft clones production manifest and shares its register

- **GIVEN** an Application `<slug>` whose production version has manifest M and register
  `buildiq-<slug>-production`
- **WHEN** an owner activates New draft
- **THEN** a new `ApplicationVersion` is created with `status: draft`, `manifest` equal to
  M, `application` set to the parent uuid
- **AND** the new version's `register` is `buildiq-<slug>-production` (shared with
  production, not a freshly minted register)
- **AND** the version list re-renders showing the new draft

#### Scenario: New draft is offered to editors and owners only

- **WHEN** the surface renders for a caller whose role is viewer
- **THEN** the New draft action is not shown

### Requirement: Release action sets production, publishes, and demotes the previous production

The surface SHALL expose a **Release** action on a draft version, visible to **owners
only**. Activating it SHALL call the release endpoint (owner-only, server-enforced) which
sets `Application.productionVersion` to the chosen version, transitions that version
`draft → published`, and demotes the previously production version so it no longer holds
the production role. After a successful release exactly one version is production
(single-production invariant, design.md Decision 1).

**ID:** REQ-OBV-VLU-006

#### Scenario: Release promotes a draft to production

- **GIVEN** an Application with production version `V_old` (status `published`) and a draft
  `V_new`
- **WHEN** an owner activates Release on `V_new`
- **THEN** `Application.productionVersion` becomes `V_new`
- **AND** `V_new.status` is `published`
- **AND** `V_old` no longer holds the production role
- **AND** the version list re-renders with `V_new` marked as production

#### Scenario: Release is owner-only in the UI

- **WHEN** the surface renders for a caller whose role is editor (not owner)
- **THEN** the Release action is not shown

#### Scenario: Release failure surfaces an error and does not silently double-produce

- **GIVEN** the release endpoint returns an error
- **WHEN** an owner activates Release
- **THEN** the surface shows an error message
- **AND** the production marker is unchanged in the list (still the prior production)

### Requirement: "Open app" split button switches versions

`ApplicationDetailActions.vue` SHALL render the primary **Open app** button that opens the
**production** runtime (`/apps/buildiq/builder/{slug}`, no `?_version=`), attached to a
chevron (`NcActions`) that lists the app's versions. Each listed version SHALL offer a
**View/Use** entry (builder at `?_version={slug}`) and, for editor+ callers, an **Edit**
entry (designer at `?_version={slug}`). The production version SHALL be clearly marked in
the dropdown. By default the dropdown SHALL list draft and published versions and SHALL
NOT list archived versions (a provisional default; see design.md Open Questions).

**ID:** REQ-OBV-VLU-007

#### Scenario: Primary Open app opens production

- **WHEN** the user clicks the primary Open app button
- **THEN** the runtime opens at `/apps/buildiq/builder/<slug>` with no `?_version=` param

#### Scenario: Chevron lists versions with View and Edit

- **GIVEN** an Application with versions `production` and `draft-2`
- **WHEN** an editor opens the Open-app chevron
- **THEN** the dropdown lists both versions with the production version marked
- **AND** each non-production version offers View/Use (`?_version=<slug>`) and Edit
  (designer `?_version=<slug>`)

#### Scenario: Chevron View opens the version in the builder

- **GIVEN** the chevron lists version `draft-2`
- **WHEN** the user activates its View/Use entry
- **THEN** the builder opens at `/apps/buildiq/builder/<slug>?_version=draft-2`

#### Scenario: Archived versions are hidden by default

- **GIVEN** an Application with a version whose status is `archived`
- **WHEN** the chevron opens with no "show archived" toggle engaged
- **THEN** the archived version is not listed

### Requirement: Release and new-draft UI strings are translated EN + NL

The system SHALL translate all user-facing strings introduced by this capability into
English and Dutch. All such strings (New draft, Release, version
markers, error/toast messages, Open-app menu labels) SHALL be wrapped in the `buildiq`
translation domain and SHALL have Dutch translations in the `buildiq` `nl` catalogue.
Translation keys SHALL be the English source strings.

**ID:** REQ-OBV-VLU-008

#### Scenario: New strings appear in the NL catalogue

- **WHEN** the build extracts translatable strings
- **THEN** every new label has an entry in the `buildiq` `nl` translation file
- **AND** no Dutch string is used as a translation key
