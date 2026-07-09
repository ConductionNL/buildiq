## 1. GitHubAppSyncService — push (serialize + broker-routed tree push)

- [ ] 1.1 Create `lib/Service/GitHubAppSyncService.php` (SPDX + copyright in the file docblock) injecting what push/pull need; lazily resolve `OCA\OpenRegister\Service\Credential\CredentialBrokerService` (`class_exists` + `Server::get`, the `RemoteTemplateStoreService` pattern) and reuse `AppRepoSerializer` (change 1), `AppRepoParser` (change 1), and `GitHubCatalogService::fetchRepoFiles` (change 2). (REQ-GHAS-005)
- [ ] 1.2 Implement `push(slug, credentialId, repo?)`: owner-gate; serialize the chosen `ApplicationVersion` via `AppRepoSerializer::serialize` into an in-memory `path => contents` map (no on-disk tree). (REQ-GHAS-002)
- [ ] 1.3 Ensure-repo: when `Application.githubRepo` is unset (or a `repo` override targets a new repo), create it via the broker (`POST /user/repos` or `POST /orgs/{org}/repos`), set the discovery topic `openbuild-app` (`PUT /repos/{owner}/{repo}/topics`), and store `githubRepo` + `githubDefaultBranch` on the Application. (REQ-GHAS-002)
- [ ] 1.4 Port the Git Data API tree-push mechanics from `GitHubPushService` (blob → tree → commit → ref) but route EVERY call through `CredentialBrokerService::request(credentialId, 'openbuild', method, path, headers, body, actingUserId)`; parent the commit on the current default-branch head (fetched via `GET …/git/ref/heads/{branch}`) so push ADDS a commit (non-destructive, never force). (REQ-GHAS-002, REQ-GHAS-005)
- [ ] 1.5 Advance the branch ref via `PATCH …/git/refs/heads/{branch}` (the `PATCH /repos/*/git/refs/*` allowRule ships in OpenRegister's `github-provider-shop-rules`; no fallback path — if the rule is absent, push is feature-detected as unavailable, tasks 3.6/4.5). (design.md OQ-1, resolved)
- [ ] 1.6 Record the resulting `commitSha` on the pushed `ApplicationVersion`; on a moved-remote-head divergence return a generic `push_conflict` (owner pulls first) — never force-overwrite. (REQ-GHAS-002)

## 2. GitHubAppSyncService — pull (fetch + parse → new draft version)

- [ ] 2.1 Implement `pull(slug, ref, credentialId?)`: owner-gate; resolve `owner`/`repo` from `Application.githubRepo`; fetch the repo file map via `GitHubCatalogService::fetchRepoFiles` (broker path when `credentialId` supplied for private repos, anonymous otherwise). (REQ-GHAS-003)
- [ ] 2.2 Parse the file map with `AppRepoParser::parse` (change 1, strict all-or-nothing); on failure return a generic-but-actionable error carrying the parser code + offending file path, creating nothing. (REQ-GHAS-003)
- [ ] 2.3 Create a NEW draft `ApplicationVersion` on the existing Application carrying the parsed `manifest`, `status: draft`, `application` → this app, stamped with `commitSha` (resolved commit for `ref`) and `sourceRef` (`ref`); NEVER modify `productionVersion` or any published version. (REQ-GHAS-003)
- [ ] 2.4 Reconcile parsed `companionSchemas` into the draft version's register using the clone seam's namespacing conventions (isolated from production until promotion); surface schema divergence rather than force-applying to production. (design.md Decision 2, OQ-3)
- [ ] 2.5 Return `{ versionUuid, commitSha, sourceRef, status: 'draft' }`; activation uses the existing version-promotion/release flow (REQ-OBV-110), not this change. (REQ-GHAS-003)

## 3. GitHubSyncController — owner-gated endpoints

- [ ] 3.1 Create `lib/Controller/GitHubSyncController.php` (SPDX + copyright in the file docblock) injecting `IRequest`, `IUserSession`, `GitHubAppSyncService`, the application-lookup + owner-gate collaborators (reuse `ApplicationVersionOwnerGuard` / the publish controller's owner check), and `LoggerInterface`.
- [ ] 3.2 Shared guard helper: load the Application by `{slug}` (404 when absent), then require the caller is an OWNER (403 for editor/viewer AND for a Nextcloud admin not in `permissions.owners` — admin power does not auto-grant); per-object guard closes no-admin-idor. (REQ-GHAS-001)
- [ ] 3.3 `link()` — `#[NoAdminRequired]`; owner-gated; body `{ owner, name, org? }` pattern-validated; resolve default branch; store `githubRepo` + `githubDefaultBranch`; return the linkage. (REQ-GHAS-001)
- [ ] 3.4 `push()` — `#[NoAdminRequired]`; owner-gated; body `{ credentialId, versionSlug?, repo? }` validated; call `GitHubAppSyncService::push`; return the commit sha or a generic `push_conflict` / `broker_denied` / `github_unreachable`. (REQ-GHAS-002)
- [ ] 3.5 `pull()` — `#[NoAdminRequired]`; owner-gated; body `{ ref, credentialId? }` validated; call `GitHubAppSyncService::pull`; return the new draft version uuid or a generic-but-actionable parse error. (REQ-GHAS-003)
- [ ] 3.6 `status()` — `#[NoAdminRequired]`; viewer-readable; return `{ githubRepo, githubDefaultBranch, lastPushedSha, lastPulledSha, brokerCredentialAvailable, publishAvailable }` (feature-detection flags computed from broker presence + widened-rules detection). (REQ-GHAS-004)
- [ ] 3.7 Register all four routes in `appinfo/routes.php` — `POST …/github/link|push|pull`, `GET …/github/status` — specific-first before the SPA catch-all, with a kebab-case `requirements` constraint on `{slug}`.

## 4. Frontend — GitHub section in the detail cockpit

- [ ] 4.1 Add a GitHub section to the application detail cockpit (`application-detail-ui`: `ApplicationDetailActions` / a tab): credential picker listing the user's github credentials via OR's `GET /apps/openregister/api/credentials` with a link to the CnCredentials pane to add one; render write controls for owners, status-only for non-owners. (application-detail-ui: GitHub section)
- [ ] 4.2 Link-repo dialog in its own file under `src/modals/` (modal-isolation): owner + name (+ optional org) → `POST …/github/link`. (application-detail-ui: GitHub section)
- [ ] 4.3 Publish action → `POST …/github/push` with the selected credential + version; reflect the resulting commit sha in the status readout. (application-detail-ui: Publish/Pull)
- [ ] 4.4 Pull action → `POST …/github/pull` with a ref (+ credential for private); on success surface the new draft version (link to promote via the existing flow); never present pull as overwriting production; surface a strict-parse failure as an actionable error naming the file. (application-detail-ui: Publish/Pull)
- [ ] 4.5 Status readout from `GET …/github/status` (linked repo, last pushed/pulled sha); feature-detect via `publishAvailable` / `brokerCredentialAvailable`: disable Publish with a clear hint when unavailable; keep public pull available anonymously. (application-detail-ui: feature detection)
- [ ] 4.6 i18n English source strings for the GitHub section (section label, Link/Publish/Pull labels, credential picker, status labels, disabled-state hints) + Dutch translations in `l10n/nl.json`.

## 5. Tests

- [ ] 5.1 PHPUnit `GitHubAppSyncService::push`: serialize via `AppRepoSerializer`; every GitHub call routed through a mocked `CredentialBrokerService::request` (assert no token in OpenBuild); unlinked app creates the repo + sets topic `openbuild-app`; commit is parented on the head (non-destructive); `commitSha` stamped on the version; moved-head → `push_conflict`. (REQ-GHAS-002, REQ-GHAS-005)
- [ ] 5.2 PHPUnit `GitHubAppSyncService::pull`: fetch via `GitHubCatalogService::fetchRepoFiles` (broker for private, anonymous for public); parse via `AppRepoParser`; creates a new draft `ApplicationVersion` stamped with `commitSha` + `sourceRef`; `productionVersion` unchanged; malformed repo creates nothing and returns the parser code + file. (REQ-GHAS-003)
- [ ] 5.3 PHPUnit `GitHubSyncController`: owner passes; editor/viewer → 403; Nextcloud admin not in owners → 403; missing app → 404; link stores the linkage; push/pull delegate to the service; status returns the feature-detection flags (`publishAvailable` false when broker/rules absent); status viewer-readable. (REQ-GHAS-001, REQ-GHAS-004)
- [ ] 5.4 PHPUnit broker-absence: with the broker class absent, `publishAvailable` is false and push does NOT fall back to any token-bearing path (fails closed with a hint). (REQ-GHAS-005)
- [ ] 5.5 Vitest detail cockpit: the GitHub section renders for owners (picker + link + Publish + Pull + status) and status-only for viewers; Publish disabled with a hint when `publishAvailable` false; Publish calls the push endpoint and reflects the sha; Pull calls the pull endpoint, surfaces the draft version, and shows a parse error naming the file; any new modal lives in its own file. (application-detail-ui)

## 6. Docs + gates

- [ ] 6.1 Update the OpenBuild docs with the owner round-trip (link/publish/pull), the non-destructive conflict model (push=new commit, pull=new draft version), the broker-routed auth (token never in OpenBuild), and the feature-detection / disabled-publish hint. Note the exporter is unchanged and orthogonal.
- [ ] 6.2 Run the hydra gates green: route-auth + semantic-auth (all four endpoints `#[NoAdminRequired]` with matching in-body owner/viewer guards), route-reachability (all routes ↔ `GitHubSyncController` methods), no-admin-idor (per-object owner guard in each write method; admins not auto-granted), orphan-auth / unsafe-auth-resolver (the broker resolution fails closed — no `catch(\Throwable){return null}` fall-open), spec-coverage (`@spec` tags on changed methods), modal-isolation (new modals in their own files), spdx-headers, forbidden-patterns, stub-scan, composer-audit.
