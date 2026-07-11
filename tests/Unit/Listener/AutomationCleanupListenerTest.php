<?php

/**
 * Unit tests for AutomationCleanupListener.
 *
 * Covers spec REQ-AUTD-005: deleting an automation removes exactly the
 * provenance-listed compiled artifacts via the post-delete OR event.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Listener;

use OCA\OpenBuild\Listener\AutomationCleanupListener;
use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see AutomationCleanupListener}.
 */
final class AutomationCleanupListenerTest extends TestCase
{
    /**
     * @var AutomationCompilerService&MockObject
     */
    private AutomationCompilerService&MockObject $compiler;

    /**
     * Listener under test.
     *
     * @var AutomationCleanupListener
     */
    private AutomationCleanupListener $listener;

    /**
     * Set up mocks + SUT.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->compiler = $this->createMock(AutomationCompilerService::class);
        $this->listener = new AutomationCleanupListener($this->createMock(LoggerInterface::class), $this->compiler);

    }//end setUp()

    /**
     * A deleted `automation` row triggers compiler removal with its
     * provenance block.
     *
     * @return void
     */
    public function testRemovesArtifactsForDeletedAutomation(): void
    {
        $automation = [
            '@self'       => ['schema' => 'automation'],
            'slug'        => 'notify-caseworkers',
            'provenance'  => ['notificationKeys' => [['schema' => 'permit', 'key' => 'aut-notify-caseworkers-1']]],
        ];

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($automation);
        $entity->method('getObject')->willReturn($automation);

        $event = new ObjectDeletedEvent($entity);

        $this->compiler->expects($this->once())
            ->method('remove')
            ->with(
                $this->callback(static fn (array $a): bool => ($a['slug'] ?? null) === 'notify-caseworkers'),
                $this->callback(static fn (array $p): bool => isset($p['notificationKeys']))
            );

        $this->listener->handle($event);

    }//end testRemovesArtifactsForDeletedAutomation()

    /**
     * A deleted row of a different schema is ignored (no compiler call).
     *
     * @return void
     */
    public function testIgnoresNonAutomationSchema(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn(['@self' => ['schema' => 'application']]);
        $entity->method('getObject')->willReturn([]);

        $event = new ObjectDeletedEvent($entity);

        $this->compiler->expects($this->never())->method('remove');

        $this->listener->handle($event);

    }//end testIgnoresNonAutomationSchema()

    /**
     * A different event type entirely is ignored.
     *
     * @return void
     */
    public function testIgnoresOtherEventTypes(): void
    {
        $entity = $this->createMock(ObjectEntity::class);
        $event  = new ObjectUpdatingEvent($entity);

        $this->compiler->expects($this->never())->method('remove');

        $this->listener->handle($event);

    }//end testIgnoresOtherEventTypes()

    /**
     * A compiler failure is logged, never re-thrown.
     *
     * @return void
     */
    public function testCompilerFailureIsSwallowed(): void
    {
        $automation = ['@self' => ['schema' => 'automation'], 'slug' => 'broken'];
        $entity     = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($automation);
        $entity->method('getObject')->willReturn($automation);

        $event = new ObjectDeletedEvent($entity);

        $this->compiler->method('remove')->willThrowException(new \RuntimeException('boom'));

        $this->listener->handle($event);
        $this->addToAssertionCount(1);

    }//end testCompilerFailureIsSwallowed()
}//end class
