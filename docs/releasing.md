# Releasing OpenBuild

## Version + changelog

1. Bump `<version>` in `appinfo/info.xml` (immutable-cache-bust: any bundle-affecting
   change must bump the version, else clients keep the old bytes).
2. Add a dated section to `CHANGELOG.md` following Keep a Changelog + SemVer.
3. Tag and let the standard release workflow build the appstore artifact.

## Refreshing the embedded template snapshot

The exporter ships a checked-in copy of `nextcloud-app-template/` under
`lib/Resources/template/`, snapshotted at OpenBuild's build time (Decision 1). The
exporter never clones or fetches the template at export time — this guarantees
reproducibility and removes any network dependency from the ZIP path.

### STOP — the rsync below is unsafe as written (measured 2026-08-05)

This tree is **no longer a snapshot of upstream. It is a fork.** Read this before
running step 1; the command in it will silently revert shipped fixes.

Three measurements, any one of which is sufficient:

1. **There is no upstream ref this tree fast-forwards from.** The declared
   `sourceCommit` `7ee06aae` is not an ancestor of upstream `main` **or**
   `development` — `git branch -r --contains 7ee06aae` places it only on two
   abandoned `wsl-rescue/*` branches.

2. **The snapshot carries OpenBuild-only fixes that upstream never received.** At
   least 10 commits have edited individual files inside `lib/Resources/template/`
   since it was taken — among them `c87d8c4e7 fix(template): the generated app
   could not be built at all (#39)` and `12da26f01 fix(export): make exported app
   a Tier-4 manifest consumer (ADR-024)`. `rsync --delete` reverts both. The
   "do not scripted-edit individual files" rule below has, in practice, not been
   followed; the tree it protects no longer exists.

3. **The two trees use different placeholder dialects.** OpenBuild resolves
   `{{token}}`. Upstream's `appinfo/info.xml` uses `{APP_NAME}`, `{APP_SUMMARY}`,
   `{APP_DESCRIPTION}`. `PlaceholderResolver` does not know that dialect, and the
   exporter's unresolved-placeholder assertion matches `/\{\{[a-zA-Z]+\}\}/`
   only — so a refreshed tree would ship literal `{APP_NAME}` into every
   generated app's `info.xml` and **no test would fail**.

Scale of the divergence, snapshot vs `origin/development` (2026-08-05): 47 files
differ, 5 paths exist only in the snapshot (`src/router`, `src/navigation`,
`src/views/Dashboard.vue`, `src/views/settings`, `tests/Unit`), and 68 exist only
upstream — including three new controllers, `lib/Mcp`, `lib/Dashboard`,
`playwright.config.ts`, `psalm-baseline.xml`, `phpstan-baseline.neon`, 37 new
`l10n/` files, and a `.forgejo/` tree for the **retired** Codeberg forge.
Upstream has also moved `src/manifest.json` from the OpenBuild-authored v1 shape
to manifest **v2** (`$schema`/`menu`/`pages`) with no placeholder tokens at all.

Reconciling this is a **migration with product decisions in it** (what should a
generated app contain?), not a copy. Until that migration is agreed, treat
`lib/Resources/template/` as OpenBuild-owned source: change it with the Edit
tool, one file at a time, with a test that fails first.

### The original procedure (do not run step 1 until the fork is reconciled)

When `nextcloud-app-template` ships a **meaningful** update (new CI workflow, toolchain
bump, Tier-4 consumer-pattern change), refresh the snapshot:

1. Copy the upstream tree into the snapshot, excluding vendored / generated dirs:

   ```bash
   rsync -a --delete \
     --exclude node_modules --exclude vendor --exclude .git \
     ../nextcloud-app-template/ lib/Resources/template/
   ```

   Do **not** scripted-edit individual files inside the snapshot — copy the whole tree.

   Note the exclude list here is shorter than the authoritative one in
   `.snapshot-meta.json` (`excludes`), and it does not protect
   `.snapshot-meta.json` / `.path-manifest.txt` themselves, which `--delete`
   would remove.

2. Confirm every placeholder token is still present in the files the exporter populates:
   `{{appId}}`, `{{appNamespace}}`, `{{appName}}`, `{{appDescription}}`,
   `{{appVersion}}`, `{{authorName}}`, `{{authorEmail}}`, `{{license}}`.

3. Regenerate the path manifest and snapshot metadata:

   ```bash
   ( cd lib/Resources/template && \
     find . -type f ! -name .path-manifest.txt ! -name .snapshot-meta.json \
       | sed 's|^\./||' | LC_ALL=C sort > .path-manifest.txt )
   ```

   Update `lib/Resources/template/.snapshot-meta.json` with the upstream source commit
   SHA and an ISO timestamp.

4. Bump the OpenBuild **minor** version and add a `CHANGELOG.md` entry noting the
   template refresh.

5. Run the exporter unit + integration tests and confirm a freshly exported
   `hello-world` app still passes `composer check:strict`.

## CI drift check

> **This job does not exist.** Verified 2026-08-05: no workflow under
> `.github/workflows/` mentions drift or `nextcloud-app-template`. The paragraph
> below describes a control that was never built, and its absence is exactly why
> the snapshot sat 86 days stale while shipping `<licence>agpl</licence>` into
> every generated app. Building it is blocked on the fork decision above — a
> drift check has no correct comparison target until it is settled whether this
> tree tracks upstream or owns itself.

A CI job diffs `lib/Resources/template/` against `apps-extra/nextcloud-app-template/`
and **warns** (does not fail) when the snapshot is more than 90 days behind upstream.
