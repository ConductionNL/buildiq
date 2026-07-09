---
kind: code
---

## Why

`tests/l10n/check-l10n-parity.js` is a well-built, already-working gate: it asserts every required locale (all official EU languages + Russian/Turkish) translates every English source key in `l10n/en.json`/`l10n/en.js`, with a clear rationale in its own docblock — "Without this, a new English string ships and the other languages silently fall back to English with a green pipeline". Running it today (`node tests/l10n/check-l10n-parity.js`) **exits 1**: `l10n/nl.json` is missing **136 of 889** keys (e.g. `App`, `Create an app`, `Open {name}`, `Updated`, `OpenConnector`, `OpenRegister`, and the entirety of the newer walkthrough-designer / manifest-layers / app-override strings — `Walkthrough designer`, `Design walkthrough`, `Record from app`, `Manifest layers`, `Your personal delta, layered over the admin delta.`, etc.). Dutch is the primary locale for Conduction's government customers (NL Design positioning), so this is the highest-impact locale gap in the app, not a cosmetic one.

The gate that would have caught this at merge time is **not wired into CI**. `.forgejo/workflows/tests.yml:101-113` runs a "HARD GATE 2 — l10n extraction-drift check" that calls `tests/l10n/check-l10n.js` (asserts every `t()` source string exists in `l10n/en.json` — an English-only drift check). `tests/l10n/check-l10n-parity.js` (the cross-locale completeness check) has **zero references** anywhere in `.forgejo/workflows/` or `package.json` scripts (`grep -rn "check-l10n-parity" package.json .forgejo/` returns nothing) — it is dead code from CI's perspective: correct, currently failing, and silently never run. Every feature added since it was written (walkthrough editor, manifest layers, app overrides) shipped with a green pipeline while quietly growing the nl gap to 136 keys.

## What Changes

- **Wire `check-l10n-parity.js` into CI** as a new step/job in `.forgejo/workflows/tests.yml` (alongside the existing `l10n-check` hard gate). Scope it with `L10N_REQUIRED_LOCALES=nl` (the script already supports this override) rather than the full default set — `de`/`fr`/`es`/etc. are 304 keys behind, a much larger pre-existing gap out of scope for this change; enabling the full default set today would break CI for everyone. This is a ratchet: nl parity is enforced now, and widening `L10N_REQUIRED_LOCALES` to more locales is tracked as follow-up work once each is backfilled.
- **Backfill the 136 missing Dutch translations** in `l10n/nl.json` so the gate passes today (translate the missing English source strings; NL Design / government terminology conventions — verify against existing nl.json neighbours for tone/terminology consistency).
- **No BREAKING changes** — this only adds translations and a CI check; no code behaviour changes.

## Capabilities

### New Capabilities

_None._

### Modified Capabilities

- `frontend-foundation`: adds a requirement that the l10n parity gate runs in CI and that `l10n/nl.json` has full key parity with `l10n/en.json`.

## Impact

- `l10n/nl.json` — 136 keys added.
- `.forgejo/workflows/tests.yml` (or `package.json` + the workflow) — one new CI step invoking `tests/l10n/check-l10n-parity.js`.
- No other locale is fixed by this change (`de`, `fr`, `es`, etc. are 304 keys behind — a pre-existing, much larger gap not scoped here; the gate, once wired, will surface them for a follow-up rather than silently pass).
