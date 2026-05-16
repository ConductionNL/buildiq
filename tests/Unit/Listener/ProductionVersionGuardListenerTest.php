<?php

/**
 * Unit tests for ProductionVersionGuardListener.
 *
 * Covers spec REQ-OBV-105 / REQ-OBA-008: cross-row back-reference
 * integrity guard on Application.productionVersion.
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

use OCA\OpenBuilt\Listener\ProductionVersionGuardListener;
use OCA\OpenBuilt\Service\ApplicationVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ProductionVersionGuardListener.
 */
class ProductionVersionGuardListenerTest extends TestCase
{
    /**
     * Mock logger.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    /**
     * Mock service.
     *
     * @var ApplicationVersionService&MockObject
     */
    private ApplicationVersionService&MockObject $service;

    /**
     * Listener under test.
     */
    private ProductionVersionGuardListener $listener;

    /**
     * Set up mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger  = $this->createMock(LoggerInterface::class);
        $this->service = $this->createMock(ApplicationVersionService::class);

        $this->listener = new ProductionVersionGuardListener(
            logger: $this->logger,
            service: $this->service,
        );
    }//end setUp()

    /**
     * Guard skips events for non-Application schemas (no service call).
     *
     * @return void
     */
    public function testIgnoresNonApplicationSchema(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn([
            '@self'              => ['schema' => 'applicationVersion'],
            'productionVersion'  => 'uuid-v',
        ]);
        $entity->method('getObject')->willReturn(['productionVersion' => 'uuid-v']);

        $event = new ObjectUpdatingEvent($entity);

        $this->service->expects(self::never())->method('guardProductionVersionOwnership');

        $this->listener->handle($event);
        self::assertFalse($event->isPropagationStopped());
    }//end testIgnoresNonApplicationSchema()

    /**
     * Guard skips when productionVersion is unset.
     *
     * @return void
     */
    public function testSkipsWhenProductionVersionAbsent(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn([
            '@self' => ['schema' => 'application'],
        ]);
        $entity->method('getObject')->willReturn(['slug' => 'foo']);

        $event = new ObjectUpdatingEvent($entity);

        $this->service->expects(self::never())->method('guardProductionVersionOwnership');

        $this->listener->handle($event);
        self::assertFalse($event->isPropagationStopped());
    }//end testSkipsWhenProductionVersionAbsent()

    /**
     * Guard stops propagation + attaches an error when the service throws.
     *
     * @return void
     */
    public function testStopsPropagationOnGuardFailure(): void
    {
        $entity = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize', 'getObject'])
            ->addMethods(['getUuid'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn([
            '@self' => ['schema' => 'application'],
        ]);
        $entity->method('getObject')->willReturn(['productionVersion' => 'uuid-other']);
        $entity->method('getUuid')->willReturn('uuid-this-app');

        $this->service->expects(self::once())
            ->method('guardProductionVersionOwnership')
            ->with(applicationUuid: 'uuid-this-app', proposedVersionUuid: 'uuid-other')
            ->willThrowException(new RuntimeException(message: 'back-reference mismatch'));

        $event = new ObjectUpdatingEvent($entity);

        $this->listener->handle($event);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(422, $event->getErrors()['status'] ?? null);
    }//end testStopsPropagationOnGuardFailure()
}//end class
