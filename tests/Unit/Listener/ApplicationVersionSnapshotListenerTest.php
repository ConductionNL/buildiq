<?php

/**
 * Unit tests for ApplicationVersionSnapshotListener.
 *
 * Spec #6 openbuilt-versioning. The listener is the declarative-first
 * fallback (ADR-031 §Exceptions(1)) for the Application schema's `publish`
 * transition: on the draft → published ObjectTransitionedEvent it (a)
 * upserts the Application's BuiltAppRoute (slug → applicationUuid) and (b)
 * snapshots the manifest into an ApplicationVersion sibling, then patches
 * Application.currentVersion. These tests pin the filter (schema/from/to
 * discrimination), the happy-path route-upsert + save + currentVersion
 * writeback, the missing-OR resilience, and the idempotency-on-repeat-publish
 * contract.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Listener
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

namespace OCA\OpenBuilt\Tests\Unit\Listener;

use OCA\OpenBuilt\Listener\ApplicationVersionSnapshotListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the publish-transition listener.
 *
 * NOTE: the listener accepts the real ObjectTransitionedEvent type-hint, but
 * we cannot construct it directly here (it requires an ObjectEntity which
 * lives in OR). Instead we fabricate a runtime-equivalent anonymous subclass
 * via PHPUnit's mock builder against the real class definition; PHP 8's
 * named-args call site only inspects `instanceof`, not equality.
 */
class ApplicationVersionSnapshotListenerTest extends TestCase
{

    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock OR ObjectService.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // ObjectTransitionedEvent is part of OpenRegister's lifecycle feature; it
        // is absent from OR's `main` (the ref the CI workflow currently installs).
        // Skip rather than fatal-error on the missing class until the lifecycle
        // feature lands on the OR release this app is tested against.
        if (class_exists(ObjectTransitionedEvent::class) === false) {
            $this->markTestSkipped('OpenRegister does not provide ObjectTransitionedEvent (lifecycle feature not in the installed OR release).');
        }

        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->makeObjectServiceMock();

