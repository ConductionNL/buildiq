<?php

/**
 * Unit tests for AutomationCompilerService.
 *
 * Covers REQ-AUTD-004 (one test per matrix ✅ cell, determinism, idempotent
 * recompile), REQ-AUTD-003 (unsupported cells throw naming the combination)
 * and REQ-AUTD-005 (delete removes only provenance-listed artifacts; drift
 * hash mismatch is detected).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenBuild\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/automation-designer/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Exception\UnsupportedAutomationCombinationException;
use OCA\OpenBuild\Service\AutomationCompilerService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for {@see AutomationCompilerService}.
 */
final class AutomationCompilerServiceTest extends TestCase
{
    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * @var SchemaMapper&MockObject
     */
    private SchemaMapper&MockObject $schemaMapper;

    /**
     * The service under test.
     *
     * @var AutomationCompilerService
     */
    private AutomationCompilerService $compiler;

    /**
     * Wire the compiler with mocked OR boundaries.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->schemaMapper  = $this->createMock(SchemaMapper::class);
        $this->compiler      = new AutomationCompilerService($this->objectService, $this->schemaMapper, new NullLogger());

    }//end setUp()

    /**
     * Build a mock Schema entity carrying the given configuration, capturing
     * every `setConfiguration()` call into `$captured` (last write wins).
     *
     * @param array<string,mixed> $initialConfig Initial `getConfiguration()` return.
     * @param array<string,mixed> &$captured     Reference populated on each setConfiguration() call.
     *
     * @return Schema&MockObject
     */
    private function schemaWithConfig(array $initialConfig, array &$captured): Schema&MockObject
    {
        $schema = $this->createMock(Schema::class);
        $current = $initialConfig;
        $schema->method('getConfiguration')->willReturnCallback(static function () use (&$current) {
            return $current;
        });
        $schema->method('setConfiguration')->willReturnCallback(static function ($config) use (&$current, &$captured) {
            $current   = $config;
            $captured  = $config;
        });

        return $schema;

    }//end schemaWithConfig()

    /**
     * REQ-AUTD-004 scenario 1: event notification compiles to the
     * notifications dialect.
     *
     * @return void
     */
    public function testObjectCreatedPlusNotificationCompilesToDialectEntry(): void
    {
        $automation = [
            'id'              => 'auto-1',
            'slug'            => 'notify-caseworkers',
            'name'            => 'Notify case workers',
            'applicationSlug' => 'permit-tracker',
            'versionUuid'     => 'version-1',
            'enabled'         => true,
            'trigger'         => ['type' => 'object-created', 'schema' => 'permit'],
            'condition'       => null,
            'actions'         => [
                [
                    'type'       => 'send-notification',
                    'channels'   => ['nc-notification'],
                    'recipients' => [['kind' => 'object-acl', 'permission' => 'manage']],
                    'subject'    => ['en' => 'New permit', 'nl' => 'Nieuwe vergunning'],
                ],
            ],
        ];

        $plan = $this->compiler->compile($automation);

        $this->assertSame(
            [
                [
                    'schema' => 'permit',
                    'key'    => 'aut-notify-caseworkers-1',
                    'entry'  => [
                        'trigger'    => ['type' => 'created'],
                        'enabled'    => true,
                        'channels'   => ['nc-notification'],
                        'recipients' => [['kind' => 'object-acl', 'permission' => 'manage']],
                        'subject'    => ['en' => 'New permit', 'nl' => 'Nieuwe vergunning'],
                    ],
                ],
            ],
            $plan['notifications']
        );
        $this->assertSame([], $plan['lifecycleActions']);
        $this->assertSame([], $plan['schedules']);
        $this->assertNull($plan['ruleSet']);
        $this->assertStringStartsWith('sha256:', $plan['hash']);

    }//end testObjectCreatedPlusNotificationCompilesToDialectEntry()

