# English vocabulary for openbuild

> Implements `hydra/openspec/changes/fleet-english-vocabulary`.

## Why

Scan found **0 Dutch-named schemas and 4 Dutch property names**:
`beschrijving`, `naam`, `regels`, `geraaktRegels`.

## What changes

| Dutch | English |
|---|---|
| `naam` | `name` |
| `beschrijving` | `description` |
| `regels` | `rules` |
| `geraaktRegels` | `matchedRules` |

⚠️ `regels` is ambiguous in Dutch — it means both **rules** and **lines** (as in
invoice lines). shillinq's `regels` meant *lines*; openbuild's appears to mean
*rules* (`geraaktRegels` = rules that matched). Confirm from the code before
renaming: picking the wrong English word here is worse than leaving the Dutch,
because a plausible-but-wrong name misleads every future reader.

## Tasks

- [ ] Confirm `regels` = rules (not lines) by reading the consumers.
- [ ] Rename the four properties.
- [ ] Check lib/ + src/ for Dutch in class, method and file names.
- [ ] `l10n/nl.json` + `check-l10n`.
- [ ] Full suite + hydra gates.

## Risks

- Renamed keys read with `??` fail silently.
- `regels` → wrong English word is a documentation defect that outlives the rename.
