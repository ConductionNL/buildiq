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
     * Mock OR ObjectService — typed as object since the real class lives in another app.
     *
     * @var MockObject
     */
    private MockObject $objectService;

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
        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['findAll', 'saveObject'])
            ->getMock();
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
        $appEntity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $appEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'app-uuid-seed']]);

        $snapEntity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $snapEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'snap-uuid-seed']]);

        $genericEntity = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['jsonSerialize'])
            ->getMock();
        $genericEntity->method('jsonSerialize')->willReturn(['@self' => ['id' => 'generic']]);

        $captured = [];
        $this->objectService->expects(self::exactly(7))
            ->method('saveObject')
            ->willReturnCallback(function (...$args) use (&$captured, $appEntity, $snapEntity, $genericEntity) {
                $captured[] = $args;
                $schema     = $args['schema'] ?? ($args[2] ?? null);
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
        $snapshotCalls = array_values(array_filter($captured, function (array $args): bool {
            return (($args['schema'] ?? ($args[2] ?? null)) === 'application-version');
        }));
        self::assertCount(1, $snapshotCalls, 'Expected exactly one initial ApplicationVersion seed save.');
        $payload = $snapshotCalls[0]['object'] ?? $snapshotCalls[0][0];
        self::assertSame('1.0.0', $payload['version']);
        self::assertSame('app-uuid-seed', $payload['applicationUuid']);
        self::assertArrayHasKey('manifest', $payload);
    }//end testRunCreatesApplicationAndThreeMessagesOnFreshInstall()
}//end class
