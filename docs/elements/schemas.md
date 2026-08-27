---
sidebar_position: 6
description: Schemas are your data. Define the shape field by field, backed by OpenRegister, with no database migration.
---

# Schemas

A schema is the shape of one kind of record. A customer, a ticket, an asset.
Your schemas are your app's data, and every widget that shows anything is
showing a schema.

## Registers and schemas

A **register** is the container. A **schema** is one record type inside it. An
app usually has one register and several schemas.

Both are OpenRegister objects, which is why there is no database migration
step. You add a field and the store accepts it.

## Defining fields

The schema designer takes it field by field: a name, a type, and whether it is
required. Types cover text, numbers, dates, enumerations and relations to other
schemas.

A relation is what lets a detail page show related records, and what lets a
widget on one schema count rows in another.

## Logic belongs on the schema

This is the part that saves the most code. State machines, aggregations,
calculations and notifications are declared as **schema metadata**, not written
as service classes. The rule lives with the data it governs, so it applies
however the data is written.

For logic that outgrows metadata, use a flow. See [Flows](./flows.md).

## Access

Per-record access is enforced by OpenRegister, not by your app. A user who may
not read a record does not receive it, whichever page asked. That holds for the
API as well as the UI, so a page cannot leak what a permission denied.

## Changing a schema later

Add a field and existing records simply lack it. Widgets reading that field show
nothing for the older rows rather than failing. Plan for that when you make a
new field required.

Next: make something happen when the data changes. See [Flows](./flows.md).
