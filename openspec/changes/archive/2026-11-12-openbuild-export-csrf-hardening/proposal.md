---
kind: code
---

## Why

`ExportsController::submit` is a state-changing POST that carries `#[NoCSRFRequired]` (`lib/Controller/ExportsController.php:327-329`). The endpoint queues an export background job and — for `target: github` — accepts a **GitHub PAT in the request body** (`ExportsController.php:348-353`) and pushes generated code to an attacker-visible external repository via `GitHubPushService`. With the CSRF check disabled, any page the victim visits while logged in to Nextcloud can fire a cross-site `POST /apps/buildiq/api/applications/{slug}/exports` in the victim's session: it cannot steal the PAT (the attacker would have to supply their own), but it CAN queue arbitrary ZIP exports (server-side resource burn, scratch-dir writes under `sys_get_temp_dir()`) and re-trigger GitHub pushes for any application the victim is authorised on.

The exemption buys nothing: the only caller is `ExportDialog.vue:196`, which posts through `@nextcloud/axios` (`axios.post(url, payload)`) — that client always sends the `requesttoken` header, so the CSRF check would pass today unchanged. The app's own, newer write surface already codifies the correct posture: the `app-override-persistence` spec mandates writes keep CSRF ("`save`/`clear` MUST NOT carry `#[NoCSRFRequired]`"), and `AppOverrideController::save`/`clear` comply (`AppOverrideController.php:446-447`, `583-584`). The export pipeline predates that rule and was never re-aligned. The read-side `download` (GET, `ExportsController.php:389-391`) legitimately keeps `#[NoCSRFRequired]` — it must be reachable via plain `<a href>` browser navigation for the ZIP download, and it is idempotent and guarded by `isAuthorisedForJob` with 404-masking.

## What Changes

- **Remove `#[NoCSRFRequired]` from `ExportsController::submit`** (`lib/Controller/ExportsController.php:328`). The route, the `#[NoAdminRequired]` attribute, the `isAuthorisedForApplication` per-object guard and the body validation are all unchanged.
- **Keep `#[NoCSRFRequired]` on `ExportsController::download`** — required for direct-link navigation; document the asymmetry in the method docblock so a future sweep does not "fix" it.
- **Spec the posture** — add an explicit CSRF requirement to the `buildiq-exporter` capability so the rule is enforceable by gate review, mirroring the wording already used in `app-override-persistence`.
- **No BREAKING changes.** The SPA's `@nextcloud/axios` calls already send the `requesttoken`; no client change is needed. Only non-browser callers that were (incorrectly) POSTing without a token are affected — and no such caller is shipped.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `buildiq-exporter`: adds a requirement that export submission enforces Nextcloud CSRF protection; the download stream is explicitly exempted (navigation download).

## Impact

- `lib/Controller/ExportsController.php` — one attribute removed, one docblock note added.
- `openspec/specs/openbuild-exporter/spec.md` — one requirement added on archive.
- Tests: existing unit tests for `submit` are attribute-agnostic (attributes are middleware-enforced); add a reflection assertion that `submit` does NOT declare `NoCSRFRequired` and `download` does, so the posture is pinned.
