## 1. Establish what the provider supports

- [x] 1.1 Confirm whether the configured provider exposes
      `generateStreamOfText`, and what it yields. The two e2e tests this
      change unblocks were written against an assumed shape; assert the
      real one before writing them.
      > 25 Nov 2026: yes for OpenAI and Ollama. hermiq's `LlphantChatCall::streamChat()` reads LLPhant `generateChatStream()` (the PSR-7 stream behind `generateStreamOfText`) and emits one `token` frame `{delta}` per chunk; a provider that cannot stream throws `MissingFeatureException` and the turn answers whole (the fallback). The tests assert that shape (`event: token`, `event: heartbeat`, `event: final`).
- [x] 1.2 Decide the heartbeat interval. The skipped test's comment
      assumed 15s and a 35s prompt; that pairing is a guess and should be
      replaced by a measured one.
      > Decided 25 Nov 2026: 15 s, as hermiq's `ChatStreamController::forwardWithHeartbeat()` already does (ADR-034 design D3), plus one heartbeat right after the headers. That first frame is what guarantees a heartbeat before completion on every call, so test 4.2 needs no 35 s prompt.

## 2. Emit token events

- [x] 2.1 Stream tokens from the orchestrator's response path while the
      provider call is in flight.
      > Built in hermiq: `ChatStreamController::streamChannel()` forwards `StreamYieldChannel::onToken` as `token` frames while the provider call is in flight. Nothing in buildiq: its FAB talks to hermiq (`DEFAULT_CHAT_APP_ID`).
- [x] 2.2 Verify `CnAiCompanion` needs no app-side change to render them —
      it renders what it is given, and a partial-token stream should not
      require Buildiq to special-case it.
      > Verified: nextcloud-vue `useAiChatStream` appends each `token` delta to `currentText`, which `CnAiMessageList` renders in the streaming bubble (`aria-live="polite"`); buildiq passes nothing extra.

## 3. Emit a heartbeat

- [x] 3.1 Emit a heartbeat event on a call outstanding longer than the
      interval fixed in 1.2.
      > Built in hermiq: a `heartbeat` frame right after the headers, and `forwardWithHeartbeat()` before any frame after 15 s of silence; `useAiChatStream` takes `heartbeat` without rendering it.

## 4. Write the two tests that are currently empty

- [x] 4.1 `partial response text appears before the call completes` —
      today an empty body. Assert the bubble's text grows over time and
      never shrinks or reorders.
      > Written, runs in nightly: records every text of the streaming bubble with a MutationObserver, asserts a non-empty strictly shorter prefix showed before the final answer and that each step starts with the previous one. Skips only without a chat backend, or when the backend answered without streaming.
- [x] 4.2 `long-running call surfaces at least one heartbeat to the
      frontend` — today an empty body. Assert at least one heartbeat
      frame reaches the frontend before completion.
      > Written, runs in nightly: reads the `/api/chat/stream` body and asserts an `event: heartbeat` frame before `event: final`.
- [x] 4.3 Remove the `test.skip(...)` guard from the
      `AI Chat Companion — true streaming` describe block once 4.1 and
      4.2 assert something. Until then the guard MUST stay: enabling
      empty tests would report coverage that does not exist.
      > Done: the guard is gone now that both bodies assert; the block skips, like the rest of the file, only when no chat backend answers its health check.
