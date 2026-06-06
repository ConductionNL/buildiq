<?php

/**
 * Unit tests for RuleImpactAnalysisService.
 *
 * Covers REQ-BRE-010: impact analysis queries RuleExecutionLog for the past
 * 30 days, aggregates by consuming app (userId), and returns an ImpactReport
 * listing each consumer with call count and last-call timestamp.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenBuild\Tests\Unit\Service;

use OCA\OpenBuild\Service\RuleImpactAnalysisService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see RuleImpactAnalysisService}.
 */
final class RuleImpactAnalysisServiceTest extends TestCase
{

    /**
     * @var ObjectService&MockObject
     */
    private ObjectService&MockObject $objectService;

    /**
     * The service under test.
     *
     * @var RuleImpactAnalysisService
     */
    private RuleImpactAnalysisService $service;

    /**
     * Wire mocks.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->service       = new RuleImpactAnalysisService(
            $this->objectService,
            $this->createMock(LoggerInterface::class)
        );

    }//end setUp()

    /**
     * Two apps calling the same RuleSet appear as two separate consumerApps
     * with correct call counts and the most active app listed first.
     *
     * @return void
     */
    public function testAnalyzeReturnsConsumerAppsFromTwoApps(): void
    {
        // Within the 30-day window.
        $recent = gmdate('Y-m-d\TH:i:s\Z', strtotime('-5 days'));

        $this->objectService
            ->method('findAll')
            ->willReturn(
                [
                    ['userId' => 'app-alpha', 'tijdstip' => $recent, 'ruleSetId' => 'loan-eligibility'],
                    ['userId' => 'app-alpha', 'tijdstip' => $recent, 'ruleSetId' => 'loan-eligibility'],
                    ['userId' => 'app-beta', 'tijdstip' => $recent, 'ruleSetId' => 'loan-eligibility'],
                ]
            );

        $report = $this->service->analyzeImpactOnActivation(ruleSetId: 'loan-eligibility');

        $this->assertSame('loan-eligibility', $report['ruleSetId']);
        $this->assertSame(30, $report['windowDays']);
        $this->assertCount(2, $report['consumerApps']);

        // Most active (callCount 2) should be first.
        $this->assertSame('app-alpha', $report['consumerApps'][0]['appId']);
        $this->assertSame(2, $report['consumerApps'][0]['callCount']);
        $this->assertSame('app-beta', $report['consumerApps'][1]['appId']);
        $this->assertSame(1, $report['consumerApps'][1]['callCount']);

        $this->assertContains('app-alpha', $report['notification_recipients']);
        $this->assertContains('app-beta', $report['notification_recipients']);

    }//end testAnalyzeReturnsConsumerAppsFromTwoApps()

    /**
     * Log entries older than the window are excluded from the report.
     *
     * @return void
     */
    public function testOldEntriesExcluded(): void
    {
        $old    = gmdate('Y-m-d\TH:i:s\Z', strtotime('-60 days'));
        $recent = gmdate('Y-m-d\TH:i:s\Z', strtotime('-5 days'));

        $this->objectService
            ->method('findAll')
            ->willReturn(
                [
                    ['userId' => 'app-old', 'tijdstip' => $old,    'ruleSetId' => 'invoice-routing'],
                    ['userId' => 'app-new', 'tijdstip' => $recent,  'ruleSetId' => 'invoice-routing'],
                ]
            );

        $report = $this->service->analyzeImpactOnActivation(ruleSetId: 'invoice-routing');

        $this->assertCount(1, $report['consumerApps']);
        $this->assertSame('app-new', $report['consumerApps'][0]['appId']);
        $this->assertNotContains('app-old', $report['notification_recipients']);

    }//end testOldEntriesExcluded()

    /**
     * When no logs exist, the report is empty but structurally correct.
     *
     * @return void
     */
    public function testEmptyLogsProducesEmptyReport(): void
    {
        $this->objectService
            ->method('findAll')
            ->willReturn([]);

        $report = $this->service->analyzeImpactOnActivation(ruleSetId: 'no-calls-yet');

        $this->assertSame('no-calls-yet', $report['ruleSetId']);
        $this->assertCount(0, $report['consumerApps']);
        $this->assertCount(0, $report['notification_recipients']);

    }//end testEmptyLogsProducesEmptyReport()

    /**
     * When ObjectService throws, the service returns a gracefully empty report.
     *
     * @return void
     */
    public function testObjectServiceExceptionReturnsEmptyReport(): void
    {
        $this->objectService
            ->method('findAll')
            ->willThrowException(new \RuntimeException('OR unavailable'));

        $report = $this->service->analyzeImpactOnActivation(ruleSetId: 'any-rule-set');

        $this->assertCount(0, $report['consumerApps']);
        $this->assertCount(0, $report['notification_recipients']);

    }//end testObjectServiceExceptionReturnsEmptyReport()

    /**
     * lastCallAt tracks the most recent call for each consumer.
     *
     * @return void
     */
    public function testLastCallAtIsTheMostRecent(): void
    {
        $earlier = gmdate('Y-m-d\TH:i:s\Z', strtotime('-10 days'));
        $later   = gmdate('Y-m-d\TH:i:s\Z', strtotime('-2 days'));

        $this->objectService
            ->method('findAll')
            ->willReturn(
                [
                    ['userId' => 'app-alpha', 'tijdstip' => $earlier, 'ruleSetId' => 'complaint-escalation'],
                    ['userId' => 'app-alpha', 'tijdstip' => $later,   'ruleSetId' => 'complaint-escalation'],
                ]
            );

        $report = $this->service->analyzeImpactOnActivation(ruleSetId: 'complaint-escalation');

        $this->assertSame(2, $report['consumerApps'][0]['callCount']);
        $this->assertSame($later, $report['consumerApps'][0]['lastCallAt']);

    }//end testLastCallAtIsTheMostRecent()

    /**
     * Custom window length is respected and reflected in the report.
     *
     * @return void
     */
    public function testCustomWindowLength(): void
    {
        $this->objectService
            ->method('findAll')
            ->willReturn([]);

        $report = $this->service->analyzeImpactOnActivation(ruleSetId: 'any', windowDays: 7);

        $this->assertSame(7, $report['windowDays']);

    }//end testCustomWindowLength()
}//end class
