---
sidebar_position: 9
title: Publish to GitHub and install from the store
description: Publish a virtual app to a GitHub repository, find it in the store's GitHub tab, and install it again on any instance — a full round-trip where the app lives in the repository.
---

# Publish to GitHub and install from the store

Exporting gives you a ZIP you carry around. **Publishing to GitHub** puts the app in a repository the store can find: build it once, publish it, and anyone (including you, on another instance) can discover it in the store and install it. The app lives in the repository, independent of the instance that built it.

## Goal

By the end you will have stored a GitHub credential, published your app to a public GitHub repository, found it in the store's GitHub tab, and installed it again as a new app.

## Prerequisites

- A virtual app you own, with a released or draft version to publish.
- A GitHub **fine-grained personal access token**, scoped to the repositories you want to publish to (or all of them), with exactly three repository permissions — **Administration: Read and write** (create the repo and set its topic), **Contents: Read and write** (push the app), and **Metadata: Read-only** (required). No issues, pull-requests, workflows, or account permissions are needed. You paste the token into Buildiq once; it is held in Doriath (Nextcloud's encrypted credential vault) and Buildiq never sees it again — the OpenRegister credential broker makes the GitHub calls for you.

  ![GitHub fine-grained token permissions: Administration and Contents at Read and write, Metadata at Read-only](/screenshots/tutorials/user/09-publish-to-github-00-permissions.jpg)

## Steps

1. Add a GitHub credential. Open the app's **user settings → Credentials**, click **Add credential**, choose the **GitHub** provider, paste your token, and allow **buildiq** to use it. The token is stored write-only — it is never shown or returned again.

2. Open your app, then **Actions → GitHub**. Pick your GitHub credential and click **Publish**. Buildiq creates a **public** repository, tags it with the `buildiq-app` topic, and commits the app — `buildiq-app.json`, `manifest.json`, and `schemas/` — in one clean commit. The token never reaches Buildiq; the broker makes the call and returns only the repository it created.

   ![The GitHub panel: credential picker with Link repository, Publish, and Pull buttons](/screenshots/tutorials/user/09-publish-to-github-01.png)

3. Go to **Store → GitHub**. The store searches GitHub for the `buildiq-app` topic and shows each published app as an installable card, built from its `buildiq-app.json`. Your app appears there — public apps with no credential at all; add your credential to also see your own private repositories.

   ![The store GitHub tab with a search box and an installable app card](/screenshots/tutorials/user/09-publish-to-github-02.png)

4. Click **Install** on the card, give the new app a name and slug, and confirm. Buildiq parses the repository and clones it into a fresh local app through the same path as any template — so it is an ordinary editable app, not a locked import.

   ![The Install app from GitHub dialog with name and slug fields](/screenshots/tutorials/user/09-publish-to-github-03.png)

5. To bring a change back later, use **Pull** in the same GitHub panel. Pull fetches the repository into a **new draft version** next to your production version — it never overwrites what is live. Review the draft and promote it through the normal version flow.

## Verification

The round-trip is good when: after publishing, the repository exists on GitHub with `buildiq-app.json`, `manifest.json`, and `schemas/`, and carries the `buildiq-app` topic. The strongest test: delete the app locally, search **Store → GitHub**, and install it again — you get the same app back, because it now lives in the repository.

## Common issues

| Symptom | Fix |
|---|---|
| **Publish** is disabled with a hint | No usable GitHub credential, or the OpenRegister broker/allow-rules are not available on this instance. Add a `github` credential in step 1; confirm OpenRegister is up to date (catalogue v1.2.0+). |
| Published app does not appear in **Store → GitHub** | Anonymous search only sees **public** repositories, and GitHub's public index can lag a minute for a brand-new repo. Pass your credential (the tab uses it automatically when present) to see it immediately, including private repos. |
| `push_conflict` on re-publish | The remote branch moved since you last synced. **Pull** first (it creates a draft), reconcile, then publish again — publish never force-overwrites. |
| Install fails naming a file | The repository does not match the expected layout (`buildiq-app.json` + `manifest.json` + `schemas/`). The error names the offending file; fix it in the repo and re-install. |

## Reference

- [GitHub store](../../github-store.md) — the full reference: repo format, endpoints, and the credential-broker security model.
- [Export your app](./08-export-app.md) — the ZIP export path, for moving an app without GitHub.
- [Snapshot and roll back a version](./07-version-snapshots.md) — pull lands as a draft you promote.
