<?php

/**
 * Unit test for the ApplicationVersion lifecycle declared in
 * `lib/Settings/openbuild_register.json` (openbuild#10 task 5.2).
 *
 * Per ADR-031 (schema-declarative business logic), the
 * `applicationVersion` schema carries an `x-openregister-lifecycle`
 * block describing the `draft → published → archived → draft (reopen)`
 * state machine. OR's TransitionEngine executes the transitions at
 * runtime; this test asserts the *contract* is well-formed:
 *
 *   - initial state is `draft`
 *   - the three named states are exactly draft/published/archived
 *   - the three transitions are publish (draft→published),
 *     archive (published→archived), reopen (archived→draft)
 *   - the publish transition declares an `upsert_relation` action
 *     targeting `openbuild/built-app-route` (BuiltAppRoute upkeep)
 *
 * A real end-to-end transition test requires booted Nextcloud +
 * OpenRegister with a Postgres / MySQL backend (see openbuild#10
 * task 5.2 note "Requires container-bound NC bootstrap"). That
 * integration scope is tracked separately; this test guards the
 * declarative contract that anchors it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests the declarative lifecycle of ApplicationVersion (openbuild#10).
 */
class ApplicationVersionLifecycleSchemaTest extends TestCase
{

    /**
     * Decoded register-seed payload, lazily loaded.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $registerSeed = null;

    /**
     * Load + cache the canonical register seed.
     *
     * @return array<string, mixed>
     */
    private function registerSeed(): array
    {
        if (self::$registerSeed === null) {
            $path = __DIR__.'/../../lib/Settings/openbuild_register.json';
            self::assertFileExists($path, 'register seed file must be present');
            $raw     = file_get_contents($path);
            $decoded = json_decode($raw, true);
            self::assertIsArray($decoded, 'register seed must be a JSON object');
            self::$registerSeed = $decoded;
        }

        return self::$registerSeed;
    }//end registerSeed()

    /**
     * Pull the ApplicationVersion schema block out of the register seed.
     *
     * @return array<string, mixed>
     */
    private function applicationVersionSchema(): array
    {
        $seed = $this->registerSeed();
        // The seed is OpenAPI-shaped; schemas live under components.schemas
        // and the version schema is keyed `ApplicationVersion` (PascalCase).
        self::assertArrayHasKey('components', $seed);
        self::assertArrayHasKey('schemas', $seed['components']);
        $schemas = $seed['components']['schemas'];
        self::assertIsArray($schemas);
        self::assertArrayHasKey(
            'ApplicationVersion',
            $schemas,
            'register seed must define an ApplicationVersion schema'
        );
        $schema = $schemas['ApplicationVersion'];
        self::assertIsArray($schema);
        return $schema;
    }//end applicationVersionSchema()

    /**
     * Pull out the lifecycle declaration.
     *
     * @return array<string, mixed>
     */
    private function lifecycle(): array
    {
        $schema = $this->applicationVersionSchema();
        // `x-openregister-lifecycle` sits at the schema level (sibling
        // of `properties`), not inside the properties map.
        self::assertArrayHasKey(
            'x-openregister-lifecycle',
            $schema,
            'ApplicationVersion must declare x-openregister-lifecycle'
        );
        $lifecycle = $schema['x-openregister-lifecycle'];
        self::assertIsArray($lifecycle, 'lifecycle block must be an object');
        return $lifecycle;
    }//end lifecycle()

    /**
     * REQ-OBV-LC-1 — initial state is draft.
     *
     * @return void
     */
    public function testInitialStateIsDraft(): void
    {
        $lifecycle = $this->lifecycle();
        self::assertSame('status', $lifecycle['field'] ?? null);
        self::assertSame('draft', $lifecycle['initial'] ?? null);
    }//end testInitialStateIsDraft()

    /**
     * REQ-OBV-LC-2 — three named states exist: draft, published, archived.
     *
     * @return void
     */
    public function testStateSetIsDraftPublishedArchived(): void
    {
        $lifecycle = $this->lifecycle();
        self::assertArrayHasKey('states', $lifecycle);
        self::assertIsArray($lifecycle['states']);

        $expected = ['draft', 'published', 'archived'];
        $actual   = array_keys($lifecycle['states']);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }//end testStateSetIsDraftPublishedArchived()

