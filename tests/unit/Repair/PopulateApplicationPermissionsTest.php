<?php

/**
 * Unit tests for PopulateApplicationPermissions repair step.
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

use OCA\OpenBuilt\Repair\PopulateApplicationPermissions;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for PopulateApplicationPermissions::run.
 */
class PopulateApplicationPermissionsTest extends TestCase
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
        $step = new PopulateApplicationPermissions(
            logger: $this->logger,
            objectService: $this->objectService
        );

        self::assertNotEmpty($step->getName());
        self::assertStringContainsString('permissions', $step->getName());
    }//end testGetNameReturnsDescriptiveName()

    /**
     * Applications without `permissions` get patched; those with non-empty
     * `permissions.owners` are skipped (idempotent).
     *
     * @return void
     */
    public function testRunPatchesOnlyApplicationsMissingPermissions(): void
    {
        $missing = [
            '@self'    => ['id' => 'uuid-missing'],
            'slug'     => 'legacy',
            'manifest' => ['version' => '1.0.0'],
        ];
        $populated = [
            '@self'       => ['id' => 'uuid-populated'],
            'slug'        => 'modern',
            'manifest'    => ['version' => '1.0.0'],
            'permissions' => [
                'owners'  => ['team-alpha'],
                'editors' => [],
                'viewers' => [],
            ],
        ];

        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([$missing, $populated]);

        $this->objectService->expects(self::once())
            ->method('saveObject')
            ->with(
                self::callback(static function (array $object): bool {
                    return ($object['@self']['id'] ?? null) === 'uuid-missing'
                        && ($object['permissions']['owners'] ?? null) === ['admin']
                        && ($object['permissions']['editors'] ?? null) === []
                        && ($object['permissions']['viewers'] ?? null) === [];
                }),
                'openbuilt',
                'application'
            );

        $step = new PopulateApplicationPermissions(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $step->run($this->output);
    }//end testRunPatchesOnlyApplicationsMissingPermissions()

    /**
     * Re-running the migration on a fully populated install is a no-op.
     *
     * @return void
     */
    public function testRunIsIdempotentWhenAllPopulated(): void
    {
        $populated = [
            '@self'       => ['id' => 'uuid-populated'],
            'slug'        => 'modern',
            'permissions' => [
                'owners'  => ['team-alpha'],
                'editors' => [],
                'viewers' => [],
            ],
        ];

        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([$populated]);
        $this->objectService->expects(self::never())->method('saveObject');

        $step = new PopulateApplicationPermissions(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $step->run($this->output);
    }//end testRunIsIdempotentWhenAllPopulated()

    /**
     * Applications with `permissions.owners = []` are treated as needing
     * migration (they'd be unreachable otherwise — REQ-OBRBAC-005).
     *
     * @return void
     */
    public function testRunPatchesWhenOwnersArrayIsEmpty(): void
    {
        $orphan = [
            '@self'       => ['id' => 'uuid-orphan'],
            'slug'        => 'orphan',
            'permissions' => [
                'owners'  => [],
                'editors' => ['team-alpha'],
                'viewers' => [],
            ],
        ];

        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([$orphan]);
        $this->objectService->expects(self::once())->method('saveObject');

        $step = new PopulateApplicationPermissions(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $step->run($this->output);
    }//end testRunPatchesWhenOwnersArrayIsEmpty()

    /**
     * Empty Application list — no-op without exception.
     *
     * @return void
     */
    public function testRunSucceedsOnEmptyApplicationList(): void
    {
        $this->objectService->expects(self::once())
            ->method('findAll')
            ->willReturn([]);
        $this->objectService->expects(self::never())->method('saveObject');

        $step = new PopulateApplicationPermissions(
            logger: $this->logger,
            objectService: $this->objectService
        );
        $step->run($this->output);
    }//end testRunSucceedsOnEmptyApplicationList()
}//end class
