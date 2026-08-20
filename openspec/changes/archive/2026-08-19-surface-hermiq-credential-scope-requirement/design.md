## Context

`AppChannelApplier::apply()` (openbuild) is the single choke point both
GitHub round-trip paths use: `ShopController::githubInstall()` (via
`ApplicationsController::installFromTemplateArray()`) and
`GitHubSyncController::pull()` (via `GitHubAppSyncService::pull()`). Its
`skills` channel does not install anything itself — it delegates to
hermiq's `SkillBundleInstaller::installFromRepo()`, which does its OWN
independent GitHub fetch, authenticating to OpenRegister's credential broker
under app id `"hermiq"` — a constant on hermiq's
`GitHubTemplateCatalogService`, entirely unrelated to which app (`openbuild`)
the credential was originally scoped for.

`CredentialBrokerService::assertAppAllowed()` is a deliberate, correct
security boundary (ADR-005 fail-closed, guard 2 of 4): a credential's
`allowedApps[]` is an explicit allow-list, and `deny()` always throws the
fixed message `Request not permitted` regardless of which guard failed
(design.md D4 in openregister's own credential-broker change) — the calling
code is never told WHY a call was denied, on purpose, so a compromised or
buggy app cannot use the broker's own error messages to probe a credential's
configuration.

That correct design has a real consequence here: nothing in openbuild's
existing skills-delegation code could distinguish "wrong app scope" from any
other broker denial or from a genuine hermiq/GitHub failure, using only the
exception hermiq's call raised. Observed live tonight: a credential scoped
`allowedApps: ["openbuild"]` — which is *exactly* the credential every other
part of this feature needs, and the only scope the UI or API ever hints
at — reaches `SkillChannelDelegate`'s generic `catch (Throwable $e)` and is
reported as `skipChannel(reason: 'hermiq-install-failed')`: true, but
uninformative, and buried three levels deep in the response
(`channels.skills.reason`).

## Goals / Non-Goals

- **Goal**: stop the specific "credential works for everything except the
  one delegated channel" failure mode from reaching the generic
  `hermiq-install-failed` catch-all, and surface it somewhere a caller
  would actually see it.
- **Goal**: do this without changing the broker's fail-closed contract, or
  needing hermiq/openregister code changes — this is an openbuild-only fix,
  landable in one PR.
- **Goal**: keep the existing best-effort, no-atomicity philosophy this
  capability was explicitly built around (see the `AppChannelApplier` class
  docblock's three rules) — a skills-scope gap should degrade exactly like
  every other optional-channel gap already does (openconnector absent,
  hermiq app absent), not become a new way for the whole install to fail.
- **Non-goal**: teaching the frontend to pre-select or auto-provision a
  credential with the right scope, or building a credential-creation UI
  inside openbuild. The fix stops at "tell the caller what's missing,
  clearly, where they'll see it" — creating the right credential is a
  one-time, one-line settings change once the caller knows to make it.
- **Non-goal**: generalising the proactive-scope-check mechanism to
  connectors/automations. Those channels are openbuild's OWN writes via
  `ObjectServiceInterface` (this app's own broker identity, `"openbuild"`,
  already covers them) — only `skills` crosses into a SECOND app's own
  broker identity, so only `skills` has this specific failure mode.

## Decision

**Proactively check the credential's own `allowedApps` inside
`AppChannelApplier`, before ever calling hermiq, and skip with a specific
reason instead of attempting a call we can already tell will fail.**

`AppChannelApplier` already reads the SAME register/schema
(`credential-broker`/`brokeredcredential`) directly via
`ObjectServiceInterface`, in `credentialExists()` — a plain metadata read,
not a broker "use this credential" call, and therefore NOT subject to (and
not in tension with) the broker's own fail-closed "never tell the caller
which guard failed" contract. That contract governs what the BROKER tells a
caller trying to USE a credential; it says nothing about openbuild reading
its own credential document's `allowedApps` field before deciding whether to
even attempt a downstream call with it. This is exactly the same shape of
check `credentialExists()` already makes for connector `credentialRef`s, so
it reuses an established, tested pattern rather than inventing a new one.

Concretely: before delegating to `SkillChannelDelegate::apply()`, when the
skills channel is non-empty and a `$credentialId` was supplied,
`AppChannelApplier` looks up that credential and checks `"hermiq" ∈
allowedApps`. `false` (conclusively) → skip the skills channel with reason
`credential-missing-hermiq-scope` and record a `warnings` entry, without
ever calling hermiq. `true`, or the lookup is inconclusive (credential not
found, or the read itself throws) → behaviour is completely unchanged:
delegate to hermiq exactly as before. An inconclusive lookup must never
manufacture a NEW block that did not exist before this change — it can only
ever suppress the misleading generic reason on the one case we can prove.

The new `warnings` array is a small, generic, structured mechanism on
`ChannelApplyReport` (`{code, channel, message}`) rather than a bespoke
"skills scope" field — future channel-vs-credential-scope gaps (should any
arise) reuse the same surfacing mechanism, and both call sites
(`installFromTemplateArray`, `pull`) get it automatically because both
already thread the whole report through unchanged; only the two-line
top-level copy is new per caller.

### Alternatives considered

1. **Fail the whole install/pull when the credential lacks hermiq scope and
   the repo declares skills.** Rejected: directly contradicts this
   capability's own documented "never claim atomicity" / best-effort
   design, which exists precisely so one channel's gap does not cost the
   caller the channels that DID work. A repo with `openregister`
   channels + `connectors` + a `skills/` folder would become impossible to
   install at all with an `openbuild`-only credential, even though 90% of
   it installs cleanly today. The task brief itself frames this as an
   option to weigh, not a mandate, and the codebase's own stated
   philosophy is the deciding factor against it.
2. **Derive a manifest-level "this app needs credential scopes:
   [openbuild, hermiq]" declaration, surfaced BEFORE the install starts
   (e.g. from `ShopController::githubSearch()` cards, or a pre-check
   endpoint), so a caller creates the right credential the first time.**
   Genuinely the most complete fix, and worth doing — but it is a
   materially larger, separately-scoped change: it needs the repo's file
   tree fetched and parsed (today only `githubInstall()`/`pull()` do that;
   `githubSearch()`'s cards come from GitHub's search API, not a parsed
   `AppRepoParser` template) purely to answer a scope question, for EVERY
   card in a search result list, before the user has chosen one — a
   potentially large fan-out of extra GitHub calls just to populate a
   badge. The in-`apply()` check delivers the same information at the one
   point it is cheap to compute (the template is already parsed, the
   credential id is already in hand) and, combined with the top-level
   `warnings` field, still lets the SECOND attempt (adding hermiq to the
   credential and re-running `pull`) succeed cleanly. Left as a natural
   follow-up if the shop UI later wants a scope badge on unopened cards.
3. **Try to recover the broker's specific denial reason from the thrown
   exception instead of pre-checking.** Rejected outright:
   `CredentialAccessDeniedException`'s message is `Request not permitted`
   by design (D4/ADR-005) — there is no reason string to recover from it,
   only from the SERVER LOG the broker writes, which openbuild's process
   has no access to. Any fix built on "parse the exception message" would
   be reading a value the broker deliberately never sets.

## Risks / Trade-offs

- **The pre-check duplicates, rather than reuses, the broker's own guard
  logic** (`in_array($appId, $allowedApps, true)`). If `allowedApps`'s shape
  or field name ever changes on the broker side, this check silently stops
  firing (falls through to the unchanged, pre-existing behaviour — not a
  regression, just a missed improvement) rather than breaking loudly. Judged
  acceptable: the field is a stable, already-public part of the credential
  document's shape (`CredentialBrokerService::create()`'s own
  `$allowedApps` parameter), not an internal broker implementation detail.
- **A second `find()` call per install/pull with a supplied credential.**
  One extra OpenRegister read, gated behind "skills channel non-empty AND a
  credential was supplied" — the common case (no credential, or a v1 repo
  with no skills) makes zero extra calls.

## Migration Plan

None — this is additive (a new skip reason value, a new `warnings` array
key). No existing response field changes shape or meaning.

## Open Questions

None outstanding for this change. The manifest-level pre-declaration
(alternative 2 above) is flagged as a follow-up, not a blocker.
