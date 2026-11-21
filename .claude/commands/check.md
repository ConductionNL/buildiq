---
description: Run the full quality suite (PHP gate plus frontend lint)
---

Run the quality suite from the repo root and report every failure with file and line.

1. `composer check:strict` (lint, phpcs, phpmd, psalm, phpstan, PHPUnit and the shared gates)
2. `npm run lint`
3. `npm run stylelint`
4. `npm run check:manifest`

Needs `composer install` and `npm ci` first. Fix what you can; for style issues try
`composer phpcs:fix` or `npm run lint-fix`, then run the failing step again.