    /**
     * REQ-AUTD-004 scenario 2: scheduled sync compiles to a schedules entry.
     *
     * @return void
     */
    public function testSchedulePlusRunSynchronizationCompilesToScheduleEntry(): void
    {
        $automation = [
            'id'              => 'auto-2',
            'slug'            => 'nightly-sync',
            'name'            => 'Nightly sync',
            'applicationSlug' => 'permit-tracker',
            'versionUuid'     => 'version-1',
            'enabled'         => true,
            'trigger'         => ['type' => 'schedule', 'interval' => 86400],
            'condition'       => null,
            'actions'         => [['type' => 'run-synchronization', 'synchronizationId' => 'sync-1']],
        ];

        $plan = $this->compiler->compile($automation);

        $this->assertSame(
            [
                [
                    'id'        => 'aut-nightly-sync-1',
                    'enabled'   => true,
                    'action'    => 'openconnector:synchronization',
                    'arguments' => ['synchronizationId' => 'sync-1'],
                    'interval'  => 86400,
                ],
            ],
            $plan['schedules']
        );

    }//end testSchedulePlusRunSynchronizationCompilesToScheduleEntry()

    /**
     * REQ-AUTD-004 scenario 3: manual automation compiles to a namespaced
     * rule set + one ConditionActionRule.
     *
     * @return void
     */
    public function testManualPlusConditionPlusObjectOpCompilesToRuleSet(): void
    {
        $automation = [
            'id'              => '11112222-3333-4444-5555-666677778888',
            'slug'            => 'flag-large-claims',
            'name'            => 'Flag large claims',
            'applicationSlug' => 'claims',
            'versionUuid'     => 'version-3',
            'enabled'         => true,
            'trigger'         => ['type' => 'manual'],
            'condition'       => ['type' => 'feel', 'expression' => 'payload.amount > 1000'],
            'actions'         => [
                ['type' => 'object-op', 'operation' => 'create', 'schema' => 'flag', 'fieldMapping' => ['reason' => 'large-claim']],
            ],
        ];

        $plan = $this->compiler->compile($automation);

        $this->assertSame(
            [
                'slug'        => 'aut-11112222',
                'naam'        => 'Flag large claims',
                'versie'      => '1.0.0',
                'status'      => 'active',
                'ruleType'    => 'condition-action',
                'eigenaarApp' => 'claims',
            ],
            $plan['ruleSet']
        );

        $this->assertSame(
            [
                'ruleSetId' => 'aut-11112222',
                'naam'      => 'Flag large claims',
                'conditie'  => 'payload.amount > 1000',
                'acties'    => [
                    [
                        'type'       => 'object-op',
                        'parameters' => [
                            'schema'    => 'flag',
                            'operation' => 'create',
                            'object'    => ['reason' => 'large-claim'],
                            'register'  => 'openbuild',
                        ],
                    ],
                ],
                'actief'    => true,
            ],
            $plan['conditionActionRule']
        );

    }//end testManualPlusConditionPlusObjectOpCompilesToRuleSet()

    /**
     * Lifecycle-transition matrix cell: object-op/webhook compile to typed
     * lifecycle actions tagged with the `aut-<slug>` marker.
     *
     * @return void
     */
    public function testLifecycleTransitionPlusObjectOpCompilesToLifecycleAction(): void
    {
        $automation = [
            'id'              => 'auto-4',
            'slug'            => 'archive-related',
            'name'            => 'Archive related',
            'applicationSlug' => 'permit-tracker',
            'versionUuid'     => 'version-1',
            'enabled'         => true,
            'trigger'         => ['type' => 'lifecycle-transition', 'schema' => 'permit', 'transition' => 'activate'],
            'condition'       => null,
            'actions'         => [
                ['type' => 'object-op', 'operation' => 'update', 'schema' => 'audit-log', 'fieldMapping' => ['event' => 'activated']],
            ],
        ];

        $plan = $this->compiler->compile($automation);

        $this->assertSame(
            [
                [
                    'schema'     => 'permit',
                    'transition' => 'activate',
                    'marker'     => 'aut-archive-related',
                    'action'     => [
                        'type'         => 'related-object-upsert',
                        'operation'    => 'update',
                        'schema'       => 'audit-log',
                        'fieldMapping' => ['event' => 'activated'],
                        'marker'       => 'aut-archive-related',
                    ],
                ],
            ],
            $plan['lifecycleActions']
        );

    }//end testLifecycleTransitionPlusObjectOpCompilesToLifecycleAction()

