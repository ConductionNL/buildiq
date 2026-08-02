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
use OCA\OpenBuild\Service\ListenerSlugContract;
use OCA\OpenBuild\Service\ObjectSchemaSlugResolver;
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
     * cleanup path itself. `testDoesNothingWhenContractDisabled()` covers the
     * shipped default, which is off.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->compiler = $this->createMock(AutomationCompilerService::class);
        $this->slugs    = $this->createMock(ObjectSchemaSlugResolver::class);
        $this->contract = $this->createMock(ListenerSlugContract::class);
        $this->contract->method('isEnabled')->willReturn(true);

        $this->listener = new AutomationCleanupListener(
            $this->createMock(LoggerInterface::class),
            $this->compiler,
            $this->slugs,
            $this->contract
        );

    }//end setUp()

    /**
     * The shipped default is OFF: an automation delete must change nothing,
     * because waking this listener starts deleting compiled artifacts on a
     * path that has never executed.
     *
     * @return void
     */
    public function testDoesNothingWhenContractDisabled(): void
    {
        $compiler = $this->createMock(AutomationCompilerService::class);
        $slugs    = $this->createMock(ObjectSchemaSlugResolver::class);
        $contract = $this->createMock(ListenerSlugContract::class);
        $contract->method('isEnabled')->willReturn(false);

        $listener = new AutomationCleanupListener(
            $this->createMock(LoggerInterface::class),
            $compiler,
            $slugs,
            $contract
        );

        $automation = [
            '@self'      => ['schema' => '116'],
            'slug'       => 'notify-caseworkers',
            'provenance' => ['notificationKeys' => [['schema' => 'permit', 'key' => 'k']]],
        ];

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($automation);
        $entity->method('getObject')->willReturn($automation);

        // Not even the slug lookup happens — the gate returns first.
        $slugs->expects($this->never())->method('isOpenBuildSchema');
        $compiler->expects($this->never())->method('remove');

        $listener->handle(new ObjectDeletedEvent($entity));

    }//end testDoesNothingWhenContractDisabled()

    /**
     * A deleted `automation` row triggers compiler removal with its
     * provenance block.
     *
     * @return void
     */
    public function testRemovesArtifactsForDeletedAutomation(): void
    {
        // `@self.schema` carries the NUMERIC ID, as MagicMapper writes it —
        // the old test fed a slug here, which is why it passed against a
        // listener that could never match in production.
        $automation = [
            '@self'       => ['schema' => '116'],
            'slug'        => 'notify-caseworkers',
            'provenance'  => ['notificationKeys' => [['schema' => 'permit', 'key' => 'aut-notify-caseworkers-1']]],
        ];

        $entity = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($automation);
        $entity->method('getObject')->willReturn($automation);

        $this->slugs->expects($this->once())
            ->method('isOpenBuildSchema')
            ->with($entity, AutomationCompilerService::AUTOMATION_SCHEMA)
            ->willReturn(true);

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
        $entity->method('jsonSerialize')->willReturn(['@self' => ['schema' => '117']]);
        $entity->method('getObject')->willReturn([]);

        $this->slugs->method('isOpenBuildSchema')->willReturn(false);

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
        $automation = ['@self' => ['schema' => '116'], 'slug' => 'broken'];
        $entity     = $this->createMock(ObjectEntity::class);
        $entity->method('jsonSerialize')->willReturn($automation);
        $entity->method('getObject')->willReturn($automation);

        $this->slugs->method('isOpenBuildSchema')->willReturn(true);

        $event = new ObjectDeletedEvent($entity);

        $this->compiler->method('remove')->willThrowException(new \RuntimeException('boom'));

        $this->listener->handle($event);
        $this->addToAssertionCount(1);

    }//end testCompilerFailureIsSwallowed()
}//end class
