---
sidebar_position: 20
title: Import an app
description: Bring an app exported from one buildiq into another, with or without its records.
---

# Import an app

An app exported from one buildiq (for example production) can be imported into
another (for example a test instance). The import creates a new app that you
own, with the same pages, menu and schemas.

## Import from the Applications page

1. Open the **Applications** page and choose **Import app**, next to **Add app**.
2. Pick the archive, for example `vergunningen-1.2.0.zip`. The dialog shows the
   app's name and how many pages, schemas and records the archive holds. Nothing
   is created yet.
3. Keep the slug or type another one. The slug is part of the app's address:
   lowercase letters, digits and hyphens.
4. Tick **Import records** to bring the records along. It is off by default and
   only offered when the archive holds records.
5. Choose **Import**. The new app opens. With records, a message says how many
   records arrived per schema, for example "40 permit records imported".

## When the import is refused

- **The slug is taken.** The dialog asks for another slug. Nothing was created.
- **The archive is not a valid package.** The dialog shows the error code and
  the file at fault, for example `schemas/permit.json`. Nothing was created.
- **The archive is unsafe or too large.** An archive over 50 MB, with more than
  5,000 entries, an entry outside the package (such as `../config.php`) or a
  symlink is refused as a whole.

The archive is read as data and never run: only the package files
(`openbuild-app.json`, `manifest.json`, the package folders and `data/`) are
read, and any code in it is ignored.

## Records

The records of each schema are written through OpenRegister's register import
into the new app's register. When one schema's records fail, the message names
the schema and how many failed; the app and the other schemas' records stay.

Administrators can do the same from the command line with
`occ buildiq:app:import`; see [App commands](../Technical/app-commands.md).