    /**
     * REQ-OBV-LC-3 — three transitions: publish, archive, reopen.
     *
     * @return void
     */
    public function testThreeTransitionsAreDeclared(): void
    {
        $lifecycle   = $this->lifecycle();
        $transitions = ($lifecycle['transitions'] ?? []);
        self::assertIsArray($transitions);
        self::assertCount(3, $transitions, 'expected exactly 3 transitions (publish/archive/reopen)');

        // The post-OR-#1520 shape is a MAP keyed by action name, with
        // `from` as an array of states. The list-shape form (where each
        // transition had a `name` field and a string `from`) is no longer
        // accepted by OR's LifecycleAnnotationValidator.
        self::assertSame(['publish', 'archive', 'reopen'], array_keys($transitions));

        self::assertSame(['draft'], $transitions['publish']['from']);
        self::assertSame('published', $transitions['publish']['to']);

        self::assertSame(['published'], $transitions['archive']['from']);
        self::assertSame('archived', $transitions['archive']['to']);

        self::assertSame(['archived'], $transitions['reopen']['from']);
        self::assertSame('draft', $transitions['reopen']['to']);
    }//end testThreeTransitionsAreDeclared()

    /**
     * REQ-OBV-LC-4 — publish fires the upsert_relation that keeps
     * BuiltAppRoute in sync. This is the declarative replacement for
     * the old ApplicationVersionSnapshotListener (per ADR-031).
     *
     * @return void
     */
    public function testPublishUpsertsBuiltAppRoute(): void
    {
        $lifecycle  = $this->lifecycle();
        $transition = $lifecycle['transitions']['publish'] ?? null;

        self::assertIsArray($transition, 'publish transition must exist');
        self::assertArrayHasKey('on_transition', $transition);
        self::assertArrayHasKey('upsert_relation', $transition['on_transition']);

        $upsert = $transition['on_transition']['upsert_relation'];
        self::assertSame('openbuild/built-app-route', $upsert['schema'] ?? null);
        // The slug-keyed match ensures the route survives republishes
        // (one row per Application slug).
        self::assertArrayHasKey('match', $upsert);
        self::assertArrayHasKey('slug', $upsert['match']);
        self::assertArrayHasKey('payload', $upsert);
        self::assertArrayHasKey('slug', $upsert['payload']);
        self::assertArrayHasKey('applicationUuid', $upsert['payload']);
    }//end testPublishUpsertsBuiltAppRoute()

    /**
     * Pull an arbitrary schema block out of the register seed by its
     * (PascalCase) component key.
     *
     * @param string $key Component schema key (e.g. `exportJob`).
     *
     * @return array<string, mixed>
     */
    private function schemaByKey(string $key): array
    {
        $seed    = $this->registerSeed();
        $schemas = $seed['components']['schemas'];
        self::assertIsArray($schemas);
        self::assertArrayHasKey($key, $schemas, sprintf('register seed must define a %s schema', $key));
        $schema = $schemas[$key];
        self::assertIsArray($schema);
        return $schema;
    }//end schemaByKey()

    /**
     * Collect the declared transition action names for a schema's
     * `x-openregister-lifecycle` block.
     *
     * @param array<string, mixed> $schema A decoded schema block.
     *
     * @return list<string>
     */
    private function transitionNames(array $schema): array
    {
        self::assertArrayHasKey('x-openregister-lifecycle', $schema);
        $lifecycle = $schema['x-openregister-lifecycle'];
        self::assertIsArray($lifecycle);
        self::assertArrayHasKey('transitions', $lifecycle);
        self::assertIsArray($lifecycle['transitions']);
        return array_keys($lifecycle['transitions']);
    }//end transitionNames()

    /**
     * Collect the `transition`-trigger action keys declared by a schema's
     * `x-openregister-notifications` rules.
     *
     * @param array<string, mixed> $schema A decoded schema block.
     *
     * @return list<string>
     */
    private function notificationTransitionActions(array $schema): array
    {
        self::assertArrayHasKey('x-openregister-notifications', $schema);
        $rules = $schema['x-openregister-notifications'];
        self::assertIsArray($rules);

        $actions = [];
        foreach ($rules as $rule) {
            self::assertIsArray($rule);
            $trigger = ($rule['trigger'] ?? []);
            self::assertIsArray($trigger);
            if ((string) ($trigger['type'] ?? '') !== 'transition') {
                continue;
            }

            self::assertArrayHasKey('action', $trigger, 'transition rule must name an action');
            $actions[] = (string) $trigger['action'];
        }

        return $actions;
    }//end notificationTransitionActions()

