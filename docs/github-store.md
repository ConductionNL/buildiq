# GitHub store — publish and install apps

The **GitHub store** lets you publish an Buildiq app to a GitHub repository and
discover and install apps that others have published — on top of the built-in
templates and the [remote template store](./template-store.md).

Unlike the remote template store (which is consume-only), the GitHub store is a
full **round-trip**: build an app, publish it to GitHub, and install it again on
any instance — the app lives in the repository, independent of the instance that
built it.

## What a published app looks like

Publishing writes the app to a repository as plain, re-importable files, and tags
the repo with the `buildiq-app` topic (the store's discovery contract):

| File | Contents |
|------|----------|
| `buildiq-app.json` | App descriptor — slug, name, description, category, `appType`, version, icon refs, and the declared `credentials[]`. |
| `manifest.json` | The `ApplicationVersion` manifest — every page, widget, menu entry, sidebar, and setting. |
| `schemas/<slug>.json` | The companion schemas that make up the data model. |
| `README.md` | Generated overview of the app. |

`AppRepoSerializer` writes this layout deterministically (recursively key-sorted);
`AppRepoParser` reads it back with strict, all-or-nothing validation, so a
malformed repository fails loudly and installs nothing.

## Credentials — the token never reaches Buildiq

Every GitHub call is routed through OpenRegister's **credential broker**. You
store a GitHub personal access token once, in the **Credentials** pane of the
app's user settings; it is kept in **Doriath**, the encrypted credential vault.
Buildiq never receives the token — it asks the broker to make each GitHub call
(create repo, push commit, set topic, read contents), the token is injected
server-side, host-locked to `api.github.com`, and only the result comes back.

- Browsing the store is **anonymous** by default (public repos, no credential).
- Passing a credential **upgrades** the call through the broker so you also see
  your own private repositories and get a higher rate limit.
- Publishing and pulling always go through the broker.

Use a GitHub **fine-grained** token with exactly three repository permissions:
**Administration — Read and write** (create the repository, set its topic),
**Contents — Read and write** (push the app's files and commits), and
**Metadata — Read-only** (required). Nothing else is needed; the broker's
allow-rules deny issues, pull-requests, workflows, and webhooks regardless.

The token owner controls access per credential (which apps may use it) and can
revoke or rotate it in one place — nothing to clean up inside Buildiq.

## Publishing an app

Open the app, choose **Actions → GitHub**, pick a `github` credential, and select
**Publish**. Buildiq:

1. Serializes the chosen version to the repo layout.
2. Creates the repository (via the broker) — **public by default** so it is
   discoverable in the store's anonymous search; pass `visibility: "private"` to
   keep it private.
3. Sets the `buildiq-app` topic and commits the app in one clean commit via the
   Git Data API (blob → tree → commit → ref).
4. Records the resulting `commitSha` and repository on the app.

Re-publishing advances the branch on a new commit — it never force-pushes or
rewrites history.

## Pulling changes back

**Pull** fetches a repository ref back into a **new draft `ApplicationVersion`** —
it never touches the production version. A change someone else pushed lands next
to your production version for you to review and promote through the normal
version-promotion flow.

## Filling the store from GitHub

Go to **Store → GitHub**. The store searches GitHub for the `buildiq-app` topic
and renders each published app as an installable card built from its
`buildiq-app.json`. Click **Install**, name the new app, and confirm — the
repository is parsed and cloned into a fresh local app through the same seam as
any template (`ApplicationsController::installFromTemplateArray`), so it is an
ordinary editable virtual app, not a locked import.

## Endpoints

| Method + path | Purpose |
|---|---|
| `GET /index.php/apps/buildiq/api/shop/github/search?q=<term>&credentialId=<uuid?>` | Search GitHub for `buildiq-app` repos. Anonymous by default; `credentialId` broker-upgrades to include private repos. Returns `{ outcome, cards, brokerCredentialAvailable, brokerUsed, rateLimited }`. |
| `POST /index.php/apps/buildiq/api/shop/github/install` | Install an app from a repo. Body `{ owner, repo, ref?, name?, slug?, credentialId? }` → `201 { uuid, slug, register, companionSchemas }`. |
| `GET /index.php/apps/buildiq/api/applications/{slug}/github/status` | Linked repo, default branch, last pushed/pulled sha, and feature-detection flags (`brokerCredentialAvailable`, `publishAvailable`). Viewer-readable. |
| `POST /index.php/apps/buildiq/api/applications/{slug}/github/link` | Link an app to a repo. Body `{ owner, name, org? }`. Owner-only. |
| `POST /index.php/apps/buildiq/api/applications/{slug}/github/push` | Publish. Body `{ credentialId, versionSlug?, repo?, visibility? }` → `{ outcome, repoUrl, commitSha, branch }`. Owner-only. |
| `POST /index.php/apps/buildiq/api/applications/{slug}/github/pull` | Pull a ref into a new draft version. Body `{ ref, credentialId? }` → `{ outcome, versionUuid, versionSlug, commitSha, sourceRef, status: 'draft', register }`. Owner-only. |

`outcome` values include `ok`, `not_linked`, `broker_unavailable`,
`broker_denied`, `push_conflict`, `github_rate_limited`, `github_unreachable`.

## Security

- All GitHub reads are **fixed-host** to `api.github.com`; callers supply a path,
  never a full URL, and the broker host-locks and allow-rule-checks every call.
- The write operations (search, identity, repo-create, ref-update, topic-set)
  require the widened `github` provider allow-rules shipped in OpenRegister
  (catalogue v1.2.0). Issues, pull-requests, workflows, webhooks, and deletes
  stay denied.
- Publish/pull/link/status are **owner-gated** on the app's `permissions` model;
  a Nextcloud admin who is not an owner is not auto-granted.
- The credential secret is never returned to the app, the browser, a log line, or
  an error message.

See the OpenSpec changes `github-app-repo-format`, `github-shop-catalogue`, and
`github-app-sync` (Buildiq) and `github-provider-shop-rules` (OpenRegister) for
the full specification.