    /**
     * Compilation is deterministic: identical input compiles to an identical
     * plan (including hash) across repeated calls.
     *
     * @return void
     */
    public function testCompilationIsDeterministic(): void
    {
        $automation = [
            'id'              => 'auto-1',
            'slug'            => 'notify-caseworkers',
            'name'            => 'Notify case workers',
            'applicationSlug' => 'permit-tracker',
            'versionUuid'     => 'version-1',
            'enabled'         => true,
            'trigger'         => ['type' => 'object-created', 'schema' => 'permit'],
            'condition'       => null,
            'actions'         => [['type' => 'send-notification', 'subject' => ['en' => 'x']]],
        ];

        $planA = $this->compiler->compile($automation);
        $planB = $this->compiler->compile($automation);

        $this->assertSame($planA, $planB);

    }//end testCompilationIsDeterministic()

    /**
     * REQ-AUTD-003: unsupported action for an event trigger is blocked with
     * the combination named.
     *
     * @return void
     */
    public function testObjectCreatedPlusWebhookIsBlocked(): void
    {
        $automation = [
            'id'      => 'auto-x',
            'slug'    => 'bad',
            'trigger' => ['type' => 'object-created', 'schema' => 'permit'],
            'actions' => [['type' => 'webhook', 'url' => 'https://example.test']],
        ];

        $this->expectException(UnsupportedAutomationCombinationException::class);
        $this->expectExceptionMessageMatches('/object-created.*webhook/');
        $this->compiler->compile($automation);

    }//end testObjectCreatedPlusWebhookIsBlocked()

    /**
     * REQ-AUTD-003: a condition on a schedule trigger is blocked.
     *
     * @return void
     */
    public function testConditionOnScheduleTriggerIsBlocked(): void
    {
        $automation = [
            'id'        => 'auto-x',
            'slug'      => 'bad',
            'trigger'   => ['type' => 'schedule', 'interval' => 3600],
            'condition' => ['type' => 'feel', 'expression' => 'true'],
            'actions'   => [['type' => 'run-synchronization', 'synchronizationId' => 's']],
        ];

        $this->expectException(UnsupportedAutomationCombinationException::class);
        $this->compiler->compile($automation);

    }//end testConditionOnScheduleTriggerIsBlocked()

    /**
     * The reserved `approval` action is always blocked.
     *
     * @return void
     */
    public function testApprovalActionIsAlwaysBlocked(): void
    {
        $automation = [
            'id'      => 'auto-x',
            'slug'    => 'bad',
            'trigger' => ['type' => 'manual'],
            'actions' => [['type' => 'approval']],
        ];

        $this->expectException(UnsupportedAutomationCombinationException::class);
        $this->compiler->compile($automation);

    }//end testApprovalActionIsAlwaysBlocked()

    /**
     * Documented deviation: manual + run-synchronization is blocked (no
     * verified OpenConnector "run now" primitive exists — see class docblock).
     *
     * @return void
     */
    public function testManualPlusRunSynchronizationIsBlocked(): void
    {
        $automation = [
            'id'      => 'auto-x',
            'slug'    => 'bad',
            'trigger' => ['type' => 'manual'],
            'actions' => [['type' => 'run-synchronization', 'synchronizationId' => 's']],
        ];

        $this->expectException(UnsupportedAutomationCombinationException::class);
        $this->compiler->compile($automation);

    }//end testManualPlusRunSynchronizationIsBlocked()

