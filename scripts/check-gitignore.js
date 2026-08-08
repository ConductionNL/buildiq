#!/usr/bin/env node
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * check:gitignore — no tracked source file may be matched by .gitignore.
 *
 * A file that is BOTH tracked and ignored keeps working day to day: git already
 * versions it, so nothing complains. The damage is silent and arrives later.
 * Any flow that untracks and re-adds — a fresh `git add` after `git rm
 * --cached`, an archive round-trip, a re-import into a new repo — drops the
 * file, and every tool that enumerates the repository through `git ls-files`
 * stops seeing it.
 *
 * Measured on this repository, 2026-08-08: `.gitignore` carried the scaffold
 * pattern `**\/*references*`, aimed at stray notes-to-self with odd extensions.
 * It is a substring glob, so it also matched
 *
 *     lib/Controller/PreferencesController.php
 *     tests/Unit/Controller/PreferencesControllerTest.php
 *
 * The hydra-gates runner enumerates controllers via `git ls-files`. It reported
 * SEVEN route-reachability findings against the working tree and FIVE against a
 * `git archive`-and-re-add copy of the SAME commit — two different numbers for
 * one tree, because the second copy had no PreferencesController.php in it.
 *
 * Scope is the source directories only. `docs/build` and `docs/.docusaurus` are
 * committed build output and are deliberately ignored-and-tracked; that is a
 * separate decision and is not what this check is about.
 */

const { execFileSync } = require('child_process')

const SOURCE_DIRS = ['lib', 'src', 'appinfo', 'tests', 'scripts']

/**
 * Run a git command and return its stdout lines.
 *
 * @param {string[]} args Arguments to pass to git.
 * @return {string[]} Non-empty stdout lines.
 */
function git(args) {
	const out = execFileSync('git', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 })
	return out.split('\n').filter((line) => line.length > 0)
}

let tracked
let trackedAndIgnored

try {
	tracked = git(['ls-files', '--', ...SOURCE_DIRS])
	trackedAndIgnored = git(['ls-files', '-i', '-c', '--exclude-standard', '--', ...SOURCE_DIRS])
} catch (error) {
	// A check that could not run must not look like a check that passed.
	console.error('[check:gitignore] FAILED TO RUN: could not execute git.')
	console.error('[check:gitignore] ' + error.message)
	console.error('[check:gitignore] This is a broken instrument, not a clean result.')
	process.exit(2)
}

// Positive control on the INPUT. "I found nothing" and "I read nothing" are the
// same output otherwise, and the second one is the failure this file exists to
// catch in the first place.
if (tracked.length === 0) {
	console.error(
		'[check:gitignore] Enumerated ZERO tracked files under ' + SOURCE_DIRS.join(', ') + '.',
	)
	console.error('[check:gitignore] That is a broken enumeration, not an empty repository.')
	process.exit(2)
}

if (trackedAndIgnored.length > 0) {
	console.error(
		'[check:gitignore] '
		+ trackedAndIgnored.length
		+ ' tracked source file(s) are matched by .gitignore:',
	)
	for (const path of trackedAndIgnored) {
		console.error('  ' + path)
	}
	console.error('')
	console.error('[check:gitignore] git keeps versioning them today, so this is silent. It stops')
	console.error('[check:gitignore] being silent the moment anything re-adds them from scratch, or')
	console.error('[check:gitignore] enumerates the repo with `git ls-files` — which the hydra-gates')
	console.error('[check:gitignore] runner does. Narrow the pattern, or add a `!` negation for the')
	console.error('[check:gitignore] source directory. Do NOT untrack the file.')
	process.exit(1)
}

console.log(
	'[check:gitignore] OK — '
	+ tracked.length
	+ ' tracked file(s) under '
	+ SOURCE_DIRS.join(', ')
	+ ', none matched by .gitignore.',
)
