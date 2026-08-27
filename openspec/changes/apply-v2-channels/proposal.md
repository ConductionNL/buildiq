---
kind: code
---

## Why

App-repo **format v2** added four channels to a published Buildiq app —
`data-registers/`, `connectors/`, `automations/` and `skills/` — so that installing an
app yields something that actually runs, not just a manifest.

The serializer emits all four. The parser reads all four. As of buildiq#80 the fetcher
fetches all four. **No install path applies any of them.** They are parsed into the
template array and dropped on the floor.

Verified against the code, with a positive control:

| lookup | hits outside parser / serializer / fetcher |
|---|---|
| `$template['manifest']`, `$template['version']` *(control)* | 7 |
| `$template['connectors']` | **0** |
| `$template['automations']` | **0** |
| `$template['skills']` | **0** |
| `$template['dataRegisters']` | 3 — all in the **export/zip** path, reading `Application.dataRegisters` bindings, never a parsed template |

Both entry points confirmed by reading their bodies, not by grep alone:

- `GitHubAppSyncService::pull()` persists a draft Version holding `manifest` + companion
  schemas only.
- `ApplicationsController::installFromTemplateArray()` — the target of
  `ShopController::githubInstall()` — reads exactly `$template['slug']` and
  `$template['manifest']`.

The user-visible consequence: installing `buildiq-spectr` or `buildiq-hydra` today
produces an app with its manifest and **nothing that makes it run** — no registers, no
connectors, none of the 94 skills — and **reports success**. A silent, complete failure
of the feature the format exists to deliver.

This is the fourth time in this workstream that one half of a round trip was extended
and the other left behind (serializer without binding; publish without install-side aux
files; parser without fetcher; now fetch+parse without apply). Publish looked correct
every time, because publish is the half that kept getting extended. This change closes
the last one.

## What Changes

A new `AppChannelApplier` service applies the four channels, called from the **single
seam** that both install paths already funnel through — so `pull()` and `githubInstall()`
cannot drift apart again.

- **Data registers** — create registers and schemas that do not exist; **never mutate**
  ones that do.
- **Connectors** — upsert by the **published UUID** (`saveObject(uuid:)`) so that the
  `Application.connectors[]` bindings still resolve after install. A UUID that already
  exists locally is **skipped and reported, never overwritten**: connectors are shared
  infrastructure, and installing an app must not silently rewrite a source another app
  depends on.
- **Skills** — delegated to hermiq's existing `POST /api/skills/bundle/install` by repo
  **coordinates** (owner/repo/ref), which hermiq fetches itself. Buildiq does not
  reimplement skill installation.
- **Automations** — same shape as connectors.
- **Credential reporting** — `stripSecrets()` blanks secrets at publish time while keeping
  `credentialRef`. Every `credentialRef` that does not resolve on the target instance is
  collected into `needsCredentials[]` and surfaced in the install response. This is the
  difference between *installed* and *installed and runnable*, and it must be visible.

Applying is **best-effort with a complete per-item outcome report**. OpenRegister offers
no cross-object transaction, so atomicity cannot be delivered and will not be faked; a
partial apply reports exactly which items landed, which were skipped, and why.

Every channel is **bounded**, and truncation is **logged and reported** — never silent.
An install that quietly drops half an app is precisely the failure this change exists to
prevent.

Buildiq declares only `openregister` as a dependency. `openconnector` and `hermiq`
are therefore optional: when absent, the dependent channel is skipped with a machine-
readable reason, and the install still succeeds for the channels that can be applied.

## Capabilities

### New Capabilities
- `app-channel-application`: applying a parsed v2 app repo's data-register, connector,
  automation and skill channels onto the target instance, with per-item outcomes,
  skip-never-overwrite collision handling, optional-dependency degradation and explicit
  bounds.

### Modified Capabilities
- `buildiq-application-register`: installing an application from a linked GitHub repo
  now provisions its bound registers and connectors, rather than only its manifest.

## Impact

- **New**: `lib/Service/AppChannelApplier.php`, `lib/Service/ChannelApplyReport.php`
- **Modified**: `lib/Service/GitHubAppSyncService.php` (call the applier in `pull()`),
  `lib/Controller/ApplicationsController.php` (call it in `installFromTemplateArray()`),
  `lib/Controller/ShopController.php` (surface the report)
- **Optional runtime dependencies**: `openconnector` (connectors, automations), `hermiq`
  (skills) — both degrade with a reason, neither becomes a hard dependency
- **API**: install/pull responses gain a `channels` report object (additive)
- **Data**: creates OpenRegister registers, schemas and `openconnector` objects on the
  target instance; never updates or deletes an existing one
