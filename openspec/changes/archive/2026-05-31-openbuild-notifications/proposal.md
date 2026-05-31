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
driven by the schema's `x-openregister-lifecycle` transitions named
`start | succeed | fail`. The `transition` trigger matches on the
transition **action name** (`succeed` / `fail`), not the destination
state — see Caveats — so the rules key on `succeed` / `fail`. The schema
has **no structured owner uid field**, so
recipients use `object-acl` (the user who owns / can manage the job
object — the builder who started the export).

```jsonc
"x-openregister-notifications": {
  "export-succeeded": {
    "trigger": { "type": "transition", "action": "succeed" },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ { "kind": "object-acl", "permission": "manage" } ],
    "subject": {
      "nl": "Export geslaagd voor {{applicationVersion}}",
      "en": "Export succeeded for {{applicationVersion}}"
    }
  },
  "export-failed": {
    "trigger": { "type": "transition", "action": "fail" },
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

`ApplicationVersion.status` is an enum `draft | published | archived`,
driven by the schema's `x-openregister-lifecycle` transitions named
`publish | archive | reopen`. Publishing and archiving are version
lifecycle milestones the builder (and any co-maintainers) want to know
about. The `transition` trigger matches on the transition **action name**
(`publish` / `archive`), not the destination state — so the rules key on
`publish` / `archive`. Recipients use `object-acl` (no structured owner
field on the schema).

```jsonc
"x-openregister-notifications": {
  "version-published": {
    "trigger": { "type": "transition", "action": "publish" },
    "enabled": true,
    "channels": ["nc-notification"],
    "recipients": [ { "kind": "object-acl", "permission": "manage" } ],
    "subject": {
      "nl": "Versie {{semver}} van {{name}} is gepubliceerd",
      "en": "Version {{semver}} of {{name}} has been published"
    }
  },
  "version-archived": {
    "trigger": { "type": "transition", "action": "archive" },
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

- **The trigger matches the transition NAME, not the destination state.**
  OR's `AnnotationNotificationDispatcher::matches()` compares the rule's
  `trigger.action` against `ObjectTransitionedEvent::getAction()`, which
  carries the transition table's action *name* (e.g. `succeed`,
  `publish`) — not the `to` state (`succeeded`, `published`). The
  `exportJob` lifecycle declares transitions `start | succeed | fail` and
  `ApplicationVersion` declares `publish | archive | reopen`. The rules
  therefore key on `succeed` / `fail` / `publish` / `archive`. A rule
  keyed on a state name would be declared-but-dormant. This alignment is
  pinned by a unit test
  (`ApplicationVersionLifecycleSchemaTest::testNotificationActionsMatchLifecycleTransitionNames`).
- **exportJob pipeline drives the named transitions — verified.** The
  export pipeline (`RunExportJob` → `ExportJobService::transitionJob()`)
  drives `exportJob.status` through OR's `TransitionEngine->transition()`
  with the named actions `start` / `succeed` / `fail`, which dispatch
  `ObjectTransitionedEvent`. So `export-succeeded` / `export-failed` fire
  end-to-end once OR's `TransitionEngine` is available on the installed
  build (older builds log a gap and skip — never silent direct writes).
- **ApplicationVersion publish/archive still writes status directly
  (known dormancy).** `VersionPromotionService` sets
  `$target['status'] = 'published'` / `'archived'` via `saveObject`
  rather than driving the named `publish` / `archive` transitions through
  `TransitionEngine`; no `ObjectTransitionedEvent` is dispatched, so the
  `version-published` / `version-archived` rules are declared-but-dormant
  until that pipeline is routed through the lifecycle engine. The rule
  action keys are nonetheless correct (`publish` / `archive`), so the
  rules light up automatically once the promotion path is wired — no
  schema change will then be needed. Routing the promotion pipeline
  through `TransitionEngine` is out of scope for this configuration
  change (it touches `VersionPromotionService` PHP and is tracked
  separately).
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
