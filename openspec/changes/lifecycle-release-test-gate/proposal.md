---
kind: code
---

# Proposal: lifecycle-release-test-gate

## Why

buildiq matrix, row `lc-automated-tests`, "Run automated tests against an app
before releasing it.", rated `partial`, built. No tender, featureRequest or
roadmap row carries it. Two competitors rated `yes` on the half buildiq lacks:

- Mendix: "Unit Testing module runs microflow and JUnit tests"
  (https://docs.mendix.com/refguide/testing-microflows-with-unit-testing-module/).
- Microsoft Power Apps: "Test Studio builds end-to-end UI tests for canvas apps"
  (https://learn.microsoft.com/en-us/power-apps/maker/canvas-apps/test-studio).

NocoBase, Budibase and Appsmith are rated `no`.

What buildiq does today, per the matrix `built.evidence`: "rules#testAll POST
/api/rules/{ruleSetSlug}/test-all -> lib/Controller/RulesController.php:236
testAll() runs stored RuleTestCase rows against a rule set via
versioningService->runTestGate(); reached from src/views/RuleSetsPage.vue:227 and
src/modals/RuleSetTestSandboxModal.vue:180". At development `d21e42f` the route
sits at `appinfo/routes.php:193`. The matrix note names the missing half: "Only
business-rule sets have a test gate. There is no general automated test suite
(e2e/functional) run against an app's pages/data before release. The RuleSets
page (/business-rules) has no menu entry and no in-app link
(src/manifest.d/20-business-rules.json menu []), so the test gate is reached only
by typing the URL."

The code agrees. A rule set cannot go active with a failing test
(`RuleSetVersioningService::promoteToActive()` calls `runTestGate()` first), but
releasing an app version runs nothing: `ApplicationVersionsController::release()`
(`lib/Controller/ApplicationVersionsController.php:516`) checks the owner and
calls `ApplicationVersionService::releaseVersion()`
(`lib/Service/ApplicationVersionService.php:428`), which moves the production
pointer. The missing half this change builds is an app test suite and a gate
that runs it before a release.

## What changes

- Each app version gets a test suite with three kinds of checks:
  - structure checks buildiq derives itself: every page's register and schema
    exist, every column and field names a property its schema has, every menu
    item leads to a page;
  - the test cases of every rule set the app owns (`RuleSet.ownerApp`);
  - record tests a maker writes per schema: a sample record and whether it should
    be accepted or refused, and on which field.
- Releasing a version, and promoting one onto the production line, runs the suite
  first. A failing suite stops the release and shows the failures.
- An owner can release anyway with a written reason; the reason and the failures
  are kept on the test run.
- A "Tests" tab on the app detail page lists the checks, runs them on demand, and
  shows each version's last run, including the rule-set tests, which are now
  reachable from the app.

## Rows this closes

| matrix | row id | row name | own rating | what is missing |
|-|-|-|-|-|
| buildiq | lc-automated-tests | Run automated tests against an app before releasing it. | partial | an app-level test suite and a gate that runs it before release (only rule sets have tests today) |

## Existing work it builds on

- `openspec/specs/business-rules-engine`, REQ-BRE-004 "Test-case driven sandbox
  validation" and REQ-BRE-005 "Versioning on activation": the rule-set test
  cases and `runTestGate()` this suite calls.
- `openspec/specs/application-versions` and `openspec/specs/version-promotion`:
  release and promotion, which gain the gate.
- `openspec/specs/version-lifecycle-ui`: the release button that shows the
  result.
- `operate-debug-log-and-monitoring` (this pass): its binding checks in the
  designer and this suite's structure checks describe the same problems; the
  suite is the server-side, release-time form.

## Sibling halves

- openregister owes a validate-only call on its published contract. Record tests
  need "would this record be accepted by this schema, and if not, on which
  fields" without saving. OpenRegister has it internally
  (`ValidateObject::validateObject()`, `lib/Service/Object/ValidateObject.php:1694`),
  but `OCA\OpenRegister\Contract\ObjectServiceInterface`
  (`lib/Contract/ObjectServiceInterface.php`), the contract buildiq consumes,
  offers no validate method, and `objects#validate` (`appinfo/routes.php:329`)
  re-validates stored objects rather than a sample. No open openregister change
  covers it.

## Out of scope

- Browser tests that click through pages (a recorder and a runner). The suite
  checks configuration and data rules, not rendering.
- Tests of flows and agents, which run in OpenRegister and Hermiq.
- Running the suite on a schedule or on every save.
