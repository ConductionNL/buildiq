---
kind: config
---

# OpenBuild — schema-declared notifications

## Why

OpenBuild is a low-code app builder for citizen developers. The two
moments a builder most needs to hear about are **export jobs finishing**
(success or failure — the export pipeline runs async and can fail on
GitHub auth, validation, or packaging) and **version lifecycle changes**
(a version being published or archived). Today nothing surfaces these;
the builder has to poll the export-job list.

The OpenRegister notification engine (shipped in the `openregister`
change `notification-schema-rules-and-userconfig-prefs`, archived
2026-05-26) consumes a top-level `x-openregister-notifications` key on a
schema in the register JSON and dispatches `nc-notification` (and other)
channels on the configured trigger. Declaring these rules on OpenBuild's
`exportJob` and `ApplicationVersion` schemas gives builders timely
feedback with no per-app notification code.

This is a configuration change: it adds annotations to
`lib/Settings/openbuild_register.json`. No PHP/Vue changes.

## What Changes

Add `x-openregister-notifications` to two schemas in
`lib/Settings/openbuild_register.json`.

### `exportJob` — export pipeline outcome

`exportJob.status` is an enum `queued | running | succeeded | failed`,
so the lifecycle is expressible via the `transition` trigger (no engine
gap dependency). The schema has **no structured owner uid field**, so
recipients use `object-acl` (the user who owns / can manage the job
object — the builder who started the export).

```jsonc
"x-openregister-notifications": {
  "export-succeeded": {
    "trigger": { "type": "transition", "action": "succeeded" },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ { "kind": "object-acl", "permission": "manage" } ],
    "subject": {
      "nl": "Export geslaagd voor {{applicationVersion}}",
      "en": "Export succeeded for {{applicationVersion}}"
    }
  },
  "export-failed": {
    "trigger": { "type": "transition", "action": "failed" },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ { "kind": "object-acl", "permission": "manage" } ],
    "subject": {
      "nl": "Export mislukt voor {{applicationVersion}}: {{errorMessage}}",
      "en": "Export failed for {{applicationVersion}}: {{errorMessage}}"
    }
  }
}
```

### `ApplicationVersion` — version published / archived

`ApplicationVersion.status` is an enum `draft | published | archived`.
Publishing and archiving are version lifecycle milestones the builder
(and any co-maintainers) want to know about. Expressed via `transition`.
Recipients use `object-acl` (no structured owner field on the schema).

```jsonc
"x-openregister-notifications": {
  "version-published": {
    "trigger": { "type": "transition", "action": "published" },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ { "kind": "object-acl", "permission": "manage" } ],
    "subject": {
      "nl": "Versie {{semver}} van {{name}} is gepubliceerd",
      "en": "Version {{semver}} of {{name}} has been published"
    }
  },
  "version-archived": {
    "trigger": { "type": "transition", "action": "archived" },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ { "kind": "object-acl", "permission": "manage" } ],
    "subject": {
      "nl": "Versie {{semver}} van {{name}} is gearchiveerd",
      "en": "Version {{semver}} of {{name}} has been archived"
    }
  }
}
```

## Capabilities

- A builder is notified when an export job they own succeeds or fails,
  with the failure reason inlined in the failed-export subject.
- A builder (and co-maintainers with manage ACL) is notified when an
  application version is published or archived.
- All rules ship `enabled: true` by default; users can override per
  `(schema, rule)` via OpenRegister's override-only user-config prefs.
- Subjects ship in both Dutch and English (ADR-007 / ADR-025).

## Impact

- Affected file: `lib/Settings/openbuild_register.json` (two schemas
  gain a `x-openregister-notifications` key).
- No PHP, Vue, route, or migration changes.
- Runtime dependency on the OpenRegister notification engine
  (`notification-schema-rules-and-userconfig-prefs`, already archived).
- Notifications only fire once the export pipeline writes the named
  `transition` actions — see Caveats.

## Caveats

- **Export pipeline must write transition actions.** The `transition`
  trigger fires on a named lifecycle action, not on a raw `status`
  field write. The OpenBuild export pipeline currently sets
  `exportJob.status` directly; for `export-succeeded` / `export-failed`
  to fire, the pipeline must drive status through OpenRegister
  transition actions named `succeeded` and `failed` (and the
  `ApplicationVersion` publish/archive flows through `published` /
  `archived` actions). If transition actions are not wired, these rules
  are declared-but-dormant. This is the prerequisite for the change to
  have observable effect.
- **No structured owner uid on either schema.** Neither `exportJob` nor
  `ApplicationVersion` carries an owner-uid field, so recipients use
  `object-acl` (`permission: manage`) rather than `field`. This routes
  to whoever holds manage ACL on the object — typically the builder who
  created it, plus co-maintainers. If a precise "the builder who started
  this export" target is needed later, add a structured `ownerUid`
  field and switch to `{"kind":"field","field":"ownerUid"}`.
- The `updated` trigger has no field-changed condition yet (the engine
  change `notification-updated-field-change-condition` adds it); this
  change deliberately uses only `transition` to avoid that dependency.
