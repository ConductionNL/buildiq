---
kind: code
---

## Why

`tests/e2e/chat-companion-streaming.spec.ts` carries two tests excluded on
this reason:

```
Streaming surface not yet wired — see openspec/changes/ai-chat-companion-streaming/
```

**That path did not exist.** Not in this repo, not in `.github`,
`openregister` or `hermiq`, and a fleet-wide code search finds the string
referenced only by the skip message that cites it. The tests were deferred
to a tracker nobody had created, so nothing could ever close them.

They also assert nothing today — both bodies are empty, carrying only a
comment describing the intended assertion:

```js
test('partial response text appears before the call completes', async ({ page }) => {
    // Long-prompt test: ask for a multi-paragraph answer, assert the
    // assistant bubble's text grows over time rather than appearing
    // all at once.
})
```

So enabling them as they stand would turn two green ticks on for
assertions that were never written — worse than the skip.

The hydra skip-discipline gate classifies both as **V2** ("CI decides this
state: impossible for the app under test, it IS the head commit — assert
it or drop the test, do not stand down"). Its verdict is right, and this
change is the missing half: the work the tests were waiting for, written
down so the exclusion points at something real.

## What Changes

The non-streaming chat flow already ships and is covered: the FAB, panel,
user bubble and the Thinking indicator with its three animated dots are
asserted by the five passing tests in the same file. What is missing is
**incremental delivery** — today the assistant bubble appears in one
piece when the call returns.

This change adds:

1. Token events from the orchestrator, so the assistant bubble grows
   while the call is still in flight.
2. A heartbeat on long-running calls, so a slow provider is
   distinguishable from a hung one.
3. The two e2e assertions those make possible, written properly rather
   than left as empty bodies.

## Impact

- Affected specs: `ai-chat-companion`
- Affected code: the chat orchestrator's response path; `CnAiCompanion`
  consumption in `@conduction/nextcloud-vue` (it renders what it is given,
  so a partial-token stream must not require an app-side change here)
- Affected tests: `tests/e2e/chat-companion-streaming.spec.ts` — the two
  currently-empty tests gain real bodies and stop being skipped
