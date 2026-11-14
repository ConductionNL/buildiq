---
sidebar_position: 20
title: Letting users restore a record
description: Show a record's history on a detail page and let app users put a record back to an earlier state.
---

# Letting users restore a record

Every change to a record is kept in its history. On a detail page you decide whether app users see that history, and whether they may put a record back to how it was after an earlier change.

1. Open the detail page in the page designer.
2. Under **Record history**, turn on **Show record history**. The page's sidebar gets a History tab. A page without a sidebar gets one.
3. To let users restore, also turn on **Let users restore an earlier version**. It is off until you turn it on, so apps made before this option existed show the history without a restore button.

In the built app, a user opens a record's History tab, expands an entry and chooses to restore the record to the state after that entry, after confirming. The restore is saved as a new version, so it appears as the newest entry and nothing in the history is lost.

Who may restore is decided by OpenRegister, not by the page: only a user who may edit the record can restore it, and a record that someone else has locked cannot be restored until the lock is released. Restoring a record does not change other records that point at it.
