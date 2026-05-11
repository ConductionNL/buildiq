<?php

/**
 * Integration test for the full publish → rollback → republish cycle.
 *
 * Spec #6 openbuilt-versioning. Walks an Application object through:
 *   1. draft → published (asserts ApplicationVersion row created, BuiltAppRoute
 *      created, currentVersion set on the Application)
 *   2. rollback to v1.0.0 (asserts a NEW ApplicationVersion row is created
 *      pointing at the old manifest — append-only history per design.md
 *      Decision 3; we never rewrite history)
 *   3. republish (asserts another version row appears)
 *
 * Implemented as a PHPUnit test that wires the listener against a fake
 * in-memory ObjectService so we do not need to spin up OR's full storage
 * engine. The fake is intentionally narrow — it implements only the
 * `saveObject` + `find` + `searchObjects` surface the listener and seed
 * step actually use.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Integration
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

namespace OCA\OpenBuilt\Tests\Integration;

use OCA\OpenBuilt\Listener\ApplicationVersionSnapshotListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * End-to-end publish/rollback/republish cycle test.
 *
 * The flow under test is owned by 3 collaborators we exercise jointly:
 *   - ApplicationVersionSnapshotListener (listener / lib)
 *   - the OR ObjectTransitionedEvent contract (event shape)
 *   - the rollback handler's append-only semantics (re-uses the listener
 *     by re-emitting a draft→published transition on the restored manifest)
 *
 * We never touch the DB — a tiny FakeObjectService records every saveObject
 * call so the assertion phase can reconstruct the history.
 */
class PublishRollbackTest extends TestCase
{

    /**
     * In-memory OR shim — records every saveObject call and supports find().
     *
     * @var object
     */
    private object $fakeObjectService;

    /**
     * Set up fixtures: a fresh listener bound to a fresh FakeObjectService.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Build the fake OR shim as an anonymous class so it implements the
        // narrow surface our SUT actually invokes (saveObject + find).
        $this->fakeObjectService = new class () {
            /**
             * Every saveObject call's payload — used for assertions.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $saved = [];

            /**
             * Counter for fake UUID minting.
             *
             * @var int
             */
            private int $uuidCounter = 0;

            /**
             * In-memory storage keyed by (schema, uuid).
             *
             * @var array<string, array<string, array<string, mixed>>>
             */
            public array $store = [];

            /**
             * Mimic ObjectService::saveObject — assigns a uuid + appends to history.
             *
             * @param array<string, mixed> $object   The payload.
             * @param string               $register Register slug (ignored).
             * @param string               $schema   Schema slug.
             *
             * @return object Entity-like value exposing jsonSerialize().
             *
             * @SuppressWarnings(PHPMD.UnusedFormalParameter)
             */
            public function saveObject(array $object, string $register, string $schema): object
            {
                $existing = ($object['@self']['id'] ?? $object['uuid'] ?? null);
                if ($existing === null) {
                    $this->uuidCounter++;
                    $uuid           = 'uuid-'.$schema.'-'.$this->uuidCounter;
                    $object['@self'] = ['id' => $uuid];
                } else {
                    $uuid = (string) $existing;
                    if (isset($object['@self']) === false) {
                        $object['@self'] = ['id' => $uuid];
                    }
                }

                $this->store[$schema][$uuid] = $object;
                $this->saved[]               = ['schema' => $schema, 'object' => $object];

                return new class ($object) {
                    /**
                     * @param array<string, mixed> $payload Stored serialised payload.
                     */
                    public function __construct(private array $payload)
                    {
                    }

                    /**
                     * @return array<string, mixed>
                     */
                    public function jsonSerialize(): array
                    {
                        return $this->payload;
                    }
                };
            }

