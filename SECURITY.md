# Security Policy

## Reporting a Vulnerability

If you discover a security vulnerability in any Conduction Nextcloud app, please report it responsibly.

**Do NOT open a public GitHub issue for security vulnerabilities.**

Instead, please email us at: **security@conduction.nl**

Include the following in your report:

- Description of the vulnerability
- Steps to reproduce the issue
- Potential impact
- Suggested fix (if any)

## Response Timeline

- **Acknowledgement:** Within 48 hours of receiving your report
- **Initial assessment:** Within 1 week
- **Fix and disclosure:** We aim to resolve critical vulnerabilities within 30 days

## Supported Versions

We provide security updates for the latest stable release of each app. Older versions may not receive security patches.

## Scope

This security policy applies to all repositories under the [ConductionNL](https://github.com/ConductionNL) organization.

## Recognition

We appreciate responsible disclosure and will credit reporters (with permission) in our release notes.

## Software Bill of Materials (SBOM)

We publish a [CycloneDX](https://cyclonedx.org/) 1.5 JSON SBOM for every release of every Conduction Nextcloud app. The SBOM lists every production dependency (Composer + npm, merged, dev-dependencies excluded) with name, version, license, and PURL. Each SBOM is CVE-scanned with [Grype](https://github.com/anchore/grype) at build time and the release fails if any **critical** vulnerability is detected.

### Stable URLs

For every app `<app>` under [ConductionNL](https://github.com/ConductionNL), two URLs always work:

| Use case                                                           | URL pattern                                                                    |
| ------------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| **Always-latest released SBOM** (auto-redirects to newest release) | `https://github.com/ConductionNL/<app>/releases/latest/download/sbom.cdx.json` |
| **Specific release SBOM** (pinned, for compliance archives)        | `https://github.com/ConductionNL/<app>/releases/download/<tag>/sbom.cdx.json`  |

Example — fetch the latest buildiq SBOM:

```bash
curl -sL https://github.com/ConductionNL/buildiq/releases/latest/download/sbom.cdx.json | jq .
```

Example — fetch the SBOM for a specific historical release:

```bash
curl -sL https://github.com/ConductionNL/buildiq/releases/download/v1.0.0/sbom.cdx.json | jq .
```

### Update cadence

A new SBOM is generated and attached on every release tag. We do not commit SBOMs into the repository tree — they are published exclusively as release assets to keep main-branch history clean and to guarantee every SBOM corresponds to an immutable release artifact.

### Format

- **Specification:** CycloneDX 1.5
- **Encoding:** JSON
- **Filename:** `sbom.cdx.json` (consistent across all apps)
- **Scope:** Production dependencies only — `--omit=dev` for both Composer (`composer CycloneDX:make-sbom`) and npm (`@cyclonedx/cyclonedx-npm`). Composer plugins are also omitted.

### Verification before publication

Each release SBOM passes through these gates before it ships:

1. **Grype CVE scan** — `--fail-on critical` against the SBOM itself.
2. **`composer audit`** — informational, captured in CI logs.
3. **`npm audit --audit-level=critical`** — informational, captured in CI logs.

If any of these block, the release is held until the underlying issue is patched.

### Workflow artifact (CI-only)

A 90-day workflow artifact named `sbom-<app>` is also produced on every successful CI run on `main` / `beta` / `development`. This is for internal audit / replay only — external consumers should always use the release-asset URLs above for stable, version-pinned access.

### Reporting SBOM-related issues

If you spot a missing dependency, an incorrect version, or a CVE we should be alerted to, email `security@conduction.nl` per the disclosure process at the top of this document.

## Accepted npm advisories

`npm audit` on this repository is not expected to come back clean. The entries below are the ones we have looked at and deliberately left open, with the reason. Anything **not** on this list is unreviewed and should be treated as a real finding — fix it, then either close it here or add it with a reason.

Two rules for this list. Every entry states what makes the vulnerable code unreachable **in this app**, not merely that it is "only a devDependency" — dev tooling runs against real credentials in CI and is worth defending. And every entry states the condition that ends the exemption, so the list expires rather than accumulates.

Last reviewed **2026-09-10**, against the `overrides` block in `package.json` as it stands today. Nineteen of the thirty-one advisories `npm ci` reported at that time were closed outright by those overrides — including the one critical, a handlebars JS injection — and the first three entries below are what remains. The fourth entry is not an `npm audit` finding at all — it is a vulnerable copy the audit cannot see, recorded here because that is exactly the kind of thing a list like this exists to catch.

### @faker-js/faker 5.5.3 — high, [GHSA-qxc2-j82w-r537](https://github.com/advisories/GHSA-qxc2-j82w-r537)

Reached through `newman` → `postman-collection`, our Postman integration-test runner.

**Why it is not fixed.** postman-collection pins faker to the exact version `5.5.3`, and postman-collection 5.3.1 — its own latest release — still pins the same exact version, so there is no upgrade path through the parent either. Forcing faker forward does not degrade newman, it stops it loading: with `"overrides": {"@faker-js/faker": "10.6.0"}`, a bare `require('postman-collection')` throws `TypeError: Cannot read properties of undefined (reading 'city')`. Two changes stack up. The subpath export moved — in v10 `@faker-js/faker/locale/en` resolves to `{ faker }` rather than the instance, so postman-collection's `var faker = require('@faker-js/faker/locale/en')` binds an object with no generators on it — and the v5 API is gone anyway (`address` became `location`; `random.*`, `datatype.number` and `phone.phoneNumberFormat` were removed). `lib/superstring/dynamic-variables.js` has 117 faker call sites written against that surface, some at module scope, which is why the failure lands on require. newman and postman-runtime both require postman-collection at module scope, so the whole runner would fail to start.

**Why it is unreachable.** The advisory needs `faker.helpers.fake()` reached with an attacker-controlled template string. postman-collection never calls `.fake()` anywhere in `lib/`; it calls individual generators. Nothing in this app calls faker directly, and faker is never bundled — the SBOM is generated with `--omit=dev`.

**Revisit when** postman-collection unpins faker, or when we replace the Postman-based integration suite.

### csv-parse 4.16.3 — moderate, [GHSA-8cw4-87c7-c6xx](https://github.com/advisories/GHSA-8cw4-87c7-c6xx)

Reached through `newman`.

**Why it is not fixed.** Only 7.0.2 carries the fix, and csv-parse v5 changed the CommonJS export from a callable default to `{ parse }` and renamed the `relax` option. newman's `lib/run/options.js` does `parseCsv = require('csv-parse')` and calls it directly with `relax: true`, so an override breaks `newman run -d <file>.csv` at require time.

**Why it is unreachable.** The prototype-replacement path is in csv-parse's `columns` handling, which only runs when newman is given a CSV iteration-data file. Neither `test:newman` nor `test:newman:ci` passes `-d`, and no CI job does.

**Revisit when** newman updates csv-parse, or if anyone adds a `-d` flag to an integration-test invocation — at that point this exemption is void and the override plus a `patch-package` patch of that one require becomes the fix.

### elliptic (all versions) — low, [GHSA-848j-6mx2-7j84](https://github.com/advisories/GHSA-848j-6mx2-7j84)

Reached through `node-polyfill-webpack-plugin` → `crypto-browserify` → `browserify-sign` / `create-ecdh`.

**Why it is not fixed.** No patched version exists upstream in any release line, so there is nothing to override to. `npm audit fix --force` "resolves" it by downgrading `@nextcloud/webpack-vue-config` to 4.0.0.

**Why it is unreachable.** Build-time only, and it does not even run at build time: `webpack.config.js` replaces `webpackConfig.plugins` wholesale, so `NodePolyfillPlugin` is never registered and no browserified crypto reaches a bundle. Node builtins that the app genuinely needs are handled by the explicit `resolve.fallback.path` → `path-browserify` entry instead.

**Revisit when** elliptic ships a fix. Note that removing the direct `node-polyfill-webpack-plugin` entry from `package.json` — which our config does not use, though ADR-004 describes it as the usual pattern across apps — would tidy the dependency list but would *not* clear the advisory: `@nextcloud/webpack-vue-config` depends on it too, so the chain survives either way.

### DOMPurify 2.3.3, inlined into @toast-ui/editor — not visible to `npm audit`

This one is the opposite case: it **is** shipped, and the audit does not report it.

`@toast-ui/editor` (pulled in by `@conduction/nextcloud-vue`) inlines a copy of DOMPurify 2.3.3 into `dist/esm/index.js` rather than importing the package. That ESM entry is the one webpack bundles, so the string `DOMPurify.version = '2.3.3'` is verifiable in `js/buildiq-vendors-node_modules_toast-ui_editor_dist_esm_index_js.js` after `npm run build`. The `dompurify` override in `package.json` lifts the *resolved* dependency (toast-ui's nested 2.5.9 collapses onto our own 3.4.15, which is what `src/` uses) but cannot touch the inlined copy, and lifting it removed the audit entry that was the only hint this situation exists — hence this note.

No dependency pin in this repository can fix it. It needs a change in `@conduction/nextcloud-vue`: drop `@toast-ui/editor`, upgrade to a release that stops vendoring DOMPurify, or sanitize with the app's own DOMPurify before content reaches the editor.

**Revisit when** `@conduction/nextcloud-vue` changes its editor dependency.
