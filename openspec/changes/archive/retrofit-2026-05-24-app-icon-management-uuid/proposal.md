# Retrofit — app-icon-management (UUID resolution)

Describes the observed behaviour of `IconService::extractUuid` as 1 new
REQ added to the `app-icon-management` capability. Code already exists —
this change retroactively specifies it.

## Affected code units

- lib/Service/IconService.php::extractUuid

## Approach

- Single helper, single REQ. The method governs how `IconService`
  derives the OR object UUID it then hands to `FileService::getFile`
  when pulling the icon attachment off the Application record.
- Folded into `app-icon-management` (extend) because the helper exists
  exclusively in service of the icon-serving endpoints already
  specified by REQ-OBICON-002..003. A standalone capability for a
  3-fallback UUID accessor would be REQ inflation.
- Scenarios codify the documented fallback order (`@self.id` →
  `@self.uuid` → top-level `uuid`) so future refactors can't quietly
  drop a fallback step without changing the spec.

Source: `openspec/coverage-report.md` generated 2026-05-24. See
[retrofit playbook](../../../../hydra/.github/docs/claude/retrofit.md).
