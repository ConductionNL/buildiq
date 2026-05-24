# Retrofit — deep-link-registration (new capability)

Describes the observed behaviour of
`DeepLinkRegistrationListener::handle` as 2 REQs in a brand-new
capability spec. Code already exists — this change retroactively
specifies it.

## Affected code units

- lib/AppInfo/Application.php::register (event-listener wiring only)
- lib/Listener/DeepLinkRegistrationListener.php::handle

## Approach

- New capability (`--cluster`) rather than `--extend`: the existing
  openbuilt specs (runtime, RBAC, exporter, versioning, etc.) all
  describe authoring or runtime serving of virtual apps. None covers
  the listener that hooks OpenRegister's unified-search deep-link
  registration event. ADR-019 (integration registry) is the closest
  org-wide concept, but it does not specify per-app event-listener
  wiring as a capability.
- Two REQs: one for the event-listener wiring (REQ-OBDL-001) and one
  for the registration payload that the handler emits (REQ-OBDL-002).
  Splitting the wiring from the payload lets future schema additions
  (more `$event->register(...)` calls) extend REQ-OBDL-002 without
  re-litigating the wiring posture.
- The current `handle` body registers a single deep link for a
  placeholder schema slug `example` (template-stub left over from
  scaffold) — Notes block flags this as observed-but-incomplete TODO,
  not silently spec'd as production behaviour.

Source: `openspec/coverage-report.md` generated 2026-05-24. See
[retrofit playbook](../../../../hydra/.github/docs/claude/retrofit.md).
