---
retrofit: true
---

# deep-link-registration Specification

## Purpose

@e2e exclude pure-backend event-listener spec — listener registration, event wiring, URL template, and short-circuit guards verified by PHPUnit; no UI surface testable via Playwright (deep-link integration requires OR to dispatch events, not exercisable in isolation)

OpenBuild opts in to Nextcloud's unified-search deep-link integration
by listening for OpenRegister's `DeepLinkRegistrationEvent` and
registering per-schema URL templates. When OR resolves a search hit on
a registered schema, the unified-search result row links straight to
the matching OpenBuild detail view — no double-click through OR.

This capability is event-driven: the contract is "if OR is installed
and dispatches the event, OpenBuild provides its deep-link table". If
OR is absent the event never fires and OpenBuild silently no-ops, so
the integration is optional and adds zero hard dependency.

## Requirements

### Requirement: Event listener wired at app registration

The app SHALL register a listener for
`OCA\OpenRegister\Event\DeepLinkRegistrationEvent` during
`Application::register` via
`IRegistrationContext::registerEventListener`. The listener class
SHALL be `OCA\OpenBuild\Listener\DeepLinkRegistrationListener` and
SHALL implement `OCP\EventDispatcher\IEventListener<Event>`. The
listener SHALL be idempotent at the Nextcloud DI level — re-running
`register()` SHALL NOT result in duplicate registrations. The wiring
SHALL NOT introduce a hard dependency on OpenRegister: if OR is not
installed, no event fires and the listener is never invoked.

**ID:** REQ-OBDL-001

#### Scenario: Listener is registered exactly once

- **WHEN** Nextcloud bootstraps the OpenBuild app and calls
  `Application::register`
- **THEN** `IRegistrationContext::registerEventListener` is invoked
  with `DeepLinkRegistrationEvent::class` and
  `DeepLinkRegistrationListener::class`

#### Scenario: OpenRegister absent → no-op

- **WHEN** OpenRegister is not installed and OpenBuild boots
- **THEN** the wiring registers without raising
- **AND** no deep-link entries are emitted because the event never
  fires

#### Scenario: Handler short-circuits on non-matching events

- **WHEN** the listener is invoked with an event that is not an
  instance of `DeepLinkRegistrationEvent`
- **THEN** `handle()` returns immediately without calling
  `$event->register(...)` and without raising

### Requirement: Per-schema deep-link entries registered against the event

When `handle()` receives a `DeepLinkRegistrationEvent`, it SHALL
register one or more deep-link entries by calling
`$event->register(appId, registerSlug, schemaSlug, urlTemplate)`.
Each entry SHALL declare `appId: 'openbuild'` as the host Nextcloud
app id. The `urlTemplate` SHALL be a relative path of shape
`/apps/openbuild/#/{schemaPath}/{uuid}` with `{uuid}` as the canonical
placeholder OR substitutes with the matching object's UUID. The
registration SHALL be additive — calling `$event->register(...)` once
per schema; the listener SHALL NOT mutate or remove previously
registered entries on the event.

**ID:** REQ-OBDL-002

#### Scenario: At least one deep-link entry is registered

- **WHEN** OR dispatches `DeepLinkRegistrationEvent` and the listener
  handles it
- **THEN** `$event->register(...)` is called at least once with
  `appId: 'openbuild'`

#### Scenario: URL template carries the {uuid} placeholder

- **WHEN** the listener registers a deep-link entry
- **THEN** the `urlTemplate` string contains the literal placeholder
  `{uuid}` that OR substitutes per result row

#### Note

The handler currently registers only a single placeholder entry for
schema slug `example` (left over from the
`nextcloud-app-template` scaffold — see `lib/Resources/template/lib/
Listener/DeepLinkRegistrationListener.php`). This is observed-but-
incomplete: the real OpenBuild schemas (`application`,
`application-version`, `built-app-route`, etc.) have no deep-link
entries yet, so unified-search hits on those rows still land on OR's
generic detail page. TODO (filed separately): replace the placeholder
with one `$event->register(...)` call per OpenBuild-owned schema. This
retrofit spec captures the wiring contract; the catalogue is
intentionally left to a follow-up so future PRs can extend
REQ-OBDL-002 without re-litigating REQ-OBDL-001.
