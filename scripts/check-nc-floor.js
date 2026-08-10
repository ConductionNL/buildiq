#!/usr/bin/env node
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * check:nc-floor — the declared Nextcloud floor must match what CI installs on.
 *
 * `<nextcloud min-version>` is enforced at INSTALL time, so it is not prose: it
 * decides whether `occ app:enable openbuild` succeeds. A floor ABOVE the
 * Nextcloud version a CI leg boots makes the enable refuse, and that surfaces
 * ~70 seconds later as *missing tables* — which reads like a migration fault
 * and sends you to entirely the wrong file.
 *
 * This repository has been wrong in both directions:
 *
 *  - #146 lowered the floor to 28 because "this repo tests stable31", and left
 *    the surrounding comment asserting 32. Value and prose disagreed, and the
 *    comment is the part a reader trusts.
 *  - Before that, the floor sat above the version CI installed on — the
 *    openconnector#1172 shape.
 *
 * Deliberately a repo-checkout check and NOT a PHPUnit test. This fleet's
 * PHPUnit job runs against a DEPLOYED copy of the app under
 * `server/apps/openbuild`, which is not the repository: `.github/workflows/`
 * is not part of it. A first attempt at this invariant as
 * `tests/Unit/AppInfo/NextcloudFloorTest.php` went red on all four PHPUnit legs
 * for exactly that reason. The `frontend-checks` matrix runs in the checkout,
 * which is where questions about repository layout can actually be answered.
 */

const fs = require('fs')
const path = require('path')

const REPO = path.resolve(__dirname, '..')
const INFO = path.join(REPO, 'appinfo', 'info.xml')
const WORKFLOW = path.join(REPO, '.github', 'workflows', 'code-quality.yml')

const EXPECTED_NC_FLOOR = 32
const EXPECTED_PHP_FLOOR = '8.3'

/**
 * Read a file or fail loudly. A check that could not run must not look like a
 * check that passed.
 *
 * @param {string} file Absolute path to read.
 * @return {string} File contents.
 */
function read(file) {
	if (!fs.existsSync(file)) {
		console.error('[check:nc-floor] CANNOT RUN: ' + file + ' does not exist.')
		console.error('[check:nc-floor] This is a broken instrument, not a clean result.')
		process.exit(2)
	}
	return fs.readFileSync(file, 'utf8')
}

const info = read(INFO)

// Exactly one <nextcloud .../> element is expected. Asserting the COUNT (not
// just "a match was found") is what stops a second, contradictory declaration
// from hiding behind the first.
const ncMatches = info.match(/<nextcloud\b[^>]*>/g) || []
if (ncMatches.length !== 1) {
	console.error(
		'[check:nc-floor] Expected exactly one <nextcloud> element in appinfo/info.xml, found '
		+ ncMatches.length + '.',
	)
	process.exit(2)
}

const minMatch = ncMatches[0].match(/min-version\s*=\s*"(\d+)"/)
if (minMatch === null) {
	console.error('[check:nc-floor] <nextcloud> carries no min-version: ' + ncMatches[0])
	process.exit(2)
}
const floor = Number(minMatch[1])

const phpMatch = info.match(/<php\b[^>]*min-version\s*=\s*"([^"]+)"/)
if (phpMatch === null) {
	console.error('[check:nc-floor] appinfo/info.xml declares no <php min-version>.')
	process.exit(2)
}
const phpFloor = phpMatch[1]

// The CI legs. Read from the ASSIGNMENT LINE only, never from the surrounding
// comment: the comment above this input names `stable31` while explaining that
// it was REMOVED, so a comment-blind grep reads a leg that does not exist.
const workflow = read(WORKFLOW)
const refsLine = workflow
	.split('\n')
	.map((line) => line.trim())
	.find((line) => line.startsWith('nextcloud-test-refs:'))

if (refsLine === undefined) {
	console.error('[check:nc-floor] No nextcloud-test-refs input found in ' + WORKFLOW + '.')
	process.exit(2)
}

const refs = [...refsLine.matchAll(/stable(\d+)/g)].map((m) => ({
	ref: 'stable' + m[1],
	major: Number(m[1]),
}))

// Positive control on the INPUT. "I found nothing wrong" and "I read nothing"
// are the same output otherwise.
if (refs.length === 0) {
	console.error('[check:nc-floor] Parsed ZERO nextcloud-test-refs out of ' + WORKFLOW + '.')
	console.error('[check:nc-floor] That is a broken parser, not a clean result.')
	process.exit(2)
}

let failed = false

for (const { ref, major } of refs) {
	if (major < floor) {
		console.error(
			'[check:nc-floor] CI installs OpenBuild on ' + ref + ' (Nextcloud ' + major + ') but '
			+ 'appinfo/info.xml declares min-version="' + floor + '". min-version is enforced at '
			+ 'install time, so `occ app:enable openbuild` refuses on that leg and the seed fails '
			+ 'with "is not installed or enabled" — which reads like a migration fault. '
			+ 'Lower the floor or drop the leg.',
		)
		failed = true
	}
}

// Pinned literals. The comparison above only proves the floor is not ABOVE what
// CI tests — it would happily accept 28. These pin the product decision so a
// silent lowering has to argue with a check.
if (floor !== EXPECTED_NC_FLOOR) {
	console.error(
		'[check:nc-floor] Nextcloud floor is ' + floor + ', expected ' + EXPECTED_NC_FLOOR + '. '
		+ 'The fleet standardises on Nextcloud ' + EXPECTED_NC_FLOOR + ' so it can require PHP '
		+ EXPECTED_PHP_FLOOR + '. Change this constant deliberately, with a reason.',
	)
	failed = true
}

if (phpFloor !== EXPECTED_PHP_FLOOR) {
	console.error(
		'[check:nc-floor] PHP floor is ' + phpFloor + ', expected ' + EXPECTED_PHP_FLOOR + '.',
	)
	failed = true
}

if (failed) {
	process.exit(1)
}

console.log(
	'[check:nc-floor] OK — floor NC ' + floor + ' / PHP ' + phpFloor + '; CI legs '
	+ refs.map((r) => r.ref).join(', ') + ' all satisfy it.',
)
