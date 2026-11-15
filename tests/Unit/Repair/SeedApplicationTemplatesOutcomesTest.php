<?php

/**
 * Outcome-reporting tests for the SeedApplicationTemplates repair step.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Buildiq\Tests\Unit\Repair
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

namespace OCA\Buildiq\Tests\Unit\Repair;

use OCA\Buildiq\Repair\SeedApplicationTemplates;
use OCA\Buildiq\Service\TemplateSeedService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Buildiq\Repair\SeedApplicationTemplates
 */
class SeedApplicationTemplatesOutcomesTest extends TestCase {
	/**
	 * The step names what it seeds.
	 *
	 * @return void
	 */
	public function testTheNameSaysWhatItSeeds(): void {
		$step = new SeedApplicationTemplates($this->createMock(TemplateSeedService::class));

		$this->assertSame('Seed Conduction-curated Buildiq ApplicationTemplate records', $step->getName());

	}//end testTheNameSaysWhatItSeeds()

	/**
	 * A deferred seed warns and does not report completion.
	 *
	 * @return void
	 */
	public function testADeferredSeedWarnsAndStops(): void {
		$seed = $this->createMock(TemplateSeedService::class);
		$seed->expects($this->once())->method('seed')->willReturn(['deferred' => true, 'errors' => ['ignored']]);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Seeding ApplicationTemplate records...');
		$output->expects($this->once())->method('warning')->with(
			'Buildiq register/schema not available yet — deferring template seeding '
			. '(completes on the next repair once the register exists).'
		);

		(new SeedApplicationTemplates($seed))->run($output);

	}//end testADeferredSeedWarnsAndStops()

	/**
	 * Every collected error is surfaced, then the step fails loudly with all of them.
	 *
	 * @return void
	 */
	public function testErrorsAreSurfacedThenTheStepFails(): void {
		$seed = $this->createMock(TemplateSeedService::class);
		$seed->method('seed')->willReturn(['errors' => ['first bad', 'second bad'], 'seeded' => 0, 'updated' => 0, 'skipped' => 0]);

		$warned = [];
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$warned): void {
				$warned[] = $message;
			}
		);

		try {
			(new SeedApplicationTemplates($seed))->run($output);
			$this->fail('Expected the step to fail on seeding errors.');
		} catch (RuntimeException $e) {
			$this->assertSame('Buildiq template seeding failed: first bad; second bad', $e->getMessage());
		}

		$this->assertSame(['first bad', 'second bad'], $warned);

	}//end testErrorsAreSurfacedThenTheStepFails()

	/**
	 * A clean seed reports its counts.
	 *
	 * @return void
	 */
	public function testACleanSeedReportsItsCounts(): void {
		$seed = $this->createMock(TemplateSeedService::class);
		$seed->method('seed')->willReturn(['errors' => [], 'seeded' => 2, 'updated' => 1, 'skipped' => 3]);

		$said = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$said): void {
				$said[] = $message;
			}
		);
		$output->expects($this->never())->method('warning');

		(new SeedApplicationTemplates($seed))->run($output);

		$this->assertSame(
			['Seeding ApplicationTemplate records...', 'Buildiq template seeding complete. New: 2, updated: 1, skipped: 3'],
			$said
		);

	}//end testACleanSeedReportsItsCounts()
}//end class
