## ADDED Requirements

### Requirement: Dutch locale has full translation key parity, enforced in CI

`l10n/nl.json` (and `l10n/nl.js` if it carries a separate key set) SHALL contain a non-empty translation for every key present in `l10n/en.json`/`l10n/en.js`. CI SHALL run `tests/l10n/check-l10n-parity.js` scoped to at least the `nl` locale (`L10N_REQUIRED_LOCALES=nl` or wider) as a required check, so a PR that adds an English string without its Dutch translation fails CI rather than silently falling back to English at runtime.

#### Scenario: New English string ships without its Dutch translation

- **GIVEN** a PR that adds a new `t('openbuild', '...')` call with a new English source key
- **WHEN** the PR does not add the corresponding key to `l10n/nl.json`
- **THEN** the `check-l10n-parity` CI step SHALL fail, blocking merge

#### Scenario: nl.json is at full parity today

- **WHEN** `node tests/l10n/check-l10n-parity.js` (or its CI-scoped invocation) runs against `l10n/nl.json`
- **THEN** it SHALL report 0 missing keys and 0 empty values for the `nl` locale
