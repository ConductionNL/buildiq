# Buildiq API-contract tests (Newman)

Newman/Postman contract tests that exercise buildiq's HTTP controllers
directly, locking the API contract. Per the gate-19 split, **API/contract
correctness lives in Newman**; Playwright drives the UI only.

This is the **Phase-3** suite (`buildiq-api-contract.postman_collection.json`).
It follows the fleet procest Newman pattern (collection-var `baseUrl` + explicit
admin basic-auth, host-split authz, `--ignore-redirects`, `OCS-APIRequest: true`,
self-contained setup→assert→teardown, `flock`). It is **distinct** from the
legacy `buildiq.postman_collection.json` in this directory, which uses
`base_url`/`admin_user`/`admin_password` and depends on the now-removed
`hello-world` seed app.

## What is covered

| Folder | Endpoint(s) | Happy | Error (4xx not 500) | Authz |
| --- | --- | --- | --- | --- |
| 0. Setup | `GET /status.php` | generates a unique app slug + checks NC is up | — | — |
| 1. App Creation Wizard | `POST /api/applications/wizard` | **201** + `applicationUuid` (PHASE-0) | 422 missing name, 422 bad slug | 401 no-auth |
| 2. Manifest | `GET /api/applications/{slug}/manifest` | **200** for a wizard-built app + no `permissions` leak (PHASE-0) | 404 unknown slug | 401 no-auth |
| 3. Application List | `GET /api/applications` (listMine, RBAC) | 200 flat array containing the wizard app | — | 401 no-auth |
| 4. Virtual App CRUD | OR `/api/objects/buildiq/application` (ADR-022) | create → read → update → delete with id capture | 404 unknown object | (OR object reads are not buildiq-gated) |
| 5. Settings | `GET /api/settings` | 200 + contract shape (`register`, `openregisters`) | — | 401 no-auth |
| 6. Exports | `POST /api/applications/{slug}/exports`, `GET /api/exports/{uuid}/download` | — (see KNOWN BUG) | 403/422 bad-target (4xx not 500), 404 unknown job | 401 no-auth on download |
| 9. Teardown | OR delete | idempotent cleanup of the wizard app | — | — |

Totals: **22 requests / 36 assertions**, all green against the dev container
(localhost:8080, admin:admin).

The collection is **self-contained and idempotent**: setup creates a fresh
virtual app through the wizard (random `nm-…` slug, `single` preset), the body of
the suite reuses that slug/uuid, and teardown deletes the Application object.

## Phase-0 fixes locked

- **Wizard returns 201, not 422** — the OpenRegister `lockObject` advisory-lock
  fix. `POST /api/applications/wizard` with `{name, slug, preset:"single"}`
  returns `201 { applicationUuid }`. Asserted in
  *1. App Creation Wizard → wizard happy path (201)*.
- **Wizard-built app manifest returns 200, not 404** — the `BuiltAppRoute`
  publish fix. The wizard now publishes a `built-app-route` index object, so the
  slug → BuiltAppRoute → Application → manifest lookup resolves. Asserted in
  *2. Manifest → wizard-built app manifest (200)*.

## Known bug (quarantined, NOT a fake pass)

`POST /api/applications/{slug}/exports` returns **HTTP 403 even for an NC admin
who owns the freshly wizard-built app**. `ExportsController::submit()` gates on
`isAuthorisedForApplication()`, which calls
`ObjectService::searchObjectsBySlug('buildiq','application',['slug'=>$slug])`;
that returns an empty array for the wizard app, so the method returns `false`
**before** reaching its NC-admin bypass (the bypass at the end of the method is
only evaluated when the slug lookup found the app). Result: no caller — not even
admin — can queue an export of a wizard-built app.

The collection documents this honestly: *export submit QUARANTINED (current 403)*
asserts the **current** 403 (and `!== 500`) so the suite stays green **without
faking a 202**. When the auth ordering is fixed (move the admin bypass before the
slug lookup, or make `searchObjectsBySlug` resolve the wizard app), that test
goes RED — flip it to a happy-path `202 { uuid }` assertion at that point.

`#41`-quarantined builder / page-designer nested SPA routes are **not** covered —
they are frontend-only and expose no JSON API surface.

## Running

```bash
# defaults: BASE_URL=http://localhost:8080, ADMIN_USER=admin, ADMIN_PASS=admin
./run-newman.sh

# or directly:
npx newman run buildiq-api-contract.postman_collection.json \
  --env-var baseUrl=http://localhost:8080 \
  --env-var adminUser=admin \
  --env-var adminPass=admin \
  --ignore-redirects
```

`run-newman.sh` prefers a globally-installed `newman`, falls back to
`npx newman`, and serialises runs under `flock /tmp/uiaudit-buildiq.lock`
(shared with the Playwright UI audit) to avoid tripping the Nextcloud
brute-force protection when multiple agents run in parallel.

## Auth-isolation detail (important for reuse)

Newman keeps a per-run cookie jar. Authenticated requests against `baseUrl`
(`localhost`) establish a Nextcloud session cookie; because the jar is shared,
that cookie would silently authenticate the no-auth requests too (they then
return 200 instead of 401). Two measures keep the authorization tests honest:

1. **Host split** — authenticated requests use `{{baseUrl}}`
   (`http://localhost:8080`); the no-auth requests use `{{noAuthBase}}`
   (`http://127.0.0.1:8080`). NC session cookies are host-scoped, so the
   `localhost` session is never sent to `127.0.0.1`, making those requests
   genuinely unauthenticated. `run-newman.sh` derives `noAuthBase` from
   `BASE_URL` automatically (override with `NO_AUTH_BASE`).
2. **`--ignore-redirects` + `Accept: application/json`** — unauthenticated
   requests get NC's JSON `401`, not the `303`→login-page `200` HTML that a
   browser `Accept` would follow.

This is the reusable Newman authz pattern for the fleet.

### OpenRegister object reads are not buildiq-gated

Application object CRUD is delegated to OpenRegister (ADR-022). OR's object API
enforces its own multitenancy/RBAC, not buildiq's, so folder 4 asserts the
CRUD round-trip and the 404-on-unknown shape rather than an buildiq 401/403 the
OR API never returns. The buildiq controllers themselves (folders 1, 2, 3, 5,
6) **are** auth-gated and return `401`/`403`.

## Collection variables

`baseUrl`, `noAuthBase`, `adminUser`, `adminPass`, plus the runtime-captured
`appSlug` (random per run), `appUuid` (the wizard app), and `crudAppUuid` (the
OR-CRUD probe object). No deployed register/schema IDs are pinned — the suite
addresses OR by the `buildiq` register slug + `application` schema slug, so it
is not coupled to numeric IDs.
