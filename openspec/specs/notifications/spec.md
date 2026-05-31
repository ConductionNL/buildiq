# notifications Specification

## Purpose

Schema-declared notifications for OpenBuild. OpenBuild does not ship any
per-app notification code; instead it declares `x-openregister-notifications`
rules on its `exportJob` and `ApplicationVersion` schemas, and OpenRegister's
notification engine (shipped in the `openregister` change
`notification-schema-rules-and-userconfig-prefs`) dispatches the configured
channels when a matching lifecycle event fires. This surfaces the two moments
a builder most needs to hear about — export jobs finishing (success or
failure) and version lifecycle changes (published / archived) — without any
imperative notification code in OpenBuild.

The OpenRegister engine matches a `transition` trigger on the transition
**action name** carried by `ObjectTransitionedEvent::getAction()` (the
transition table's action key, e.g. `succeed` / `publish`), not the
destination state. OpenBuild's rules therefore key on the lifecycle
transition names declared in each schema's `x-openregister-lifecycle.transitions`.

**OpenSpec changes**: [openbuild-notifications](../../changes/archive/2026-05-31-openbuild-notifications/) _(archived 2026-05-31)_

## Requirements

### Requirement: Export job outcome notifications

The OpenBuild `exportJob` schema SHALL declare `x-openregister-notifications`
rules that notify the job's manage-ACL holders when an export job runs the
named lifecycle transition `succeed` or `fail`, with bilingual (nl/en)
subjects. The rule `trigger.action` keys SHALL match the transition names
declared in the schema's `x-openregister-lifecycle.transitions` (the engine
matches the transition action name, not the destination state).

#### Scenario: Export job succeeds

- **WHEN** an `exportJob` object runs the `succeed` lifecycle transition
- **THEN** the OpenRegister notification engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{applicationVersion}}`

#### Scenario: Export job fails

- **WHEN** an `exportJob` object runs the `fail` lifecycle transition
- **THEN** the engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{applicationVersion}}` and `{{errorMessage}}`

### Requirement: Application version lifecycle notifications

The OpenBuild `ApplicationVersion` schema SHALL declare
`x-openregister-notifications` rules that notify the version's manage-ACL
holders when it runs the named lifecycle transition `publish` or `archive`,
with bilingual (nl/en) subjects. The rule `trigger.action` keys SHALL match
the transition names declared in the schema's
`x-openregister-lifecycle.transitions` (the engine matches the transition
action name, not the destination state).

#### Scenario: Version published

- **WHEN** an `ApplicationVersion` object runs the `publish` lifecycle transition
- **THEN** the engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{semver}}` and `{{name}}`

#### Scenario: Version archived

- **WHEN** an `ApplicationVersion` object runs the `archive` lifecycle transition
- **THEN** the engine dispatches an `nc-notification` to the object's manage-ACL holders with a nl/en subject referencing `{{semver}}` and `{{name}}`
