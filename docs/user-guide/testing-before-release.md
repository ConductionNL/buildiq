---
sidebar_position: 10
title: Testing an app before release
description: Run an app version's tests, write record tests, and see why a release was stopped.
---

# Testing an app before release

Every version of an app has a test suite. Releasing a version, or promoting one onto
production, runs it first. When a check fails, the release stops and production stays as
it was.

## What the suite checks

- **Structure.** Every page's register and schema exist, every column of an index page and
  every field of a detail page names a property its schema has, and every menu item leads
  to a page of the version. A failure names the page or menu item and what is missing, for
  example: page `permits`, column `kvkNumber`, no such property on `permit`.
- **Rule sets.** The stored test cases of every rule set the app owns. A failing case is
  named by its rule set and case, for example `permit-fees: Large build pays 1200`.
- **Record tests.** Sample records you write per schema, with whether the schema should
  accept or refuse them. Record tests are reported as **skipped** for now: OpenRegister
  cannot yet check a record without saving it, and a test that did not run is never shown
  as passed.

## The Tests tab

Open the app and choose **Tests** in the sidebar.

- Pick a version to see its **last run**: when it ran, what started it (run by hand, a
  release or a promotion), how many checks passed, failed and were skipped, and each check
  with its message.
- **Run tests** runs the suite of that version now. Owners and editors can run it; viewers
  see the results.
- Under **Record tests**, owners and editors add, edit and remove record tests: a name, the
  schema, the sample record as JSON, whether it should be accepted or refused, and for a
  refusal the fields it should be refused on. The record is checked, never saved.
- Under **Rule sets**, **Open tests** opens a rule set's test sandbox, where you add test
  cases and run them.

Every run is kept, so the tab always shows the latest one per version.

## When a release is stopped

When you release a version from **Version history** and a check fails, a dialog lists the
failed checks and nothing is released. Fix the problem and release again.

An owner can choose **Release anyway** by writing a reason, for example "Column removed on
purpose, page fixed in next release". The version is then released, and the test run keeps
the failures, the owner and the reason. Editors cannot release past failing checks.

The same check runs when a version is promoted onto production.
