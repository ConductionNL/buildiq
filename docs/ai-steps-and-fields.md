<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# AI steps and AI fields

Buildiq can let a language model classify, summarise, extract, translate or
answer an instruction over a record, and write the answer back onto that same
record. There are two ways in:

- **Ask AI**, an action in the automation composer (Automations page, *New
  automation* or *Edit*, action type *Ask AI*).
- **AI fields**, a section of the schema designer under *Calculations*: a
  field whose value the AI fills from other fields of the same record.

Buildiq does not call a model itself. Each AI step compiles to an OpenRegister
flow with three nodes: the trigger, Hermiq's `hermiq.agent-step`, and
`openregister.object-write` in update mode on the record that started the
run. Hermiq runs the model with the agent you pick; OpenRegister runs the flow
and keeps its run log.

## What you need

- **Hermiq installed.** Without it OpenRegister's node catalogue has no
  `hermiq.agent-step`; *Ask AI* and *Add an AI field* are disabled with
  "Install Hermiq to use AI steps.", and saving an automation that already
  holds an AI step fails without creating a flow.
- **A Hermiq agent without tools.** The pickers list only active agents with
  an empty tool list: an AI step answers, it does not act. The agent decides
  the model and provider.

## Which fields are sent

Only the fields you name as **Fields the AI reads** are sent, never the whole
record. The composer shows the list before you save ("Sends omschrijving to
the AI"). Buildiq writes the prompt itself: a fixed English instruction for the
preset, then each source field as a `{{field}}` placeholder that Hermiq fills
from the record. A placeholder for any other field, in your own instruction,
in a label or in a language, is refused on save.

A step cannot write its answer into a field it reads.

## The Ask AI presets

| Preset | Writes | Extra settings |
| --- | --- | --- |
| Classify | one label into the target field | the labels; the target field must be a choice list of exactly those labels, or the save fails with "Make *field* a choice list of your labels first." An answer outside the labels fails the write step and leaves the record unchanged. |
| Summarise | a short summary into the target field | none |
| Extract fields | each listed field, under its own name | the fields to fill |
| Translate | the translation into the target field | the language |
| Own instruction | the answer into the target field | the instruction |

## AI fields

An AI field is a read-only property: forms show it but nobody types into it.
Its operations are translate, categorise, clean, sentiment, summarise and own
instruction. Sentiment writes `positive`, `neutral` or `negative`; clean
corrects spelling and layout of the source text and keeps its meaning, and
leaves the source field itself unchanged. Categorise and sentiment fields are
declared as choice lists of their labels.

Adding an AI field declares the property on the schema. When you save the
schema, Buildiq saves an automation with `origin: ai-field` that fills the
field. These automations are not listed on the Automations page; the schema
designer is where they are edited. Removing the field removes its automation
and, through its provenance, its flow.

## When it runs

| Trigger | Runs |
| --- | --- |
| Object created | once, on the new record |
| Object updated | only when one of the step's source fields changed, so writing the answer does not start it again |
| Manual | when started by hand on a record |

Deletions, lifecycle transitions and schedules cannot carry an AI step. An AI
field runs when a record is created and again when one of its source fields
changes. Adding an AI field does not fill records that already exist.

Every run is a model call, so it takes time. Runs are asynchronous flow runs:
the save that started one does not wait for the answer.
