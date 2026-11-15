---
kind: code
depends_on: []
---

# Proposal: data-generated-number-field

## Why

Row `data-auto-number`: give records an automatic, formatted number such as INV-2026-0001. OpenRegister builds it. Its change `generated-identifier` has every build task ticked; the open C41 tasks go past the row (random sequences, foreign identifiers, scheme migration). A string property declaring `x-openregister-generated: { sequence, format, resetOn }` gets the next number on create, under a lock, and is read-only afterwards.

buildiq's schema designer (`src/components/schema-editor/FieldEditor.vue`) cannot set that keyword, so a maker cannot number records without editing the register JSON.

## What changes

- A text field in the schema designer gets an "Automatic number" setting: the counter name (default the schema slug), the format with placeholders `{year}`, `{month}` and `{seq:n}` and a live example, and whether the counter restarts each year.
- `FieldEditor.vue` reads the keyword back and writes it only when the setting is on. A generated field is shown as read-only in form pages.

## Out of scope

- Numbering itself, which OpenRegister owns.
- The C41 extensions of `openregister/generated-identifier`.
