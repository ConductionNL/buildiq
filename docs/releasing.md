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

When `nextcloud-app-template` ships a **meaningful** update (new CI workflow, toolchain
bump, Tier-4 consumer-pattern change), refresh the snapshot:

1. Copy the upstream tree into the snapshot, excluding vendored / generated dirs:

   ```bash
   rsync -a --delete \
     --exclude node_modules --exclude vendor --exclude .git \
     ../nextcloud-app-template/ lib/Resources/template/
   ```

   Do **not** scripted-edit individual files inside the snapshot — copy the whole tree.

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

A CI job diffs `lib/Resources/template/` against `apps-extra/nextcloud-app-template/`
and **warns** (does not fail) when the snapshot is more than 90 days behind upstream.
