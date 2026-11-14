<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Page buttons — actions and status buttons

The page designer adds buttons to index and detail pages that do real work.
Each button is a typed manifest action the nextcloud-vue renderer runs; no
code is written.

## What a button can do

Pick one in the action editor, then fill in only what that choice needs:

| Choice | Manifest `type` | What you fill in |
| --- | --- | --- |
| Change fields on this record | `object-op` (`op: patch`) | The fields to set and their values; optionally ask to confirm |
| Start a flow | `run-node` | A flow the app declares (App settings → Flows) and the node to start |
| Open a form | `open-form` | The register and schema the form creates a record in |
| Go to a page | `open-page` | A page of this app |
| Open a link | `navigate` | The URL |
| Export | `export` | The formats offered (csv, excel, json) |

Actions of other types (`handler`, `agent`, `api-call`, …) are shown read-only
and kept as they are; edit them in Raw JSON.

Every button can be shown **only when** a condition on the record holds, with
the same condition builder as form fields (`visibleWhen`).

## Where buttons go

- **Index pages**: the *Actions* section writes `config.actions`.
- **Detail pages**: the *Buttons* section writes `config.headerActions`; the
  buttons appear in the page header's Actions menu.

## Status buttons (detail pages)

Moving a record to another status goes through its lifecycle, so OpenRegister
decides which moves are allowed. Switch on **Status buttons** to write
`config.lifecycleActions: {field: 'status'}`: the page then offers every
transition the lifecycle allows from the record's current status. List
transitions by hand to give them your own labels and confirm questions; only
the listed ones are offered then.

A *Change fields* button may not set the status field: the editor refuses it
and points you to status buttons, because a plain field change would bypass
the lifecycle's rules.

## Older apps

Actions saved before buttons were typed (`target: navigate`, `emit` or `none`)
open in their typed form: `navigate` with a page route becomes *Go to a page*,
otherwise *Open a link*; `emit` is kept as a read-only `handler`; `none` did
nothing and is dropped. The typed form is saved the next time you save the
page.
