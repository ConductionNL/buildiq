---
title: Embed an app in another site
description: Show a built app, or one of its pages, inside another site your colleagues already use, such as the intranet.
---

# Embed an app in another site

An app you built can show inside another site your colleagues use, such as
the intranet. They use it there without opening Nextcloud first. Embedding is
for people who can already sign in to Nextcloud: it never makes the app
public. Forms or lists for residents belong in portaliq.

## Switch embedding on

1. Open the app and choose **App settings**.
2. In **Embed**, switch on **Allow other sites to show this app**.
3. Add each site that may show the app, for example
   `https://intranet.example.nl`. A site is `https://` and a host, optionally
   a port. A path or a wildcard (`https://*.example.nl`) is refused: list each
   site separately.
4. Choose **What to embed**: the whole app, or one page.
5. Copy the snippet and paste it into the other site.

The snippet is an `<iframe>` whose address ends in `?embed=1`. For that
address, and only while embedding is on, Nextcloud answers without its own
header and lets exactly the listed sites frame the app
(`Content-Security-Policy: frame-ancestors`). Every other site is refused by
the browser, and with embedding off the app cannot be framed at all.

An embedded page shows without the app's navigation, so a one-page embed
shows only that page.

## The same-site limit

Nextcloud's session cookie is `SameSite=Lax`. A frame only carries the
session when the other site shares Nextcloud's site, for example an intranet
on `intranet.example.nl` next to Nextcloud on `cloud.example.nl`. On a site
that does not, the frame has no session.

The embedded app never asks for a password inside the frame. Without a
session it shows **Sign in to use this app** with a link that opens
Nextcloud in a new tab. After signing in there, reload the page with the
embedded app.

## Front ends

A reverse proxy in front of Nextcloud may add its own headers. Current
browsers ignore `X-Frame-Options` when a `Content-Security-Policy` with
`frame-ancestors` is present, but they enforce every content security policy
they receive. A proxy that adds a policy with its own `frame-ancestors`
refuses the framing, so leave that directive out for Nextcloud's addresses.
