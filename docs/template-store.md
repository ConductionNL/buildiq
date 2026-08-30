# Remote template store

The **template store** lets you discover and install apps from a *remote*
OpenRegister-backed template catalogue, on top of the built-in templates that
ship with Buildiq.

It is **consume-only**: you can search a remote OpenRegister catalogue and
install a template into your instance. To *publish* your own apps and install
them again anywhere — a full round-trip backed by GitHub — see the
[GitHub store](./github-store.md).

## How it works

- The remote catalogue is an **OpenRegister instance** that exposes
  `application-template` objects through its public objects API.
- Buildiq reads that catalogue **server-side** through a proxy
  (`RemoteTemplateStoreService`) — the browser never sees the catalogue URL or
  token, and there is no cross-origin (CORS) problem.
- Installing a store template **clones it locally** through the exact same path
  as a built-in template (`ApplicationsController::installFromTemplateArray`), so
  an installed app is an ordinary local `Application` + per-app register — a
  normal virtual app you can edit, version, and publish.

## Configuring a registry (admin)

Open **Settings → Administration → Buildiq** and set:

| Field | Meaning |
|-------|---------|
| **Registry URL** | Base URL of the remote OpenRegister catalogue (e.g. `https://store.example.com`). Leave empty to disable the store. |
| **Registry register** | The register segment that holds the templates on the catalogue. Defaults to `buildiq`. |
| **Registry token** | Optional bearer token for a private catalogue. Write-only — re-saving with the field blank keeps the stored token. |

When no registry URL is configured, the Templates page shows the **built-in
templates** exactly as before; once a registry is set, the **store search**
becomes the primary surface and the built-in templates move to a secondary
section.

## Endpoints

| Method + path | Purpose |
|---|---|
| `GET /index.php/apps/buildiq/api/store/templates?q=<term>` | Search the remote catalogue. Returns `{ outcome, cards }` (login required). |
| `POST /index.php/apps/buildiq/api/store/templates/{slug}/install` | Resolve the remote template `{slug}` and install it locally (clone). Body: `{ name, slug }`. The caller becomes the new app's owner. |

`outcome` is one of `ok`, `not_configured`, `store_unreachable`,
`store_invalid_response`.

## Security

- Every outbound fetch is **SSRF-guarded** (OpenRegister's
  `SecurityService::assertSafeFetchUrl`) — private, reserved, and loopback hosts
  and non-`http(s)` schemes are rejected before any request is made.
- Both endpoints require an authenticated session; the registry token is never
  returned to the browser.

See the OpenSpec change `buildiq-remote-template-store` for the full
specification.