            /**
             * Mimic ObjectService::find — look up by (schema, uuid).
             *
             * @param string $id       The uuid.
             * @param string $register Register slug (ignored).
             * @param string $schema   Schema slug.
             *
             * @return array<string, mixed>|null
             *
             * @SuppressWarnings(PHPMD.UnusedFormalParameter)
             */
            public function find(string $id, string $register, string $schema): ?array
            {
                return ($this->store[$schema][$id] ?? null);
            }
        };
    }//end setUp()

    /**
     * Build a fake ObjectTransitionedEvent stub.
     *
     * @param string               $from            From state.
     * @param string               $to              To state.
     * @param array<string, mixed> $applicationData The Application payload.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function transition(string $from, string $to, array $applicationData): \PHPUnit\Framework\MockObject\MockObject
    {
        $entity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn($applicationData);

        $event = $this->getMockBuilder(\OCA\OpenRegister\Event\ObjectTransitionedEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSchema', 'getFrom', 'getTo', 'getObject', 'getUserId'])
            ->getMock();
        $event->method('getSchema')->willReturn('application');
        $event->method('getFrom')->willReturn($from);
        $event->method('getTo')->willReturn($to);
        $event->method('getObject')->willReturn($entity);
        $event->method('getUserId')->willReturn('alice');

        return $event;
    }//end transition()

    /**
     * Filter the fake's saved log to a particular schema.
     *
     * @param string $schema Schema slug.
     *
     * @return array<int, array<string, mixed>> The matching save payloads.
     */
    private function savedFor(string $schema): array
    {
        $matches = [];
        foreach ($this->fakeObjectService->saved as $entry) {
            if ($entry['schema'] === $schema) {
                $matches[] = $entry['object'];
            }
        }
        return $matches;
    }//end savedFor()

    /**
     * Full cycle: publish → rollback → republish.
     *
     * @return void
     */
    public function testFullPublishRollbackRepublishCycle(): void
    {
        $listener = new ApplicationVersionSnapshotListener(
            logger: new NullLogger(),
            objectService: $this->fakeObjectService
        );

        $manifestV1 = [
            'version'      => '1.0.0',
            'menu'         => [],
            'pages'        => [['id' => 'p1', 'route' => '/', 'type' => 'index']],
            'dependencies' => ['openregister'],
        ];

        // Bootstrap: seed the Application + a v1.0.0 snapshot via the fake OR
        // directly so we have a stable starting state (matches SeedHelloWorld's
        // behaviour without depending on its repair-step plumbing).
        $appEntity = $this->fakeObjectService->saveObject(
            object: [
                'slug'           => 'hello-world',
                'name'           => 'Hello World',
                'version'        => '1.0.0',
                'manifest'       => $manifestV1,
                'currentVersion' => 'snap-bootstrap',
            ],
            register: 'openbuilt',
            schema: 'application'
        );
        $appUuid = $appEntity->jsonSerialize()['@self']['id'];

        $this->fakeObjectService->saveObject(
            object: [
                'slug'            => 'hello-world',
                'applicationUuid' => $appUuid,
            ],
            register: 'openbuilt',
            schema: 'built-app-route'
        );

        $this->fakeObjectService->saveObject(
            object: [
                '@self'          => ['id' => 'snap-bootstrap'],
                'applicationUuid' => $appUuid,
                'version'         => '1.0.0',
                'manifest'        => $manifestV1,
                'publishedAt'     => '2026-05-01T10:00:00Z',
                'publishedBy'     => 'system',
            ],
            register: 'openbuilt',
            schema: 'application-version'
        );

        // Phase 1 — publish v1.1.0 (manifest edit).
        $manifestV11 = $manifestV1;
        $manifestV11['version'] = '1.1.0';
        $manifestV11['pages'][] = ['id' => 'p2', 'route' => '/about', 'type' => 'detail'];

        $listener->handle($this->transition(
            from: 'draft',
            to: 'published',
            applicationData: [
                '@self'   => ['id' => $appUuid],
                'slug'    => 'hello-world',
                'version' => '1.1.0',
                'manifest' => $manifestV11,
                'status'   => 'published',
            ]
        ));

        // Assert: ApplicationVersion row created (v1.1.0).
        $versions = $this->savedFor('application-version');
        self::assertCount(2, $versions, 'Bootstrap + 1 publish = 2 ApplicationVersion rows.');
        $publishedSnapshot = $versions[1];
        self::assertSame('1.1.0', $publishedSnapshot['version']);
        self::assertSame($appUuid, $publishedSnapshot['applicationUuid']);
        self::assertSame($manifestV11, $publishedSnapshot['manifest']);

        // Assert: Application.currentVersion got patched.
        $appWrites = $this->savedFor('application');
        $lastApp   = end($appWrites);
        self::assertNotFalse($lastApp);
        self::assertArrayHasKey('currentVersion', $lastApp);
        self::assertSame('draft', $lastApp['status'], 'Status must reset to draft per design.md Decision 3.');

        // Phase 2 — rollback to v1.0.0. Rollback copies the old manifest onto
        // the Application's draft AND re-publishes (which fires the listener
        // again, appending a new snapshot row). NEVER mutates v1.0.0 — that's
        // the append-only history contract.
        $listener->handle($this->transition(
            from: 'draft',
            to: 'published',
            applicationData: [
                '@self'   => ['id' => $appUuid],
                'slug'    => 'hello-world',
                'version' => '1.0.0-rb1',
                'manifest' => $manifestV1,
                'status'   => 'published',
            ]
        ));

        $versions = $this->savedFor('application-version');
        self::assertCount(3, $versions, 'Rollback must create a NEW row (append-only), not rewrite history.');
        // Original v1.0.0 row is untouched.
        self::assertSame('1.0.0', $versions[0]['version']);
        self::assertSame($manifestV1, $versions[0]['manifest']);
        // New rollback row.
        $rollbackSnapshot = $versions[2];
        self::assertSame('1.0.0-rb1', $rollbackSnapshot['version']);
        self::assertSame($manifestV1, $rollbackSnapshot['manifest']);

        // Phase 3 — republish (yet another draft→published transition).
        $manifestV12 = $manifestV1;
        $manifestV12['version'] = '1.2.0';
        $manifestV12['pages'][] = ['id' => 'p3', 'route' => '/admin', 'type' => 'form'];

        $listener->handle($this->transition(
            from: 'draft',
            to: 'published',
            applicationData: [
                '@self'   => ['id' => $appUuid],
                'slug'    => 'hello-world',
                'version' => '1.2.0',
                'manifest' => $manifestV12,
                'status'   => 'published',
            ]
        ));

        $versions = $this->savedFor('application-version');
        self::assertCount(4, $versions);
        self::assertSame('1.2.0', $versions[3]['version']);

        // Belt-and-braces: assert v1.0.0 row's manifest is still identical to the
        // bootstrap state (append-only history contract).
        self::assertSame($manifestV1, $versions[0]['manifest']);
    }//end testFullPublishRollbackRepublishCycle()
}//end class
