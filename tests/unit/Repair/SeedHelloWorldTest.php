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
     * Mock OR ObjectService — typed against the stub when OR is not on the
     * autoload path; against the real class when it is. createMock() yields
     * a usable double either way.
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
     * Application plus three HelloMessage objects (4 total saves at minimum).
     *
     * NOTE: the actual implementation also writes a BuiltAppRoute when the
     * Application's @self.id is exposed (5 saves total). The minimum-4
     * guarantee documented here protects against regressions that skip
     * any of the four core writes (1 Application + 3 messages).
     *
     * @return void
     */
    public function testRunCreatesApplicationAndThreeMessagesOnFreshInstall(): void
    {
        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        // Stub Application save to return an ObjectEntity that does NOT expose
        // a @self.id — the BuiltAppRoute write path is skipped, leaving
        // exactly 4 calls: 1 Application + 3 sample HelloMessages. We mock the
        // concrete ObjectEntity (saveObject is typed `: ObjectEntity`) and
        // make jsonSerialize() return an empty array.
        $bareEntity = $this->createMock(ObjectEntity::class);
        $bareEntity->method('jsonSerialize')->willReturn([]);

        $this->objectService->expects(self::exactly(4))
            ->method('saveObject')
            ->willReturn($bareEntity);

        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);
        $step->run($this->output);
    }//end testRunCreatesApplicationAndThreeMessagesOnFreshInstall()

    /**
     * Test that the BuiltAppRoute upkeep path is exercised when the
     * Application save returns an entity exposing @self.id — five saves
     * total (Application + BuiltAppRoute + 3 sample messages). This
     * locks the design.md Decision 6 fallback for the missing
     * x-openregister-lifecycle `on_transition.upsert_relation` hook.
     *
     * @return void
     */
    public function testRunCreatesBuiltAppRouteWhenApplicationUuidIsExposed(): void
    {
        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        // Stub Application save to return an ObjectEntity exposing @self.id —
        // five saves total (Application + BuiltAppRoute + 3 sample messages).
        $applicationEntity = $this->createMock(ObjectEntity::class);
        $applicationEntity->method('jsonSerialize')
            ->willReturn(['@self' => ['id' => 'app-uuid-123']]);

        $this->objectService->expects(self::exactly(5))
            ->method('saveObject')
            ->willReturn($applicationEntity);

        $step = new SeedHelloWorld(logger: $this->logger, objectService: $this->objectService);
        $step->run($this->output);
    }//end testRunCreatesBuiltAppRouteWhenApplicationUuidIsExposed()
}//end class
