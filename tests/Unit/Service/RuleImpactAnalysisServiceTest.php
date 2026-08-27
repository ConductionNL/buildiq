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
 * @package  OCA\Buildiq\Tests\Unit\Service
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

namespace OCA\Buildiq\Tests\Unit\Service;

use OCA\Buildiq\Service\RuleImpactAnalysisService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see RuleImpactAnalysisService}.
 */
final class RuleImpactAnalysisServiceTest extends TestCase {

	/**
	 * OpenRegister ObjectService mock.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

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
	protected function setUp(): void {
		$this->objectService = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$this->service = new RuleImpactAnalysisService(
			objectService: $this->objectService,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Two apps calling the same RuleSet appear as two separate consumerApps
	 * with correct call counts and the most active app listed first.
	 *
	 * @return void
	 */
	public function testAnalyzeReturnsConsumerAppsFromTwoApps(): void {
		// Within the 30-day window.
		$recent = gmdate('Y-m-d\TH:i:s\Z', strtotime('-5 days'));

		$this->objectService
			->method('findAll')
			->willReturn(
				[
					['userId' => 'app-alpha', 'timestamp' => $recent, 'ruleSetId' => 'loan-eligibility'],
					['userId' => 'app-alpha', 'timestamp' => $recent, 'ruleSetId' => 'loan-eligibility'],
					['userId' => 'app-beta', 'timestamp' => $recent, 'ruleSetId' => 'loan-eligibility'],
				]
			);

		$report = $this->service->analyzeImpactOnActivation(ruleSetId: 'loan-eligibility');

		$this->assertSame(expected: 'loan-eligibility', actual: $report['ruleSetId']);
		$this->assertSame(expected: 30, actual: $report['windowDays']);
		$this->assertCount(expectedCount: 2, haystack: $report['consumerApps']);

		// Most active (callCount 2) should be first.
		$this->assertSame(expected: 'app-alpha', actual: $report['consumerApps'][0]['appId']);
		$this->assertSame(expected: 2, actual: $report['consumerApps'][0]['callCount']);
		$this->assertSame(expected: 'app-beta', actual: $report['consumerApps'][1]['appId']);
		$this->assertSame(expected: 1, actual: $report['consumerApps'][1]['callCount']);

		$this->assertContains(needle: 'app-alpha', haystack: $report['notification_recipients']);
		$this->assertContains(needle: 'app-beta', haystack: $report['notification_recipients']);

	}//end testAnalyzeReturnsConsumerAppsFromTwoApps()

	/**
	 * Log entries older than the window are excluded from the report.
	 *
	 * @return void
	 */
	public function testOldEntriesExcluded(): void {
		$old = gmdate('Y-m-d\TH:i:s\Z', strtotime('-60 days'));
		$recent = gmdate('Y-m-d\TH:i:s\Z', strtotime('-5 days'));

		$this->objectService
			->method('findAll')
			->willReturn(
				[
					['userId' => 'app-old', 'timestamp' => $old,    'ruleSetId' => 'invoice-routing'],
					['userId' => 'app-new', 'timestamp' => $recent,  'ruleSetId' => 'invoice-routing'],
				]
			);

		$report = $this->service->analyzeImpactOnActivation(ruleSetId: 'invoice-routing');

		$this->assertCount(expectedCount: 1, haystack: $report['consumerApps']);
		$this->assertSame(expected: 'app-new', actual: $report['consumerApps'][0]['appId']);
		$this->assertNotContains(needle: 'app-old', haystack: $report['notification_recipients']);

	}//end testOldEntriesExcluded()

	/**
	 * When no logs exist, the report is empty but structurally correct.
	 *
	 * @return void
	 */
	public function testEmptyLogsProducesEmptyReport(): void {
		$this->objectService
			->method('findAll')
			->willReturn([]);

		$report = $this->service->analyzeImpactOnActivation(ruleSetId: 'no-calls-yet');

		$this->assertSame(expected: 'no-calls-yet', actual: $report['ruleSetId']);
		$this->assertCount(expectedCount: 0, haystack: $report['consumerApps']);
		$this->assertCount(expectedCount: 0, haystack: $report['notification_recipients']);

	}//end testEmptyLogsProducesEmptyReport()

	/**
	 * When ObjectService throws, the service returns a gracefully empty report.
	 *
	 * @return void
	 */
	public function testObjectServiceExceptionReturnsEmptyReport(): void {
		$this->objectService
			->method('findAll')
			->willThrowException(new \RuntimeException('OR unavailable'));

		$report = $this->service->analyzeImpactOnActivation(ruleSetId: 'any-rule-set');

		$this->assertCount(expectedCount: 0, haystack: $report['consumerApps']);
		$this->assertCount(expectedCount: 0, haystack: $report['notification_recipients']);

	}//end testObjectServiceExceptionReturnsEmptyReport()

	/**
	 * The lastCallAt field should track the most recent call for each consumer.
	 *
	 * @return void
	 */
	public function testLastCallAtIsTheMostRecent(): void {
		$earlier = gmdate('Y-m-d\TH:i:s\Z', strtotime('-10 days'));
		$later = gmdate('Y-m-d\TH:i:s\Z', strtotime('-2 days'));

		$this->objectService
			->method('findAll')
			->willReturn(
				[
					['userId' => 'app-alpha', 'timestamp' => $earlier, 'ruleSetId' => 'complaint-escalation'],
					['userId' => 'app-alpha', 'timestamp' => $later,   'ruleSetId' => 'complaint-escalation'],
				]
			);

		$report = $this->service->analyzeImpactOnActivation(ruleSetId: 'complaint-escalation');

		$this->assertSame(expected: 2, actual: $report['consumerApps'][0]['callCount']);
		$this->assertSame(expected: $later, actual: $report['consumerApps'][0]['lastCallAt']);

	}//end testLastCallAtIsTheMostRecent()

	/**
	 * Custom window length is respected and reflected in the report.
	 *
	 * @return void
	 */
	public function testCustomWindowLength(): void {
		$this->objectService
			->method('findAll')
			->willReturn([]);

		$report = $this->service->analyzeImpactOnActivation(ruleSetId: 'any', windowDays: 7);

		$this->assertSame(expected: 7, actual: $report['windowDays']);

	}//end testCustomWindowLength()
}//end class