    /**
     * The crux of the openbuild-notifications prerequisite: every
     * `transition`-trigger notification rule MUST reference a transition
     * **action name** that is actually declared in the schema's
     * `x-openregister-lifecycle.transitions` map.
     *
     * OR's AnnotationNotificationDispatcher::matches() compares the rule's
     * `trigger.action` against `ObjectTransitionedEvent::getAction()`, which
     * is the transition NAME from the transition table (e.g. `succeed`,
     * `publish`) — NOT the destination STATE (`succeeded`, `published`).
     * A rule keyed on a state name would be declared-but-dormant: it would
     * never fire because no event ever carries that action. This test pins
     * the keys so they cannot drift back to state names.
     *
     * @return void
     */
    public function testNotificationActionsMatchLifecycleTransitionNames(): void
    {
        $cases = [
            'exportJob'          => ['succeed', 'fail'],
            'ApplicationVersion' => ['publish', 'archive'],
        ];

        foreach ($cases as $schemaKey => $expectedActions) {
            $schema          = $this->schemaByKey($schemaKey);
            $transitionNames = $this->transitionNames($schema);
            $ruleActions     = $this->notificationTransitionActions($schema);

            self::assertSame(
                $expectedActions,
                $ruleActions,
                sprintf('%s notification rules must trigger on transition names %s', $schemaKey, implode('/', $expectedActions))
            );

            foreach ($ruleActions as $action) {
                self::assertContains(
                    $action,
                    $transitionNames,
                    sprintf(
                        '%s notification action "%s" must be a declared lifecycle transition (have: %s) — '
                        .'a state name here would never fire (dispatcher matches transition NAME, not state).',
                        $schemaKey,
                        $action,
                        implode(', ', $transitionNames)
                    )
                );
            }
        }//end foreach
    }//end testNotificationActionsMatchLifecycleTransitionNames()

    /**
     * REQ-OBN-001 — exportJob declares exactly the two notification rules
     * required by openbuild-notifications (#23): export-succeeded and
     * export-failed.
     *
     * @spec openspec/changes/openbuild-notifications/tasks.md#task-1
     *
     * @return void
     */
    public function testExportJobNotificationRuleNames(): void
    {
        $schema = $this->schemaByKey('exportJob');
        self::assertArrayHasKey(
            'x-openregister-notifications',
            $schema,
            'exportJob must declare x-openregister-notifications'
        );
        $rules = $schema['x-openregister-notifications'];
        self::assertIsArray($rules);
        self::assertArrayHasKey('export-succeeded', $rules, 'export-succeeded rule must exist');
        self::assertArrayHasKey('export-failed', $rules, 'export-failed rule must exist');
        self::assertCount(2, $rules, 'exportJob must declare exactly 2 notification rules');
    }//end testExportJobNotificationRuleNames()

    /**
     * REQ-OBN-002 — ApplicationVersion declares exactly the two notification
     * rules required by openbuild-notifications (#23): version-published and
     * version-archived.
     *
     * @spec openspec/changes/openbuild-notifications/tasks.md#task-2
     *
     * @return void
     */
    public function testApplicationVersionNotificationRuleNames(): void
    {
        $schema = $this->applicationVersionSchema();
        self::assertArrayHasKey(
            'x-openregister-notifications',
            $schema,
            'ApplicationVersion must declare x-openregister-notifications'
        );
        $rules = $schema['x-openregister-notifications'];
        self::assertIsArray($rules);
        self::assertArrayHasKey('version-published', $rules, 'version-published rule must exist');
        self::assertArrayHasKey('version-archived', $rules, 'version-archived rule must exist');
        self::assertCount(2, $rules, 'ApplicationVersion must declare exactly 2 notification rules');
    }//end testApplicationVersionNotificationRuleNames()

