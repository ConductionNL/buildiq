---
sidebar_position: 6
title: Offering an app in several languages
description: Write an app's labels once, translate them per language, and let each user see the app in their own Nextcloud language.
---

# Offering an app in several languages

An app you build has one language its labels are written in, and any number of
languages those labels are translated into. Each user sees the app in their own
Nextcloud language when the app has it, and in the written language when it
does not. A label without a translation shows as written; the rest of the page
is still translated, so the fallback is per label, never per page.

## 1. Set the app's languages

Open the app, choose **Settings**, and find **Languages**.

- **Labels are written in** — the language you typed the menu, page titles,
  columns and buttons in. For an app built in Dutch, pick Dutch.
- **Add a language** — each language the labels should be translated into.

Removing a language asks you to confirm first: its translations are dropped
with it. Only the app's owners and editors can change its languages.

## 2. Translate the labels

Open the page designer and choose **Translations** in the toolbar. You see
every label of the version once, where it is used, and one column per
language.

- The counts at the top say how many labels each language is still missing.
- **Missing only** hides what is already translated.
- **Page** narrows the list to one page, or to the menu.
- A column that shows a schema property's own title is marked **From the
  schema**. Its text belongs to the schema, so it cannot be translated here.

A translation is saved like any other change in the designer: **Undo**
takes it back, and **Save & open preview** stores it with the version.

The same text in two places gets one translation. That is how Nextcloud
translates everything; when one word needs two meanings, write two different
labels.

Changing a label's written text starts it over: the old translations belonged
to the old text, so the label shows as missing until you translate it again.

## 3. Check it in the preview

When the app has more than one language, the live preview gets a **Preview
language** switch. Pick a language to see the page as a user with that
Nextcloud language would. The designer around the preview stays in your own
language.

## Where translations go

Translations are part of the version, in the manifest's `i18n` block. They
travel with it when you promote a version, sync it with GitHub, or export the
app. An exported app gets one `l10n/<language>.json` file per language, merged
with the template's own strings, so it translates like any other Nextcloud
app.

What is not translated here: the records themselves (that is OpenRegister's
multilingual content), and schema property titles a page shows without a label
of its own.
