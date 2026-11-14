---
sidebar_position: 10
title: Error log in the page designer
description: See why a page in the preview does not show what you expect.
---

# Error log in the page designer

Under the preview in the page designer sits the **Error log**. It lists the
problems the preview ran into while showing your pages, so you can see why a
page stays empty or shows less than you expect.

## What it shows

Only failures, one entry per problem, with a count of how often it happened:

- **Failed requests**: the page asked for data and got an error back, for
  example because the schema it points at does not exist (404), or the request
  did not complete.
- **Render errors**: a part of the page could not be drawn.
- **Broken bindings**: a column or field points at a property the schema does
  not have.

Each entry says when it happened, on which page, what went wrong and, for a
request, which register and schema it was for.

## Working with the log

- Choose **Everything**, **Failed requests**, **Render errors** or **Broken
  bindings** to narrow the list.
- **Go to the page** opens the page the entry is about in the designer.
- **Copy** puts the entry on your clipboard, to paste into a question for a
  colleague.
- **Reload preview** draws the preview again after you fixed something.
- **Clear** empties the log.

The log keeps the latest 200 entries and lives only in your browser. It is not
saved and nobody else sees it. When it is empty it says "No problems in the
preview."

The marks the designer puts next to a field that is not valid stay where they
are; they are not repeated in the log.

## See also

How a published app reports its health once people use it:
[App health: performance and errors](../Technical/app-health.md).
