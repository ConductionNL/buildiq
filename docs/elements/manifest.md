---
sidebar_position: 8
description: The manifest is the app. One document holding navigation, pages, widgets and their wiring.
---

# The manifest

Read [the four parts of an app](../intro.md) first. This page is what holds them.

Buildiq stores your whole app as a single **manifest**. Navigation, pages,
widgets, actions, and how they are wired. The manifest is not a description of
the app; it *is* the app. The running app is that document rendered, and
Buildiq's designers are a way of editing it without hand-writing JSON.

## What the manifest holds

| Key | What it declares |
|---|---|
| `menu` | Navigation entries. See [Navigation](./navigation.md). |
| `pages` | Every screen. See [Pages](./pages.md). |
| `setup` | The first-run setup wizard steps. |
| `walkthrough` | Guided tours, as `tours[].steps[]`. |
| `adminSettings` | The app's admin settings surface. |
| `dependencies` | Apps this one needs. |
| `credentials` | Credential requirements. |
| `schedules` | Recurring jobs. |
| `observability` | Health and metrics endpoints. |
| `deepLinks` | Links other apps can use to open a screen here. |
| `pageTemplates` / `pageInstances` | A page shape declared once and reused. |
| `sets` | Grouped declarations. |
| `mcp` | Tools exposed to an assistant. |
| `nav` / `runtime` | Shell and runtime options, including theme. |

`version` records the manifest format. `openbuildEditable` marks which parts
Buildiq's designers may change.

## Deltas, so upgrades do not overwrite you

Your changes are stored as a **delta over the shipped manifest**, not as a copy
of it. When the app ships an update, your edits survive it, because they are
recorded as the difference rather than as a replacement.

This is what makes a customised app upgradeable, and it is why editing the
manifest through Buildiq is safer than editing a file.

## Because it is one document

Three things follow, and they are the practical payoff:

- **Snapshots.** Save a version of the whole app and roll a bad edit back.
- **Export.** Take the app out as a ZIP.
- **Publish.** Push the app to a GitHub repository and install it on another
  instance. See [GitHub store](../github-store.md).

A snapshot captures the manifest, so it captures the app: schemas, pages, and
wiring together, not a set of files you have to keep in step.

## Validation

The manifest validates against the `app-manifest-v2` JSON schema published with
`@conduction/nextcloud-vue`. Buildiq also ships `scripts/check-manifest.js`,
which the quality pipeline runs, so an invalid manifest fails before it reaches
a user.

Next: build one. Open [create a virtual app](../tutorials/create-a-virtual-app.md).
