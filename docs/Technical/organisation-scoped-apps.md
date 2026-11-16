# Organisation-scoped apps

Several organisations can share one Nextcloud. An app can be kept to the
organisation that holds it, so members of other organisations do not see it,
open it or read its records.

## The scope

Every app has an organisation scope, set by its owner under **App settings**:

| Scope | Stored value | Who sees the app |
|-|-|-|
| All organisations | `all`, or no value | everyone with a role on the app, as before |
| This organisation only | `holder` | only members of the organisation that holds the app, or of one of its child organisations |

The holder is the organisation OpenRegister stamped on the Application when it
was saved: the active organisation of the person who made or installed it.
Buildiq does not keep a second copy.

The setting is only offered while OpenRegister's multitenancy is on. With it
off, organisations do not exist for OpenRegister and the scope has no effect.

## New and installed apps

While multitenancy is on, an app made in the wizard, installed from the store
or a GitHub repository, or imported from an archive starts as **This
organisation only**, held by the maker's active organisation. Apps that existed
before keep **All organisations**, so nothing changes for them.

Switching **This organisation only** off asks first: every organisation will
then see the app, and its records stay with the holder.

## What the scope covers

The scope is checked before any role, for administrators too. For a member of
another organisation a scoped app answers as an app that does not exist on every
path: the app list, the manifest endpoint and the runtime, the navigation and
the MCP tools (`list_apps`, `get_app_manifest`). A scoped app whose scope or
holder cannot be read is treated as not visible.

A scoped app's registers and records are held by its organisation in
OpenRegister, so OpenRegister's own API does not return them to other
organisations either.

It does not cover:

- **Slugs.** Slugs stay unique on the whole instance, so links keep working. A
  slug taken by another organisation's app is refused with "This slug is taken.
  Choose another.", which names no organisation.
- **Rule sets and data registers**, which stay shared across the instance.
- **Moving** an app or its records to another organisation.

## For developers

`AppOrganisationGuard::canSee()` answers whether a user may see an
Application. Every class that reads the Application schema is listed in
`tests/Unit/Service/AppOrganisationGuardCoverageTest.php` as a guarded door, as
no door for a user (such as the `occ` commands), or as still to be reviewed; a
new reader on none of those lists fails the test.
