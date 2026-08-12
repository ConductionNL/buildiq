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

use OCA\OpenBuild\Service\RuleEngineService;
use OCA\OpenBuild\Service\RuleSetVersioningService;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for {@see RuleSetVersioningService}.
 */
final class RuleSetVersioningServiceTest extends TestCase {

	/**
	 * @var ObjectService&MockObject
	 */
	private ObjectService&MockObject $objectService;

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
		$this->objectService = $this->createMock(ObjectService::class);
		$this->ruleEngine = $this->createMock(RuleEngineService::class);
		$this->service = new RuleSetVersioningService(
			$this->objectService,
			$this->ruleEngine,
			$this->createMock(LoggerInterface::class)
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
}//end class
