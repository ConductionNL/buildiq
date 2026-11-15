<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->

# Build the form a case type asks

One case type can carry several forms. The client fills in one, the desk
fills in another, an architect acting for a client fills in a third. Each
one asks its own questions, in its own order, through its own channel.

This page is about building them. The form is an object of its own, so it
is not the case type and not the schema: changing what a form asks never
touches the data model underneath it.

## Where it lives

Open the page designer, pick a **detail** page, and set its register, its
schema and the case type under **Applies to**. The forms for that type
appear below, under **Forms for this case type**.

Add one and it opens straight away. A form with no fields asks nothing, so
there is no reason to leave it closed.

## What a form carries

**Name.** How a consumer asks for this form. Three forms for one case type
is the point, and the name is how you tell them apart.

**Who fills it in.** The client, a colleague, or a supplier. A consumer
that asks for the client default gets the one you marked here.

**Which property carries the channel.** Name the property on the other
app's schema that records the intake channel, the way `typeProperty` names
the one that records the type. Buildiq reads its values and the channel
below becomes a list.

Buildiq never guesses which property that is. A save refused because a
property name merely looked channel-ish is worse than a save that is not
refused.

**Intake channel.** Which way in this form serves: the portal, the desk,
the post room. Leave it empty and the form serves every channel.

**The intake picks this one.** At most one form per type, audience and
channel. A second one is refused, and the refusal names the first.

**State.** A draft is stored and not served. Publish it and a consumer can
ask for it.

## Who may open it

Tick **anyone may fill this in** and the form may be shown to somebody who
is not signed in. Buildiq does not serve it: the portal does, and it reads
this flag.

**What they read after sending** is the sentence that ends an application.
Write it here, because it belongs to the form and not to whoever renders
it.

## Sections and fields

A form lists only the fields you put on it, in the order you put them. It
never falls back to the schema's own property order, which would reshuffle
the day somebody adds a property.

Add a section, give it a heading, and move it with the arrows. The
reference underneath is what fields point at; it follows the heading until
you type your own.

Each field picks a property on the target schema, a label the filer reads,
a type, and the section it sits in. The arrows move it inside its own
section, and the stored order is rewritten to match what you see.

A section that still holds fields cannot be deleted. Move its fields
somewhere else first. Deleting it with fields inside would leave a form
that points at a section it does not declare, and the save refuses that.

## Preset values

A preset sets an answer in advance.

Hide it and the filer never sees the question. The citizen's form for a
building permit records that it came in through the portal, and nobody is
asked a question whose answer was already decided.

Leave it visible and the answer is filled in and still editable.

Buildiq never writes the other app's object. A hidden preset travels beside
the served form and the consumer applies it on submit.

## When the other app's schema cannot be read

The property and channel pickers come from the consuming schema. When
buildiq cannot read it, they become plain text boxes and the editor says
the checks did not run.

That is deliberate. A builder that refused every save because another app
was briefly unreachable is a harder failure than a form that is merely
unchecked, and an empty picker would look exactly like a schema that
declares nothing.

A preset or a field naming a property the other app does not have is saved
with a warning, not refused. The warning says what will happen to the
answer.
