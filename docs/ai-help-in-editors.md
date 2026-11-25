---
sidebar_position: 9
description: Ask the AI to write or explain a rule condition, a decision table row, a field mapping or a webhook payload, and generate an app from a requirements document in your Files.
---

# AI help in the editors

Next to the [AI copilot](./ai-copilot.md), Buildiq offers smaller AI help in
the places where makers type expressions, and lets "Generate with AI" read a
requirements document. Both use the same AI provider as the copilot: when the
instance has no text-to-text provider, the buttons below do not show and the
editors work as before.

## Ask AI in the rule and automation editors

An **Ask AI** button sits beside four fields:

| Editor | Field |
| --- | --- |
| Condition-action rule editor | Condition (FEEL) |
| Decision table editor | Each rule row, and beside **Add rule** to propose a new row |
| Automation editor, record step | Field mapping (JSON) |
| Automation editor, webhook step | Payload template (JSON) |

Click it, describe what you want in plain words ("applicants younger than 18")
and pick **Write it**. The dialog shows a proposal with a one-line
explanation. **Accept** puts it in the field; **Reject** leaves the field as it
was. Nothing is saved until you save the editor, so you can still change or
discard what you accepted.

**Explain** describes what the current value does, in plain words, without
proposing a new one.

### What Buildiq checks before you see a proposal

- A condition must be valid FEEL for the rules engine.
- Each cell of a decision row must be a valid cell condition, and the row may
  only use the table's own input columns.
- A field mapping must be JSON whose keys are properties of the target schema.
- A payload template must be JSON.

When an answer fails the check, Buildiq asks the AI once more with the
reason. If the second answer fails too, the dialog shows why and no proposal.

### What the AI sees

Only names: the input column names and payload paths of the table, and the
target and trigger schema names. Never the values in your records. The help
is only for owners and editors of the app, and a target schema is only looked
up among that app's own schemas.

## Generate an app from a document

In the creation wizard, **Generate with AI** has an **Add a document** button.
It opens the Nextcloud file picker; pick the requirements document and click
**Generate**. You may leave the description empty when the document says it
all.

- Plain text (`.txt`) and Markdown (`.md`) documents are always read. PDF,
  Word (`.docx`) and OpenDocument text (`.odt`) are read through OpenRegister,
  on an OpenRegister that offers its file text read; elsewhere they are refused
  with a message naming the types that work. A PDF, Word or OpenDocument file
  can be at most 20 MB.
- A document can be at most 60,000 characters. A longer one is refused, never
  cut; shorten or split it.
- The document is opened as you. A file you cannot open yourself is not
  found.

The review lists **User stories** first: who the app is for and what they want
to do, each with the schemas and pages that serve it. After you confirm, the
app keeps those stories, and its detail page shows them in a **User stories**
widget, so a colleague can read why a page exists.
