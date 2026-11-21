# Design: adopt-connection-registry

The contract is hydra `openspec/changes/connection-registry/design.md` (hydra#667, amended in hydra#673, and hydra#674 still open). This file records how Buildiq meets it and where it fits loosely.

## D1. Which connections are declared

Each candidate was checked against the code on `development`, not against its name.

| Key | Declared as | Why |
|---|---|---|
| `store` | `requiredConfig: ["registry_url"]`, `settingsUrl` to `#section-store` | OpenRegister's `GenericStoreService` reads `registry_url` and `registry_token` from Buildiq's app config. `StoreController::search()` is the one caller. The token is optional, so only the URL is required. |
| `github` | `reportedOnly: true` | `GitHubCatalogService` searches `api.github.com` without a token. `GitHubAppSyncService` and `GitHubPushService` push and pull through OpenRegister's `CredentialBrokerService`, with a credential the user picks per call. No app-config key says whether GitHub works, so integriq has nothing to read. |
| `documents` | `reportedOnly: true` | `DocumentGenerationService` posts to Filinq's generate route over HTTP, as the owner of the app. No setting selects it. |
| `rule-webhooks` | `reportedOnly: true` | `RuleActionDispatcher::dispatchWebhook()` posts to the URL a rule names. That is one address per rule, so it is one family row (contract D12). |

**Why `llm` is not declared.** `CopilotService::health()` asks `OCP\TaskProcessing\IManager` for the `core:text2text` task type. Buildiq stores no provider name, no key and no URL. The provider is whatever Nextcloud's AI admin settings route that task type to: a local model through an ExApp, or an outside API through an integration app. Buildiq cannot tell which, and declaring it would put a Nextcloud-wide setting on Buildiq's page.

**Why `documents` is declared although Filinq runs on the same instance.** Dossiq's `templates` row is the precedent. The call is a real HTTP request back into the instance, with a minted login token. It fails on a loopback that does not resolve, on a refused login, and on a route that is not registered. Today every one of those is a log line an admin never reads. Measured on 2026-09-14: the route is named `docudesk.correspondence.generate`, and Filinq's `<id>` on `main` and `development` is `filinq`. The URL generator then answers the bare instance URL, so no document is generated. The row now says so. Renaming the route is left to the coordinated rename pass (CLAUDE.md).

**Why only the store links to settings.** The admin page has one section, Configuration. Its Template registry block writes the store keys, and now carries `id="section-store"`. Nothing on the admin page configures GitHub, Filinq or a webhook.

## D2. What the reports say

`ConnectionReporter` sends the events. `ConnectionObservations` turns an outcome into a status and a message, and holds no state. Each caller hands over the outcome it already had; no call is added.

| Caller | Outcome | Status | Message |
|---|---|---|---|
| Store search | `ok` | `configured` | "The template store at {host} answered the last search." |
| | `not_configured` | `unconfigured` | "No store address is set. Set the registry URL under Template registry." |
| | `store_unreachable` | `error` | "The last search could not reach the template store at {host}." |
| | `store_invalid_response` | `error` | "The template store at {host} answered, but not with a list of templates." |
| | `rate_limited` | `limited` | "The template store at {host} limited the last search." |
| GitHub search | `ok`, broker present | `configured` | "The last catalogue search reached GitHub." |
| | `ok`, no broker | `limited` | "The last catalogue search reached GitHub. Push and pull need the OpenRegister credential broker, which is not installed." |
| | `github_rate_limited` | `limited` | "GitHub limited the last catalogue search. Without a credential GitHub allows 60 requests an hour." |
| | `github_unreachable` | `error` | "The last catalogue search could not reach GitHub." |
| Push or pull | `ok` | `configured` | "The last push or pull reached GitHub through the credential broker." |
| | `broker_unavailable` | `limited` | "Push and pull need the OpenRegister credential broker, which is not installed. Catalogue search still works." |
| | `github_unreachable` | `error` | "The last push or pull could not reach GitHub." |
| Document generation | no route | `error` | "No route answers to {route}, so no document was generated. Is Filinq installed and enabled?" |
| | no answer | `error` | "The last call to Filinq got no answer." |
| | HTTP 401 or 403 | `error` | "Filinq refused the login (HTTP 401)." |
| | HTTP 502, 503 or 504 | `error` | "Filinq answered HTTP 503 on the last call." |
| | HTTP 2xx or 3xx | `configured` | "Filinq answered the last call." |
| Rule webhook | same HTTP mapping | | "The rule webhook at {host} answered HTTP 503 on the last call.", with the host only. |

Anything else sends nothing. `broker_denied`, `github_forbidden`, `push_conflict`, `not_linked` and `version_not_found` are about one credential or one app. A 404, a 400 or a 500 from Filinq or a webhook receiver is about one request. Those would make a working connection read Error.

A message names a host from the admin's setting or the rule's URL, and nothing else from it. No path, query, user info or payload reaches the row.

**When it reports.** The report memory is one app-config value per connection, `connection_report_{key}`, holding the last status and its time.

- A different status reports once five minutes have passed since the last report, so two webhook receivers that disagree cannot write on every call.
- The same status reports again after an hour, so `lastReport` stays newer than a stale probe.
- A save that writes a store key clears the store's memory, so the next search reports at once.

Without integriq the class check fails first: nothing is read, stored, sent or logged. Every report method catches everything, because it runs beside a request whose own answer is what the caller returns.

**Why this is cheap enough (ADR-076).** A report costs a `class_exists` and one app-config read, which Nextcloud has already loaded for the request. A write and an event happen at most once an hour per connection while nothing changes. No report is sent on a page load.

## D3. The refresh

`SettingsService::updateSettings()` is the one writer of the store keys. The admin form reaches it through `SettingsController`, and the setup wizard through `SetupController`. After the write it hands the keys it wrote to `ConnectionReporter::refreshFromSave()`. When `registry_url`, `registry_register` or `registry_token` is among them, the reporter clears the store's report memory and sends `ConnectionRefreshRequestedEvent('buildiq', 'store')`. A save that writes only `register` sends nothing.

An empty token is not written (it means "keep the current token"), so it asks for no refresh either.

Under hydra#674 the refresh retires older observations, so a save brings back rule 5 until the next search reports.

## D4. The page

- `src/manifest.d/80-connection-registry.json`: an `index` page `Integrations` at `/settings/integrations`, `requiresApp` integriq, `permission: admin`, `showAdd: false`, and the contract columns: connection, status, status message, last checked, settings.
- Its menu entry `IntegrationsMenu` sits in the settings gear with `query: {app: buildiq}`, `permission: admin` and `visibleIf.appInstalled: integriq`.
- `src/services/connectionRegistry.js` holds `connectionStatus`, `connectionSettingsLabel` and `openIntegriqConnections`.
- `App.vue` passes the formatters through CnAppRoot's `formatters` prop. Buildiq has no `customComponents.js`: App.vue already hands CnAppRoot a `customComponents` map flattened from `registry.js`, and CnIndexPage resolves a header action's handler against that map. The handler is merged into it there.

**Formatters.** The installed `@conduction/nextcloud-vue` 3.0.0 ships no `connectionStatus` built-in, so Buildiq carries a local copy with all six labels, `limited` included.

## D5. Contract misfits

- **A credential per user.** GitHub push and pull use a broker credential the user picks per call. The contract has `requiredConfig` for an app-wide key and a family row for per-record targets, and nothing for a per-user credential. `reportedOnly` plus outcome reports is the closest fit, and a denied credential is left unreported because it says nothing about the next user.
- **A connection back into the same instance.** D2 says "outside connections". Filinq on the same instance fails like one, but the contract has no word for it. Buildiq follows dossiq's `templates` precedent.
- **A report outlives the save until hydra#674 lands.** Rule 4b ranks a store report above rule 5. Until integriq implements the retirement, a save alone cannot bring back "Required settings are filled." after a search has reported.
- **A family row shows one receiver.** The last webhook call stands for every rule's address. The message names that host, so the reader can tell which one.

## Risks

- **The store may read Configured on an instance where the setup wizard filled `registry_url` with a placeholder.** Rule 5 says "Required settings are filled.", which stays true until a search reports.
- **A busy instance writes app config once an hour per connection.** That is four keys at most.
