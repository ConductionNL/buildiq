<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Signatures, spoken notes and positions in forms

A form can collect three things from the user's device: a **signature**,
an **audio recording** (a spoken note) and the user's **position**. A map
page can also show the user where they are. This page explains how to set
each one up, where the value is stored, and what the page asks the browser
to allow.

> The fields are rendered by `@conduction/nextcloud-vue`. Authoring works
> now, and the settings are saved in the manifest. A user sees the
> recorder, the signature pad or the position button only once the app
> runs on a nextcloud-vue build that renders these field types.

## Adding the fields

In the **Form page** editor, add a field and choose its type:

| Type | Options | Where the value goes |
|-|-|-|
| `signature` | **Allow drawing**, **Allow typing a name**. At least one must be on. | A `file` property named like the field key |
| `audio` | **Maximum length (seconds)**, from 5 to 120 (default 60) | A `file` property named like the field key |
| `location` | **Latitude property**, **Longitude property**, optional **Accuracy property**, and **Let the user place a pin on a map instead** (on by default) | The two number properties you name, the same pair a map page reads |

A signature and an audio clip are stored with the record as inline file
content. The builder shows how much room a clip of the chosen length
takes. If a clip of that length would not fit with the record, the
builder names the longest length that does fit.

The schema designer offers the `file` type for the property that holds a
signature or a clip.

## Public forms

A form in `public` mode (the default mode) may collect a signature, but
not an audio clip or a position. Anonymous public pages belong to the
portal, so the builder and manifest validation refuse those two field
types there. To use them, switch the form to `create` or `edit`.

## Showing the user's position on a map

The **Map page** editor has two options:

- **Show my position** (`config.showUserLocation`) puts the user's
  position on the map among the markers.
- **Center on my position when the page opens** (`config.centerOnUser`).

## What the browser is asked to allow

By default, Nextcloud's pages may not use the camera, the microphone or
the device position. When a version of an app is saved, buildiq stores
the devices its manifest uses on that version as `deviceFeatures`:

- `microphone` for an `audio` field;
- `geolocation` for a `location` field or a map page with **Show my
  position**.

The page that serves the app allows exactly those features for itself
(`'self'`) and keeps Nextcloud's default policy for all others. An app
that uses a location field but no audio field can read the position and
still cannot use the microphone. An app that uses none of these fields is
served under Nextcloud's default policy, unchanged.

A hybrid app shown by another host gets that host's policy. That host must
allow the microphone or the position itself.
