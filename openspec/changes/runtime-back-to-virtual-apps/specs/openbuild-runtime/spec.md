## ADDED Requirements

### Requirement: The standalone shell offers builders a way back to the app list

The standalone runtime at `/apps/buildiq/builder/{slug}` SHALL show a link labelled "Back to virtual apps" at the top of the app's navigation. The link SHALL open Buildiq's app list at `/apps/buildiq/applications`.

When the page on screen, or the app's navigation, declares its own `primaryAction`, that action keeps the top of the navigation and the link SHALL NOT show on that page.

The link SHALL show when the page is a version preview (the URL carries `?_version=`) or when the manifest response marks the caller as the app's owner (`runtime.user.isOwner` is true). In every other case the link SHALL NOT show.

The link SHALL be rendered by the runtime host. It SHALL NOT be added to the manifest, so an in-app save never stores it.

#### Scenario: A version preview shows the way back

- **WHEN** a builder opens `/apps/buildiq/builder/{slug}?_version=development`
- **THEN** the top of the app's navigation shows a "Back to virtual apps" link
- **AND** the link points at `/apps/buildiq/applications`

#### Scenario: Other users of a published app see no link

- **WHEN** a user who does not own the app opens `/apps/buildiq/builder/{slug}` without `?_version=`
- **THEN** no "Back to virtual apps" link is shown

#### Scenario: The link never lands in the manifest

- **WHEN** a version preview shows the link
- **THEN** the manifest the server returns for that version contains no entry for the link
- **AND** an in-app save, which stores that manifest, cannot store the link
