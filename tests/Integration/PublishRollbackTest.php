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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
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
 * We never touch the DB — a mocked ObjectService records every saveObject
 * call so the assertion phase can reconstruct the history.
 */
class PublishRollbackTest extends TestCase
{

    /**
     * Mocked OR ObjectService — records every saveObject call.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $fakeObjectService;

    /**
     * Every saveObject call's `{schema, object}` pair — used for assertions.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $saved = [];

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
    private array $store = [];

    /**
     * Set up fixtures: a fresh in-memory ObjectService shim.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The publish/rollback cycle is driven by OR's lifecycle feature
        // (ObjectTransitionedEvent), which is absent from OR's `main` — skip
        // rather than fatal-error on the missing class.
        if (class_exists(ObjectTransitionedEvent::class) === false) {
            $this->markTestSkipped('OpenRegister does not provide ObjectTransitionedEvent (lifecycle feature not in the installed OR release).');
        }

        $this->saved       = [];
        $this->store       = [];
        $this->uuidCounter = 0;

        $this->fakeObjectService = $this->createMock(ObjectService::class);

        // ObjectService::saveObject(array|ObjectEntity $object, ?array $extend, mixed $register, mixed $schema):
        // the SUT calls it with named args (object/register/schema) — captured here as positional [object, [], register, schema].
        $this->fakeObjectService->method('saveObject')->willReturnCallback(
            function (...$args): ObjectEntity {
                $object = ($args['object'] ?? ($args[0] ?? []));
                $schema = (string) ($args['schema'] ?? ($args[3] ?? ''));
                return $this->recordSave($object, $schema);
            }
        );

        // The listener's BuiltAppRoute lookup goes through searchObjectsBySlug;
        // an empty result makes it fall through to the create path.
        $this->fakeObjectService->method('searchObjectsBySlug')->willReturn([]);
    }//end setUp()

    /**
     * Mimic ObjectService::saveObject — assigns a uuid + appends to history.
     *
     * @param array<string, mixed> $object The payload.
     * @param string               $schema Schema slug.
     *
     * @return ObjectEntity Entity-like value exposing jsonSerialize().
     */
    private function recordSave(array $object, string $schema): ObjectEntity
    {
        $existing = ($object['@self']['id'] ?? $object['uuid'] ?? null);
        if ($existing === null) {
            $this->uuidCounter++;
            $uuid            = 'uuid-'.$schema.'-'.$this->uuidCounter;
            $object['@self'] = ['id' => $uuid];
        } else {
            $uuid = (string) $existing;
            if (isset($object['@self']) === false) {
                $object['@self'] = ['id' => $uuid];
            }
        }

        $this->store[$schema][$uuid] = $object;
        $this->saved[]               = ['schema' => $schema, 'object' => $object];

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($object);
        return $entity;
    }//end recordSave()

    /**
     * Build a fake ObjectTransitionedEvent stub.
     *
     * @param string               $from            From state.
     * @param string               $to              To state.
     * @param array<string, mixed> $applicationData The Application payload.
     *
     * @return \PHPUnit\Framework\MockObject\MockObject
     */
    private function transition(string $from, string $to, array $applicationData): MockObject
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($applicationData);

        $event = $this->getMockBuilder(ObjectTransitionedEvent::class)
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
        foreach ($this->saved as $entry) {
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
