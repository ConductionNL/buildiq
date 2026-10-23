# screen-override-layers Specification (delta)

## Purpose

A screen override is a patch over the layout it extends, bound to a named
audience, resolved by a declared fall-through, and it says so out loud when the
base it was cut against moves. Closes gap-register rows 11.43 and 11.51.

Extends `page-layout-per-type` (`case-page-layout-per-case-type`) and reuses the
keyed-delta contract of `app-delta-storage` (`app-delta-override`).

## ADDED Requirements

### Requirement: A screen override stores a patch, not a copy (REQ-OBSO-001)

`pageLayout` SHALL carry optional `baseRef` and `layoutDelta`. `baseRef` names
the layout this one extends, or nothing when it extends the consuming app's own
manifest layout. `layoutDelta` SHALL be the keyed delta that
`@conduction/nextcloud-vue` `diffManifest` produces and `mergeManifestDelta`
applies: tabs and widgets keyed by `id`, `{"$op": "remove"}` for a deletion and
`__order` for a reorder.

The editor SHALL persist the minimal delta against the resolved base, never a
full copy of it. A `pageLayout` carrying neither `baseRef` nor `layoutDelta`
SHALL resolve exactly as it does today, so no stored layout changes meaning.

**ID:** REQ-OBSO-001

#### Scenario: Renaming one tab stores one patch

- **WHEN** an admin opens a published schema-wide layout of four tabs, renames one and saves it as an override
- **THEN** the stored `layoutDelta` names that tab only, and the other three appear nowhere in it
- @e2e exclude storage shape; covered by PHPUnit on the editor save path

#### Scenario: A base change reaches an override that did not touch it

- **GIVEN** an override that patches tab two of a four tab base
- **WHEN** the base gains a fifth tab
- **THEN** the resolved layout has five tabs, with the override's change still on tab two
- @e2e exclude resolution; covered by PHPUnit on the merge

#### Scenario: A layout with no base keeps today's behaviour

- **WHEN** a `pageLayout` with neither `baseRef` nor `layoutDelta` is served
- **THEN** it is returned whole, exactly as before this change
- @e2e exclude regression guard; covered by PHPUnit

### Requirement: An override pins the base it was cut against (REQ-OBSO-002)

A `pageLayout` carrying `layoutDelta` SHALL record `baseFingerprint`, a hash of
the base as it stood when the delta was computed, and `baseCutAt`. Both SHALL
be written by the editor on every save of the delta, and never by hand.

Resolution SHALL compare the stored fingerprint with the base in hand. A
difference is drift, whether or not every patch still finds its key: a base can
move in ways a key match does not see, such as a tab that kept its id and
changed its kind.

**ID:** REQ-OBSO-002

#### Scenario: Saving a delta pins the base

- **WHEN** an override is saved
- **THEN** `baseFingerprint` and `baseCutAt` describe the base that was on screen
- @e2e exclude storage shape; covered by PHPUnit on the editor save path

#### Scenario: A base that changed is drift even when every patch applies

- **GIVEN** an override whose patches all still find their keys
- **WHEN** the base has changed since `baseCutAt`
- **THEN** resolution reports drift
- @e2e exclude resolution; covered by PHPUnit

### Requirement: Drift is loud, and the page still renders (REQ-OBSO-003)

On drift the system SHALL:

1. move the override to lifecycle state `needs-review`, declared through
   `x-openregister-lifecycle` (ADR-031),
2. notify the override's maintainer through
   `x-openregister-notifications` (ADR-031), naming the override, the base and
   the paths that no longer apply,
3. serve the base layout, with the override's own change withheld, and
4. carry in the served answer that an override was withheld and why.

The system SHALL NOT apply a drifted override, and SHALL NOT drop it. The
stored delta survives untouched so a maintainer can re-cut it against the new
base with one action.

A screen SHALL NOT fail to render because an override drifted. Loud means
somebody is told and the answer says so, not that a user meets an error page.

**ID:** REQ-OBSO-003

#### Scenario: A withheld override is announced, not skipped

- **GIVEN** a published override in drift
- **WHEN** a consumer asks for the layout
- **THEN** the base layout is served, the answer says an override was withheld and names it
- **AND** the override is in `needs-review` and its maintainer has been notified
- @e2e exclude declarative lifecycle and notification; covered by PHPUnit on resolution and a register-import test on the dialect

#### Scenario: The stored patch survives the drift

