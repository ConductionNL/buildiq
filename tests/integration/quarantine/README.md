<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Quarantined Newman collections

CI runs `*.postman_collection.json` in `tests/integration/` **non-recursively**
(`for collection in *.postman_collection.json` in ConductionNL/.github's
`quality.yml`), so nothing in this directory is executed by the
`Integration Tests (Newman)` job.

A collection lives here for exactly one reason: **it asserts a contract the
product does not implement, and rewriting it to match today's behaviour would
convert a product gap into a fixed-looking test.** Quarantine is therefore a
DEFERRAL, not a fix. Each file below names the divergence, the issue tracking
it, and what has to be true before it moves back up a directory.

Run one by hand with:

```
npx newman run tests/integration/quarantine/<file> \
  --env-var "base_url=http://localhost:8080" \
  --env-var "admin_user=admin" --env-var "admin_password=admin"
```

## buildiq-version-diff.postman_collection.json

Extracted from `buildiq-versioning.postman_collection.json`, where it was the
`REQ-OBV-005 — diff endpoint returns both manifest blobs` folder and had been
carrying the comment "STILL RED, DELIBERATELY". It was three of the twelve
Newman assertion failures on `development`.

The divergence, unchanged by this move:

- **The spec** (`openspec/specs/openbuild-version-snapshots/spec.md`, REQ-OBV-005)
  defines `{fromRef}`/`{toRef}` as an ApplicationVersion **slug** (`staging`), or
  the literal `current:<versionSlug>`, or `history:<versionSlug>:<revisionId>` —
  the canonical case being two historical states of ONE ApplicationVersion. The
  documented response shape is `{ from: { manifest, semver, savedAt }, to: {…} }`.
- **The controller** (`ApplicationsController::resolveVersionBlob()`) implements
  none of that grammar. It accepts the literal `draft` or an ApplicationVersion
  **UUID**, and returns `{ manifest, version, publishedAt }` — different key names
  as well.
- **And the request cannot even be formed today.** The folder diffs two sibling
  snapshot rows, which is exactly what ADR-002 retired: publishing creates no
  sibling `ApplicationVersion`, so `v2_uuid` is never assigned and the request
  goes out with an empty `to`. `resolveVersionBlob()` treats an empty token as a
  miss ("AN EMPTY TOKEN IS A MISS, NOT A LOOKUP") and the endpoint answers 404 —
  correctly.

It moves back when the ref grammar exists. It is NOT unquarantined by pointing
the assertions at UUIDs: that would pass, and it would delete the only executable
record that the endpoint and its specification disagree.
