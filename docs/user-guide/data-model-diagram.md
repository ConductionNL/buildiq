---
sidebar_position: 10
title: Data model diagram
description: Read an app's data model as a diagram of its schemas and their relations.
---

# Reading an app's data model as a diagram

The **Schemas** page of an app lists the schemas of the version you are working on. Next to
**List** there is a **Diagram** button that shows the same schemas as a picture:

- **One box per schema**, with its title and its fields (name and type). A box lists twelve
  fields and then says how many more there are. When a register has more than 50 schemas the
  boxes show their titles only, to keep the picture readable.
- **One line per relation**, labelled with the relation's name and `1` (links one) or `n`
  (links many). A relation declared on both ends shows as one line with both names.
- **Relations come from two places**: the relations you add in the schema's relation editor,
  and fields that point at another schema.
- **Schemas in another register** stand at the right-hand edge. One you may not read shows
  as an untitled box and does not open. A reference that points at nothing shows as a box
  marked **Not found**, so a broken link is easy to spot.

The diagram is read only: you cannot make a relation by drawing a line. Add or change
relations in the relation editor of the schema.

## Opening a schema

Click a box, or move to it with <kbd>Tab</kbd> and press <kbd>Enter</kbd>, to open that schema
in the schema designer. You stay on the same version.

## The table under the diagram

Under the diagram, a table lists every relation as *from, relation, to, one or many*, and each
schema without relations on its own row. Every row has an **Open** button. The table holds the
same information as the picture, so you can use it with the keyboard or a screen reader, and it
stays complete when the boxes are folded.

## Data registers

If the app is bound to shared data registers (in the app's settings), a **Register** switch
appears above the diagram. Pick a data register to see its schemas and their relations.

## Sharing a link

The address keeps `view=diagram`, so a link you copy opens the diagram directly.