    /**
     * REQ-OBN-003 — Every notification rule on both schemas ships with
     * object-acl recipients (permission: manage), is enabled by default, and
     * uses the nc-notification channel (openbuild-notifications #23 task-3).
     *
     * @spec openspec/changes/openbuild-notifications/tasks.md#task-3
     *
     * @return void
     */
    public function testNotificationRulesHaveObjectAclRecipientsAndAreEnabled(): void
    {
        $cases = [
            'exportJob'          => ['export-succeeded', 'export-failed'],
            'ApplicationVersion' => ['version-published', 'version-archived'],
        ];

        foreach ($cases as $schemaKey => $ruleKeys) {
            $schema = $this->schemaByKey($schemaKey);
            self::assertArrayHasKey('x-openregister-notifications', $schema);
            $rules = $schema['x-openregister-notifications'];
            self::assertIsArray($rules);

            foreach ($ruleKeys as $ruleKey) {
                self::assertArrayHasKey($ruleKey, $rules, sprintf('%s must have rule %s', $schemaKey, $ruleKey));
                $rule = $rules[$ruleKey];
                self::assertIsArray($rule);

                // Must be enabled by default.
                self::assertTrue(
                    (bool) ($rule['enabled'] ?? false),
                    sprintf('%s.%s must ship with enabled=true', $schemaKey, $ruleKey)
                );

                // Must use nc-notification channel.
                self::assertContains(
                    'nc-notification',
                    ($rule['channels'] ?? []),
                    sprintf('%s.%s must declare nc-notification channel', $schemaKey, $ruleKey)
                );

                // Must have object-acl manage recipient.
                $recipients = ($rule['recipients'] ?? []);
                self::assertIsArray($recipients);
                self::assertNotEmpty($recipients, sprintf('%s.%s must have at least one recipient', $schemaKey, $ruleKey));

                $hasObjectAclManage = false;
                foreach ($recipients as $recipient) {
                    if (is_array($recipient) === true
                        && ($recipient['kind'] ?? '') === 'object-acl'
                        && ($recipient['permission'] ?? '') === 'manage'
                    ) {
                        $hasObjectAclManage = true;
                        break;
                    }
                }

                self::assertTrue(
                    $hasObjectAclManage,
                    sprintf(
                        '%s.%s must have a {kind:object-acl, permission:manage} recipient',
                        $schemaKey,
                        $ruleKey
                    )
                );
            }//end foreach
        }//end foreach
    }//end testNotificationRulesHaveObjectAclRecipientsAndAreEnabled()

    /**
     * REQ-OBN-004 — Every notification rule ships bilingual subjects in
     * both Dutch (nl) and English (en) per ADR-007 / ADR-025
     * (openbuild-notifications #23 task-4).
     *
     * @spec openspec/changes/openbuild-notifications/tasks.md#task-4
     *
     * @return void
     */
    public function testNotificationRulesHaveBilingualSubjects(): void
    {
        $cases = [
            'exportJob'          => ['export-succeeded', 'export-failed'],
            'ApplicationVersion' => ['version-published', 'version-archived'],
        ];

        foreach ($cases as $schemaKey => $ruleKeys) {
            $schema = $this->schemaByKey($schemaKey);
            self::assertArrayHasKey('x-openregister-notifications', $schema);
            $rules = $schema['x-openregister-notifications'];
            self::assertIsArray($rules);

            foreach ($ruleKeys as $ruleKey) {
                $rule = $rules[$ruleKey];
                self::assertIsArray($rule);

                $subject = ($rule['subject'] ?? []);
                self::assertIsArray($subject, sprintf('%s.%s subject must be an object', $schemaKey, $ruleKey));

                self::assertArrayHasKey(
                    'nl',
                    $subject,
                    sprintf('%s.%s must have a Dutch (nl) subject (ADR-007)', $schemaKey, $ruleKey)
                );
                self::assertArrayHasKey(
                    'en',
                    $subject,
                    sprintf('%s.%s must have an English (en) subject (ADR-007)', $schemaKey, $ruleKey)
                );

                self::assertNotEmpty(
                    (string) ($subject['nl'] ?? ''),
                    sprintf('%s.%s nl subject must not be empty', $schemaKey, $ruleKey)
                );
                self::assertNotEmpty(
                    (string) ($subject['en'] ?? ''),
                    sprintf('%s.%s en subject must not be empty', $schemaKey, $ruleKey)
                );
            }//end foreach
        }//end foreach
    }//end testNotificationRulesHaveBilingualSubjects()

    /**
     * Sanity guard — a disallowed transition (e.g. draft → archived
     * directly) is NOT declared. OR's TransitionEngine rejects undefined
     * transitions; the test catches accidental schema drift that would
     * widen the state machine.
     *
     * @return void
     */
    public function testDisallowedTransitionIsAbsent(): void
    {
        $lifecycle = $this->lifecycle();
        // Each transition's `from` is an array of states post-OR-#1520.
        // Walk the cartesian product so we still catch a stray
        // (e.g.) `draft → archived` even if it slipped into a multi-from
        // transition.
        $pairs = [];
        foreach ($lifecycle['transitions'] as $spec) {
            $froms = ($spec['from'] ?? []);
            $to    = ($spec['to'] ?? '?');
            foreach ((array) $froms as $from) {
                $pairs[] = sprintf('%s->%s', (string) $from, (string) $to);
            }
        }

        self::assertNotContains('draft->archived', $pairs);
        self::assertNotContains('published->draft', $pairs);
        self::assertNotContains('archived->published', $pairs);
    }//end testDisallowedTransitionIsAbsent()
}//end class
