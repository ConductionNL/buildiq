# Design — Retrofit app-icon-management UUID resolution

> Retrofit change. Tasks describe retroactive annotation, not new implementation
> work. The code already exists at HEAD.

## Context

The 2026-05-24 coverage scan dropped `IconService::extractUuid` into
Bucket 2a (`app-icon-management`) because the capability's existing
REQs (REQ-OBICON-001..004) specify the icon-serving endpoints, the
top-level `icon`/`iconDark` fields on the Application schema, and the
upload UX — but none of them name the helper that turns an OR
Application array into the UUID handed to `FileService::getFile`.

## Decisions

- **Extend not cluster.** The helper is plumbing for the icon endpoints
  already covered by REQ-OBICON-002..003. A new capability for a
  3-fallback accessor would be REQ inflation.
- **One REQ, not three.** The three fallback locations (`@self.id`,
  `@self.uuid`, top-level `uuid`) are one observable behaviour — "give
  me the UUID for this Application, however OR happens to have shaped
  it". Split into three REQs would inflate without adding testable
  surface.
- **Codify the order.** The spec pins the fallback order. The order
  matters: `@self.id` is the modern OR shape, top-level `uuid` is the
  legacy shape; flipping the order would let stale legacy fields
  shadow a present-day `@self.id` when both are set.
- **Null is part of the contract.** Returning `null` (vs throwing) is
  observable and load-bearing — the icon-serving endpoint's fallback
  chain (Decision 2 in design.md of the original change) relies on it
  to step through to the filesystem fallbacks. The REQ pins this so
  it can't be refactored into an exception without a spec change.

## Out of scope

- Caching the resolved UUID — out of scope; the call is microseconds.
- Validating UUID shape (RFC 4122) — out of scope; OR is the source
  of truth for what counts as a valid UUID.
