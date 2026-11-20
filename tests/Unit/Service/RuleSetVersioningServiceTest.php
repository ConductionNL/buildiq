<?php

/**
 * Unit tests for RuleSetVersioningService.
 *
 * Covers REQ-BRE-005: semver bump kinds, the pre-activation test gate, and
 * test-failure blocking promotion.
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

use OCA\Buildiq\Service\Automation\RuleSetActivationRecompiler;
use OCA\Buildiq\Service\RuleEngineService;
use OCA\Buildiq\Service\RuleSetVersioningService;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see RuleSetVersioningService}.
 */
final class RuleSetVersioningServiceTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * @var RuleEngineService&MockObject
	 */
	private RuleEngineService&MockObject $ruleEngine;

	/**
	 * The service under test.
	 *
	 * @var RuleSetVersioningService
	 */
	private RuleSetVersioningService $service;

	/**
	 * Wire mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->ruleEngine = $this->createMock(RuleEngineService::class);
		$this->service = new RuleSetVersioningService(
			$this->objectService,
			$this->ruleEngine,
			$this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Patch / minor / major bumps compute correctly.
	 *
	 * @return void
	 */
	public function testBumpVersion(): void {
		$this->assertSame('1.2.4', $this->service->bumpVersion('1.2.3', 'patch'));
		$this->assertSame('1.3.0', $this->service->bumpVersion('1.2.3', 'minor'));
		$this->assertSame('2.0.0', $this->service->bumpVersion('1.2.3', 'major'));

	}//end testBumpVersion()

	/**
	 * Promotion bumps the version and persists when all tests pass.
	 *
	 * @return void
	 */
	public function testPromoteToActivePassing(): void {
		$this->ruleEngine->method('evaluate')->willReturn(
			['result' => ['decision' => 'approve'], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []]
		);
		$this->objectService->expects($this->once())->method('saveObject');

		$ruleSet = ['slug' => 'loan-eligibility', 'version' => '1.0.0', 'status' => 'test'];
		$tests = [['name' => 't1', 'inputPayload' => [], 'expectedResult' => ['decision' => 'approve']]];

		$updated = $this->service->promoteToActive($ruleSet, $tests, 'patch');
		$this->assertSame('1.0.1', $updated['version']);
		$this->assertSame('active', $updated['status']);

	}//end testPromoteToActivePassing()

	/**
	 * A failing test blocks promotion with an exception naming the case.
	 *
	 * @return void
	 */
	public function testPromoteBlockedByFailingTest(): void {
		$this->ruleEngine->method('evaluate')->willReturn(
			['result' => ['decision' => 'deny'], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []]
		);
		$this->objectService->expects($this->never())->method('saveObject');

		$ruleSet = ['slug' => 'loan-eligibility', 'version' => '1.0.0', 'status' => 'test'];
		$tests = [['name' => 'should-approve', 'inputPayload' => [], 'expectedResult' => ['decision' => 'approve']]];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('should-approve');
		$this->service->promoteToActive($ruleSet, $tests, 'patch');

	}//end testPromoteBlockedByFailingTest()

	/**
	 * Activating a version recompiles the automations that copy the rule set,
	 * with the version just activated (logic-automation-actions-that-run REQ-BQAR-005).
	 *
	 * @return void
	 */
	public function testPromotionRecompilesTheAutomationsThatUseTheRuleSet(): void {
		$this->ruleEngine->method('evaluate')->willReturn(
			['result' => ['decision' => 'approve'], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []]
		);
		$recompiler = $this->createMock(RuleSetActivationRecompiler::class);
		$recompiler->expects($this->once())->method('recompileReferencing')
			->with('leges-categorie', '1.1.0')
			->willReturn(['recompiled' => 1, 'failed' => 0]);
		$service = new RuleSetVersioningService(
			$this->objectService,
			$this->ruleEngine,
			$this->createMock(LoggerInterface::class),
			$recompiler,
		);

		$ruleSet = ['slug' => 'leges-categorie', 'version' => '1.0.3', 'status' => 'test'];
		$tests = [['name' => 't1', 'inputPayload' => [], 'expectedResult' => ['decision' => 'approve']]];

		self::assertSame('1.1.0', $service->promoteToActive($ruleSet, $tests, 'minor')['version']);
	}//end testPromotionRecompilesTheAutomationsThatUseTheRuleSet()

	/**
	 * A blocked or unsaved promotion recompiles nothing.
	 *
	 * @return void
	 */
	public function testAFailedPromotionRecompilesNothing(): void {
		$this->ruleEngine->method('evaluate')->willReturn(
			['result' => ['decision' => 'approve'], 'triggeredRules' => [], 'executionTime' => 1, 'errors' => []]
		);
		$this->objectService->method('saveObject')->willThrowException(new RuntimeException('down'));
		$recompiler = $this->createMock(RuleSetActivationRecompiler::class);
		$recompiler->expects($this->never())->method('recompileReferencing');
		$service = new RuleSetVersioningService(
			$this->objectService,
			$this->ruleEngine,
			$this->createMock(LoggerInterface::class),
			$recompiler,
		);

		$ruleSet = ['slug' => 'leges-categorie', 'version' => '1.0.3', 'status' => 'test'];
		$tests = [['name' => 't1', 'inputPayload' => [], 'expectedResult' => ['decision' => 'approve']]];

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Failed to persist promoted RuleSet');
		$service->promoteToActive($ruleSet, $tests, 'patch');
	}//end testAFailedPromotionRecompilesNothing()
}//end class
