---
retrofit_extensions:
  - REQ-OBICON-005
---

# app-icon-management Specification Delta (Retrofit — UUID resolution)

## Requirements

### Requirement: Application UUID resolution for icon attachment lookup

`IconService` SHALL derive the OR object UUID it uses for icon
attachment lookups (`FileService::getFile`) from a normalised
Application array. The derivation SHALL walk three fallback locations
in this order: `@self.id`, `@self.uuid`, then top-level `uuid`. Each
candidate SHALL be accepted only when it is a non-empty string;
anything else (missing key, null, empty string, non-string scalar)
SHALL be skipped without raising. When no candidate produces a usable
value the helper SHALL return `null`, and the calling
`fetchAttachedFileStream` SHALL surface that as a short-circuit
fallback (no OR call, downstream fallback chain runs) — not as an
exception.

**ID:** REQ-OBICON-005

#### Scenario: UUID lifted from @self.id

- **GIVEN** an Application array of shape
  `{ '@self': { id: 'abc-123' }, ... }`
- **WHEN** `IconService::extractUuid` is called
- **THEN** the returned UUID is `'abc-123'`

#### Scenario: UUID lifted from @self.uuid when @self.id is missing

- **GIVEN** an Application array of shape
  `{ '@self': { uuid: 'def-456' }, ... }` (no `@self.id`)
- **WHEN** `extractUuid` is called
- **THEN** the returned UUID is `'def-456'`

#### Scenario: UUID lifted from top-level uuid when @self is absent

- **GIVEN** an Application array of shape
  `{ uuid: 'ghi-789' }` (no `@self`)
- **WHEN** `extractUuid` is called
- **THEN** the returned UUID is `'ghi-789'`

#### Scenario: Empty string candidates are skipped

- **GIVEN** an Application array of shape
  `{ '@self': { id: '' }, uuid: 'fallback-uuid' }`
- **WHEN** `extractUuid` is called
- **THEN** the empty `@self.id` is skipped and the returned UUID is
  `'fallback-uuid'`

#### Scenario: No usable UUID returns null

- **GIVEN** an Application array of shape `{ name: 'x' }` (no UUID
  anywhere)
- **WHEN** `extractUuid` is called
- **THEN** the helper returns `null` and the downstream
  `fetchAttachedFileStream` SHALL return `null` without invoking
  `FileService::getFile`, so the icon-serving endpoint cascades to
  the next fallback (Decision 2 chain in design.md)