    /**
     * Delete removes exactly the provenance-listed notification key; a
     * hand-authored (non-`aut-`) key on the same schema survives.
     *
     * @return void
     */
    public function testRemoveDeletesOnlyProvenanceListedNotificationKey(): void
    {
        $captured = [];
        $schema   = $this->schemaWithConfig(
            [
                'x-openregister-notifications' => [
                    'aut-notify-caseworkers-1' => ['trigger' => ['type' => 'created']],
                    'hand-authored-alert'      => ['trigger' => ['type' => 'created']],
                ],
            ],
            $captured
        );

        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->expects($this->once())->method('update');

        $automation = ['slug' => 'notify-caseworkers', 'versionUuid' => 'version-1'];
        $provenance = [
            'notificationKeys' => [['schema' => 'permit', 'key' => 'aut-notify-caseworkers-1']],
            'lifecycleActions' => [],
            'scheduleIds'      => [],
            'ruleSetSlug'      => null,
        ];

        $this->compiler->remove($automation, $provenance);

        $this->assertArrayNotHasKey('aut-notify-caseworkers-1', $captured['x-openregister-notifications']);
        $this->assertArrayHasKey('hand-authored-alert', $captured['x-openregister-notifications']);

    }//end testRemoveDeletesOnlyProvenanceListedNotificationKey()

    /**
     * Drift: a live artifact hash that no longer matches the stamped
     * `provenance.compiledHash` is reported as drift.
     *
     * @return void
     */
    public function testStatusDetectsDriftOnHashMismatch(): void
    {
        $captured = [];
        $schema   = $this->schemaWithConfig(
            [
                'x-openregister-notifications' => [
                    'aut-notify-caseworkers-1' => ['trigger' => ['type' => 'created'], 'enabled' => false],
                ],
            ],
            $captured
        );

        $this->schemaMapper->method('find')->willReturn($schema);

        $automation = ['slug' => 'notify-caseworkers', 'versionUuid' => 'version-1'];
        $provenance = [
            'notificationKeys' => [['schema' => 'permit', 'key' => 'aut-notify-caseworkers-1']],
            'lifecycleActions' => [],
            'scheduleIds'      => [],
            'ruleSetSlug'      => null,
            // A hash that does not match the live (hand-edited) artifact above.
            'compiledHash'     => 'sha256:0000000000000000000000000000000000000000000000000000000000000000',
        ];

        $status = $this->compiler->status($automation, $provenance);

        $this->assertTrue($status['drift']);

    }//end testStatusDetectsDriftOnHashMismatch()

    /**
     * No drift when nothing was ever compiled (empty provenance).
     *
     * @return void
     */
    public function testStatusNoDriftWhenNeverCompiled(): void
    {
        $status = $this->compiler->status(['slug' => 'x'], []);
        $this->assertFalse($status['drift']);

    }//end testStatusNoDriftWhenNeverCompiled()

    /**
     * Idempotent recompile: applying the SAME plan twice against the SAME
     * prior provenance produces the same resulting provenance (upsert, not
     * duplicate).
     *
     * @return void
     */
    public function testApplyIsIdempotentOnUnchangedPlan(): void
    {
        $captured = [];
        $schema   = $this->schemaWithConfig([], $captured);
        $this->schemaMapper->method('find')->willReturn($schema);
        $this->schemaMapper->method('update')->willReturn($schema);

        $automation = [
            'id'              => 'auto-1',
            'slug'            => 'notify-caseworkers',
            'applicationSlug' => 'permit-tracker',
            'versionUuid'     => 'version-1',
            'enabled'         => true,
            'trigger'         => ['type' => 'object-created', 'schema' => 'permit'],
            'condition'       => null,
            'actions'         => [['type' => 'send-notification', 'subject' => ['en' => 'x']]],
        ];

        $plan = $this->compiler->compile($automation);

        $provenanceA = $this->compiler->apply($automation, $plan, []);
        $provenanceB = $this->compiler->apply($automation, $plan, $provenanceA);

        $this->assertSame($provenanceA['notificationKeys'], $provenanceB['notificationKeys']);
        $this->assertSame($provenanceA['compiledHash'], $provenanceB['compiledHash']);
        $this->assertCount(1, $captured['x-openregister-notifications']);

    }//end testApplyIsIdempotentOnUnchangedPlan()
}//end class
