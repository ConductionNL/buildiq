# Spec: app-embedding

## Purpose

An organisation shows a built app, or one of its pages, inside another site its
staff use, such as the intranet. Embedding is switched on per app, limited to
listed sites, and never makes the app public.

## ADDED Requirements

### Requirement: A maker switches embedding on and lists the allowed sites (REQ-BQEM-001)

The app settings SHALL offer an Embed section with a switch and a list of
allowed origins. Embedding SHALL be off by default. An origin SHALL be an https
scheme and host without a path or a wildcard; anything else SHALL be refused on
save with the reason.

#### Scenario: A maker allows the intranet

- **GIVEN** the owner of the app "Room bookings"
- **WHEN** they switch embedding on, add `https://intranet.example.nl` and save
- **THEN** the app stores that origin and the section shows a snippet to copy

#### Scenario: A wildcard is refused

- **WHEN** the owner adds `https://*.example.nl`
- **THEN** the save is refused and the section says to list each site separately

### Requirement: Only listed sites can frame the app (REQ-BQEM-002)

A request for the app's runtime with `embed=1` SHALL be answered without the
Nextcloud header and with a `frame-ancestors` policy naming only the app's
allowed origins, when embedding is on. With embedding off, the runtime SHALL
answer as it does without `embed=1`, and framing SHALL stay refused.

#### Scenario: The intranet shows the app

- **GIVEN** "Room bookings" allows `https://intranet.example.nl`
- **WHEN** the intranet page loads `/apps/buildiq/builder/room-bookings?embed=1` in an iframe for a signed-in colleague
- **THEN** the response's policy lists `https://intranet.example.nl` as a frame ancestor and the colleague sees the app without the Nextcloud header

#### Scenario: Another site cannot frame it

- **GIVEN** the same app
- **WHEN** a page on `https://other.example.com` frames the same URL
- **THEN** the browser refuses to show it, because that origin is not a frame ancestor

### Requirement: The frame never asks for a password (REQ-BQEM-003)

When an embedded app has no session, it SHALL show a notice with a link that
opens Nextcloud's sign-in in a new tab, and SHALL NOT show a login form inside
the frame.

#### Scenario: A colleague is not signed in

- **GIVEN** a colleague who is not signed in to Nextcloud opens the intranet page
- **WHEN** the embedded app loads
- **THEN** the frame says "Sign in to use this app" with a link that opens in a new tab

### Requirement: A maker embeds one page (REQ-BQEM-004)

The Embed section SHALL offer a snippet for the whole app and for any one page.
An embedded page SHALL show without the app navigation.

#### Scenario: Only the booking form goes on the intranet

- **WHEN** the owner picks the page "Book a room" and copies its snippet
- **THEN** the snippet's URL opens that page in embed mode with no navigation to other pages
