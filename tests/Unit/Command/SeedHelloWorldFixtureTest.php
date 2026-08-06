<?php

/**
 * Unit tests for the openbuild:seed-hello-world-fixture command's HYBRID path.
 *
 * The hybrid example app is written as ONE create carrying a pre-minted
 * Application UUID, because the create/create/update shape it replaced was
 * rejected outright by HybridMetadataLockListener and therefore never seeded
 * anything. That failure was invisible for as long as it existed: the E2E job
 * had never run in CI, and global-setup.ts downgrades a seed failure to a
 * console warning.
 *
 * These tests assert the VALUES that reach the mapper, not the command's exit
 * code — an exit code of 0 is also what "already present, skipping" returns, so
 * it is not evidence that anything was written.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/unify-apps-with-app-type/specs/unified-app-model/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Command;

use OCA\OpenBuild\Command\SeedHelloWorldFixture;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for the SeedHelloWorldFixture occ command.
 */
class SeedHelloWorldFixtureTest extends TestCase
{
    /**
     * Mock OR object service.
     *
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * Every saveObject() call the command made, in order.
     *
     * Each entry is `['data' => array, 'schema' => string, 'uuid' => ?string]`.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $saved = [];

    /**
     * The command under test, wired to the mocks.
     *
     * @var SeedHelloWorldFixture
     */
    private SeedHelloWorldFixture $command;

    /**
     * Wire the command so only the HYBRID branch runs.
     *
     * `applicationExists()` is made to answer true (the hello-world virtual app
     * is already there) so `execute()` short-circuits straight to
     * `seedHybridExample()`, and `hybridExists()` answers false so the hybrid is
     * actually written. The two are distinguished by their query: only the
     * hybrid probe carries an `appType` filter.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->wire(helloWorldAlreadyPresent: true);
    }//end setUp()

    /**
     * Build the command against mocks, choosing which branch of `execute()`
     * runs.
     *
     * @param bool $helloWorldAlreadyPresent When true the hello-world probes
     *                                       answer "present", so `execute()`
     *                                       short-circuits to the hybrid path.
     *
     * @return void
     */
    private function wire(bool $helloWorldAlreadyPresent): void
    {
        $this->saved         = [];
        $this->objectService = $this->createMock(ObjectService::class);
        $registerMapper      = $this->createMock(RegisterMapper::class);
        $schemaMapper        = $this->createMock(SchemaMapper::class);

        $register = $this->createMock(Register::class);
        $register->method('getId')->willReturn(1);
        $registerMapper->method('find')->willReturn($register);

        $schema = $this->createMock(Schema::class);
        $schema->method('getId')->willReturn(2);
        $schemaMapper->method('find')->willReturn($schema);

        $this->objectService->method('searchObjects')->willReturnCallback(
            static function (array $query=[]) use ($helloWorldAlreadyPresent) {
                // The hybrid probe — nothing seeded yet.
                if (isset($query['appType']) === true) {
                    return [];
                }

                // The hello-world virtual app / route probes.
                if ($helloWorldAlreadyPresent === true) {
                    return [['uuid' => 'existing-hello-world']];
                }

                return [];
            }
        );

        $this->objectService->method('saveObject')->willReturnCallback(
            function (
                array|ObjectEntity $object,
                ?array $extend=[],
                Register|string|int|null $register=null,
                Schema|string|int|null $schema=null,
                ?string $uuid=null
            ): ObjectEntity {
                // The identifier the store hands back: the caller-supplied one
                // when the caller minted it, otherwise a deterministic stand-in
                // so a test can name it.
                $returned = ($uuid ?? 'store-minted-'.(count($this->saved) + 1));

                $this->saved[] = [
                    'data'     => $object,
                    'schema'   => $schema,
                    'uuid'     => $uuid,
                    'returned' => $returned,
                ];

                $entity = $this->createMock(ObjectEntity::class);
                $entity->method('getUuid')->willReturn($returned);

                return $entity;
            }
        );

        $this->command = new SeedHelloWorldFixture(
            $this->objectService,
            $registerMapper,
            $schemaMapper
        );
    }//end wire()

