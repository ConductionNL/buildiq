# Grid pages and saved views

Two ways to work with many records at once in an app you build: a grid page,
where app users change values in place like in a spreadsheet, and views, which
save a filtered and sorted list of records.

## Grid pages

A grid page shows one row per record of a schema. The columns you mark
editable can be changed in place: click a cell (or press Enter or F2 on it),
type, and press Enter. The value is saved to that record at once.

### Add a grid page

1. Open the app in the page designer and choose **Add page**.
2. Pick **Grid (edit records in place)**, give the page a title and confirm.
3. In the grid editor, pick the register and the schema.
4. Add the columns to show.
5. Under **Editable columns**, tick the columns app users may change.
6. Publish the app.

A grid page is stored as a `custom` page whose `component` is `record-grid`;
its `config` holds `register`, `schema`, `columns` and `editable` (the keys of
the editable columns).

### What app users can change

A cell can be edited only when all of these hold:

- you ticked its column under **Editable columns**;
- the schema does not mark the property read-only (metadata columns such as
  the creation date are always read-only);
- the server accepts the change for this user. When it refuses, the cell gets
  its old value back and the grid says why.

Before saving the first change on a row, the grid takes that record's lock. If
a colleague is editing the record, the row says who, and a change to it is not
saved.

The grid loads 50 records per page; use **Previous** and **Next** to move
through larger schemas. It edits existing records only: it does not add or
delete records.

## Saved views on index pages

The index page editor has a **Saved views** section.

- **Let app users save their own views** turns on the views control on the
  page: an app user saves the current filters and sort under a name and picks
  it again later.
- **Add view for everyone** defines a view every app user of the page finds in
  the views list. Give it a name, add filters (a property and the value it must
  have) and choose what it sorts on, ascending or descending.

Views for everyone are stored with the page, as `config.defaultSavedViews`, so
they are published, versioned and copied together with the app. App users can
apply them or save a copy of their own, but cannot change or delete them.

For example, a `Requests` page with a view "Open requests" filtered on
`status` = `open` and sorted on `date`, descending, shows every app user the
open requests, newest first.
