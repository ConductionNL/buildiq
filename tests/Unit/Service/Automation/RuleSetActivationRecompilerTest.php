<?php

/**
 * Unit tests for RuleSetActivationRecompiler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Service\Automation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Buildiq\Tests\Unit\Service\Automation;

use OCA\Buildiq\Exception\UnsupportedAutomationCombinationException;
use OCA\Buildiq\Kernel\Contracts\AutomationCompilerServiceInterface;
use OCA\Buildiq\Kernel\Contracts\AutomationWriteServiceInterface;
use OCA\Buildiq\Service\Automation\RuleSetActivationRecompiler;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A new rule set version reaches the flows that copy it (REQ-BQAR-005).
 *
 * @covers \OCA\Buildiq\Service\Automation\RuleSetActivationRecompiler
 * @uses   \OCA\Buildiq\Support\ObjectRecord
 * @uses   \OCA\Buildiq\Service\Automation\AutomationRecords
 * @uses   \OCA\Buildiq\Exception\UnsupportedAutomationCombinationException
 */
final class RuleSetActivationRecompilerTest extends TestCase {

	/**
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * @var AutomationCompilerServiceInterface&MockObject
	 */
	private AutomationCompilerServiceInterface&MockObject $compiler;

	/**
	 * @var AutomationWriteServiceInterface&MockObject
	 */
	private AutomationWriteServiceInterface&MockObject $writer;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The recompiler under test.
	 *
	 * @var RuleSetActivationRecompiler
	 */
	private RuleSetActivationRecompiler $recompiler;

	/**
	 * Build the recompiler over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->compiler = $this->createMock(AutomationCompilerServiceInterface::class);
		$this->writer = $this->createMock(AutomationWriteServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->recompiler = new RuleSetActivationRecompiler(
			objectService: $this->objects,
			compiler: $this->compiler,
			writeService: $this->writer,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * An automation holding version 1.0.0 is recompiled and stored with the new provenance;
	 * one already on 1.0.1, one on another rule set and one without provenance are left alone.
	 *
	 * @return void
	 */
	public function testRecompilesOnlyTheAutomationsThatCopyAnOlderVersion(): void {
		$stale = $this->automation(uuid: 'a-1', versions: [['slug' => 'leges-categorie', 'version' => '1.0.0']]);
		$this->objects->expects($this->once())->method('findAll')
			->with(
				['filters' => ['register' => 'buildiq', 'schema' => 'automation'], 'limit' => 10000],
				false,
				false
			)
			->willReturn([
				$stale,
				$this->automation(uuid: 'a-2', versions: [['slug' => 'leges-categorie', 'version' => '1.0.1']]),
				$this->automation(uuid: 'a-3', versions: [['slug' => 'other', 'version' => '1.0.0']]),
				['id' => 'a-4', 'actions' => []],
			]);

		$plan = ['flow' => ['name' => 'aut-fee']];
		$this->compiler->expects($this->once())->method('compile')->with($stale)->willReturn($plan);
		$this->compiler->expects($this->once())->method('apply')
			->with($stale, $plan, $stale['provenance'])
			->willReturn(['ruleSetVersions' => [['slug' => 'leges-categorie', 'version' => '1.0.1']]]);
		$this->writer->expects($this->once())->method('saveCompiled')
			->with(['provenance' => ['ruleSetVersions' => [['slug' => 'leges-categorie', 'version' => '1.0.1']]]] + $stale, 'a-1')
			->willReturn([]);

		self::assertSame(
			['recompiled' => 1, 'failed' => 0],
			$this->recompiler->recompileReferencing(slug: 'leges-categorie', version: '1.0.1')
		);
	}//end testRecompilesOnlyTheAutomationsThatCopyAnOlderVersion()

	/**
	 * A refused compile is logged and counted; the next automation is still recompiled
	 * and nothing is stored for the refused one.
	 *
	 * @return void
	 */
	public function testARefusedCompileIsCountedAndDoesNotStopTheOthers(): void {
		$refused = $this->automation(uuid: 'a-1', versions: [['slug' => 'leges-categorie', 'version' => '1.0.0']]);
		$fine = $this->automation(uuid: 'a-2', versions: [['slug' => 'leges-categorie', 'version' => '0.9.0']]);
		$this->objects->method('findAll')->willReturn([$refused, $fine]);
		$this->compiler->method('compile')->willReturnCallback(
			static function (array $automation): array {
				if ($automation['id'] === 'a-1') {
					throw new UnsupportedAutomationCombinationException(message: 'The rule set "leges-categorie" has more than one outcome.');
				}

				return ['flow' => []];
			}
		);
		$this->compiler->method('apply')->willReturn([]);
		$this->writer->expects($this->once())->method('saveCompiled')->with($this->anything(), 'a-2')->willReturn([]);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('could not recompile "a-1"'));

		self::assertSame(
			['recompiled' => 1, 'failed' => 1],
			$this->recompiler->recompileReferencing(slug: 'leges-categorie', version: '1.0.1')
		);
	}//end testARefusedCompileIsCountedAndDoesNotStopTheOthers()

	/**
	 * Unreadable automations are logged and nothing is compiled; an empty slug reads nothing.
	 *
	 * @return void
	 */
	public function testUnreadableAutomationsAndAnEmptySlugCompileNothing(): void {
		$this->objects->expects($this->once())->method('findAll')->willThrowException(new RuntimeException('down'));
		$this->compiler->expects($this->never())->method('compile');
		$this->logger->expects($this->once())->method('warning')->with($this->stringContains('could not be read'));

		self::assertSame(['recompiled' => 0, 'failed' => 0], $this->recompiler->recompileReferencing(slug: 'leges-categorie', version: '1.0.1'));
		self::assertSame(['recompiled' => 0, 'failed' => 0], $this->recompiler->recompileReferencing(slug: ' ', version: '1.0.1'));
	}//end testUnreadableAutomationsAndAnEmptySlugCompileNothing()

	/**
	 * The version check reads only the named rule set's entry.
	 *
	 * @return void
	 */
	public function testCopiesOtherVersion(): void {
		$automation = $this->automation(uuid: 'a', versions: [['slug' => 'x', 'version' => '2.0.0'], ['slug' => 'y', 'version' => '1.0.0']]);

		self::assertTrue(RuleSetActivationRecompiler::copiesOtherVersion(automation: $automation, slug: 'y', version: '1.1.0'));
		self::assertFalse(RuleSetActivationRecompiler::copiesOtherVersion(automation: $automation, slug: 'y', version: '1.0.0'));
		self::assertFalse(RuleSetActivationRecompiler::copiesOtherVersion(automation: $automation, slug: 'z', version: '1.0.0'));
		self::assertFalse(RuleSetActivationRecompiler::copiesOtherVersion(automation: ['provenance' => 'broken'], slug: 'y', version: '1.0.0'));
	}//end testCopiesOtherVersion()

	/**
	 * An automation whose provenance names rule set versions.
	 *
	 * @param string                                   $uuid     Its id.
	 * @param list<array{slug: string, version: string}> $versions The rule sets it copies.
	 *
	 * @return array<string, mixed>
	 */
	private function automation(string $uuid, array $versions): array {
		return [
			'id' => $uuid,
			'trigger' => ['type' => 'object-created', 'schema' => 'aanvraag'],
			'actions' => [['type' => 'rule-decision', 'ruleSetSlug' => 'leges-categorie', 'outputField' => 'categorie']],
			'provenance' => ['flowUuid' => 'flow-' . $uuid, 'ruleSetVersions' => $versions],
		];
	}//end automation()
}//end class
