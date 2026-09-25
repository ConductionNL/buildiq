#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// check-single-dexie.js: the Dexie singleton guard.
//
// WHY THIS EXISTS
//
//   Dexie refuses to initialise twice in one page: a second copy at a
//   different version throws "Two different versions of Dexie loaded in the
//   same app" at module init, before the SPA mounts. Buildiq never has a page
//   to itself. Hermiq's companion panel and agent leaf load beside
//   buildiq-main.js on every Buildiq page, openregister's integration script
//   loads on every page of the instance, and a Buildiq-built app runs the
//   same runtime bundle. So the version Buildiq ships has to be the version
//   the rest of the instance ships.
//
//   That is measured, not hypothetical. On 2026-09-18 hermiq was deployed
//   carrying dexie 4.4.6 while Buildiq shipped 4.4.5. The Buildiq apps list,
//   Hello World, dossiq and every Buildiq-built app rendered blank: empty
//   body, nothing in the Nextcloud log, one console throw. Buildiq was the
//   victim that time rather than the cause, and this guard is what keeps it
//   from being the cause next time: `dexie` was declared `^4.0.8` here, a
//   range wide enough for any `npm install` to walk the fleet apart.
//
// WHAT IT CHECKS
//
//   1. every built chunk that embeds a Dexie copy embeds the SAME version;
//   2. that version is the one package-lock.json resolves, so a stale chunk
//      from an earlier build cannot ship unnoticed;
//   3. that version is FLEET_DEXIE, the version the other apps ship.
//
//   Check 3 will fail the day the fleet moves to a new Dexie, and that is the
//   intent: the apps that share a page move together, in one pass. A failure
//   here is never fixed by deleting the check.
//
// HOW IT DETECTS A COPY
//
//   Dexie's own duplicate check ships in every copy of the library, so a
//   built chunk embeds Dexie exactly when it contains the error string
//   "Two different versions of Dexie". Inside such a chunk the version
//   literal survives minification as `semVer:"x.y.z"` (Dexie.semVer).
//
// WHEN IT RUNS
//
//   As `postbuild`, so it runs wherever `npm run build` runs: locally, in
//   code quality CI, and in the release build that packages js/ into the App
//   Store tarball. Standalone via `npm run check:dexie`. When js/ does not
//   exist yet it skips loudly instead of failing.
//
// Exit codes:
//   0: zero or one Dexie version across js/, matching the lockfile and the fleet
//   1: two or more versions, or a version the lockfile or the fleet disagrees with

const fs = require('fs')
const path = require('path')

// The Dexie version every other Conduction app on an instance ships today.
// Moved to 4.4.6 on 2026-09-25 in one fleet-wide pass together with
// ConductionNL/openregister#3788 (it was 4.4.5, verified 2026-09-19 against
// hermiq, dossiq, openregister, opencatalogi and integriq). Still within
// @conduction/nextcloud-vue's peer range ^4.0.8. Move this only together with them.
const FLEET_DEXIE = '4.4.6'

const repoRoot = path.join(__dirname, '..')
const jsDir = path.join(repoRoot, 'js')

const SENTINEL = 'Two different versions of Dexie'
const SEMVER_RE = /semVer\s*[:=]\s*["']([0-9][0-9A-Za-z.+-]*)["']/g

if (!fs.existsSync(jsDir)) {
	console.log(
		'i dexie singleton: js/ not built yet, skipping (run npm run build first)',
	)
	process.exit(0)
}

let expected = null
try {
	const lock = JSON.parse(
		fs.readFileSync(path.join(repoRoot, 'package-lock.json'), 'utf8'),
	)
	expected =
		(lock.packages
			&& lock.packages['node_modules/dexie']
			&& lock.packages['node_modules/dexie'].version)
		|| null
} catch (e) {
	console.log(
		`i dexie singleton: could not read package-lock.json (${e.message}); checking chunk agreement only`,
	)
}

if (expected && expected !== FLEET_DEXIE) {
	console.error(
		`x dexie singleton: package-lock.json resolves dexie ${expected}, but the fleet ships ${FLEET_DEXIE}. Buildiq shares every one of its pages with hermiq's panel and openregister's integration script, so this drift blanks them all. Pin dexie back, or bump FLEET_DEXIE in the same pass that bumps the rest of the fleet.`,
	)
	process.exit(1)
}

const findings = []
for (const name of fs.readdirSync(jsDir).sort()) {
	if (!name.endsWith('.js')) {
		continue
	}
	const text = fs.readFileSync(path.join(jsDir, name), 'utf8')
	if (!text.includes(SENTINEL)) {
		continue
	}
	const versions = new Set()
	for (const m of text.matchAll(SEMVER_RE)) {
		versions.add(m[1])
	}
	if (versions.size === 0) {
		// A chunk carries Dexie's error string but no recognisable version
		// literal. The marker contract changed, so the guard can no longer
		// see, and a guard that cannot see must say so rather than pass.
		console.error(
			`x dexie singleton: js/${name} embeds Dexie (sentinel found) but no semVer literal matched; update SEMVER_RE in ${path.basename(__filename)}`,
		)
		process.exit(1)
	}
	for (const v of versions) {
		findings.push({ file: name, version: v })
	}
}

if (findings.length === 0) {
	console.log('+ dexie singleton: no built chunk embeds Dexie')
	process.exit(0)
}

const distinct = [...new Set(findings.map((f) => f.version))].sort()

for (const f of findings) {
	console.log(`  js/${f.file}: dexie ${f.version}`)
}

if (distinct.length > 1) {
	console.error(
		`x dexie singleton: ${distinct.length} different Dexie versions in one chunk set (${distinct.join(', ')}). Loading any two of these chunks in one page throws at module init and the SPA never mounts. Rebuild from a clean js/ with a single resolved dexie.`,
	)
	process.exit(1)
}

if (expected && distinct[0] !== expected) {
	console.error(
		`x dexie singleton: built chunks carry dexie ${distinct[0]} but package-lock.json resolves ${expected}. A chunk is stale, or a dependency vendors its own copy. Rebuild from a clean js/.`,
	)
	process.exit(1)
}

if (distinct[0] !== FLEET_DEXIE) {
	console.error(
		`x dexie singleton: built chunks carry dexie ${distinct[0]} but the fleet ships ${FLEET_DEXIE}. Buildiq's pages also carry hermiq's panel and openregister's integration script; two versions in one page blank them all.`,
	)
	process.exit(1)
}

console.log(
	`+ dexie singleton: one Dexie version (${distinct[0]}) across ${findings.length} chunk(s), matching the lockfile and the fleet`,
)
