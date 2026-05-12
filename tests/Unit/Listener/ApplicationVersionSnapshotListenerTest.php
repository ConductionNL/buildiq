<?php

/**
 * Unit tests for ApplicationVersionSnapshotListener.
 *
 * Spec #6 openbuilt-versioning. The listener is the declarative-first
 * fallback (ADR-031 §Exceptions(1)) that snapshots the Application's
 * manifest into an ApplicationVersion sibling row whenever OR fires the
 * draft → published ObjectTransitionedEvent. These tests pin the
 * filter (schema/from/to discrimination), the happy-path save +
 * currentVersion writeback, the missing-OR resilience, and the
 * idempotency-on-repeat-publish contract.
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
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the snapshot-on-publish listener.
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
     * Mock OR ObjectService — typed as object since the real class lives in another app.
     *
     * @var MockObject
     */
    private MockObject $objectService;

    /**
     * Set up shared fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['saveObject'])
            ->getMock();
    }//end setUp()

    /**
     * Build a fake ObjectTransitionedEvent.
     *
     * We mock the real OR class (loaded via the openregister submodule on
     * the include path) but stub the three accessors the listener calls.
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
        $entity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn($serialisedObject);

        $event = $this->getMockBuilder(\OCA\OpenRegister\Event\ObjectTransitionedEvent::class)
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
        $entity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn($payload);
        return $entity;
    }//end makeReturnedEntity()

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
     * Happy path: draft→published on Application creates ApplicationVersion
     * AND patches Application.currentVersion to the new snapshot.
     *
     * @return void
     */
    public function testHandleCreatesSnapshotAndPatchesCurrentVersionOnPublish(): void
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

        // First saveObject — creates the ApplicationVersion sibling.
        // Second saveObject — patches the parent Application with currentVersion.
        $snapshotEntity = $this->makeReturnedEntity(['@self' => ['id' => 'snap-uuid-1']]);

        $captured = [];
        $this->objectService->expects(self::exactly(2))
            ->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$captured, $snapshotEntity) {
                // PHP 8 named-args: $args[0] is `object`, then register, schema.
                $captured[] = $args;
                $payload    = $args[0] ?? ($args['object'] ?? null);
                $schema     = $args['schema'] ?? ($args[2] ?? null);
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

        self::assertCount(2, $captured, 'Expected exactly 2 saveObject() calls (snapshot + writeback)');

        // First call: snapshot — carries applicationUuid + manifest + actor uid.
        $snapshotArgs    = $captured[0];
        $snapshotPayload = ($snapshotArgs['object'] ?? $snapshotArgs[0]);
        $snapshotSchema  = ($snapshotArgs['schema'] ?? $snapshotArgs[2]);
        self::assertSame('application-version', $snapshotSchema);
        self::assertSame('app-uuid-1', $snapshotPayload['applicationUuid']);
        self::assertSame($manifest, $snapshotPayload['manifest']);
        self::assertSame('1.0.0', $snapshotPayload['version']);
        self::assertSame('alice', $snapshotPayload['publishedBy']);
        self::assertArrayHasKey('publishedAt', $snapshotPayload);

        // Second call: writeback — Application gets currentVersion + status reset.
        $writeArgs    = $captured[1];
        $writePayload = ($writeArgs['object'] ?? $writeArgs[0]);
        $writeSchema  = ($writeArgs['schema'] ?? $writeArgs[2]);
        self::assertSame('application', $writeSchema);
        self::assertSame('snap-uuid-1', $writePayload['currentVersion']);
        // Per design.md Decision 3 the status is reset to draft so the next edit cycle starts clean.
        self::assertSame('draft', $writePayload['status']);
    }//end testHandleCreatesSnapshotAndPatchesCurrentVersionOnPublish()

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

        // The handle() must not propagate the runtime exception.
        $listener->handle($event);
        $this->expectNotToPerformAssertions();
    }//end testHandleLogsAndDoesNotThrowWhenOrServiceFails()

    /**
     * Repeat-publish: handle() called twice with byte-equal manifest results
     * in TWO snapshot rows (append-only history per design.md Decision 3).
     * The contract we pin here is "the listener does not silently swallow
     * a repeat publish" — duplicate-detection is OR's job, not the
     * listener's. We simply assert each invocation causes its own save.
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

        $callCount = 0;
        $this->objectService->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$callCount) {
                $callCount++;
                // Each snapshot return is a fresh entity with a fresh uuid; the
                // writeback returns its inbound payload as-is.
                $schema = $args['schema'] ?? ($args[2] ?? null);
                if ($schema === 'application-version') {
                    return $this->makeReturnedEntity(['@self' => ['id' => 'snap-'.$callCount]]);
                }
                return $this->makeReturnedEntity(['@self' => ['id' => 'app-uuid-3']]);
            });

        $listener = new ApplicationVersionSnapshotListener(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $listener->handle($event);
        $listener->handle($event);

        // Two publish events → 4 saves (2 snapshots + 2 writebacks).
        self::assertSame(4, $callCount);
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
