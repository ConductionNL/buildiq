---
kind: code
depends_on: []
---

# Proposal: apps-unified-search-deep-links

## Why

Capability row `apps-unified-search`: find an app or its records in the Nextcloud search and open it directly. Today a record of a built app is found, because OpenRegister's `ObjectsProvider` searches every object, but the hit opens in OpenRegister, not in the app. An app itself does not open in buildiq either.

The cause: buildiq registers no deep links. `lib/AppInfo/Application.php` wires OpenRegister's AppHost `GenericDeepLinkRegistrationListener`, which reads `deepLinks` from `src/manifest.json`, and that list is empty (`"deepLinks": []`). The main spec `deep-link-registration` is marked `status: done` and describes `DeepLinkRegistrationListener`, a class that was deleted when AppHost took over.

A static list can only name buildiq's own schemas. The records of a built app live in a register per app version (`buildiq-{app}-{version}`) with schemas the maker defines, so their links must be computed from the published apps.

## What changes

1. `src/manifest.json` `deepLinks` gets the entry for buildiq's own `built-app` schema in register `buildiq`, so an app found in search opens on its detail page (`/apps/buildiq/applications/{uuid}`).
2. A buildiq listener extends the AppHost listener: next to the manifest entries it adds, for every app with a production version, one entry per detail page of that version's manifest (register, schema, and the page route with its id parameter as `{uuid}`), so a record found in search opens on its page in the built app (`/apps/buildiq/builder/{slug}/...`).
3. The `deep-link-registration` spec is rewritten to say this, and its `status` goes from `done` to `in-progress` until the tasks land.
4. Row `apps-unified-search` in `openspec/parity/capabilities.json` points at this change.

Recommended over retiring `deep-link-registration`: the plumbing exists on both sides, and opening a record in the app it belongs to is what the row asks.

## Out of scope

- A buildiq search provider of its own: OpenRegister's provider already finds the objects.
- Access control: the link only decides where a hit opens; who may see a hit stays with OpenRegister, and the built app checks access when the page opens.