- **GIVEN** an override in `needs-review`
- **WHEN** the maintainer opens it
- **THEN** the delta is still stored and offered for re-cutting against the new base
- e2e: `tests/e2e/screen-override-drift.spec.ts`

#### Scenario: Nothing is silently dropped

- **GIVEN** an override whose every patch path is orphaned by the base
- **WHEN** a consumer asks for the layout
- **THEN** the answer still names the withheld override, and the page renders on the base
- @e2e exclude resolution; covered by PHPUnit

### Requirement: An override is named and bound to an audience (REQ-OBSO-004)

`pageLayout` SHALL carry `name`, shown wherever an override is listed, and
`audience` of `{kind, ref}`. `kind` SHALL be one of `everyone`, `group`, `team`,
`portal` or `user`. `ref` SHALL name the group, team, portal or user, and SHALL
be absent for `everyone`.

An override bound to `portal` SHALL be resolvable for a visitor with no account.
An override bound to `group`, `team` or `user` SHALL NOT be resolvable for such
a visitor, whatever the consumer asks for.

`audience` unset SHALL read as `everyone`, so every stored layout keeps its
current reach.

**ID:** REQ-OBSO-004

#### Scenario: Three screens for one case type

- **WHEN** an admin publishes a handler override bound to a team, a front-office override bound to a group and a portal override for one case type
- **THEN** all three exist side by side on the same base
- e2e: `tests/e2e/screen-override-editor.spec.ts`

#### Scenario: A portal visitor never gets an internal override

- **GIVEN** a group-bound override and a portal-bound override on one layout
- **WHEN** the portal asks as a visitor with no account
- **THEN** only the portal override composes the answer
- @e2e exclude resolution; covered by PHPUnit on the provider

### Requirement: Fall-through is declared, total and tie-free (REQ-OBSO-005)

Resolution SHALL compose the layers in this order, each patching the result of
the one before it:

1. the consuming app's own manifest layout, or the layout named by `baseRef`,
2. the schema-wide layout,
3. the layout for the object's type value,
4. the audience override, `portal` first for a visitor with no account, else
   `team`, then `group`,
5. the user override.

A caller matching two overrides of the same `kind` at the same step SHALL be an
error at save, not a race at read: saving a second published override with the
same `(targetApp, register, schema, typeProperty, typeValue, audience)` SHALL be
refused and SHALL name the existing one, the way REQ-OBPL-001 refuses two
layouts for one type value.

Draft overrides SHALL never resolve.

**ID:** REQ-OBSO-005

#### Scenario: The narrow screen wins over the wide one

- **GIVEN** a schema-wide layout, a type layout and a team override
- **WHEN** a member of that team opens an object of that type
- **THEN** the served layout is all three composed, in that order
- @e2e exclude resolution; covered by PHPUnit on the provider

#### Scenario: A user in two teams with overrides is refused at save

- **WHEN** an admin publishes a second team override for the same schema, type value and audience kind
- **THEN** the save is refused and names the existing override
- @e2e exclude uniqueness invariant; covered by PHPUnit on the register import

#### Scenario: A draft override changes nothing

- **GIVEN** a draft team override
- **WHEN** a member of that team asks for the layout
- **THEN** the answer is the published layers only
- @e2e exclude resolution; covered by PHPUnit

### Requirement: The answer names the layers that composed it (REQ-OBSO-006)

The `buildiq-page-layout` provider SHALL return, beside the resolved layout, an
ordered `appliedLayers[]` naming each layer by id, name and audience, and a
`withheld[]` naming every override that drifted or was refused for this caller.

A consumer MAY show this to an administrator. A consumer SHALL NOT be required
to read it to render, and the resolved layout SHALL remain complete on its own.

Without this, a handler who sees fewer tabs than a colleague has no way to learn
why, and neither has the administrator who is asked about it.

**ID:** REQ-OBSO-006

#### Scenario: An administrator can see why the screen is narrow

- **GIVEN** a base, a type layout and a front-office override composing one answer
- **WHEN** the provider answers
- **THEN** `appliedLayers[]` names the three in order with their audiences
- @e2e exclude provider contract; covered by PHPUnit

#### Scenario: A withheld override is in the answer

- **GIVEN** a drifted override for this caller
- **WHEN** the provider answers
- **THEN** `withheld[]` names it with the reason `drift`
- @e2e exclude provider contract; covered by PHPUnit
