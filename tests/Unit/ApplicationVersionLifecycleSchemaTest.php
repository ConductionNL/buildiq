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
        }
    }//end testNotificationActionsMatchLifecycleTransitionNames()

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
