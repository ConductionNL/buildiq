## Context

Kind: code. Two property groups on one schema, a resolution order, a drift
check and a declarative alarm.

`case-page-layout-per-case-type` stores a `pageLayout` per target schema and
type value and serves it through the `buildiq-page-layout` data-provider leaf.
`app-delta-override` stores an application manifest as `baseRef` plus a keyed
`manifestDelta` and merges it server side. This change puts the second
mechanism under the first object, and adds the audience the layout object has
no shape for.

## D1. The patch, borrowed rather than built

`layoutDelta` is the same keyed delta as `manifestDelta`: tabs and widgets keyed
by `id`, `{"$op": "remove"}`, `__order`. The merge is the PHP
`mergeManifestDelta` port that `app-delta-override` specifies, and the diff is
the JS `diffManifest` the editor already calls.

Two delta dialects in one repo would be a second contract to keep in step, and
the tabs and widgets a layout carries are the manifest detail-page shape
already, so the same keys work unchanged.

## D2. Drift, and why a fingerprint rather than a version number

A version number answers "is this the same row". A fingerprint answers "is this
the same content", which is the question a patch has. A base layout can be
edited without a version bump, and an app's bundled manifest moves on upgrade
with no row to bump at all.

So `baseFingerprint` is a hash of the resolved base at the moment the delta was
cut, and `baseCutAt` says when. Comparison is exact: any difference is drift.
That is deliberately stricter than the orphaned-key check in
`app-delta-override`, which only sees a patch whose key vanished. A tab that
kept its id and changed its kind orphans nothing and still makes the patch
wrong.

## D3. What loud means

Three things happen and one does not.

- The override moves to `needs-review`, declared in
  `x-openregister-lifecycle`.
- Its maintainer is notified, declared in `x-openregister-notifications`.
- The served answer carries the withheld override in `withheld[]`.
- The screen does not break. The base renders.

Withholding rather than best-effort merging is the choice worth naming. A
half-applied patch gives a customer a screen nobody designed, and it looks like
a bug in the product rather than a stale override. The base is a screen somebody
did design.

Re-cutting is one action: the maintainer opens the override against the new base
and saves, which recomputes the delta and the fingerprint.

## D4. The audience, and the one rule that is a security rule

`audience` is `{kind, ref}` with `kind` of `everyone`, `group`, `team`, `portal`
or `user`. Groups and teams are Nextcloud's, resolved from the caller's session.

The rule that matters: a caller with no account resolves `portal` and
`everyone`, never `group`, `team` or `user`. A public render asking for an
internal screen is how an internal tab reaches a portal page. It is enforced in
the provider, on the caller's own identity, not on what the consumer asks for.

A narrow screen is not a permission. Hiding a widget hides nothing on the
server, and OpenRegister RBAC stays the only thing that decides what a caller
may read.

## D5. The order, and ties

Base, schema-wide, type value, audience, user. Each patches the result of the
one before, so a team override written against the type layout keeps working
when the schema-wide layout gains a tab.

A caller can belong to two teams. Rather than ranking teams, which is an
organisation policy this app does not know, two published overrides for the same
schema, type value and audience kind are refused at save. That moves the
ambiguity to the admin who created it, at the moment they created it.

## D6. Provenance in the answer

`appliedLayers[]` and `withheld[]` ride beside the resolved layout on the
provider's answer. The resolved layout stays complete on its own, so a consumer
that ignores both renders correctly.

## Risks

- **A fingerprint that changes on formatting.** Hash the normalised layout, key
  order sorted, not the serialised string.
- **A notification per drifted override on a fleet upgrade.** The alarm is per
  override and per base change, not per resolution, so an upgrade sends one
  notification per stale override rather than one per page view.
- **Layer count.** Five layers compose on every read. The provider caches on the
  ids and fingerprints of the composing layers, which is the cache key
  `case-page-layout-per-case-type` already needs for its condition values.
