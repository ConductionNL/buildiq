<?php

/**
 * Unit tests for SeedHelloWorld repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuilt\Tests\Unit\Repair
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

namespace OCA\OpenBuilt\Tests\Unit\Repair;

use OCA\OpenBuilt\Repair\SeedHelloWorld;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SeedHelloWorld::run.
 */
class SeedHelloWorldTest extends TestCase
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
     * Mock IOutput.
     *
     * @var IOutput&MockObject
     */
    private IOutput&MockObject $output;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->output        = $this->createMock(IOutput::class);
        $this->objectService = $this->createMock(ObjectService::class);
    }//end setUp()

    /**
     * Test that getName returns a non-empty descriptive name.
     *
     * @return void
     */
    public function testGetNameReturnsDescriptiveName(): void
    {
        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);

        $name = $step->getName();

        self::assertNotEmpty($name);
        self::assertStringContainsString('hello-world', $name);
    }//end testGetNameReturnsDescriptiveName()

    /**
     * Test idempotency — when an existing hello-world Application is found, saveObject is NOT called.
     *
     * @return void
     */
    public function testRunIsIdempotentWhenSeedAlreadyExists(): void
    {
        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([['slug' => 'hello-world']]);

        $this->objectService->expects(self::never())
            ->method('saveObject');

        $this->output->expects(self::atLeastOnce())->method('info');

        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);
        $step->run($this->output);
    }//end testRunIsIdempotentWhenSeedAlreadyExists()

    /**
     * Test fresh-install path — when no existing hello-world exists, saveObject is called for the
     * Application + BuiltAppRoute + initial ApplicationVersion + currentVersion writeback + three
     * HelloMessage objects = 7 total saves.
     *
     * Per chain spec #6 openbuilt-versioning (design.md §Seed Data) the initial snapshot is
     * created at install time so the version-history panel is non-empty on the fresh-install
     * hello-world Application.
     *
     * @return void
     */
    public function testRunCreatesApplicationAndThreeMessagesOnFreshInstall(): void
    {
        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        // Returned entities must jsonSerialize() to an array carrying a uuid so the
        // seed code can chain (Application uuid → snapshot, snapshot uuid → patch).
        $appEntity = $this->createMock(ObjectEntity::class);
        $appEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'app-uuid-seed']]);

        $snapEntity = $this->createMock(ObjectEntity::class);
        $snapEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'snap-uuid-seed']]);

        $genericEntity = $this->createMock(ObjectEntity::class);
        $genericEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'generic']]);

        // ObjectService::saveObject(array|ObjectEntity $object, ?array $extend, mixed $register, mixed $schema):
        // the named-arg call site (object/register/schema) yields positional args [object, [], register, schema].
        $schemaOf = static fn (array $args): mixed => ($args['schema'] ?? ($args[3] ?? null));
        $objectOf = static fn (array $args): mixed => ($args['object'] ?? ($args[0] ?? null));

        $captured = [];
        $this->objectService->expects(self::exactly(7))
            ->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$captured, $appEntity, $snapEntity, $genericEntity, $schemaOf) {
                $captured[] = $args;
                $schema     = $schemaOf($args);
                if ($schema === 'application') {
                    return $appEntity;
                }
                if ($schema === 'application-version') {
                    return $snapEntity;
                }
                return $genericEntity;
            });

        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);
        $step->run($this->output);

        // Assert at least one save targets the application-version schema with a 1.0.0 manifest.
        $snapshotCalls = array_values(array_filter($captured, static function (array $args) use ($schemaOf): bool {
            return ($schemaOf($args) === 'application-version');
        }));
        self::assertCount(1, $snapshotCalls, 'Expected exactly one initial ApplicationVersion seed save.');
        $payload = $objectOf($snapshotCalls[0]);
        self::assertSame('1.0.0', $payload['version']);
        self::assertSame('app-uuid-seed', $payload['applicationUuid']);
        self::assertArrayHasKey('manifest', $payload);
    }//end testRunCreatesApplicationAndThreeMessagesOnFreshInstall()
}//end class