    /**
     * The LAST write to the hello-world Application still carries its
     * permissions block.
     *
     * This is the field-preservation assertion, and it is the whole point of the
     * test. OR's `saveObject()` update path is PUT-semantic —
     * `fillMissingSchemaPropertiesWithNull()` nulls every schema property the
     * payload omits — so the write whose only intent is to attach
     * `productionVersion` will silently wipe `permissions` unless it repeats it.
     *
     * An empty permissions block denies everyone (`allowAdminBypass` is false),
     * which is indistinguishable, from a spec's side, from the product refusing
     * an owner: the manifest editor renders `readonly`, the owner-only Settings
     * entry is absent, and the copilot execute endpoint answers 403. Asserting
     * only the field the write MEANT to change would pass on the broken code.
     *
     * @return void
     */
    public function testThePointerUpdateDoesNotWipeTheApplicationPermissions(): void
    {
        $this->wire(helloWorldAlreadyPresent: false);

        $tester = new CommandTester($this->command);
        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $applicationWrites = array_values(
            array_filter(
                $this->saved,
                static fn(array $call): bool => $call['schema'] === 'application'
                    && ($call['data']['slug'] ?? null) === 'hello-world'
            )
        );

        $this->assertGreaterThanOrEqual(
            2,
            count($applicationWrites),
            'the fixture writes the Application, then updates it with the production pointer'
        );

        $last = end($applicationWrites)['data'];

        $this->assertSame(
            ['user:admin'],
            $last['permissions']['owners'] ?? null,
            'the production-pointer write must carry the permissions block forward — '
            .'OR nulls every schema property a partial update omits, and an empty '
            .'permissions block denies everyone'
        );
        $this->assertArrayHasKey(
            'productionVersion',
            $last,
            'the update this test guards is the one that attaches the production pointer'
        );
    }//end testThePointerUpdateDoesNotWipeTheApplicationPermissions()

    /**
     * The hybrid app is written as ONE create, and its production pointer and
     * description are both part of it.
     *
     * The create/create/update shape this replaced sent `description` and
     * `productionVersion` in an UPDATE, which is the only event
     * HybridMetadataLockListener fires on. Asserting on the number of
     * Application writes and on `uuid` being non-null is what distinguishes a
     * create from an update here — `saveObject()` resolves a `uuid:` naming a
     * non-existent object to a create, so a single Application write with a
     * uuid is a create by construction.
     *
     * @return void
     */
    public function testHybridAppIsWrittenAsASingleCreateCarryingItsProductionPointer(): void
    {
        $tester = new CommandTester($this->command);
        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $applicationWrites = array_values(
            array_filter(
                $this->saved,
                static fn(array $call): bool => $call['schema'] === 'application'
            )
        );
        $versionWrites = array_values(
            array_filter(
                $this->saved,
                static fn(array $call): bool => $call['schema'] === 'applicationVersion'
            )
        );

        $this->assertCount(
            1,
            $applicationWrites,
            'the hybrid Application must be written exactly once — a second write '
            .'is an UPDATE, and an update is what HybridMetadataLockListener rejects'
        );
        $this->assertCount(1, $versionWrites, 'exactly one delta ApplicationVersion is seeded');

        $application = $applicationWrites[0]['data'];
        $version     = $versionWrites[0]['data'];

        $this->assertSame('hybrid', $application['appType']);
        $this->assertSame('opencatalogi', $application['slug']);
        $this->assertArrayHasKey(
            'description',
            $application,
            'description belongs in the create, not in a follow-up update'
        );
        $this->assertSame(
            $versionWrites[0]['returned'],
            $application['productionVersion'],
            'the Application must point at the delta version that was just created'
        );
    }//end testHybridAppIsWrittenAsASingleCreateCarryingItsProductionPointer()

    /**
     * The forward reference between the two writes is consistent, and the
     * Application UUID is a real RFC-4122 v4.
     *
     * The version is written BEFORE the Application exists and names it by UUID,
     * so a malformed or non-matching identifier would leave the delta version
     * pointing at nothing — a shape that reads, from the outside, exactly like a
     * hybrid app with no delta at all.
     *
     * @return void
     */
    public function testTheMintedApplicationUuidIsAValidV4AndIsWhatTheVersionPointsAt(): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute([]);

        $applicationWrites = array_values(
            array_filter(
                $this->saved,
                static fn(array $call): bool => $call['schema'] === 'application'
            )
        );
        $versionWrites = array_values(
            array_filter(
                $this->saved,
                static fn(array $call): bool => $call['schema'] === 'applicationVersion'
            )
        );

        $applicationUuid = $applicationWrites[0]['uuid'];

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            (string) $applicationUuid,
            'the minted identifier must be a canonical UUIDv4 — variant and version nibbles included'
        );
        $this->assertSame(
            $applicationUuid,
            $versionWrites[0]['data']['application'],
            'the delta version must name the same Application the create writes'
        );
    }//end testTheMintedApplicationUuidIsAValidV4AndIsWhatTheVersionPointsAt()

    /**
     * Two runs mint two different identifiers.
     *
     * A constant would make the seeder non-idempotent in the worst way: the
     * second run's version would attach to the first run's Application.
     *
     * @return void
     */
    public function testEachRunMintsADistinctApplicationUuid(): void
    {
        (new CommandTester($this->command))->execute([]);
        $first = $this->saved;

        $this->saved = [];
        (new CommandTester($this->command))->execute([]);

        $firstUuid = array_values(
            array_filter($first, static fn(array $c): bool => $c['schema'] === 'application')
        )[0]['uuid'];
        $secondUuid = array_values(
            array_filter($this->saved, static fn(array $c): bool => $c['schema'] === 'application')
        )[0]['uuid'];

        $this->assertNotSame($firstUuid, $secondUuid);
    }//end testEachRunMintsADistinctApplicationUuid()
}//end class
