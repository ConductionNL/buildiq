---
sidebar_position: 30
title: Install an app and use it offline
description: Put an app on a phone or desktop, let it keep working without a connection, and hand your organisation a project for the app stores.
---

# Install an app and use it offline

Every app has a run address, `/apps/buildiq/run/<slug>/`. People who may open
the app can install it from there, and you can let it keep working when the
connection drops.

## Make the app installable

1. Open the app and choose **Settings**.
2. Under **Installable**, set a **Short name** (at most 12 characters; empty
   uses the app name) and a **Theme colour**, or leave **Use the browser
   colour** on.
3. Save. On a phone or desktop, people open the run address and choose
   **Install** (or **Add to home screen**). The app opens full screen with its
   own name and icon.

The icon is the app's own icon. When the server can turn an SVG icon into a
PNG, phones get a PNG touch icon as well.

## Let an app work offline

Offline is off on every instance until an administrator turns on **Let apps
work offline** in the buildiq admin settings. Until then the settings below are
hidden.

### Take records along

In the app's **Settings**, under **Offline**, choose **Add records to take
along** and set for each:

- **Schema**: which records.
- **Filter**: as JSON, for example `{"status": "open"}`. Empty takes every
  record up to the limit.
- **At most this many records** (up to 500).
- **Usable for this many hours** (up to 72). After that the device drops them.

When someone opens the installed app, it downloads these records and shows
them, with the time they were downloaded, while there is no connection. When
the app is opened by someone who is not signed in, the device removes the
records it holds for that app.

### Forms that work offline

In the page designer, open a form page and switch on **Works offline**.
Submissions made without a connection wait on the device and are sent when the
connection returns. The app then gets a **Waiting to sync** page in its menu,
where people see what is still on the device, send it again, and settle a
conflict when someone else changed the same record. Switching the last offline
form off removes that page again.

## A project for the app stores

When you export the app, switch on **Mobile app project**. The archive then
holds `mobile/`, a project your organisation builds into iOS and Android apps.
The store app opens this instance's run address of the app and nothing else:
other links open in the phone's browser.

- **Bundle id**: leave it empty for `nl.<instance-host>.<slug>`, or type your
  own, such as `nl.gemeente.toezicht`. The stores do not let you change it
  after the first release.
- `mobile/README.md` explains how to build, sign and publish. Buildiq does not
  build, sign or publish the store apps.
- People sign in with this Nextcloud's own login page. An identity provider
  that refuses embedded browsers stops that login; the installed web app above
  does not have this limit.
