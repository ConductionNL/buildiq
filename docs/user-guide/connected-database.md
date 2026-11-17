---
sidebar_position: 20
title: Using a connected database
description: Build an app on tables an external database already holds, and add a query as a table.
---

# Using a connected database

An administrator can connect an external database (PostgreSQL, MariaDB,
MySQL or SQLite) in OpenRegister under **Sources** and introspect it. Every
table then shows up as a schema of one register, and Buildiq can use that
register like any other: no copy of the data, no import. Buildiq never opens a
connection itself and never sees the password; every read and write goes
through OpenRegister.

## Bind a database to an app

In the app's settings, under data registers, choose **Connect a database** and
pick the database. It is added as a data register of the app. In the page
editor its register is marked **Connected database** in the register list.

Whether the database takes changes is the administrator's setting **Allow
writes** on the source in OpenRegister. Buildiq reads it every time the editor
opens and never stores it in the app:

- **Writes allowed**: pages on the database work like any other page.
- **Read only**: list pages get no add, edit or delete buttons and detail pages
  no edit button. The controls in the page editor are locked with a note, so
  the app never offers a button that can only fail. When the administrator
  turns writes on, the controls unlock the next time the editor opens.

## Start an app from a database

In **Create app**, tick **Start from a database** on the first step, pick the
database and tick the tables you need. The review step lists the pages that
will be made. On **Create**, every ticked table gets a list page (its first
eight columns, which you can change in the column builder), a detail page and
a menu entry, and the database is bound as a data register. Every version of
the app (development, staging, production) reads the same database.

The server checks the choice again: a register that is not a connected
database, or one you cannot read, is refused and nothing is created.

## Add a query table (administrators)

On the schema list of an app, an administrator sees **Add a query table**:

1. Pick a connected database.
2. Write one SQL query, for example
   `SELECT district, count(*) AS open FROM permits WHERE status = 'open' GROUP BY district`.
3. Choose **Run preview**. The first rows (at most 50) and the columns show.
4. Give the table a name and choose **Save as table**.

The query becomes a read-only table of the database's register, and pages bind
to it like any table. **Save as table** stays off until a preview of the query
as it stands succeeded.

The query runs in OpenRegister on the database's own connection, read only,
with a time limit and a row limit. Only one statement that starts with
`SELECT` or `WITH` runs; anything else is refused with "Only a single read-only
query can run." Someone who is not a Nextcloud administrator does not see the
button, and OpenRegister refuses their preview and save as well.

An administrator can read every table the database user can read. Use a
database user with only the grants the apps need.
