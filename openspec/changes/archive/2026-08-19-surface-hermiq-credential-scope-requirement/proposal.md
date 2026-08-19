---
kind: code
---

## Why

Installing a virtual app via the buildiq-* GitHub round trip
(`ShopController::githubInstall` and `GitHubSyncController::pull`) needs a
GitHub credential. `openbuild` itself identifies to OpenRegister's credential
broker as app `"openbuild"` (`GitHubCatalogService::APP_ID`), so the obvious,
natural credential scope to grant it — the only scope any part of the shop UI
or install response hints is needed — is `allowedApps: ["openbuild"]`.

That credential works for the repo search and the repo-file-map fetch. It
does NOT work for the skills channel: `AppChannelApplier`'s skills channel
(`SkillChannelDelegate::apply()`) delegates to hermiq's own bundle installer
(`SkillBundleInstaller::installFromRepo()`), which performs an INDEPENDENT
GitHub fetch through `GitHubTemplateCatalogService`, identifying itself to
the broker as app `"hermiq"` (`GitHubTemplateCatalogService::APP_ID`) — a
hardcoded constant, unrelated to which app originally created or selected the
credential. `CredentialBrokerService::assertAppAllowed()` denies that call
(`app "hermiq" not in allowedApps`, openregister
`lib/Service/Credential/CredentialBrokerService.php:984`), logged server-side
only — `deny()` always throws the fixed message `Request not permitted`
(design.md D4, ADR-005 fail-closed), so the real reason never reaches the
calling code, by design.

`GitHubTemplateCatalogService::brokerGet()` swallows that denial and falls
back to an anonymous GitHub fetch, which fails for either a private
repository or GitHub's low unauthenticated rate limit on a many-file skill
bundle. The failure surfaces all the way up as a generic `Throwable`, caught
in `SkillChannelDelegate::apply()`:

```php
} catch (Throwable $e) {
    $this->logger->warning('OpenBuild channel apply: hermiq skill install failed: ' . $e->getMessage());
    $report->skipChannel(channel: self::CHANNEL, reason: 'hermiq-install-failed');
}
```

The install still reports overall success (`ShopController::githubInstall`
returns 201; `GitHubSyncController::pull` returns `outcome: ok`). The only
trace of the failure is `channels.skills.reason: "hermiq-install-failed"`,
nested three levels deep in the response body, with nothing distinguishing
"credential scope gap" from "hermiq crashed" from "GitHub was unreachable".
Nothing before or during the install tells the caller that a repo with a
`skills/` folder needs a SECOND app scoped onto the same credential. This was
found live, via a real install, tonight.

## What Changes

- **`AppChannelApplier::apply()`** gains a proactive, in-process check before
  delegating to `SkillChannelDelegate`: when the template declares a
  non-empty `skills` channel AND a `$credentialId` was supplied, look up that
  credential's own `allowedApps` (register `credential-broker`, schema
  `brokeredcredential` — the same lookup `credentialExists()` already makes
  in this class) and check for `"hermiq"` directly, without going through the
  broker's request-guard flow at all — this is a metadata read of a document
  the acting user's own request already names, not a use of the credential.
  When `"hermiq"` is absent, the skills channel is skipped with a NEW,
  specific reason (`credential-missing-hermiq-scope`) instead of ever
  attempting — and failing — the hermiq call. When the lookup is
  inconclusive (throws, or the credential is not found), behaviour is
  UNCHANGED: fall through and attempt the delegate call as before, so this
  can only ever suppress the misleading generic failure, never introduce a
  new false block.
- **`ChannelApplyReport`** gains a `warnings` list (`addWarning(code,
  channel, message)`), rendered as a top-level `warnings` array in
  `toArray()` — structured, plain-language entries, distinct from the
  per-connector `needsCredentials` list (which is about a data-level
  `credentialRef` that never resolves, not a broker-scope denial on the
  credential used for the request itself).
- **`ApplicationsController::installFromTemplateArray()`** and
  **`GitHubAppSyncService::pull()`** — both already thin passthroughs to
  `AppChannelApplier::apply()` — copy the report's `warnings` onto their own
  top-level response (`data.warnings` / `warnings`), so a caller does not
  have to know to look inside `channels.skills.reason` to find it.
- **`GitHubSyncModal.vue`** renders `pullResult.warnings` as a warning
  `NcNoteCard`, next to the existing pull-success note card.
- **`TemplateGallery.vue`** shows each `created.warnings[].message` as a
  `showWarning()` toast before redirecting into the newly installed app, so
  the shop-install path (which redirects immediately) does not silently
  drop the same information.

## What does NOT change

- The install/pull still succeeds as a partial, best-effort result — this
  capability's own class docblock states three ground rules the whole
  applier is built on: "Never overwrite", "Never claim atomicity", "Never
  drop silently." Turning a missing skills scope into a hard install failure
  would mean an app with a valid `openregister`/`connectors` payload and a
  `skills/` folder can no longer be installed at all with an
  `openbuild`-only credential, even though every OTHER channel installs
  fine. See design.md for the alternatives considered and rejected.
- The credential broker itself, `SkillChannelDelegate`, and hermiq's
  `GitHubTemplateCatalogService`/`SkillBundleInstaller` are unchanged. The
  fix is a pre-check openbuild can make entirely with data it already reads
  in the same class, not a change to the broker's fail-closed contract or to
  hermiq's own app identity.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `app-channel-application`: the applier now detects — rather than merely
  reports after the fact — a credential that lacks the scope the skills
  channel's delegated hermiq fetch needs, and surfaces it through a new
  top-level `warnings` list on both install paths' responses.

## Impact

- `lib/Service/AppChannelApplier.php` — new proactive scope check before the
  skills delegation, two small private helpers.
- `lib/Service/ChannelApplyReport.php` — new `addWarning()` / `warnings`.
- `lib/Controller/ApplicationsController.php` — `installFromTemplateArray()`
  copies `warnings` to the top level of `data`.
- `lib/Service/GitHubAppSyncService.php` — `pull()` copies `warnings` to the
  top level of its result.
- `src/modals/GitHubSyncModal.vue`, `src/views/TemplateGallery.vue` —
  surface the warning to the caller.
- `openspec/specs/app-channel-application/spec.md` — the skills-delegation
  requirement gains a scenario; a new requirement covers the top-level
  `warnings` surfacing.
- Tests: `tests/Unit/Service/AppChannelApplierTest.php` (the core failure
  mode — a credential scoped to only `openbuild` attempting an install of an
  app with a skills channel, asserting the hermiq delegate is never even
  called), `tests/Unit/Service/ChannelApplyReportTest.php`.
