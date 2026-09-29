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

## Calculated fields

A calculated field gets its value from the record's other fields, such as a
total that is the quantity times the unit price. Open a schema in the schema
designer and go to Calculations:

1. Type the name of the field and choose "Add calculated field". A name the
   schema does not have yet becomes a new field.
2. Choose the type of the result: text, whole number, number, yes or no, or a
   date.
3. Build the expression. Each part is a field of the record, a value you type,
   or an operator such as multiply or add. An operator's parts are built the
   same way, so you can nest them.
4. Fill in sample values and choose "Try". The result shows below, and nothing
   is saved.
5. Save the schema. OpenRegister checks the calculation; when it refuses, the
   reason shows next to the field and your edit stays in the designer.

OpenRegister stores the calculated value with each record, so a list can sort
and filter on it. A calculation written in the register file itself is listed
too, but it can only be changed in that file.

## Access

Per-record access is enforced by OpenRegister, not by your app. A user who may
not read a record does not receive it, whichever page asked. That holds for the
API as well as the UI, so a page cannot leak what a permission denied.

## Changing a schema later

Add a field and existing records simply lack it. Widgets reading that field show
nothing for the older rows rather than failing. Plan for that when you make a
new field required.

Next: make something happen when the data changes. See [Flows](./flows.md).
