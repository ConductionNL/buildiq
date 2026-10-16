## MODIFIED Requirements

### Requirement: Dynamic per-app top-bar entry for each published Application

The system SHALL register one `INavigationManager` entry per published Application in
`Application::boot()` using `INavigationManager::add()` with a closure factory. Each entry
SHALL carry:

- **id**: `buildiq-app-{slug}` (e.g. `buildiq-app-hello-world`).
- **name**: the Application's `name` field value.
- **href**: the path produced by `IURLGenerator::linkToRoute('buildiq.dashboard.builder',
  ['slug' => $slug])` — the virtual-app runtime URL for that slug. The href SHALL NOT be a
  hand-built string; it SHALL always be generated through `IURLGenerator` so it resolves
  correctly whether or not the target instance requires the `/index.php` front-controller
  segment.
- **icon**: the URL produced by `IURLGenerator::linkToRouteAbsolute('buildiq.icon.iconLight',
  ['slug' => $slug])` — pointing at the icon-serving endpoint (REQ-OBICON-002).
- **order**: numeric value placing entries after buildiq's own static entry, sorted
  alpha-ascending by `name` within the virtual-app group.

The entries SHALL be registered by `AppNavigationService`, which is lazily resolved from the
DI container inside the `boot()` method.

**ID:** REQ-OBNAV-001

#### Scenario: Published app appears in the Nextcloud top bar

- **WHEN** the Nextcloud request cycle boots after an Application is transitioned to `published`
- **AND** the signed-in user satisfies the visibility predicate for that Application
- **THEN** `INavigationManager::getAll()` includes an entry with
  `id = "buildiq-app-{slug}"`, `href` equal to
  `IURLGenerator::linkToRoute('buildiq.dashboard.builder', ['slug' => slug])`, and the
  app's name

#### Scenario: Draft app does not appear in the top bar

- **WHEN** an Application has `status: draft`
- **THEN** no nav entry with `id = "buildiq-app-{slug}"` appears for any user

#### Scenario: Archived app does not appear in the top bar

- **WHEN** an Application has `status: archived`
- **THEN** no nav entry with `id = "buildiq-app-{slug}"` appears for any user

#### Scenario: Nav entry href resolves on a front-controller-required instance

- **WHEN** the target Nextcloud instance has no URL rewriting available (the front controller
  `index.php` cannot be hidden from generated URLs)
- **AND** a published Application's nav entry is registered
- **THEN** the entry's `href` includes the `/index.php` segment, exactly as
  `IURLGenerator::linkToRoute` would produce for that instance, and clicking the entry does
  not 404

#### Scenario: Nav entry href omits the front controller on a rewrite-enabled instance

- **WHEN** the target Nextcloud instance has URL rewriting enabled (the front controller is
  hidden from generated URLs)
- **AND** a published Application's nav entry is registered
- **THEN** the entry's `href` does NOT include an `/index.php` segment, matching what
  `IURLGenerator::linkToRoute` produces for that instance
