# Design — Retrofit deep-link-registration

> Retrofit change. Tasks describe retroactive annotation, not new implementation
> work. The code already exists at HEAD.

## Context

`lib/Listener/DeepLinkRegistrationListener.php` (and its companion
wiring in `lib/AppInfo/Application.php::register`) implement
OpenBuild's hook into OpenRegister's unified-search deep-link surface.
The 2026-05-24 coverage scan dropped these into Bucket 2b
(`deep-link-registration`) because no existing openbuild capability
spec names this listener or the event it consumes.

## Decisions

- **New capability, not extend.** The closest existing spec is
  `openbuild-runtime`, but that's a runtime/serving capability — the
  listener is a search-integration capability, not a runtime mount.
  ADR-019 is the closest org-wide concept, but it does not specify
  per-app listener wiring. So this is a brand-new app-local capability.
- **Two REQs, not one.** Wiring (REQ-OBDL-001) vs payload
  (REQ-OBDL-002). Splitting them lets future PRs add more
  `$event->register(...)` calls under REQ-OBDL-002 without touching
  REQ-OBDL-001's wiring contract.
- **Capture the stub honestly.** The current handler body registers a
  single placeholder entry for `schemaSlug: 'example'` — clearly
  template-stub leftover (matches the comment "Update the register
  slug, schema slug, and URL template to match your app's actual
  schemas"). REQ-OBDL-002 stays minimal ("at least one entry, with
  `{uuid}` placeholder") and the Note block names the gap as TODO
  rather than silently spec'ing the placeholder as production
  behaviour.
- **No hard OR dependency.** The wiring is "if OR is installed and
  dispatches the event, OpenBuild responds". REQ-OBDL-001's scenario
  pins this as an observable invariant — the app must not crash when
  OR is absent.

## Out of scope

- Replacing the placeholder `example` schema with real OpenBuild
  schemas (`application`, `application-version`, `built-app-route`) —
  separate PR, will extend REQ-OBDL-002 with additional scenarios.
- ADR-019 integration-registry surface — orthogonal.
- Unified-search result rendering / icon — OR concern.
