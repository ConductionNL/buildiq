---
kind: code
---

# Proposal: adopt-connection-registry

## Why

Buildiq talks to four things outside its own code, and no screen says whether any of them works.

- The template store. An admin saves a registry URL and a token. A search that cannot reach the store shows an empty gallery and logs the reason.
- GitHub. The shop searches it without a token. Push and pull go through the OpenRegister credential broker. Without the broker, publish is off and nothing on an admin screen says so.
- Document generation. An automation calls Filinq over HTTP on the same instance. A failed call is a log line.
- Rule webhooks. A business rule posts to the address it names. A failed post is a log line.

Hydra change `connection-registry` (hydra#667, amended in hydra#673 and hydra#674) gives every app one page of its connections, backed by integriq.

## What changes

- New `lib/Settings/connections.json` with four connections: `store`, `github`, `documents` and `rule-webhooks`. The store requires `registry_url` and links to a new `#section-store` anchor. The other three are reported only.
- An Integrations page under the settings gear, over integriq's `app_connection` schema, preset to `app=buildiq`, admin only, and only shown when integriq is installed.
- Add integration opens `/apps/integriq/connections?app=buildiq&link=1`.
- A settings save that writes a store key asks integriq to resolve the store again. The admin form and the setup wizard both save through `SettingsService::updateSettings()`.
- Buildiq reports what a store search, a GitHub search, push or pull, a document generation call and a rule webhook met. It reports on a change after five minutes, and at most once an hour while nothing changes.
- Local `connectionStatus` and `connectionSettingsLabel` formatters with all six statuses, and the strings in English and Dutch.
- CI installs integriq, so the e2e spec can reach the page.

## Not declared

- **LLM.** The copilot uses Nextcloud's Task Processing API. Buildiq picks no provider and holds no key. Whether the provider runs outside Nextcloud is decided in Nextcloud's own AI settings, so it is not a Buildiq connection.

## Depends on

- hydra `openspec/changes/connection-registry`, design D2, D4, D6, D8, D9 and D12.
- integriq on `development`: the `app_connection` schema, the declaration sync, both events and the Connections overview.
- hydra#674, still open: a refresh retires older observations. Without it a store report outranks a later save until the next search.

Without integriq the menu entry is hidden, a deep link shows the missing-dependency screen, and nothing is sent.

## Out of scope

- The Filinq route name. `DocumentGenerationService` links to `docudesk.correspondence.generate`, and Filinq's id is `filinq`. The report makes that failure visible. Renaming the route is the coordinated rename pass, not this change.
- Per-rule webhook rows. A static file cannot list them (design D12).

## Rollback

Revert the change. Buildiq writes no rows of its own. Integriq removes the rows without a linked source on its next sync, and the report memory keys in app config stop being read.
