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
 * @package  OCA\OpenBuild\Tests\Unit\Listener
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

namespace OCA\OpenBuild\Tests\Unit\Listener;

use OCA\OpenBuild\Listener\ProductionVersionGuardListener;
use OCA\OpenBuild\Service\ApplicationVersionService;
use OCA\OpenBuild\Service\ListenerSlugContract;
use OCA\OpenBuild\Service\ObjectSchemaSlugResolver;
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
     * Resolver double: turns the entity's schema id into a slug.
     *
     * @var ObjectSchemaSlugResolver&MockObject
     */
    private ObjectSchemaSlugResolver&MockObject $slugs;

    /**
     * Opt-in flag double for the corrected slug comparison.
     *
     * @var ListenerSlugContract&MockObject
     */
    private ListenerSlugContract&MockObject $contract;

    /**
     * Set up mocks + SUT.
     *
     * The contract defaults to ENABLED here so the tests below exercise the
     * guard itself. `testGuardStaysDormantWhenContractDisabled()` covers the
     * shipped default, which is off — this guard is fail-closed and waking it
     * starts rejecting production-version writes that succeed today.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logger   = $this->createMock(LoggerInterface::class);
        $this->service  = $this->createMock(ApplicationVersionService::class);
        $this->slugs    = $this->createMock(ObjectSchemaSlugResolver::class);
        $this->contract = $this->createMock(ListenerSlugContract::class);
        $this->contract->method('isEnabled')->willReturn(true);

        $this->listener = new ProductionVersionGuardListener(
            logger: $this->logger,
            service: $this->service,
            slugs: $this->slugs,
            contract: $this->contract,
        );
    }//end setUp()

    /**
     * The shipped default is OFF: a mismatching productionVersion must still
     * be allowed through, exactly as today.
     *
     * @return void
     */
    public function testGuardStaysDormantWhenContractDisabled(): void
    {
        $service  = $this->createMock(ApplicationVersionService::class);
        $slugs    = $this->createMock(ObjectSchemaSlugResolver::class);
        $contract = $this->createMock(ListenerSlugContract::class);
        $contract->method('isEnabled')->willReturn(false);

        $listener = new ProductionVersionGuardListener(
            logger: $this->createMock(LoggerInterface::class),
            service: $service,
            slugs: $slugs,
            contract: $contract,
        );

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn(['@self' => ['schema' => '116']]);
        $entity->method('getObject')->willReturn(['productionVersion' => 'uuid-other']);

        $slugs->expects(self::never())->method('isOpenBuildSchema');
        $service->expects(self::never())->method('guardProductionVersionOwnership');

        $event = new ObjectUpdatingEvent($entity);
        $listener->handle($event);

        self::assertFalse($event->isPropagationStopped());
    }//end testGuardStaysDormantWhenContractDisabled()

    /**
     * Guard skips events for non-Application schemas (no service call).
     *
     * @return void
     */
    public function testIgnoresNonApplicationSchema(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn([
            '@self'              => ['schema' => '117'],
            'productionVersion'  => 'uuid-v',
        ]);
        $entity->method('getObject')->willReturn(['productionVersion' => 'uuid-v']);

        $this->slugs->method('isOpenBuildSchema')->willReturn(false);

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
            '@self' => ['schema' => '116'],
        ]);
        $entity->method('getObject')->willReturn(['slug' => 'foo']);

        $this->slugs->method('isOpenBuildSchema')->willReturn(true);

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
        // getUuid() is a real declared method on the OpenRegister test stub
        // (added by automation-approval-steps for AutomationApprovalTriggerListenerTest
        // — previously only resolved via Entity::__call magic) — must go
        // through onlyMethods(), not addMethods() (PHPUnit 10 throws
        // CannotUseAddMethodsException for a method that already exists).
        $entity = $this->getMockBuilder(ObjectEntity::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['jsonSerialize', 'getObject', 'getUuid'])
            ->getMock();
        $entity->method('jsonSerialize')->willReturn([
            '@self' => ['schema' => '116'],
        ]);
        $entity->method('getObject')->willReturn(['productionVersion' => 'uuid-other']);
        $entity->method('getUuid')->willReturn('uuid-this-app');

        $this->slugs->expects(self::once())
            ->method('isOpenBuildSchema')
            ->with($entity, ApplicationVersionService::APPLICATION_SCHEMA)
            ->willReturn(true);

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