        // Default: no BuiltAppRoute exists yet → the listener creates one.
        $this->objectService->method('searchObjectsBySlug')->willReturn([]);
    }//end setUp()

    /**
     * Build an ObjectService test double exposing the surface the listener uses.
     *
     * `searchObjectsBySlug()` was added to ObjectService after the OpenRegister
     * release this app is built+tested against, so it is added via
     * `MockBuilder::addMethods()` when the real class does not declare it (and
     * mocked normally when it does) — the listener already wraps the call in a
     * try/catch that treats a missing method as "no route yet".
     *
     * @return ObjectService&MockObject
     */
    private function makeObjectServiceMock(): ObjectService&MockObject
    {
        $builder = $this->getMockBuilder(ObjectService::class)->disableOriginalConstructor();
        if (method_exists(ObjectService::class, 'searchObjectsBySlug') === true) {
            $builder->onlyMethods(['saveObject', 'searchObjectsBySlug']);
        } else {
            $builder->onlyMethods(['saveObject'])->addMethods(['searchObjectsBySlug']);
        }

        return $builder->getMock();
    }//end makeObjectServiceMock()

    /**
     * Build a fake ObjectTransitionedEvent.
     *
     * We mock the real OR class (loaded via the openregister submodule on
     * the include path) but stub the accessors the listener calls.
     *
     * @param string               $schema           Schema slug.
     * @param string               $from             Transition `from` state.
     * @param string               $to               Transition `to` state.
     * @param array<string, mixed> $serialisedObject What the inner ObjectEntity
     *                                                jsonSerialize() returns.
     * @param string|null          $userId           Actor uid (null for system).
     *
     * @return MockObject The configured event stub.
     */
    private function makeEvent(
        string $schema,
        string $from,
        string $to,
        array $serialisedObject,
        ?string $userId='alice'
    ): MockObject {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($serialisedObject);

        $event = $this->getMockBuilder(ObjectTransitionedEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSchema', 'getFrom', 'getTo', 'getObject', 'getUserId'])
            ->getMock();
        $event->method('getSchema')->willReturn($schema);
        $event->method('getFrom')->willReturn($from);
        $event->method('getTo')->willReturn($to);
        $event->method('getObject')->willReturn($entity);
        $event->method('getUserId')->willReturn($userId);

        return $event;
    }//end makeEvent()

    /**
     * Build a fake ObjectEntity-like value that jsonSerialize()s to the given array.
     *
     * @param array<string, mixed> $payload The serialised payload.
     *
     * @return MockObject
     */
    private function makeReturnedEntity(array $payload): MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($payload);
        return $entity;
    }//end makeReturnedEntity()

    /**
     * Resolve the `object`/`schema` named-arg pair out of a captured saveObject() call.
     *
     * @param array<int|string, mixed> $args Captured variadic args.
     *
     * @return array{0: mixed, 1: mixed} [payload, schema]
     */
    private function unpackSave(array $args): array
    {
        // ObjectService::saveObject(array|ObjectEntity $object, ?array $extend, mixed $register, mixed $schema):
        // a named-arg call site (object/register/schema) yields positional args [object, [], register, schema].
        $payload = ($args['object'] ?? ($args[0] ?? null));
        $schema  = ($args['schema'] ?? ($args[3] ?? null));
        return [$payload, $schema];
    }//end unpackSave()

    /**
     * Non-Application transitions (e.g. a transition on a different schema)
     * must be a no-op — no OR write, no log.
     *
     * @return void
     */
    public function testHandleIgnoresNonApplicationSchema(): void
    {
        $event = $this->makeEvent(
            schema: 'hello-message',
            from: 'draft',
            to: 'published',
            serialisedObject: ['@self' => ['id' => 'irrelevant']]
        );

        // The OR save must never fire for a non-Application transition.
        $this->objectService->expects(self::never())->method('saveObject');

        $listener = new ApplicationVersionSnapshotListener(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $listener->handle($event);
    }//end testHandleIgnoresNonApplicationSchema()

    /**
     * Non-publish transitions on the Application schema (e.g. published →
     * archived) must be a no-op.
     *
     * @return void
     */
    public function testHandleIgnoresNonPublishTransition(): void
    {
        $event = $this->makeEvent(
            schema: 'application',
            from: 'published',
            to: 'archived',
            serialisedObject: ['@self' => ['id' => 'app-1']]
        );

        $this->objectService->expects(self::never())->method('saveObject');

        $listener = new ApplicationVersionSnapshotListener(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $listener->handle($event);
    }//end testHandleIgnoresNonPublishTransition()

    /**
     * Happy path: draft→published on Application upserts the BuiltAppRoute,
     * creates the ApplicationVersion AND patches Application.currentVersion.
     *
     * @return void
     */
    public function testHandleUpsertsRouteCreatesSnapshotAndPatchesCurrentVersionOnPublish(): void
    {
        $manifest = [
            'version' => '1.0.0',
            'menu'    => [],
            'pages'   => [['id' => 'p1', 'route' => '/', 'type' => 'index']],
        ];

        $applicationData = [
            '@self'    => ['id' => 'app-uuid-1'],
            'slug'     => 'hello-world',
            'version'  => '1.0.0',
            'manifest' => $manifest,
            'status'   => 'published',
        ];

        $event = $this->makeEvent(
            schema: 'application',
            from: 'draft',
            to: 'published',
            serialisedObject: $applicationData,
            userId: 'alice'
        );

        // Three saveObject calls: BuiltAppRoute upsert, ApplicationVersion
        // create, then the Application currentVersion writeback.
        $snapshotEntity = $this->makeReturnedEntity(['@self' => ['id' => 'snap-uuid-1']]);

        $captured = [];
        $this->objectService->expects(self::exactly(3))
            ->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$captured, $snapshotEntity) {
                $captured[] = $args;
                [$payload, $schema] = $this->unpackSave($args);
                if ($schema === 'application-version') {
                    return $snapshotEntity;
                }
                return $this->makeReturnedEntity($payload ?? []);
            });

        $listener = new ApplicationVersionSnapshotListener(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $listener->handle($event);

        self::assertCount(3, $captured, 'Expected exactly 3 saveObject() calls (route + snapshot + writeback)');

        // Index calls by schema so the assertions don't depend on call order.
        $bySchema = [];
        foreach ($captured as $args) {
            [$payload, $schema] = $this->unpackSave($args);
            $bySchema[$schema] = $payload;
        }

        // BuiltAppRoute upsert — slug → applicationUuid.
        self::assertArrayHasKey('built-app-route', $bySchema);
        self::assertSame('hello-world', $bySchema['built-app-route']['slug']);
        self::assertSame('app-uuid-1', $bySchema['built-app-route']['applicationUuid']);

        // Snapshot — carries applicationUuid + manifest + actor uid.
        self::assertArrayHasKey('application-version', $bySchema);
        self::assertSame('app-uuid-1', $bySchema['application-version']['applicationUuid']);
        self::assertSame($manifest, $bySchema['application-version']['manifest']);
        self::assertSame('1.0.0', $bySchema['application-version']['version']);
        self::assertSame('alice', $bySchema['application-version']['publishedBy']);
        self::assertArrayHasKey('publishedAt', $bySchema['application-version']);

        // Writeback — Application gets currentVersion + status reset.
        self::assertArrayHasKey('application', $bySchema);
        self::assertSame('snap-uuid-1', $bySchema['application']['currentVersion']);
        // Per design.md Decision 3 the status is reset to draft so the next edit cycle starts clean.
        self::assertSame('draft', $bySchema['application']['status']);
    }//end testHandleUpsertsRouteCreatesSnapshotAndPatchesCurrentVersionOnPublish()

    /**
     * When a BuiltAppRoute already points at this Application the listener
     * leaves it untouched — only the snapshot + writeback fire.
     *
     * @return void
     */
    public function testHandleSkipsRouteUpsertWhenAlreadyCorrect(): void
    {
        $applicationData = [
            '@self'    => ['id' => 'app-uuid-9'],
            'slug'     => 'already-routed',
            'version'  => '2.0.0',
            'manifest' => ['version' => '2.0.0', 'pages' => []],
        ];

        // Re-stub searchObjectsBySlug for this test: the route exists and is correct.
        $this->objectService = $this->makeObjectServiceMock();
        $this->objectService->method('searchObjectsBySlug')->willReturn([
            ['@self' => ['id' => 'route-uuid-9'], 'slug' => 'already-routed', 'applicationUuid' => 'app-uuid-9'],
        ]);

        $event = $this->makeEvent(
            schema: 'application',
            from: 'draft',
            to: 'published',
            serialisedObject: $applicationData
        );

        $schemas = [];
        $this->objectService->expects(self::exactly(2))
            ->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$schemas) {
                [, $schema] = $this->unpackSave($args);
                $schemas[]  = $schema;
                if ($schema === 'application-version') {
                    return $this->makeReturnedEntity(['@self' => ['id' => 'snap-uuid-9']]);
                }
                return $this->makeReturnedEntity(['@self' => ['id' => 'app-uuid-9']]);
            });

        $listener = new ApplicationVersionSnapshotListener(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $listener->handle($event);

        self::assertNotContains('built-app-route', $schemas, 'route already correct → no route saveObject');
        self::assertContains('application-version', $schemas);
        self::assertContains('application', $schemas);
    }//end testHandleSkipsRouteUpsertWhenAlreadyCorrect()

    /**
     * Missing ObjectService responses must not crash — the listener swallows
     * throwables and logs an error (publish itself already succeeded).
     *
     * @return void
     */
    public function testHandleLogsAndDoesNotThrowWhenOrServiceFails(): void
    {
        $event = $this->makeEvent(
            schema: 'application',
            from: 'draft',
            to: 'published',
            serialisedObject: [
                '@self'    => ['id' => 'app-uuid-2'],
                'slug'     => 'broken',
                'manifest' => ['v' => 1],
                'version'  => '1.0.0',
            ]
        );

        // OR throws on the first saveObject — the listener must catch + log.
        $this->objectService->method('saveObject')
            ->willThrowException(new \RuntimeException('OR is down'));

        // Error path: the listener's catch-all logs an error and returns.
        $this->logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(self::stringContains('ApplicationVersionSnapshotListener failed'));

        $listener = new ApplicationVersionSnapshotListener(
            logger: $this->logger,
            objectService: $this->objectService
        );

        // The handle() must not propagate the runtime exception — reaching this
        // line at all is the assertion; the logger->error() expectation above is
        // verified at teardown.
        $listener->handle($event);
    }//end testHandleLogsAndDoesNotThrowWhenOrServiceFails()

    /**
     * Repeat-publish: first publish creates the route + a snapshot; the
     * second publish finds the route already correct (skip) and appends a
     * second snapshot row (append-only history per design.md Decision 3).
     * The contract pinned here is "each publish appends its own snapshot" —
     * duplicate-detection is OR's job, not the listener's.
     *
     * @return void
     */
    public function testHandleProducesIndependentSnapshotsOnRepeatPublish(): void
    {
        $manifest = ['version' => '1.0.0', 'pages' => []];
        $serialisedObject = [
            '@self'    => ['id' => 'app-uuid-3'],
            'slug'     => 'idempotent',
            'version'  => '1.0.0',
            'manifest' => $manifest,
        ];

        $event = $this->makeEvent(
            schema: 'application',
            from: 'draft',
            to: 'published',
            serialisedObject: $serialisedObject
        );

        // 1st publish: no route yet. 2nd publish: route exists and is correct.
        $this->objectService = $this->makeObjectServiceMock();
        $this->objectService->method('searchObjectsBySlug')->willReturnOnConsecutiveCalls(
            [],
            [['@self' => ['id' => 'route-3'], 'slug' => 'idempotent', 'applicationUuid' => 'app-uuid-3']],
        );

        $schemas = [];
        $this->objectService->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$schemas) {
                [, $schema] = $this->unpackSave($args);
                $schemas[]  = $schema;
                if ($schema === 'application-version') {
                    return $this->makeReturnedEntity(['@self' => ['id' => 'snap-'.count($schemas)]]);
                }
                return $this->makeReturnedEntity(['@self' => ['id' => 'app-uuid-3']]);
            });

        $listener = new ApplicationVersionSnapshotListener(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $listener->handle($event);
        $listener->handle($event);

        // publish 1 = route + snapshot + writeback (3); publish 2 = snapshot + writeback (2).
        self::assertSame(5, count($schemas));
        self::assertSame(2, count(array_filter($schemas, static fn ($s) => $s === 'application-version')));
        self::assertSame(1, count(array_filter($schemas, static fn ($s) => $s === 'built-app-route')));
    }//end testHandleProducesIndependentSnapshotsOnRepeatPublish()

    /**
     * Sanity: a base Event (not the ObjectTransitionedEvent subclass) must be
     * filtered out by the instanceof guard.
     *
     * @return void
     */
    public function testHandleIgnoresGenericEvent(): void
    {
        $this->objectService->expects(self::never())->method('saveObject');

        $listener = new ApplicationVersionSnapshotListener(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $listener->handle(new Event());
    }//end testHandleIgnoresGenericEvent()
}//end class
