## 1. Establish what the provider supports

- [ ] 1.1 Confirm whether the configured provider exposes
      `generateStreamOfText`, and what it yields. The two e2e tests this
      change unblocks were written against an assumed shape; assert the
      real one before writing them.
- [ ] 1.2 Decide the heartbeat interval. The skipped test's comment
      assumed 15s and a 35s prompt; that pairing is a guess and should be
      replaced by a measured one.

## 2. Emit token events

- [ ] 2.1 Stream tokens from the orchestrator's response path while the
      provider call is in flight.
- [ ] 2.2 Verify `CnAiCompanion` needs no app-side change to render them —
      it renders what it is given, and a partial-token stream should not
      require Buildiq to special-case it.

## 3. Emit a heartbeat

- [ ] 3.1 Emit a heartbeat event on a call outstanding longer than the
      interval fixed in 1.2.

## 4. Write the two tests that are currently empty

- [ ] 4.1 `partial response text appears before the call completes` —
      today an empty body. Assert the bubble's text grows over time and
      never shrinks or reorders.
- [ ] 4.2 `long-running call surfaces at least one heartbeat to the
      frontend` — today an empty body. Assert at least one heartbeat
      frame reaches the frontend before completion.
- [ ] 4.3 Remove the `test.skip(...)` guard from the
      `AI Chat Companion — true streaming` describe block once 4.1 and
      4.2 assert something. Until then the guard MUST stay: enabling
      empty tests would report coverage that does not exist.
