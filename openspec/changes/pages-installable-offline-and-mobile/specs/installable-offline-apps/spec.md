# Spec: installable-offline-apps

## Purpose

An app user in the field puts a built app on the home screen and keeps working
when the connection drops. The maker decides what an app takes along and which
forms work offline, the administrator decides whether apps may keep data on
devices at all, and submissions made offline reach the server when the connection
returns. For the app stores, the export writes a wrapper project from the same
design.

## ADDED Requirements

### Requirement: A maker makes an app installable (REQ-BQOM-001)

A maker SHALL be able to make an app installable. Buildiq SHALL then serve a web
app manifest with the app's name, short name, icon and theme colour, starting and
scoped at the app's own run path, only to users who may open the app. An app user
SHALL be able to install it from the browser on a phone or a desktop, and it SHALL
open full screen with its own name and icon.

#### Scenario: An inspector puts the inspection app on the home screen

- **GIVEN** the installable app `toezicht` and an inspector who may use it, on an Android phone
- **WHEN** the inspector opens the app in Chrome and chooses "Install"
- **THEN** a "Toezicht" icon is on the home screen, and tapping it opens the app full screen at its start page

#### Scenario: The manifest is not served to strangers

- **GIVEN** a signed-in user who may not open `toezicht`
- **WHEN** they request its web app manifest
- **THEN** the server answers 404

### Requirement: Offline is the administrator's choice first (REQ-BQOM-002)

The instance SHALL have a setting "Let apps work offline", off by default. While
it is off, no app SHALL register a worker or store records on a device, and the
maker's offline settings SHALL be hidden.

#### Scenario: A default instance keeps nothing on devices

- **GIVEN** an instance where "Let apps work offline" was never turned on, and an app with an offline declaration
- **WHEN** an app user opens the app
- **THEN** no service worker is registered and no records are stored in the browser

### Requirement: A maker declares what goes offline (REQ-BQOM-003)

With offline allowed, a maker SHALL be able to declare, per schema, which records
an app takes along (a filter, at most 500 records) and for how long a copy may be
used (at most 72 hours), and which form pages work offline. The app SHALL register
a worker scoped to its own run path only, and SHALL never serve a record read from
the worker's cache.

#### Scenario: Today's inspections go along

- **GIVEN** the app `toezicht` with a take-along of schema `inspection`, filter "assigned to me, planned today", 50 records, 12 hours
- **WHEN** an inspector opens the app with a connection in the morning
- **THEN** their inspections for today are stored on the device with the download time and a 12-hour expiry

### Requirement: App users work offline and sync later (REQ-BQOM-004)

Without a connection, an app user SHALL be able to open taken-along records, shown
with the time they were downloaded, and submit forms declared offline. Submissions
SHALL wait on the device and SHALL be sent when the connection returns, in order.
A submission the server refuses as a conflict SHALL be kept for a person to settle,
never dropped or overwritten silently. When the app opens without a session, the
app's records on the device SHALL be removed.

#### Scenario: An inspection report made in a basement arrives

- **GIVEN** an inspector with today's inspections on the device, in a building without signal
- **WHEN** they open inspection "Kerkstraat 12", fill in the offline report form and submit, and later walk outside
- **THEN** the form says it is waiting to sync, and once the connection returns the report is saved on the server and leaves the queue

### Requirement: App users see what is still on the device (REQ-BQOM-005)

An app with offline forms SHALL have a "Waiting to sync" page listing what is still
on the device, with waiting and stuck counted apart, a retry for stuck items, and
the conflicts to settle.

#### Scenario: A stuck submission is visible

- **GIVEN** a submission refused three times by the server
- **WHEN** the inspector opens "Waiting to sync"
- **THEN** the page shows "0 waiting, 1 stuck" and offers "Try again" on the stuck one

### Requirement: The export writes a mobile app project (REQ-BQOM-006)

The export SHALL offer "Mobile app project", which writes an iOS and Android
wrapper project with the app's name, an editable bundle id, its icons and splash
screen, opening this instance's run path, and sending every link outside the
instance to the system browser. Buildiq SHALL NOT build, sign or publish the
store apps.

#### Scenario: An organisation takes the project to the stores

- **GIVEN** a maker exporting `toezicht` with "Mobile app project" on
- **WHEN** the export finishes
- **THEN** the archive holds `mobile/` with the project, the icons and a README on building and signing, and the project's allowed origin is this instance only
