---
kind: code
depends_on:
  - case-page-layout-per-case-type
  - app-delta-override
---

## Why

Two rows of the competitor gap register are the mechanism under
`case-page-layout-per-case-type`, and neither is built. A customer's screen
configuration is stored whole, so the next release of the base layout either
overwrites it or freezes it out, and there is exactly one of it, so a handler,
the front office and a portal visitor all get the same screen.

Both rows come from the pending-proposal corpus,
`procest/_round4/compare/proposed-rows-dossiq-2026-09-10.md` in
ConductionNL/market-intelligence, promoted under decision D1, and both are
buildiq's under `procest/_gaps/ownership-rules.md`: a screen is buildiq's, the
case-type semantics stay dossiq's.

### Row 11.43, screen configuration stored as a patch, failing loudly when the layout moves

Rating for dossiq: `no`. Area: configuration.

Ledger `source`, verbatim:

```
dossiq#2314, published as 11.35
```

Ledger `note`, verbatim:

> Rows 11.6 to 11.9 are all no, so there is no screen configuration to store yet. This is the mechanism to build them in, because the alternative drops a customer's change silently at the next release.

Corpus batch file table row, verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.43** | 11.35 | Screen configuration stored as a patch, failing loudly when the layout moves | no | unread |  |
```

### Row 11.51, named screen overrides bound to a team or a portal, with fall-through

Rating for dossiq: `no`. Area: configuration.

Ledger `source`, verbatim:

```
dossiq#2314, published as 11.43
```

Ledger `note`, verbatim:

> Rows 11.6 to 11.9 are all no. One screen for handlers, a narrower one for the front office and a third for the portal is the ordinary requirement, and there is no shape for it.

Corpus batch file table row, verbatim:

```
| proposed | in dossiq | capability | dossiq | competitors | cross-reference |
| **11.51** | 11.43 | Named screen overrides bound to a team or a portal, with fall-through | no | unread |  |
```

## What the competitor evidence is

Nothing was read of any competitor for either row. Both are D1 rows, and the
corpus says so in as many words:

> Every competitor column is `unread`, and none of them is `no`. ... `no` is a reading of a product somebody opened, and filling these cells with it would fabricate thirty readings per row.

So this proposal makes no competitor claim. Neither row carries a
cross-reference either. The evidence is the ledger note above, which reads
dossiq's own tree, and the state of this repo's own changes, read below.

## What is already here, and where it stops

Read in full before this change was written.

- **`app-delta-override`** stores an application manifest as `baseRef` plus a
  keyed `manifestDelta`, with the `mergeManifestDelta`, `diffManifest`,
  `$op: remove` and `__order` contract from `@conduction/nextcloud-vue`. That
  is the patch mechanism row 11.43 asks for, and this change reuses it rather
  than writing a second one. It stops at the application manifest: a page
  layout is not an application.
- **Its orphaned-delta requirement** is the closest thing here to the row's
  second half, and it is deliberately the opposite: "a `manifestDelta` patch
  whose key matches no base entry SHALL be skipped (base drift), resolution
  SHALL NOT fail", with the paths surfaced to the editor and explicitly kept
  out of the served response. Nobody is told. A customer who does not open the
  editor learns nothing, which is the release the row's note describes.
- **`layered-versioned-app-deltas`** adds a per-user layer over the admin
  layer. Two layers keyed by identity, not named overrides bound to an
  audience, and a user layer is private to its owner rather than shared by a
  team.
- **`case-page-layout-per-case-type`** stores a `pageLayout` whole and resolves
  type value over schema over none. REQ-OBPL-007 says it plainly: "A
  case-type header SHALL replace the schema-wide header whole, and SHALL NOT
  merge field by field." Replacement whole is what a patch is not.

So both rows are open. This change is the mechanism under that layout change,
which is why it depends on it.

## What changes

- **A screen override is a patch.** `pageLayout` gains `baseRef` and
  `layoutDelta` and stores the minimal diff against the layout it extends,
  using the existing keyed-delta contract. A layout with neither keeps today's
  whole-object behaviour.
- **The base is pinned and drift is detected.** An override records the
  fingerprint of the base it was cut against. Resolution compares, and a moved
  base is a fact the system holds, not a patch that quietly misses.
- **Drift is loud.** A drifted override goes to `needs-review`, its maintainer
  is notified declaratively, and the served answer says the override was not
  applied. The page still renders, on the base. Loud means somebody is told,
  not that a screen breaks.
- **An override is named and bound to an audience.** `name` and `audience` of
  `everyone`, `group`, `team`, `portal` or `user`. One screen for handlers, a
  narrower one for the front office, a third for the portal.
- **Fall-through is declared and total.** Base, schema-wide, type value,
  audience, user, each patching the one below. Two overrides that would tie are
  refused at save, the way REQ-OBPL-001 already refuses two layouts for one
  type value.
- **The answer says what composed it.** The consumer receives one layout and
  the list of layers behind it, so a narrower screen can be explained instead
  of only observed.

## Size

M. Two schema property groups, a resolution order, a drift check and a
declarative notification. It is not L: the patch merge, the diff and the
orphan surface already exist in `app-delta-override`, and the layout object and
its leaf already exist in `case-page-layout-per-case-type`.

## The ADRs it cites

Read for this change, and only these:

- **ADR-031, schema-declarative business logic.** The `needs-review` transition
  is `x-openregister-lifecycle` and the maintainer's alert is
  `x-openregister-notifications`. No bespoke reminder job.
- **ADR-066, cross-app leaf registration.** The audience travels on the
  existing `buildiq-page-layout` data-provider leaf. No new cross-app surface,
  and the provider still calls nothing in the consumer.
- **ADR-022, apps consume OpenRegister abstractions.** The override is an
  OpenRegister object with OpenRegister versioning and audit. Buildiq adds no
  store of its own.
- **ADR-024, the app manifest**, for the keyed-delta contract the patch reuses.

## How dossiq and portaliq consume it

- **dossiq** places the `buildiq-page-layout` leaf once, which
  `case-page-layout-per-case-type` already specifies. Its half here is to say
  which audience it is asking for: the signed-in user's groups and teams on the
  desk channel. To be specified in dossiq, and it is one argument on a call it
  already makes.
- **portaliq** asks with the `portal` audience for a public visitor. It owns
  the public render, as the buildiq parity umbrella records. To be specified in
  portaliq.
- Neither app stores an override and neither resolves the order.

## Out of scope

- The layout editor's own screens. The "applies to" panel is
  `case-page-layout-per-case-type`'s, and this change adds two fields to it.
- Rights per audience. A narrower screen is not a permission, and hiding a
  widget hides nothing on the server. Rights stay OpenRegister RBAC.
- The application-manifest layers in `layered-versioned-app-deltas`. Its admin
  and user layers keep their meaning, and this change does not renumber them.
- Retro-fitting the drift alarm onto the application manifest. Whether
  `app-delta-override`'s fail-soft orphan skip should become loud too is a real
  question, and it belongs to that change, not this one.
