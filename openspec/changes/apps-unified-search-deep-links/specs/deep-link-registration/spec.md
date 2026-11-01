# deep-link-registration Specification (delta)

## MODIFIED Requirements

### Requirement: Event listener wired at app registration

The app SHALL register one listener for `OCA\OpenRegister\Event\DeepLinkRegistrationEvent` during `Application::register`. The listener SHALL be `OCA\Buildiq\Listener\BuiltAppDeepLinkListener`, which extends OpenRegister's AppHost `GenericDeepLinkRegistrationListener`. buildiq SHALL switch off the AppHost default deep-link listener (`'deepLinks' => false`) so the event is handled once. The wiring SHALL NOT introduce a hard dependency on OpenRegister: the event and parent class are referenced by name inside the existing OpenRegister guard, and when OpenRegister is absent no event fires.

**ID:** REQ-OBDL-001

#### Scenario: Listener is registered exactly once

- **WHEN** Nextcloud bootstraps the Buildiq app and calls `Application::register`
- **THEN** exactly one listener is registered for `DeepLinkRegistrationEvent`, and it is `BuiltAppDeepLinkListener`

#### Scenario: OpenRegister absent → no-op

- **WHEN** OpenRegister is not installed and Buildiq boots
- **THEN** the wiring registers without raising
- **AND** no deep-link entries are emitted because the event never fires

#### Scenario: Handler short-circuits on non-matching events

- **WHEN** the listener is invoked with an event that is not an instance of `DeepLinkRegistrationEvent`
- **THEN** `handle()` returns immediately without calling `$event->register(...)` and without raising

### Requirement: Per-schema deep-link entries registered against the event

When the listener handles a `DeepLinkRegistrationEvent`, it SHALL register the entries of `src/manifest.json` `deepLinks` with `appId: 'buildiq'`. That list SHALL hold at least the entry for buildiq's own `built-app` schema in register `buildiq`, with `urlTemplate` `/apps/buildiq/applications/{uuid}`, so an app found in Nextcloud search opens on its detail view in buildiq. Every `urlTemplate` SHALL be a path without the instance base, containing the literal placeholder `{uuid}`. Registration SHALL be additive.

**ID:** REQ-OBDL-002

#### Scenario: At least one deep-link entry is registered

- **WHEN** OR dispatches `DeepLinkRegistrationEvent` and the listener handles it
- **THEN** `$event->register(...)` is called with `appId: 'buildiq'`, `registerSlug: 'buildiq'`, `schemaSlug: 'built-app'` and `urlTemplate: '/apps/buildiq/applications/{uuid}'`

#### Scenario: URL template carries the {uuid} placeholder

- **WHEN** the listener registers a deep-link entry
- **THEN** the `urlTemplate` string contains the literal placeholder `{uuid}` that OR substitutes per result row

#### Scenario: An app found in search opens in buildiq

- **GIVEN** an app named "Vergunningen" built in buildiq
- **WHEN** a maker types "Vergunningen" in the Nextcloud search bar and picks the hit
- **THEN** the browser opens `/apps/buildiq/applications/{uuid}` for that app

#### Note

The placeholder entry for schema slug `example` and the `DeepLinkRegistrationListener` that held it are gone: AppHost's manifest-driven listener replaced them. The catalogue is now the manifest `deepLinks` list (buildiq's own schemas) plus the entries REQ-OBDL-003 computes for built apps.

## ADDED Requirements

### Requirement: Records of a built app open on their page in the app (REQ-OBDL-003)

Next to the manifest entries, the listener SHALL register one entry per detail page of the production version of every published built app. An entry SHALL name the version's register, the page's `config.schema`, and a `urlTemplate` of `/apps/buildiq/builder/{slug}` followed by the page route with its one route parameter replaced by `{uuid}`. A detail page whose route has no parameter, or more than one, SHALL be skipped. Versions other than the production version SHALL NOT be registered.

**ID:** REQ-OBDL-003

#### Scenario: A case found in search opens in the built app

- **GIVEN** a published app `vergunningen` whose production version has a detail page with route `/zaken/:id` on schema `zaak`
- **WHEN** a user searches a case title in the Nextcloud search bar and picks the hit
- **THEN** the browser opens `/apps/buildiq/builder/vergunningen/zaken/{uuid}` with that case's UUID

#### Scenario: A detail page without a route parameter

- **GIVEN** the production version has a detail page with route `/instellingen`
- **WHEN** the listener builds its entries
- **THEN** no entry is registered for that page

#### Scenario: An app without a production version

- **GIVEN** an app that has only a draft version
- **WHEN** the listener builds its entries
- **THEN** no entry is registered for that app's records, and its hits keep opening in OpenRegister
