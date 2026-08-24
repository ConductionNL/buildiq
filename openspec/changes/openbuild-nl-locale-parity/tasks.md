## 1. Backfill Dutch translations

- [ ] 1.1 Run `node tests/l10n/check-l10n-parity.js` locally to get the exact list of 136 missing `nl` keys (see run output in proposal.md for the full list at time of writing).
- [ ] 1.2 Translate each missing key into Dutch in `l10n/nl.json`, matching the terminology already used by neighbouring `nl.json` entries (e.g. existing "app" / "virtual app" / "manifest" translations already present).
- [ ] 1.3 Also update `l10n/nl.js` (the `OC.L10N.register` frontend variant) if it carries the same key set separately — confirm with `tests/l10n/check-l10n.js` which set(s) it checks.
- [ ] 1.4 Re-run `node tests/l10n/check-l10n-parity.js` and confirm `nl` no longer appears in the FAIL output (0 missing, 0 empty).

## 2. Wire the parity gate into CI

- [ ] 2.1 Add a new step to `.forgejo/workflows/tests.yml` (near the existing `l10n-check` job at line ~103) that runs `L10N_REQUIRED_LOCALES=nl node tests/l10n/check-l10n-parity.js`.
- [ ] 2.2 Optionally add a `"test:l10n:parity": "L10N_REQUIRED_LOCALES=nl node tests/l10n/check-l10n-parity.js"` script to `package.json` for local/dev convenience, and have the CI step call the npm script instead of node directly.
- [ ] 2.3 Confirm the new CI step fails on a PR that removes an nl key (regression check) and passes on current `main`/`development` after task 1 lands.

## 3. Follow-up tracking

- [ ] 3.1 File a GitHub issue tracking the remaining locale gap (`de`/`fr`/`es`/etc. at 304 missing keys) so `L10N_REQUIRED_LOCALES` can be widened incrementally — out of scope for this change but must not be silently dropped.
